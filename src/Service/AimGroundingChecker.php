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
   * Grounding scores fetched this request, keyed by passage and candidate.
   *
   * Lets a caller check a whole batch in one call, after which remember()
   * finds each fact's score here instead of calling again.
   *
   * @var array<string, float|null>
   */
  protected array $groundingCache = [];

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
   * 16 by default): every candidate goes into the state next
   * to the passage with its own question, which measured as fast and as
   * accurate as one call per fact (ADR-0037 addendum). Scores already
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
    $scores = array_fill_keys(array_keys($candidates), NULL);
    $passage = trim($passage);
    if ($this->settings->getGroundingMode() === 'off' || $passage === '' || !$candidates) {
      return $scores;
    }
    $model = $this->settings->decisionModelFor('grounding');
    if ($model === NULL) {
      $this->logger->warning('Grounding is @mode but no decision model is chosen for it in the AIM settings; facts are not checked.', ['@mode' => $this->settings->getGroundingMode()]);
      return $scores;
    }

    $state = ['text' => $passage];
    $questions = [];
    $asked = [];
    foreach ($candidates as $key => $candidate) {
      $hash = hash('sha256', $passage . "\0" . $candidate);
      if (array_key_exists($hash, $this->groundingCache)) {
        $scores[$key] = $this->groundingCache[$hash];
        continue;
      }
      $id = 'candidate_' . (count($asked) + 1);
      $state[$id] = (string) $candidate;
      $questions[$id] = new NoulQuestion(sprintf(self::GROUNDING_QUESTION, $id));
      $asked[$id] = [$key, $hash];
    }
    if (!$asked) {
      return $scores;
    }

    // Providers cap the questions in one call (subq: 16), so ask in groups.
    foreach (array_chunk($asked, $this->settings->getGroundingMaxQuestions(), TRUE) as $group) {
      $groupState = ['text' => $passage];
      $groupQuestions = [];
      foreach (array_keys($group) as $id) {
        $groupState[$id] = $state[$id];
        $groupQuestions[$id] = $questions[$id];
      }
      try {
        $response = $this->decisionBackend->run('grounding', new DecisionInput($groupState, $groupQuestions), $model[0], $model[1]);
        foreach ($group as $id => [$key, $hash]) {
          $scores[$key] = $this->groundingCache[$hash] = $response->getNoul($id)->getProbability();
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Grounding check failed to run for @count candidate(s): @message', [
          '@count' => count($group),
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $scores;
  }

}
