<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\Value\NoulQuestion;
use Drupal\aim\Backend\ActivityBackendInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The grounded check (ADR-0037): does a passage state each candidate fact?
 *
 * Split out of AimMemoryManager. Checks and scores only; the manager decides
 * what to do with a score when it writes the fact.
 */
class AimGroundingChecker {

  /**
   * The grounded check's question, %1$s being the candidate's state key.
   *
   * The "strict" wording measured in aim_benchmark's decision-eval.php
   * grounding task (ADR-0037 addenda): naming the failure kinds stops
   * proposals and wishes passing as settled facts. Narrower wordings were
   * tested 2026-10-11 and not adopted.
   */
  protected const GROUNDING_QUESTION = 'Does the text state the fact in %1$s? Answer no if %1$s adds, changes or drops any detail, gives an old value the text has replaced, pins a detail on the wrong person or thing, or states as settled something the text only proposes, hedges or reports second-hand.';

  /**
   * The time check's question, %1$s being the candidate's state key.
   *
   * Says "candidate", not "fact": the decision model does not know the term.
   * The "ends" wording of decision-eval.php's time task (23-item gold set,
   * 2026-11-12): AUC 1.00, no false asks. "Only true or relevant for a
   * particular date or period" scored past events and start-only dates 0.7
   * to 1.0 too, which would have sent every dated memory for a pointless
   * retry.
   */
  protected const TIME_QUESTION = 'Does %1$s state or imply a date after which it stops being true?';

  /**
   * Grounding scores fetched this request, keyed by passage and candidate.
   *
   * Lets a caller check a whole batch in one call, after which remember()
   * finds each fact's score here instead of calling again.
   *
   * @var array<string, float|null>
   */
  protected array $groundingCache = [];

  /**
   * Time-check scores fetched this request, keyed by candidate text.
   *
   * @var array<string, float|null>
   */
  protected array $timeCache = [];

  /**
   * Constructs an AimGroundingChecker object.
   *
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The settings reader.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The aim logger channel.
   * @param \Drupal\aim\Backend\ActivityBackendInterface $decisionBackend
   *   The decision backend.
   */
  public function __construct(
    protected AimSettings $settings,
    #[Autowire(service: 'logger.channel.aim')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'aim.backend.decision')]
    protected ActivityBackendInterface $decisionBackend,
  ) {}

  /**
   * Asks the decision model whether a passage states each candidate fact.
   *
   * One call per group of candidates (activities.grounding.max_questions,
   * 16 by default, counting every question): each candidate goes into the
   * state next to the passage with its own question, which measured as fast
   * and as accurate as one call per fact (ADR-0037 addendum). When the time
   * check is on, its question rides in the same call. Scores already
   * fetched this request are reused. The passage is never stored; with
   * log_query_text on, it is logged with each score for diagnosis.
   *
   * @param string $passage
   *   The text the facts were written from.
   * @param string[] $candidates
   *   Candidate fact texts, keyed however the caller likes.
   *
   * @return array
   *   A grounding probability (0 to 1) per candidate, same keys, or NULL for
   *   each candidate when the check is off, has no decision model, or failed.
   */
  public function checkGrounding(string $passage, array $candidates): array {
    $passage = trim($passage);
    $grounding = $this->settings->isGroundingOn() && $passage !== '';
    $this->warnWithoutModel();
    $this->fetch($passage, $candidates, $grounding, $this->settings->isTimeCheckOn());

    $scores = array_fill_keys(array_keys($candidates), NULL);
    if ($grounding) {
      foreach ($candidates as $key => $candidate) {
        $scores[$key] = $this->groundingCache[$this->groundingHash($passage, (string) $candidate)] ?? NULL;
      }
    }
    return $scores;
  }

  /**
   * Asks the decision model whether each candidate stops being true on a date.
   *
   * The time check (ADR-0057 addendum), see TIME_QUESTION. Scores fetched
   * earlier this request, by checkBatch() or checkGrounding(), are reused.
   *
   * @param string[] $candidates
   *   Candidate fact texts, keyed however the caller likes.
   *
   * @return array
   *   A probability (0 to 1) per candidate, same keys, or NULL for each
   *   candidate when the check is off, has no decision model, or failed.
   */
  public function checkTimeBound(array $candidates): array {
    $scores = array_fill_keys(array_keys($candidates), NULL);
    if (!$this->settings->isTimeCheckOn()) {
      return $scores;
    }
    $this->fetch('', $candidates, FALSE, TRUE);
    foreach ($candidates as $key => $candidate) {
      $scores[$key] = $this->timeCache[$this->timeHash((string) $candidate)] ?? NULL;
    }
    return $scores;
  }

