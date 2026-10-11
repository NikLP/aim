<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionResponse;
use Drupal\ai\OperationType\Decision\Value\NoulAnswer;
use Drupal\aim\Backend\ActivityBackendInterface;

/**
 * A decision backend that answers each noul question from a score table.
 *
 * Each question's ID is a state key holding a candidate text; the answer is
 * that text's score from $scores (0.5 when absent).
 */
final class FakeDecisionBackend implements ActivityBackendInterface {

  /**
   * Probability per candidate text.
   *
   * @var array<string, float>
   */
  public array $scores = [];

  /**
   * How many times run() was called.
   */
  public int $calls = 0;

  /**
   * Whether run() throws, as an unreachable provider would.
   */
  public bool $fail = FALSE;

  /**
   * {@inheritdoc}
   */
  public function run(string $activity, DecisionInput $input, string $providerId, string $modelId): DecisionResponse {
    $this->calls++;
    if ($this->fail) {
      throw new \RuntimeException('Provider unavailable.');
    }
    $state = $input->getState();
    $answers = [];
    foreach (array_keys($input->getQuestions()) as $id) {
      $answers[$id] = new NoulAnswer($this->scores[$state[$id]] ?? 0.5);
    }
    return new DecisionResponse($answers, $modelId);
  }

}
