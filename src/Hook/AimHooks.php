<?php

declare(strict_types=1);

namespace Drupal\aim\Hook;

use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Vdb\AimMariaDBProvider;
use Drupal\search_api\IndexInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   * @param \Drupal\ai\AiVdbProviderPluginManager $vdbProviders
   *   The VDB provider plugin manager.
   */
  public function __construct(
    private readonly AimMemoryManager $memoryManager,
    #[Autowire(service: 'ai.vdb_provider')]
    private readonly AiVdbProviderPluginManager $vdbProviders,
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

  /**
   * Implements hook_ai_vdb_provider_info_alter().
   *
   * Swaps in AimMariaDBProvider, which works around two
   * ai_vdb_provider_mariadb bugs (see its docblock).
   *
   * @param array $definitions
   *   The VDB provider plugin definitions, keyed by plugin ID.
   */
  #[Hook('ai_vdb_provider_info_alter')]
  public function vdbProviderInfoAlter(array &$definitions): void {
    if (isset($definitions['mariadb'])) {
      $definitions['mariadb']['class'] = AimMariaDBProvider::class;
    }
  }

  /**
   * Columns that need a BTREE index once they exist on the collection table.
   *
   * `subject_uid`: ADR-0018. `trusted`: ADR-0002's addendum, the same
   * treatment - recall() filters on it by default (a selective condition
   * once most facts are untrusted), so it needs the same pre-filtering fix
   * subject_uid needed rather than reproducing ADR-0018's finding 3.
   */
  protected const BTREE_INDEXED_COLUMNS = ['subject_uid', 'trusted'];

  /**
   * Implements hook_search_api_index_update().
   *
   * Gives aim_vector_index's indexed attribute columns listed in
   * BTREE_INDEXED_COLUMNS a BTREE index. Runs last so the provider's own
   * hook has already created the columns.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index that was saved.
   */
  #[Hook('search_api_index_update', order: Order::Last)]
  public function vectorIndexUpdate(IndexInterface $index): void {
    if ($index->id() !== 'aim_vector_index') {
      return;
    }

    $settings = $index->getServerInstance()->getBackendConfig()['database_settings'] ?? [];

    if (empty($settings['collection'])) {
      return;
    }

    $provider = $this->vdbProviders->createInstance('mariadb');
    if (!$provider instanceof AimMariaDBProvider) {
      return;
    }

    foreach (self::BTREE_INDEXED_COLUMNS as $column) {
      if ($index->getField($column)) {
        $provider->ensureColumnIndex($settings['collection'], $column, $settings['database_name'] ?? NULL);
      }
    }
  }

  /**
   * Implements hook_config_schema_info_alter().
   *
   * Declares the `mhnsw_ef_search` server setting aim ships
   * (search_api.server.aim_vector), so config validation accepts it.
   * ai_vdb_provider_mariadb 1.0.1 has no such key in its schema, its 1.0.x
   * head does, under the same name.
   *
   * @param array $definitions
   *   The config schema definitions, keyed by type.
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    $type = 'plugin.plugin_configuration.search_api_backend.search_api_ai_search';
    if (isset($definitions[$type]['mapping']['database_settings']['mapping'])) {
      $definitions[$type]['mapping']['database_settings']['mapping'] += [
        'mhnsw_ef_search' => [
          'type' => 'integer',
          'label' => 'HNSW query candidates (ef_search)',
        ],
      ];
    }
  }

}
