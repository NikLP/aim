<?php

declare(strict_types=1);

namespace Drupal\aim\Drush\Commands;

use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush front end for aim's memory store.
 *
 * All the actual logic - extraction, remember/recall, consolidation - lives
 * in \Drupal\aim\Service\AimMemoryManager, shared with any other caller
 * (a Tool API plugin, an ECA action, a future Form). These commands only
 * handle CLI-specific concerns: parsing option strings, and printing
 * results or errors via $this->io().
 */
final class AimCommands extends DrushCommands {

  /**
   * Constructs an AimCommands object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aim.memory_manager'),
    );
  }

  /**
   * Extracts candidate memory facts from a text file and saves them.
   *
   * @param string $file
   *   Path to a text file, resolved from the Drupal root.
   * @param array $options
   *   Command options.
   *
   * @command aim:extract
   * @aliases aim-extract
   *
   * @option provider The AI provider plugin ID to use.
   * @option model The chat model ID to use.
   * @option source Provenance tag stored on every created fact.
   * @option subject-uid A uid or username of a real account to attach any scope=user candidate to. Omit to skip all scope=user candidates (see ADR-0011).
   * @option index Reindex the vector index immediately after saving.
   *
   * @usage drush aim:extract notes.txt
   *   Extract facts from notes.txt using the default provider and model.
   * @usage drush aim:extract notes.txt --provider=openai --model=gpt-4o --index
   *   Use a different provider and reindex immediately.
   * @usage drush aim:extract notes.txt --subject-uid=42
   *   Attach any scope=user candidate the model finds to account 42.
   */
  #[CLI\Command(name: 'aim:extract', aliases: ['aim-extract'])]
  #[CLI\Argument(name: 'file', description: 'Path to a text file to extract facts from.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID to use.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID to use.')]
  #[CLI\Option(name: 'source', description: 'Provenance tag stored on every created fact.')]
  #[CLI\Option(name: 'subject-uid', description: 'A uid or username of a real account to attach any scope=user candidate to. Omit to skip all scope=user candidates.')]
  #[CLI\Option(name: 'index', description: 'Reindex the vector index immediately after saving.')]
  #[CLI\Usage(name: 'drush aim:extract notes.txt', description: 'Extract facts from notes.txt using the default provider and model.')]
  public function extract(
    string $file,
    array $options = [
      'provider' => NULL,
      'model' => NULL,
      'source' => NULL,
      'subject-uid' => NULL,
      'index' => FALSE,
    ],
  ): void {
    if (!is_readable($file)) {
      $this->io()->error("Cannot read file: $file");
      return;
    }
    $text = trim(file_get_contents($file));
    if ($text === '') {
      $this->io()->warning('File is empty, nothing to extract.');
      return;
    }

    $provider = $this->resolveChatProvider($options);
    if ($provider === NULL) {
      return;
    }
    [$provider_id, $model_id] = $provider;

    $facts = $this->memoryManager->extractFacts($text, $provider_id, $model_id);
    if (empty($facts)) {
      $this->io()->note('The model returned no facts worth remembering.');
      return;
    }

    $source = $options['source'] ?? ('extract:' . basename($file));
    try {
      $result = $this->memoryManager->createFactsFromCandidates($facts, $source, $options['subject-uid'] ?: NULL);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    if ($result['skipped'] > 0) {
      $this->io()->warning("{$result['skipped']} user-scope fact(s) skipped: no --subject-uid was given to attach them to.");
    }

    if ($result['blocked'] > 0) {
      $this->io()->warning("{$result['blocked']} fact(s) blocked by a guardrail check.");
    }

    if (empty($result['created'])) {
      $this->io()->note('No facts were saved.');
      return;
    }

    $rows = array_map(static fn (AimFact $fact): array => [
      $fact->id(),
      $fact->get('scope')->value,
      $fact->get('scope')->value === 'user' ? $fact->get('subject_uid')->target_id : $fact->get('subject')->value,
      $fact->get('text')->value,
    ], $result['created']);

    $this->io()->table(['ID', 'Scope', 'Subject', 'Text'], $rows);
    $this->io()->success(count($rows) . ' fact(s) created from ' . $file . '.');

    if (!empty($options['index'])) {
      $indexed = $this->memoryManager->reindex();
      if ($indexed !== NULL) {
        $this->io()->success("Indexed $indexed item(s).");
      }
    }
    else {
      $this->io()->note('Not indexed yet, next cron run will pick these up. Pass --index to do it now.');
    }
  }

  /**
   * Creates an aim_fact directly, no chat call.
   *
   * Unlike aim:extract, this does not decide what is worth remembering -
   * it stores exactly what the caller (typically an agent that already did
   * that reasoning as part of its own conversation) hands it. Pass --file
   * instead of $text to save several facts (each with its own scope/
   * subject/etc.) in one command invocation, one Drupal bootstrap.
   *
   * @param string|null $text
   *   The fact text, one short statement. Omit when using --file.
   * @param array $options
   *   Command options.
   *
   * @command aim:remember
   * @aliases aim-remember
   *
   * @option scope One of user, role, site, case.
   * @option subject Who or what the fact is about. For scope=user, a uid or username of a real account on this site. Empty for site scope.
   * @option source Provenance tag for this fact.
   * @option state Optional boolean flag value: true or false. Omit for facts with no boolean shape.
   * @option category Comma-separated term name(s) from the aim_category vocabulary. A name with no matching term is skipped.
   * @option asserted When this fact became true in reality, if different from now (any strtotime()-parseable string). Omit unless a caller explicitly knows an earlier date.
   * @option file Path to a JSON file: an array of fact objects (same fields as the options above, plus "text"), saved in one bootstrap instead of $text/the other options.
   *
   * @usage drush aim:remember "Prefers email over phone." --scope=user --subject=42
   *   Save a plain prose fact about a user, by uid or username.
   * @usage drush aim:remember "Opted out of marketing email." --scope=user --subject=42 --state=true
   *   Save a fact that is itself a boolean flag.
   * @usage drush aim:remember "Moved to Manchester." --scope=user --subject=42 --asserted="3 months ago"
   *   Save a fact whose real-world date is earlier than today.
   * @usage drush aim:remember --file=facts.json
   *   Save every fact object in facts.json in one bootstrap.
   */
  #[CLI\Command(name: 'aim:remember', aliases: ['aim-remember'])]
  #[CLI\Argument(name: 'text', description: 'The fact text, one short statement. Omit when using --file.')]
  #[CLI\Option(name: 'scope', description: 'One of user, role, site, case.')]
  #[CLI\Option(name: 'subject', description: 'Who or what the fact is about. For scope=user, a uid or username of a real account.')]
  #[CLI\Option(name: 'source', description: 'Provenance tag for this fact.')]
  #[CLI\Option(name: 'state', description: 'Optional boolean flag value: true or false. Omit for facts with no boolean shape.')]
  #[CLI\Option(name: 'category', description: 'Comma-separated term name(s) from the aim_category vocabulary.')]
  #[CLI\Option(name: 'asserted', description: 'When this fact became true in reality, if different from now.')]
  #[CLI\Option(name: 'file', description: 'Path to a JSON file of fact objects, saved in one bootstrap instead of $text/the other options.')]
  #[CLI\Usage(name: 'drush aim:remember "Prefers email over phone." --scope=user --subject=42', description: 'Save a plain prose fact about a user, by uid or username.')]
  #[CLI\Usage(name: 'drush aim:remember --file=facts.json', description: 'Save every fact in facts.json in one bootstrap.')]
  public function remember(
    ?string $text = NULL,
    array $options = [
      'scope' => 'site',
      'subject' => NULL,
      'source' => NULL,
      'state' => NULL,
      'category' => NULL,
      'asserted' => NULL,
      'file' => NULL,
    ],
  ): void {
    if (!empty($options['file'])) {
      $this->rememberBatch($options['file']);
      return;
    }

    if ($text === NULL || trim($text) === '') {
      $this->io()->error('Provide fact text, or --file with a JSON array of fact objects.');
      return;
    }

    try {
      [$state, $category, $asserted] = $this->parseRememberFields($options);
      $fact = $this->memoryManager->remember($text, $options['scope'], $options['subject'], $options['source'], $state, $category, $asserted);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    $this->io()->success('Created aim_fact ' . $fact->id() . '.');
  }

  /**
   * Saves every fact object in a JSON file, one bootstrap for the batch.
   *
   * Mirrors createFactsFromCandidates()'s per-item resilience: one bad
   * entry (missing text, invalid scope, a rejected guardrail) is reported
   * and skipped rather than aborting the rest of the file.
   *
   * @param string $file
   *   Path to a JSON file containing an array of fact objects. Each object
   *   uses the same field names as aim:remember's own options (text,
   *   scope, subject, source, state, category, asserted); category may be
   *   a JSON array instead of a comma-separated string.
   */
  private function rememberBatch(string $file): void {
    if (!is_readable($file)) {
      $this->io()->error("Cannot read file: $file");
      return;
    }

    $entries = json_decode(file_get_contents($file), TRUE);
    if (!is_array($entries)) {
      $this->io()->error('Expected --file to contain a JSON array of fact objects.');
      return;
    }

    $created = 0;
    foreach ($entries as $i => $entry) {
      if (empty($entry['text'])) {
        $this->io()->warning("Entry $i: missing text, skipped.");
        continue;
      }

      try {
        [$state, $category, $asserted] = $this->parseRememberFields($entry);
        $fact = $this->memoryManager->remember(
          $entry['text'],
          $entry['scope'] ?? 'site',
          $entry['subject'] ?? NULL,
          $entry['source'] ?? NULL,
          $state,
          $category,
          $asserted,
        );
      }
      catch (\InvalidArgumentException $e) {
        $this->io()->warning("Entry $i: " . $e->getMessage());
        continue;
      }

      $this->io()->success("Entry $i: created aim_fact " . $fact->id() . '.');
      $created++;
    }

    $this->io()->note("Created $created of " . count($entries) . ' fact(s).');
  }

  /**
   * Parses aim:remember's shared state/category/asserted fields.
   *
   * @param array $fields
   *   Raw state/category/asserted values, as given on the command line or
   *   decoded from a --file entry.
   *
   * @return array
   *   [$state, $category, $asserted], typed as AimMemoryManager::remember()
   *   expects them.
   *
   * @throws \InvalidArgumentException
   *   If state or asserted cannot be parsed.
   */
  private function parseRememberFields(array $fields): array {
    $state = NULL;
    if (($fields['state'] ?? NULL) !== NULL) {
      $state = filter_var($fields['state'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($state === NULL) {
        throw new \InvalidArgumentException('Invalid state "' . $fields['state'] . '", expected true or false.');
      }
    }

    $category = [];
    if (!empty($fields['category'])) {
      $category = is_array($fields['category']) ? $fields['category'] : explode(',', $fields['category']);
      $category = array_map('trim', $category);
    }

    $asserted = NULL;
    if (($fields['asserted'] ?? NULL) !== NULL) {
      $asserted = strtotime($fields['asserted']);
      if ($asserted === FALSE) {
        throw new \InvalidArgumentException('Invalid asserted "' . $fields['asserted'] . '", expected a date strtotime() can parse.');
      }
    }

    return [$state, $category, $asserted];
  }

  /**
   * Runs a semantic query against the vector index and prints results.
   *
   * @param string $text
   *   The search text.
   * @param array $options
   *   Command options.
   *
   * @command aim:recall
   * @aliases aim-recall
   *
   * @option scope Restrict results to one scope: user, role, site, case.
   * @option subject Restrict results to one subject. Not used for scope=user, see subject-uid.
   * @option subject-uid Restrict results to one user, by uid or username. Only meaningful with scope=user.
   * @option limit Maximum number of results.
   * @option format Output format: table or json.
   *
   * @usage drush aim:recall "email preference"
   *   Search all facts for anything related to email preference.
   * @usage drush aim:recall "email preference" --scope=user --subject-uid=42 --format=json
   *   Search scoped to one user, machine-readable output.
   */
  #[CLI\Command(name: 'aim:recall', aliases: ['aim-recall'])]
  #[CLI\Argument(name: 'text', description: 'The search text.')]
  #[CLI\Option(name: 'scope', description: 'Restrict results to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'subject', description: 'Restrict results to one subject. Not used for scope=user.')]
  #[CLI\Option(name: 'subject-uid', description: 'Restrict results to one user, by uid or username. Only meaningful with scope=user.')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of results.')]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Usage(name: 'drush aim:recall "email preference"', description: 'Search all facts for anything related to email preference.')]
  public function recall(
    string $text,
    array $options = [
      'scope' => NULL,
      'subject' => NULL,
      'subject-uid' => NULL,
      'limit' => 10,
      'format' => 'table',
    ],
  ): void {
    try {
      $rows = $this->memoryManager->recall(
        $text,
        $options['scope'] ?: NULL,
        $options['subject'] ?: NULL,
        $options['subject-uid'] ?: NULL,
        (int) $options['limit'],
      );
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode($rows, JSON_PRETTY_PRINT));
      return;
    }

    if (empty($rows)) {
      $this->io()->note('No facts found.');
      return;
    }

    $table_rows = array_map(static fn (array $row): array => [
      $row['id'],
      $row['score'],
      $row['scope'],
      $row['subject'],
      $row['text'],
      $row['source'],
      $row['state'] === NULL ? '' : ($row['state'] ? 'true' : 'false'),
    ], $rows);
    $this->io()->table(['ID', 'Score', 'Scope', 'Subject', 'Text', 'Source', 'State'], $table_rows);
  }

  /**
   * Reviews existing facts for near-duplicates and merges or retires them.
   *
   * Runs entirely against facts already in the store, independent of any
   * particular write path (see CLAUDE.md's consolidation design notes).
   * Each fact is compared to its nearest vector neighbor within the same
   * scope/subject: an obvious near-duplicate is retired automatically, an
   * ambiguous case gets a single classification call (ADD/UPDATE/DELETE/
   * NOOP, Mem0's vocabulary), and anything past the ambiguous threshold is
   * left alone at zero cost. Retiring a fact sets its `expires` field
   * rather than deleting it, to keep an audit trail - a hard DELETE only
   * happens when the model explicitly says the candidate should not exist
   * as a memory at all.
   *
   * The two threshold defaults (\Drupal\aim\Service\AimMemoryManager::
   * DEFAULT_AUTO_THRESHOLD / DEFAULT_AMBIGUOUS_THRESHOLD) were picked
   * empirically against this site's real fact data, not carried over from
   * Mem0's or Hindsight's own models. Recalibrated 2026-09-09 against
   * amazeeio__mistral-embed (a genuine near-duplicate pair scored ~0.02, a
   * distinct fact about the same subject or an unrelated fact both scored
   * ~0.17-0.27) - the original 0.35/0.65 pair was calibrated against
   * titan-embed-text-v2:0 and was never re-checked after that model was
   * discontinued and swapped mid-session; it sat entirely above the range
   * mistral-embed actually produces, so every pair looked like an
   * auto-duplicate. Re-check with `drush aim:recall`'s score column
   * whenever the embeddings model changes, not just as real fact volume
   * grows.
   *
   * @param array $options
   *   Command options.
   *
   * @command aim:consolidate
   * @aliases aim-consolidate
   *
   * @option scope Restrict the sweep to one scope: user, role, site, case.
   * @option provider The AI provider plugin ID to use for ambiguous cases.
   * @option model The chat model ID to use for ambiguous cases.
   * @option auto-threshold Score at or below which a neighbor is retired automatically, no LLM call.
   * @option ambiguous-threshold Score at or below which an ambiguous neighbor gets a classification call. Above this, facts are left alone.
   * @option dry-run Print decisions without saving anything.
   *
   * @usage drush aim:consolidate --dry-run
   *   Preview consolidation decisions across every scope, changing nothing.
   * @usage drush aim:consolidate --scope=user
   *   Consolidate only user-scoped facts.
   */
  #[CLI\Command(name: 'aim:consolidate', aliases: ['aim-consolidate'])]
  #[CLI\Option(name: 'scope', description: 'Restrict the sweep to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID to use for ambiguous cases.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID to use for ambiguous cases.')]
  #[CLI\Option(name: 'auto-threshold', description: 'Score at or below which a neighbor is retired automatically, no LLM call.')]
  #[CLI\Option(name: 'ambiguous-threshold', description: 'Score at or below which an ambiguous neighbor gets a classification call.')]
  #[CLI\Option(name: 'dry-run', description: 'Print decisions without saving anything.')]
  #[CLI\Usage(name: 'drush aim:consolidate --dry-run', description: 'Preview consolidation decisions across every scope without changing anything.')]
  public function consolidate(
    array $options = [
      'scope' => NULL,
      'provider' => NULL,
      'model' => NULL,
      'auto-threshold' => AimMemoryManager::DEFAULT_AUTO_THRESHOLD,
      'ambiguous-threshold' => AimMemoryManager::DEFAULT_AMBIGUOUS_THRESHOLD,
      'dry-run' => FALSE,
    ],
  ): void {
    $provider = $this->resolveChatProvider($options);
    if ($provider === NULL) {
      return;
    }
    [$provider_id, $model_id] = $provider;

    try {
      $result = $this->memoryManager->consolidate(
        $options['scope'] ?: NULL,
        $provider_id,
        $model_id,
        (float) $options['auto-threshold'],
        (float) $options['ambiguous-threshold'],
        !empty($options['dry-run']),
      );
    }
    catch (\RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    if (empty($result['rows'])) {
      $this->io()->note($result['has_facts'] ? 'No near-duplicate facts found.' : 'No facts to consolidate.');
      return;
    }

    $this->io()->table(['Kept', 'Candidate', 'Score', 'Decision'], $result['rows']);
    if (!empty($options['dry-run'])) {
      $this->io()->note(count($result['rows']) . ' decision(s) previewed, nothing saved. Omit --dry-run to apply.');
    }
    else {
      $this->io()->success(count($result['rows']) . ' decision(s) applied.');
    }
  }

  /**
   * Benchmarks recall latency at increasing fact counts.
   *
   * Generates synthetic facts in batches up to each requested checkpoint,
   * reindexes, then times a batch of recall() calls at that fact count -
   * see CLAUDE.md's AI dependency map and ADR-0010's open question 5
   * (retrieval latency asserted safe by reasoning about SQL cost, never
   * actually benchmarked). Generation bypasses guardrails and the
   * consolidation queue (see AimMemoryManager::generateBenchmarkFacts()),
   * so the only real cost here is one embedding-API call per generated
   * fact, at reindex time - no reasoning/LLM calls anywhere in this
   * command, cost is predictable up front.
   *
   * @param array $options
   *   Command options.
   *
   * @command aim:benchmark
   * @aliases aim-benchmark
   *
   * @option scope Which scope pool to generate facts into: site, role, case, or user.
   * @option checkpoints Comma-separated cumulative fact counts to measure at.
   * @option queries How many timed recall() calls to run at each checkpoint.
   * @option cleanup Delete every fact this run created once the benchmark finishes.
   *
   * @usage drush aim:benchmark --checkpoints=50,200,500 --cleanup
   *   Time recall() at three fact counts up to 500, then remove them all.
   * @usage drush aim:benchmark --scope=user --checkpoints=100,500
   *   Benchmark scope=user (exercises the subject_uid over-fetch gotcha).
   */
  #[CLI\Command(name: 'aim:benchmark', aliases: ['aim-benchmark'])]
  #[CLI\Option(name: 'scope', description: 'Which scope pool to generate facts into: site, role, case, or user.')]
  #[CLI\Option(name: 'checkpoints', description: 'Comma-separated cumulative fact counts to measure at.')]
  #[CLI\Option(name: 'queries', description: 'How many timed recall() calls to run at each checkpoint.')]
  #[CLI\Option(name: 'cleanup', description: 'Delete every fact this run created once the benchmark finishes.')]
  #[CLI\Usage(name: 'drush aim:benchmark --checkpoints=50,200,500 --cleanup', description: 'Generate up to 500 site-scope facts in three steps, timing recall() at each, then remove them all.')]
  public function benchmark(
    array $options = [
      'scope' => 'site',
      'checkpoints' => '50,200,500',
      'queries' => 10,
      'cleanup' => FALSE,
    ],
  ): void {
    $scope = $options['scope'];
    if (!in_array($scope, ['user', 'role', 'site', 'case'], TRUE)) {
      $this->io()->error('Invalid --scope "' . $scope . '", expected one of: user, role, site, case.');
      return;
    }

    $checkpoints = array_unique(array_map('intval', explode(',', (string) $options['checkpoints'])));
    sort($checkpoints);
    $queryCount = max(1, (int) $options['queries']);
    $runTag = 'benchmark:' . date('Ymd-His');

    $subjectUids = [];
    if ($scope === 'user') {
      $subjectUids = $this->memoryManager->sampleUserIds();
      if (empty($subjectUids)) {
        $this->io()->error('No real user accounts found to benchmark scope=user against (a user-scope fact must reference a real account, ADR-0007).');
        return;
      }
      $this->io()->note('Distributing generated facts across ' . count($subjectUids) . ' real account(s).');
    }

    $sampleQueries = [
      'email preference', 'billing question', 'mobile app issue',
      'onboarding process', 'dashboard feedback', 'support ticket follow-up',
      'notification settings', 'integration request',
    ];

    $this->io()->note("Run tag: $runTag. Bypasses guardrails and consolidation (synthetic text needs neither) - the only real cost is one embedding-API call per fact at reindex time.");

    $rows = [];
    $createdSoFar = 0;
    foreach ($checkpoints as $target) {
      if ($target > $createdSoFar) {
        $this->memoryManager->generateBenchmarkFacts($scope, $target - $createdSoFar, $runTag, $subjectUids);
        $createdSoFar = $target;
      }

      $indexStart = microtime(TRUE);
      $indexed = $this->memoryManager->reindex();
      $indexMs = (int) round((microtime(TRUE) - $indexStart) * 1000);

      $subjectUid = $scope === 'user' ? (string) $subjectUids[array_rand($subjectUids)] : NULL;
      $timings = [];
      for ($i = 0; $i < $queryCount; $i++) {
        $start = microtime(TRUE);
        try {
          $this->memoryManager->recall($sampleQueries[$i % count($sampleQueries)], $scope, NULL, $subjectUid, 10);
        }
        catch (\InvalidArgumentException | \RuntimeException $e) {
          $this->io()->error($e->getMessage());
          return;
        }
        $timings[] = (microtime(TRUE) - $start) * 1000;
      }
      sort($timings);
      $avg = (int) round(array_sum($timings) / count($timings));
      $p95 = (int) round($timings[(int) floor(0.95 * (count($timings) - 1))]);

      $rows[] = [$createdSoFar, $indexed ?? 'n/a', $indexMs, $avg, $p95];
    }

    $this->io()->table(['Facts', 'Indexed this batch', 'Reindex ms', 'Recall avg ms', 'Recall p95 ms'], $rows);

    if (!empty($options['cleanup'])) {
      $deleted = $this->memoryManager->deleteBenchmarkFacts($runTag);
      $this->memoryManager->reindex();
      $this->io()->success("Cleaned up $deleted benchmark fact(s).");
    }
    else {
      $this->io()->note("Benchmark facts left in place, tagged source=\"$runTag\". Remove them later with: drush aim:benchmark-cleanup $runTag");
    }
  }

  /**
   * Deletes every fact created by a previous aim:benchmark run.
   *
   * @param string $runTag
   *   The run tag printed by aim:benchmark, e.g. benchmark:20260910-141500.
   *
   * @command aim:benchmark-cleanup
   * @aliases aim-benchmark-cleanup
   *
   * @usage drush aim:benchmark-cleanup benchmark:20260910-141500
   *   Remove every fact tagged with that benchmark run and reindex.
   */
  #[CLI\Command(name: 'aim:benchmark-cleanup', aliases: ['aim-benchmark-cleanup'])]
  #[CLI\Argument(name: 'runTag', description: 'The run tag printed by aim:benchmark.')]
  public function benchmarkCleanup(string $runTag): void {
    $deleted = $this->memoryManager->deleteBenchmarkFacts($runTag);
    if ($deleted === 0) {
      $this->io()->note('No facts found tagged "' . $runTag . '".');
      return;
    }
    $this->memoryManager->reindex();
    $this->io()->success("Deleted $deleted fact(s), reindexed.");
  }

  /**
   * Resolves --provider/--model options, falling back to the site default.
   *
   * Deliberately does not hardcode a fallback provider/model: a literal
   * default here would be exactly the kind of value that already had to be
   * hand-edited twice this project when the site's working provider
   * changed. Resolving `ai.settings`' own default chat provider instead
   * means these commands automatically follow it.
   *
   * @param array $options
   *   The command options array, read for 'provider' and 'model'.
   *
   * @return array|null
   *   A [provider_id, model_id] pair, or NULL if neither option was given
   *   and no default chat provider is configured (an error has already
   *   been printed to the user in that case).
   */
  protected function resolveChatProvider(array $options): ?array {
    if (!empty($options['provider']) && !empty($options['model'])) {
      return [$options['provider'], $options['model']];
    }

    $default = $this->memoryManager->getDefaultChatProvider();
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      $this->io()->error('No --provider/--model given, and no default chat provider is configured. Set one at /admin/config/ai/settings, or pass --provider and --model explicitly.');
      return NULL;
    }

    return [$default['provider_id'], $default['model_id']];
  }

}
