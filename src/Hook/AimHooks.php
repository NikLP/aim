<?php

declare(strict_types=1);

namespace Drupal\aim\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Session\AccountInterface;
use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Vdb\AimMariaDBProvider;
use Drupal\search_api\IndexInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for the aim module.
 *
 * The factPresave() hook used to run aim's write guardrails (decision 7)
 * too - removed 2026-09-18 in favor of a real field-level
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
   * @param \Drupal\aim\AimScopeTypePluginManagerInterface $scopeTypeManager
   *   The scope type plugin manager, used by entityBaseFieldInfo() to
   *   merge every registered scope type's own base fields onto aim_fact,
   *   by factPresave() to resolve a fact's subject and by
   *   entityFormDisplayAlter() for the subject fields a form shows.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, the author of a new revision.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, for a new revision's creation time.
   */
  public function __construct(
    private readonly AimMemoryManager $memoryManager,
    #[Autowire(service: 'ai.vdb_provider')]
    private readonly AiVdbProviderPluginManager $vdbProviders,
    #[Autowire(service: 'plugin.manager.aim_scope_type')]
    private readonly AimScopeTypePluginManagerInterface $scopeTypeManager,
    #[Autowire(service: 'current_user')]
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'datetime.time')]
    private readonly TimeInterface $time,
  ) {}

  /**
   * Implements hook_entity_base_field_info().
   *
   * Merges every AimScopeType plugin's own base fields onto aim_fact
   * (ADR-0028 piece 1), e.g. the entity type's target_type/target_id. Per
   * plugin in code, not per scope, so the columns exist whether or not a
   * scope of that type is enabled (ADR-0063 decision 5).
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type to return base field definitions for.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition[]
   *   Field definitions keyed by field name, or an empty array for any
   *   entity type other than aim_fact.
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'aim_fact') {
      return [];
    }

    $fields = [];
    foreach (array_keys($this->scopeTypeManager->getDefinitions()) as $pluginId) {
      $plugin = $this->scopeTypeManager->createInstance($pluginId);
      $fields += $plugin->getBaseFieldDefinitions();
    }
    return $fields;
  }

  /**
   * Implements hook_entity_form_display_alter().
   *
   * Every subject field (subject, user, each plugin's own) is on every
   * fact, since scopes are bundles without fields of their own
   * (DEVELOPING.md). This takes them all off a fact form, then lets the
   * scope's plugin put back the ones it uses (alterFormDisplay()), so a
   * scope with no plugin (site) shows none. At form build time, so it
   * covers every scope, a new one included, with no stored display.
   *
   * @param \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display
   *   The form display about to build a fact form.
   * @param array $context
   *   The entity type, bundle and form mode.
   */
  #[Hook('entity_form_display_alter')]
  public function entityFormDisplayAlter(EntityFormDisplayInterface $form_display, array $context): void {
    if ($context['entity_type'] !== 'aim_fact') {
      return;
    }
    $removed = [];
    foreach ($this->scopeTypeManager->getSubjectFieldNames() as $name) {
      $component = $form_display->getComponent($name);
      if ($component !== NULL) {
        $removed[$name] = $component;
        $form_display->removeComponent($name);
      }
    }
    $this->scopeTypeManager->getTypePlugin($context['bundle'])?->alterFormDisplay($form_display, $removed);
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for aim_fact.
   *
   * Lets the scope's plugin resolve a new fact's subject (or an edited
   * one), so every write path - remember(), the add form, ingest - gets
   * the same result, e.g. the case plugin's minted or named case ID.
   * Only changes the value, never throws (see this class's docblock).
   * Also decides whether an edit is a new revision (applyRevisionPolicy()).
   */
  #[Hook('aim_fact_presave')]
  public function factPresave(AimFact $entity): void {
    $this->applyRevisionPolicy($entity);
    $subject = (string) $entity->get('subject')->value;
    if (!$entity->isNew() && $subject === (string) $entity->getOriginal()?->get('subject')->value) {
      return;
    }
    $plugin = $this->scopeTypeManager->getTypePlugin($entity->bundle());
    if ($plugin) {
      $entity->set('subject', $plugin->resolveSubject($subject, $entity->bundle()));
    }
  }

  /**
   * Saves a new revision when, and only when, a fact's text changes.
   *
   * ADR-0064 decision 3: trust, retirement, expiry, the grounded check and
   * consolidation save in place and keep the current revision's metadata,
   * so the history holds edits only. A new revision clears a grounding
   * score measured against the old text (decision 4) unless the same save
   * sets a new one. A new fact's first revision is its writer's. Core's
   * revert form saves an older revision, which is left to core: it is
   * always a new revision and keeps that revision's own score.
   *
   * @param \Drupal\aim\Entity\AimFact $entity
   *   The fact being saved.
   */
  private function applyRevisionPolicy(AimFact $entity): void {
    if ($entity->isNew()) {
      if (!$entity->getRevisionUserId()) {
        $entity->setRevisionUserId($entity->getOwnerId());
      }
      return;
    }
    /** @var \Drupal\aim\Entity\AimFact|null $original */
    $original = $entity->getOriginal();
    if (!$original || (int) $entity->getLoadedRevisionId() !== (int) $original->getRevisionId()) {
      return;
    }
    $metadata = ['revision_user', 'revision_created', 'revision_log_message'];
    if ((string) $entity->get('text')->value === (string) $original->get('text')->value) {
      $entity->setNewRevision(FALSE);
      foreach ($metadata as $field) {
        $entity->set($field, $original->get($field)->getValue());
      }
      return;
    }

    $entity->setNewRevision();
    if ($entity->get('grounding_score')->equals($original->get('grounding_score'))) {
      $entity->set('grounding_score', NULL);
    }
    // Metadata the caller left as it was belongs to the old revision.
    if ((int) $entity->getRevisionUserId() === (int) $original->getRevisionUserId()) {
      $entity->setRevisionUserId($this->currentUser->id());
    }
    if ((int) $entity->getRevisionCreationTime() === (int) $original->getRevisionCreationTime()) {
      $entity->setRevisionCreationTime($this->time->getRequestTime());
    }
    if ($entity->getRevisionLogMessage() === $original->getRevisionLogMessage()) {
      $entity->setRevisionLogMessage(NULL);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for aim_fact.
   *
   * Enqueues every newly created trusted fact for consolidation (decision
   * 4), universal for every save path; an untrusted one waits until it is
   * trusted (factUpdate(), ADR-0065). An entity flagged setSyncing(TRUE)
   * opts out - core's own "being synchronized, skip side effects" flag,
   * used by aim_benchmark's AimBenchmarkGenerator (synthetic benchmark
   * text needs no consolidation, see
   * modules/aim_benchmark/CLAUDE.md) and set by core's migrate
   * destinations on every entity they save, so a migrated fact also skips
   * this - deliberate for a bulk sync, but not a free choice.
   */
  #[Hook('aim_fact_insert')]
  public function factInsert(AimFact $entity): void {
    if ($entity->isSyncing() || !$entity->get('trusted')->value) {
      return;
    }
    $this->memoryManager->enqueueForConsolidation((int) $entity->id());
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for aim_fact.
   *
   * Enqueues a fact for consolidation when it becomes trusted (the Trust
   * action or the edit form), since untrusted facts are not consolidated
   * (ADR-0065). Same syncing opt-out as factInsert().
   */
  #[Hook('aim_fact_update')]
  public function factUpdate(AimFact $entity): void {
    $original = $entity->getOriginal();
    if ($entity->isSyncing() || !$entity->get('trusted')->value || !$entity->get('retired')->isEmpty() || !$original || $original->get('trusted')->value) {
      return;
    }
    $this->memoryManager->enqueueForConsolidation((int) $entity->id());
  }

  /**
   * Implements hook_cron().
   *
   * Backup for the `drush aim:expire` crontab entry (ADR-0057): retires
   * facts whose expires date has passed. A cheap database query with no
   * model call, so it does not break ADR-0003's rule against putting
   * LLM-bound queue work in general cron; retireExpired() is idempotent,
   * so running from both is harmless.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->memoryManager->retireExpired();
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
   * `user`: ADR-0018 (renamed from subject_uid 2026-09-28,
   * aim_update_10001()). `trusted`: ADR-0002's addendum, the same
   * treatment - recall() filters on it by default (a selective condition
   * once most facts are untrusted), so it needs the same pre-filtering fix
   * user needed rather than reproducing ADR-0018's finding 3.
   */
  protected const BTREE_INDEXED_COLUMNS = ['user', 'trusted'];

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
