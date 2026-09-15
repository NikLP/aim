<?php

declare(strict_types=1);

namespace Drupal\aim\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;

/**
 * Hook implementations for the aim module.
 */
class AimHooks {

  public function __construct(
    private readonly AimMemoryManager $memoryManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_presave() for aim_fact.
   *
   * Runs runGuardrails() (decision 7) for every fact save, on every path
   * that creates or updates one - not just the callers that used to
   * remember to call it directly (remember(), createFactsFromCandidates(),
   * aim_eca's FactWrite; see CLAUDE.md's Guardrails section). Throws to
   * abort the save, the same \InvalidArgumentException every caller
   * already catches.
   *
   * generateBenchmarkFacts() sets the aim_skip_hooks flag to opt out -
   * synthetic benchmark text needs neither this nor the consolidation
   * enqueue in factInsert() below, see CLAUDE.md's Benchmarking section.
   */
  #[Hook('aim_fact_presave')]
  public function factPresave(AimFact $entity): void {
    if (!empty($entity->aim_skip_hooks)) {
      return;
    }
    $this->memoryManager->runGuardrails($entity->get('text')->value ?? '');
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for aim_fact.
   *
   * Enqueues every newly created fact for consolidation (decision 4),
   * universal for every save path - see factPresave() above for why.
   */
  #[Hook('aim_fact_insert')]
  public function factInsert(AimFact $entity): void {
    if (!empty($entity->aim_skip_hooks)) {
      return;
    }
    $this->memoryManager->enqueueForConsolidation((int) $entity->id());
  }

}
