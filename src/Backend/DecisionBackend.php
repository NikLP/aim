<?php

declare(strict_types=1);

namespace Drupal\aim\Backend;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Decision\DecisionInput;
use Drupal\ai\OperationType\Decision\DecisionInterface;
use Drupal\ai\OperationType\Decision\DecisionResponse;

/**
 * Calls decision() on a provider that implements the Decision operation type.
 */
class DecisionBackend implements ActivityBackendInterface {

  /**
   * Constructs a DecisionBackend.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function run(string $activity, DecisionInput $input, string $providerId, string $modelId): DecisionResponse {
    $provider = $this->aiProvider->createInstance($providerId);
    if (!$provider instanceof DecisionInterface) {
      throw new \RuntimeException("Provider $providerId does not implement the Decision operation type.");
    }
    return $provider->decision($input, $modelId, [self::TAGS[$activity]])->getNormalized();
  }

}
