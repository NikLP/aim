<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionResponse;
use Drupal\ai\OperationType\Decision\Value\ChoiceAnswer;
use Drupal\ai\OperationType\Decision\Value\NoulAnswer;
use Drupal\aim\Backend\ActivityBackendInterface;

/**
 * A decision backend that answers from scripted values.
 *
 * A question whose ID is in $answers gets that answer: a string is the
 * choice, a float the noul probability. Any other question is a noul whose
 * ID is a state key holding a candidate text; the answer is that text's
 * score from $scores (0.5 when absent).
 */
final class FakeDecisionBackend implements ActivityBackendInterface {

  /**
   * Probability per candidate text.
   *
   * @var array<string, float>
   */
  public array $scores = [];

  /**
   * Answers per question ID: a choice key, or a noul probability.
   *
   * @var array<string, string|float>
   */
  public array $answers = [];

  /**
   * How many times run() was called.
   */
  public int $calls = 0;

  /**
   * Each call's activity and state, in order.
   *
   * @var array<int, array{0: string, 1: array}>
   */
  public array $log = [];

  /**
   * Whether run() throws, as an unreachable provider would.
   */
  public bool $fail = FALSE;

  /**
   * {@inheritdoc}
   */
  public function run(string $activity, DecisionInput $input, string $providerId, string $modelId): DecisionResponse {
    $this->calls++;
    $state = $input->getState();
    $this->log[] = [$activity, $state];
    if ($this->fail) {
      throw new \RuntimeException('Provider unavailable.');
    }
    $answers = [];
    foreach (array_keys($input->getQuestions()) as $id) {
      $answer = $this->answers[$id] ?? NULL;
      $answers[$id] = match (TRUE) {
        is_string($answer) => new ChoiceAnswer($answer, [$answer => 1.0], 1.0),
        is_float($answer) => new NoulAnswer($answer),
        default => new NoulAnswer($this->scores[$state[$id] ?? ''] ?? 0.5),
      };
    }
    return new DecisionResponse($answers, $modelId);
  }

}
