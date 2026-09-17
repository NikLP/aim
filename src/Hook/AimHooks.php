<?php

declare(strict_types=1);

namespace Drupal\aim\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;

/**
 * Hook implementations for the aim module.
 *
 * A factPresave() implementation used to run aim's write guardrails
 * (decision 7) here - removed 2026-09-18 in favor of a real field-level
 * validation Constraint (AimGuardrails, on aim_fact's text field) plus
 * AimMemoryManager::saveFact() explicitly calling validate() for a
 * programmatic writer. See AimGuardrailsConstraint's own docblock for
 * why: a presave exception surfaced as an uncaught EntityStorageException
 * (a 500) on the entity add/edit form, since ContentEntityForm::save()
 * does not catch it - a Constraint gets rendered as a normal field error
 * by ContentEntityForm::validateForm() instead, which already calls
 * $entity->validate() before every submit.
 */
class AimHooks {

  public function __construct(
    private readonly AimMemoryManager $memoryManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for aim_fact.
   *
   * Enqueues every newly created fact for consolidation (decision 4),
   * universal for every save path. An entity flagged setSyncing(TRUE)
   * opts out - core's own "being synchronized, skip side effects" flag,
   * used by generateBenchmarkFacts() (synthetic benchmark text needs no
   * consolidation, see CLAUDE.md's Benchmarking section) and set by core's
   * migrate destinations on every entity they save, so a migrated fact
   * also skips this - deliberate for a bulk sync, but not a free choice;
   * see CLAUDE.md's Benchmarking section.
   */
  #[Hook('aim_fact_insert')]
  public function factInsert(AimFact $entity): void {
    if ($entity->isSyncing()) {
      return;
    }
    $this->memoryManager->enqueueForConsolidation((int) $entity->id());
  }

}