  /**
   * Fetches both checks for a whole list in the fewest calls.
   *
   * For a caller that will write the facts one by one: each remember() then
   * finds its scores already fetched.
   *
   * @param string|null $passage
   *   The text the facts were written from, or NULL for none (the grounded
   *   check is then skipped).
   * @param string[] $candidates
   *   Candidate fact texts.
   */
  public function checkBatch(?string $passage, array $candidates): void {
    $passage = trim((string) $passage);
    $this->fetch($passage, $candidates, $this->settings->isGroundingOn() && $passage !== '', $this->settings->isTimeCheckOn());
  }

  /**
   * Logs a warning when a check is on but no decision model is chosen.
   */
  protected function warnWithoutModel(): void {
    if ($this->settings->decisionModelFor('grounding') !== NULL) {
      return;
    }
    $modes = [
      'grounded check' => $this->settings->getGroundingMode(),
      'time check' => $this->settings->getTimeCheckMode(),
    ];
    foreach ($modes as $check => $mode) {
      if ($mode !== 'off') {
        $this->logger->warning('The @check is @mode but no decision model is chosen for it in the AIM settings; facts are not checked.', [
          '@check' => $check,
          '@mode' => $mode,
        ]);
      }
    }
  }

  /**
   * The cache key of a grounding score.
   *
   * @param string $passage
   *   The passage.
   * @param string $candidate
   *   The candidate text.
   *
   * @return string
   *   The key.
   */
  protected function groundingHash(string $passage, string $candidate): string {
    return hash('sha256', $passage . "\0" . $candidate);
  }

  /**
   * The cache key of a time-check score: the candidate alone.
   *
   * @param string $candidate
   *   The candidate text.
   *
   * @return string
   *   The key.
   */
  protected function timeHash(string $candidate): string {
    return hash('sha256', $candidate);
  }

  /**
   * Runs the checks not already cached, filling the caches.
   *
   * @param string $passage
   *   The trimmed passage, unused when $grounding is FALSE.
   * @param string[] $candidates
   *   Candidate fact texts.
   * @param bool $grounding
   *   Whether to run the grounded check.
   * @param bool $time
   *   Whether to run the time check.
   */
  protected function fetch(string $passage, array $candidates, bool $grounding, bool $time): void {
    if ((!$grounding && !$time) || !$candidates) {
      return;
    }
    $model = $this->settings->decisionModelFor('grounding');
    if ($model === NULL) {
      return;
    }

    // Candidates with a check still to run, keyed by their question ID.
    $asked = [];
    foreach ($candidates as $candidate) {
      $candidate = (string) $candidate;
      $needGrounding = $grounding && !array_key_exists($this->groundingHash($passage, $candidate), $this->groundingCache);
      $needTime = $time && !array_key_exists($this->timeHash($candidate), $this->timeCache);
      if (!$needGrounding && !$needTime) {
        continue;
      }
      $asked['candidate_' . (count($asked) + 1)] = [$candidate, $needGrounding, $needTime];
    }
    if (!$asked) {
      return;
    }

    // Providers cap the questions in one call (subq: 16), so ask in groups
    // sized by questions, not candidates.
    $perCandidate = ($grounding ? 1 : 0) + ($time ? 1 : 0);
    $size = max(1, intdiv($this->settings->getGroundingMaxQuestions(), $perCandidate));
    foreach (array_chunk($asked, $size, TRUE) as $group) {
      $state = $grounding ? ['text' => $passage] : [];
      $questions = [];
      foreach ($group as $id => [$candidate, $needGrounding, $needTime]) {
        $state[$id] = $candidate;
        if ($needGrounding) {
          $questions[$id] = new NoulQuestion(sprintf(self::GROUNDING_QUESTION, $id));
        }
        if ($needTime) {
          $questions[$id . '_time'] = new NoulQuestion(sprintf(self::TIME_QUESTION, $id));
        }
      }
      try {
        $response = $this->decisionBackend->run('grounding', new DecisionInput($state, $questions), $model[0], $model[1]);
        foreach ($group as $id => [$candidate, $needGrounding, $needTime]) {
          if ($needGrounding) {
            $this->groundingCache[$this->groundingHash($passage, $candidate)] = $response->getNoul($id)->getProbability();
          }
          if ($needTime) {
            $this->timeCache[$this->timeHash($candidate)] = $response->getNoul($id . '_time')->getProbability();
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Grounding or time check failed to run for @count candidate(s): @message', [
          '@count' => count($group),
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

}
