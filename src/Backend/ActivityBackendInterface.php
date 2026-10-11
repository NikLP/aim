<?php

declare(strict_types=1);

namespace Drupal\aim\Backend;

use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionResponse;

/**
 * Runs one decision-shaped aim activity on some kind of model.
 *
 * The input and the answer are drupal/ai's own Decision types: typed
 * questions over a state in, typed answers plus token usage out
 * (DecisionResponse::getUsage()). Implementations differ only in how the
 * model is reached.
 */
interface ActivityBackendInterface {

  /**
   * Maps an activity to the tag its provider call carries.
   *
   * The metrics subscriber keys on the aim_ prefix.
   */
  public const TAGS = [
    'extraction' => 'aim_extract',
    'consolidation' => 'aim_consolidate',
    'verifier' => 'aim_consolidate_verify',
    'grounding' => 'aim_ground',
  ];

  /**
   * Answers the input's questions.
   *
   * @param string $activity
   *   One of the TAGS keys.
   * @param \Drupal\ai\OperationType\Decision\DecisionInput $input
   *   The state and questions.
   * @param string $providerId
   *   The AI provider plugin ID.
   * @param string $modelId
   *   The model ID.
   *
   * @return \Drupal\ai\OperationType\Decision\DecisionResponse
   *   One answer per question ID, with model and token usage.
   *
   * @throws \RuntimeException
   *   If the provider cannot serve this backend or the reply is malformed.
   */
  public function run(string $activity, DecisionInput $input, string $providerId, string $modelId): DecisionResponse;

}
