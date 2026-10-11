<?php

/**
 * @file
 * Scores the aim decision points through Drupal's own Decision provider.
 *
 * Every call goes through aim.backend.decision and the configured provider (keys stay in Drupal,
 * metrics land in aim_activity_metrics under the activity's tag).
 *
 * Usage (from the site root, script path shortened; set files are named
 * relative to this directory):
 *   ddev drush php:script .../decision-eval.php -- grounding [set-file]
 *   ddev drush php:script .../decision-eval.php -- merges [set-file] [cutoff]
 *   ddev drush php:script .../decision-eval.php -- pairs [set-file]
 *
 * Provider and model come from aim.settings activities (grounding, verifier,
 * consolidation).
 */

use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\Value\ChoiceQuestion;
use Drupal\ai\OperationType\Decision\Value\NoulQuestion;

$task = $extra[0] ?? 'grounding';
$dir = __DIR__;
// Set files are named relative to this directory (drush php:script runs
// from the docroot).
$set = fn (string $default): string => "$dir/" . basename($extra[1] ?? $default);

// Candidate wordings; %1$s is the candidate's state key.
$grounding_qs = [
  'strict' => 'Does the text state the fact in %1$s? Answer no if %1$s adds, changes or drops any detail, gives an old value the text has replaced, pins a detail on the wrong person or thing, or states as settled something the text only proposes, hedges or reports second-hand.',
  'narrow' => 'Does the text state the fact in %1$s? Answer no if %1$s adds or changes a detail, drops a detail that changes its meaning, gives an old value the text has replaced, pins a detail on the wrong person or thing, or states as settled something the text only proposes, hedges or reports second-hand.',
  'narrow_ok' => 'Does the text state the fact in %1$s? Answer no if %1$s adds or changes a detail, drops a detail that changes its meaning, gives an old value the text has replaced, pins a detail on the wrong person or thing, or states as settled something the text only proposes, hedges or reports second-hand. Leaving out greetings, filler, typos or a request to remember is fine.',
];
// The live verifier question (AimMemoryManager::modelConfirmsMerge()).
$merge_q = 'Every detail of fact_a and fact_b (names, numbers, dates, conditions, negations) survives in merged, and merged adds nothing the two facts do not say, except that a value in fact_b which differs from the value fact_a gives for the same attribute (a duration, day, price or place) supersedes it, so the old value should be absent. Judge faithfulness to the inputs, not whether they are true.';
// The live pair question (AimMemoryManager::classifyPairDecision()).
$pair_q = 'Decide what to do with the candidate fact relative to the existing fact. When unsure, choose ADD: keeping both facts is always safe.';
$pair_options = [
  'ADD' => 'The facts are genuinely different; keep both.',
  'UPDATE' => 'The candidate refines, corrects or supersedes the existing fact.',
  'NOOP' => 'The candidate restates the existing fact with no new information.',
  'RETIRE' => 'The candidate should not exist as a memory at all (nonsensical or clearly erroneous). Use sparingly.',
];

$activities = \Drupal::config('aim.settings')->get('activities');
$backend = \Drupal::service('aim.backend.decision');
$ask = function (string $activity, DecisionInput $input) use ($activities, $backend) {
  $start = microtime(TRUE);
  $response = $backend->run($activity, $input, $activities[$activity]['provider'], $activities[$activity]['model']);
  return [$response, microtime(TRUE) - $start];
};

$auc = function (array $pos, array $neg): float {
  $sum = 0;
  foreach ($pos as $p) {
    foreach ($neg as $n) {
      $sum += $p > $n ? 1 : ($p == $n ? 0.5 : 0);
    }
  }
  return $sum / (count($pos) * count($neg));
};
$summary = function (array $vals): string {
  sort($vals);
  return sprintf('n=%d min %.2f med %.2f max %.2f', count($vals), $vals[0], $vals[intdiv(count($vals), 2)], end($vals));
};
$times = [];

