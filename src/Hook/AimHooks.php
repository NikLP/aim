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
   * that creates or updates one - remember(), createFactsFromCandidates(),
   * the entity add/edit forms, default content import, anything future -
   * not just the callers that used to remember to call it directly (see
   * CLAUDE.md's Guardrails section). Throws to abort the save, the same
   * \InvalidArgumentException every caller already catches. A rewriting
   * guardrail (RewriteInputResult) changes what gets stored, so the
   * returned text is written back onto the entity.
   *
   * An entity flagged setSyncing(TRUE) opts out - core's own "being
   * synchronized, skip side effects" flag, used by generateBenchmarkFacts()
   * (synthetic benchmark text needs neither this nor the consolidation
   * enqueue in factInsert() below, see CLAUDE.md's Benchmarking section).
   * Core's migrate destinations (EntityContentBase and friends) set the
   * same flag on every entity they save, so a fact written by a migration
   * also skips guardrails here AND the consolidation enqueue below - a
   * migrated corpus is stored exactly as given and never consolidated
   * unless drush aim:consolidate is run over it afterward. Deliberate for a
   * bulk sync, but not a free choice; see CLAUDE.md's Benchmarking section.
   */
  #[Hook('aim_fact_presave')]
  public function factPresave(AimFact $entity): void {
    if ($entity->isSyncing()) {
      return;
    }
    $text = $entity->get('text')->value ?? '';
    $checked = $this->memoryManager->runGuardrails($text);
    if ($checked !== $text) {
      $entity->set('text', $checked);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for aim_fact.
   *
   * Enqueues every newly created fact for consolidation (decision 4),
   * universal for every save path - see factPresave() above for why, and
   * for the isSyncing() opt-out.
   */
  #[Hook('aim_fact_insert')]
  public function factInsert(AimFact $entity): void {
    if ($entity->isSyncing()) {
      return;
    }
    $this->memoryManager->enqueueForConsolidation((int) $entity->id());
  }

}