if ($task === 'grounding') {
  $data = json_decode(file_get_contents($set("decision-eval-grounding.json")), TRUE);
  $items = $data['grounding'];
  $cutoff = (float) ($activities['grounding']['threshold'] ?? 0.7);
  $groups = [];
  foreach ($items as $i => $item) {
    $groups[$item['passage']][] = $i;
  }
  // One call per passage, every candidate and wording in it (as live).
  $scores = [];
  foreach ($groups as $passage => $idx) {
    $state = ['text' => $data['passages'][$passage]];
    $questions = [];
    foreach ($idx as $n => $i) {
      $key = 'candidate_' . ($n + 1);
      $state[$key] = $items[$i]['candidate'];
      foreach ($grounding_qs as $w => $q) {
        $questions["{$w}_" . ($n + 1)] = new NoulQuestion(sprintf($q, $key));
      }
    }
    [$response, $t] = $ask('grounding', new DecisionInput($state, $questions));
    $times[] = $t;
    foreach ($idx as $n => $i) {
      foreach ($grounding_qs as $w => $q) {
        $scores[$w][$i] = $response->getNoul("{$w}_" . ($n + 1))->getProbability();
      }
    }
  }
  foreach ($grounding_qs as $w => $q) {
    $pos = $neg = $by = [];
    foreach ($items as $i => $item) {
      $s = $scores[$w][$i];
      $item['label'] === 'supported' ? $pos[] = $s : $neg[] = $s;
      $by[$item['label']][] = $s;
    }
    $false_trust = count(array_filter($neg, fn ($s) => $s >= $cutoff));
    $false_hold = count(array_filter($pos, fn ($s) => $s < $cutoff));
    printf("grounding/%s: AUC %.2f, @%.2f false trust %d/%d, false hold %d/%d\n", $w, $auc($pos, $neg), $cutoff, $false_trust, count($neg), $false_hold, count($pos));
    foreach ($by as $label => $vals) {
      printf("  %-14s %s\n", $label, $summary($vals));
    }
    foreach ($items as $i => $item) {
      $s = $scores[$w][$i];
      $new = str_starts_with($item['passage'], 'chat_cleanup') || str_starts_with($item['passage'], 'chat_drop');
      if (($item['label'] === 'supported') !== ($s >= $cutoff) || $new) {
        printf("  %s %.2f %-13s %s | %s\n", ($item['label'] === 'supported') !== ($s >= $cutoff) ? 'MISS' : 'ok  ', $s, $item['label'], mb_substr($item['candidate'], 0, 60), $item['note']);
      }
    }
  }
}
elseif ($task === 'merges') {
  $sets = json_decode(file_get_contents($set("decision-eval-sets.json")), TRUE);
  $cutoff = (float) ($extra[2] ?? $activities['verifier']['threshold'] ?? 0.5);
  $pos = $neg = [];
  foreach ($sets['merges'] as $m) {
    [$response, $t] = $ask('verifier', new DecisionInput(
      ['fact_a' => $m['kept'], 'fact_b' => $m['candidate'], 'merged' => $m['merged']],
      ['faithful' => new NoulQuestion($merge_q)],
    ));
    $times[] = $t;
    $s = $response->getNoul('faithful')->getProbability();
    $m['label'] === 'good' ? $pos[] = $s : $neg[] = $s;
    if (($m['label'] === 'good') !== ($s >= $cutoff) || str_starts_with($m['note'], 'supersede') || str_contains($m['note'], 'supersede')) {
      printf("  %s %.2f %-4s %s\n", ($m['label'] === 'good') !== ($s >= $cutoff) ? 'MISS' : 'ok  ', $s, $m['label'], $m['note']);
    }
  }
  printf("merges: AUC %.2f, @%.2f bad passed %d/%d, good held %d/%d\n", $auc($pos, $neg), $cutoff,
    count(array_filter($neg, fn ($s) => $s >= $cutoff)), count($neg), count(array_filter($pos, fn ($s) => $s < $cutoff)), count($pos));
  printf("  good %s\n  bad  %s\n", $summary($pos), $summary($neg));
}
elseif ($task === 'pairs') {
  $sets = json_decode(file_get_contents($set("decision-eval-sets.json")), TRUE);
  $labels = array_keys($pair_options);
  $conf = [];
  $hits = $unsafe = 0;
  foreach ($sets['pairs'] as $p) {
    [$response, $t] = $ask('consolidation', new DecisionInput(
      ['existing' => $p['existing'], 'candidate' => $p['candidate']],
      ['decision' => new ChoiceQuestion($pair_q, $pair_options)],
    ));
    $times[] = $t;
    $got = $response->getChoice('decision')->getChoice();
    $conf[$p['label']][$got] = ($conf[$p['label']][$got] ?? 0) + 1;
    $hits += $got === $p['label'];
    if (($got === 'RETIRE' && $p['label'] !== 'RETIRE') || ($got === 'UPDATE' && in_array($p['label'], ['ADD', 'NOOP'], TRUE))) {
      $unsafe++;
      printf("  UNSAFE truth=%s got=%s | %s\n", $p['label'], $got, mb_substr($p['candidate'], 0, 70));
    }
  }
  printf("pairs: accuracy %d/%d, unsafe errors %d\n  truth\\got %s\n", $hits, count($sets['pairs']), $unsafe, implode(' ', array_map(fn ($l) => sprintf('%7s', $l), $labels)));
  foreach ($labels as $truth) {
    printf("  %-9s %s\n", $truth, implode(' ', array_map(fn ($g) => sprintf('%7d', $conf[$truth][$g] ?? 0), $labels)));
  }
}

sort($times);
printf("%d calls, median %.2fs, max %.2fs\n", count($times), $times[intdiv(count($times), 2)], end($times));
