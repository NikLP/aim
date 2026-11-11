<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\aim\Service\AimSettings;
use Drupal\aim\Vdb\AimMariaDBProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reports on the database-side state the vector search feature depends on.
 *
 * `config:status` only diffs config, not runtime DB state - a fact can fall
 * out of the vector index, the provider shim can stop being swapped in, an
 * existing collection table keeps whatever HNSW M it was built with
 * regardless of what the code now ships. This checks those directly
 * instead of walking them by hand, replacing step 6 of DEVELOPING.md's
 * "Upgrading an existing site". Shared between `aim:status` (Drush) and
 * /admin/config/aim/status (the controller) so the checks live in one
 * place.
 */
final class AimStatusChecker {

  /**
   * Constructs an AimStatusChecker object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The default database connection, used to query aim_fact_vectors
   *   directly - it's a plain table the ai_vdb_provider_mariadb backend
   *   owns, not a Drupal entity, so there's no query API for it.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read the live HNSW and recall cutoff
   *   settings.
   * @param \Drupal\ai\AiVdbProviderPluginManager $vdbProviders
   *   The VDB provider plugin manager, used to confirm AimMariaDBProvider
   *   is still swapped in.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to query aim_fact - a real content
   *   entity - via the entity query API rather than raw SQL.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, used to tell an overdue expiry from a fresh one.
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager, used to confirm the configured models
   *   still exist with their providers.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory, used to count the consolidation queue on whatever
   *   backend the site runs it on.
   */
  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'ai.vdb_provider')]
    protected AiVdbProviderPluginManager $vdbProviders,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    #[Autowire(service: 'ai.provider')]
    protected AiProviderPluginManager $aiProvider,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * Runs every check.
   *
   * @return array[]
   *   A list of ['label' => string, 'ok' => bool, 'detail' => string],
   *   keyed by a stable machine name (same shape hook_requirements() keys
   *   its own entries by), so a caller building a requirements-style
   *   render array (AimStatusController) has a key to key by.
   */
  public function checkAll(): array {
    return [
      'index_parity' => $this->checkIndexParity(),
      'orphan_vector_rows' => $this->checkOrphanVectorRows(),
      'provider_shim' => $this->checkProviderShim(),
      'hnsw_tuning' => $this->checkHnswTuning(),
      'recall_cutoff' => $this->checkRecallCutoff(),
      'expiry_sweep' => $this->checkExpirySweep(),
      'consolidation_queue' => $this->checkConsolidationQueue(),
      'untrusted_facts' => $this->checkUntrustedFacts(),
      'models' => $this->checkModels(),
      'index_directly' => $this->checkIndexDirectly(),
      'model_call_logging' => AimModelCallLoggingCheck::check($this->configFactory->get('ai_logging.settings')),
    ];
  }

  /**
   * Checks that no live fact is long past its expires date (ADR-0057).
   *
   * The sweep runs from the crontab and from hook_cron, so a fact two hours
   * past its date means neither is running.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkExpirySweep(): array {
    $overdue = (int) $this->entityTypeManager->getStorage('aim_fact')->getQuery()
      ->accessCheck(FALSE)
      ->notExists('retired')
      ->condition('expires', $this->time->getRequestTime() - 7200, '<')
      ->count()
      ->execute();

    return [
      'label' => 'Expiry sweep running',
      'ok' => $overdue === 0,
      'detail' => $overdue === 0
        ? 'no live fact is overdue'
        : "$overdue live fact(s) expired over 2 hours ago - run drush aim:expire, and add it to the crontab (or check Drupal cron runs)",
    ];
  }

  /**
   * Checks that the consolidation queue is being drained.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkConsolidationQueue(): array {
    $queue = $this->queueFactory->get('aim_consolidate');
    $items = (int) $queue->numberOfItems();

    if ($items === 0) {
      return [
        'label' => 'Consolidation queue draining',
        'ok' => TRUE,
        'detail' => 'queue is empty',
      ];
    }

    // The Queue API has no item age, so it is only measured on the database
    // queue, where the item table can be read. Any other backend reports the
    // count alone and cannot tell a stuck queue from a busy one.
    if (!$queue instanceof DatabaseQueue) {
      return [
        'label' => 'Consolidation queue draining',
        'ok' => TRUE,
        'detail' => "$items item(s) waiting, age not measurable on this queue backend - check that `drush queue:run aim_consolidate` is in the crontab (ADR-0003)",
      ];
    }

    $query = $this->database->select('queue', 'q');
    $query->condition('q.name', 'aim_consolidate');
    $query->addExpression('MIN(q.created)', 'oldest');
    $age = $this->time->getRequestTime() - (int) $query->execute()->fetchField();

    return [
      'label' => 'Consolidation queue draining',
      'ok' => $age < 7200,
      'detail' => $age < 7200
        ? "$items item(s) waiting, oldest " . (int) ($age / 60) . ' minute(s) old'
        : "$items item(s) waiting, the oldest " . (int) ($age / 3600) . ' hour(s) old - add `drush queue:run aim_consolidate` to the crontab (ADR-0003)',
    ];
  }

  /**
   * Reports how many live facts are untrusted and so held out of recall.
   *
   * Informational: always ok. Facts by writers without a trust permission
   * and facts the grounded check demoted land here (ADR-0065).
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkUntrustedFacts(): array {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $untrusted = (int) $storage->getQuery()->accessCheck(FALSE)->notExists('retired')->condition('trusted', 0)->count()->execute();
    $scored = (int) $storage->getQuery()->accessCheck(FALSE)->notExists('retired')->condition('trusted', 0)->exists('grounding_score')->count()->execute();

    return [
      'label' => 'Untrusted facts awaiting review',
      'ok' => TRUE,
      'detail' => $untrusted === 0
        ? 'none'
        : "$untrusted untrusted ($scored with a grounding score for the reviewer)",
    ];
  }

  /**
   * Checks that every live fact has exactly one row in the vector index.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkIndexParity(): array {
    $live = (int) $this->entityTypeManager->getStorage('aim_fact')->getQuery()
      ->accessCheck(FALSE)
      ->notExists('retired')
      ->exists('text')
      ->count()
      ->execute();
    $vectorRows = (int) $this->database->select('aim_fact_vectors', 'v')
      ->countQuery()
      ->execute()
      ->fetchField();

    $ok = $live === $vectorRows;

    if ($ok) {
      $detail = "$live live fact(s), $vectorRows vector row(s)";
    }
    else {
      $detail = "$live live fact(s) but $vectorRows vector row(s) - reindex: drush search-api:index aim_vector_index, or the \"Index now\" button at /admin/config/search/search-api/index/aim_vector_index";
    }

    return [
      'label' => 'Index parity',
      'ok' => $ok,
      'detail' => $detail,
    ];
  }

  /**
   * Checks for vector rows that no longer point at a real aim_fact.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkOrphanVectorRows(): array {
    // Built in PHP and matched with a NOT IN rather than joined with a
    // driver-specific CONCAT() - MariaDB, PostgreSQL and SQLite each spell
    // string concatenation differently, and building the comparison value
    // here avoids the divergence entirely. (aim's vector storage is
    // MariaDB-only per ADR-0001, so this isn't reachable on another
    // driver today, but nothing about the query itself should assume
    // that.)
    // The item ID ends in the fact's own langcode (the site's single
    // operating language, not necessarily "en"), so read it with the ID.
    $entityType = $this->entityTypeManager->getDefinition('aim_fact');
    $idKey = $entityType->getKey('id');
    $langKey = $entityType->getKey('langcode');
    $facts = $this->database->select($entityType->getBaseTable(), 'f')
      ->fields('f', [$idKey, $langKey])
      ->execute()
      ->fetchAll(\PDO::FETCH_NUM);

    $query = $this->database->select('aim_fact_vectors', 'v');
    if ($facts) {
      $entityIds = array_map(static fn (array $row): string => "entity:aim_fact/$row[0]:$row[1]", $facts);
      $query->condition('v.drupal_entity_id', $entityIds, 'NOT IN');
    }

    $orphans = (int) $query->countQuery()
      ->execute()
      ->fetchField();

    return [
      'label' => 'Orphan vector rows',
      'ok' => $orphans === 0,
      'detail' => $orphans === 0 ? 'none' : "$orphans orphan row(s) - search_api self-heals these on the next query that returns one, or reindex now: drush search-api:index aim_vector_index",
    ];
  }

  /**
   * Checks that the mariadb VDB provider plugin still resolves to the shim.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkProviderShim(): array {
    $class = get_class($this->vdbProviders->createInstance('mariadb'));
    $ok = $class === AimMariaDBProvider::class;

    return [
      'label' => 'Provider shim',
      'ok' => $ok,
      'detail' => $ok ? $class : "expected " . AimMariaDBProvider::class . ", got $class - check AimHooks::vdbProviderInfoAlter() ran (drush cr)",
    ];
  }

  /**
   * Checks the collection table's build-time M and the server's ef_search.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkHnswTuning(): array {
    // MariaDB drops the VECTOR KEY's M=/DISTANCE= suffix from SHOW CREATE
    // TABLE under this connection's own sql_mode ('ANSI,TRADITIONAL', set
    // by Drupal's mysql driver) - present under ANSI or TRADITIONAL alone,
    // only their combination hides it (checked against 2026-09-27 live
    // data). ANSI_QUOTES alone keeps it and still double-quotes
    // identifiers, matching what {aim_fact_vectors} substitutes to here, so
    // swap to that for this one query and restore afterward. The M
    // attribute's case also varies (`M` in DDL written explicitly, `m` when
    // MariaDB applies createCollection()'s SET SESSION mhnsw_default_m and
    // re-serializes it) - the match below is case-insensitive.
    $originalMode = (string) $this->database->query('SELECT @@SESSION.sql_mode')->fetchField();
    $this->database->query('SET SESSION sql_mode = :mode', [':mode' => 'ANSI_QUOTES']);

    try {
      $createTable = (string) $this->database->query('SHOW CREATE TABLE {aim_fact_vectors}')->fetchField(1);
    }
    finally {
      $this->database->query('SET SESSION sql_mode = :mode', [':mode' => $originalMode]);
    }

    $m = preg_match('/VECTOR KEY[^\n]*["`]M["`]=\'?(\d+)/i', $createTable, $matches) ? (int) $matches[1] : NULL;
    $efSearch = $this->configFactory->get('search_api.server.aim_vector')->get('backend_config.database_settings.mhnsw_ef_search');

    $problems = [];
    if ($m === NULL) {
      $problems[] = "M not found in aim_fact_vectors' VECTOR KEY";
    }
    elseif ($m !== AimMariaDBProvider::HNSW_M) {
      $problems[] = "M=$m, expected " . AimMariaDBProvider::HNSW_M . ' - rebuild the index (DEVELOPING.md "Tuning vector search accuracy")';
    }

    if ($efSearch === NULL) {
      $problems[] = 'backend_config.database_settings.mhnsw_ef_search is unset on search_api.server.aim_vector, MariaDB defaults to 20';
    }

    return [
      'label' => 'HNSW tuning',
      'ok' => empty($problems),
      'detail' => empty($problems) ? "M=$m, ef_search=$efSearch" : implode('; ', $problems),
    ];
  }

  /**
   * Checks that recall_max_distance is a real per-site value, not a fallback.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkRecallCutoff(): array {
    $value = $this->configFactory->get('aim.settings')->get('recall_max_distance');
    $ok = $value !== NULL;

    if ($ok) {
      $detail = (string) $value;
    }
    else {
      $detail = 'not set, silently using the in-code default ' . AimSettings::DEFAULT_RECALL_MAX_DISTANCE . ' - recalibrate for this site\'s dataset (ADR-0019)';
    }

    return [
      'label' => 'Recall cutoff configured',
      'ok' => $ok,
      'detail' => $detail,
    ];
  }

  /**
   * Warns when index_directly is on with a hosted embeddings provider.
   *
   * With index_directly on, a fact's text is sent to the embeddings provider
   * during the request that saves it, so a hosted provider adds its latency
   * and failures to every write and receives each fact as it is written
   * (ADR-0015 addendum: this site runs it with local Ollama).
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkIndexDirectly(): array {
    $direct = (bool) $this->configFactory->get('search_api.index.aim_vector_index')->get('options.index_directly');
    $engine = (string) $this->configFactory->get('search_api.server.aim_vector')->get('backend_config.embeddings_engine');
    $provider = explode('__', $engine)[0];

    if (!$direct) {
      $detail = 'off, facts are embedded by the indexing run';
      $ok = TRUE;
    }
    elseif ($provider === 'ollama') {
      $detail = 'on with local embeddings (' . $engine . ')';
      $ok = TRUE;
    }
    else {
      $detail = 'on with a hosted embeddings provider (' . ($engine ?: 'none set') . '): each write waits on it and sends the fact text to it - turn it off at /admin/config/search/search-api/index/aim_vector_index/edit, or use a local provider';
      $ok = FALSE;
    }

    return [
      'label' => 'Index directly',
      'ok' => $ok,
      'detail' => $detail,
    ];
  }

  /**
   * Checks that every model aim and the finder rely on still exists.
   *
   * Covers the AI module's default for chat, decision and embeddings (the
   * literals finder reads the decision default) and each aim activity that
   * names its own provider and model. A provider that retires a model leaves
   * the setting pointing at nothing; the first sign is otherwise a failed
   * call.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkModels(): array {
    $targets = [];
    foreach (['chat', 'decision', 'embeddings'] as $operation) {
      $default = $this->aiProvider->getDefaultProviderForOperationType($operation) ?? [];
      $targets["default $operation model"] = [
        $operation,
        (string) ($default['provider_id'] ?? ''),
        (string) ($default['model_id'] ?? ''),
        'set it at /admin/config/ai/settings',
      ];
    }
    $activities = $this->configFactory->get('aim.settings')->get('activities') ?? [];
    foreach ($activities as $name => $activity) {
      if (!empty($activity['provider']) && !empty($activity['model'])) {
        $operation = ($activity['backend'] ?? 'chat') === 'decision' ? 'decision' : 'chat';
        $targets["$name activity model"] = [
          $operation,
          (string) $activity['provider'],
          (string) $activity['model'],
          'set it at /admin/config/aim/settings, Models tab',
        ];
      }
    }

    $problems = [];
    $problems = [];
    // The vector server's own two model IDs are per-site (DEVELOPING.md,
    // "Setting up vector search"): the shipped values name this site's
    // providers, so a fresh site has to repoint them. chat_model is checked
    // the way the backend's own status page does (against the option list);
    // embeddings_engine only for being set with a usable provider, since the
    // stored model name is the provider's own (Ollama's has a ":latest" the
    // option key lacks) and indexing works with it.
    $server = $this->configFactory->get('search_api.server.aim_vector')->get('backend_config') ?? [];
    $chatModel = (string) ($server['chat_model'] ?? '');
    if (!isset($this->aiProvider->getSimpleProviderModelOptions('chat')[$chatModel])) {
      $problems[] = $chatModel === ''
        ? 'The vector server has no chat model set (Search API > server aim_vector).'
        : "The vector server's chat model $chatModel is not an available chat model (Search API > server aim_vector).";
    }
    [$engineProvider, $engineModel] = array_pad(explode('__', (string) ($server['embeddings_engine'] ?? ''), 2), 2, '');
    try {
      $engineOk = $engineProvider !== '' && $engineModel !== '' && $this->aiProvider->createInstance($engineProvider)->isUsable('embeddings');
    }
    catch (\Throwable) {
      $engineOk = FALSE;
    }
    if (!$engineOk) {
      $problems[] = "The vector server's embeddings engine is not set, or its provider is not usable (Search API > server aim_vector).";
    }

    foreach ($targets as $label => [$operation, $providerId, $modelId, $fix]) {
      if ($providerId === '' || $modelId === '') {
        $problems[] = "The $label is not set ($fix).";
        continue;
      }
      try {
        $models = $this->aiProvider->createInstance($providerId)->getConfiguredModels($operation);
      }
      catch (\Throwable) {
        $problems[] = "The $label uses provider $providerId, which is not usable (check its settings and that it is reachable).";
        continue;
      }
      if (!isset($models[$modelId])) {
        $available = array_slice(array_keys($models), 0, 6);
        $problems[] = "The $label is $modelId, but $providerId offers no such model"
          . ($available ? ' (it offers: ' . implode(', ', $available) . (count($models) > 6 ? ', ...' : '') . ')' : ' (it offers none for this operation)')
          . "; $fix.";
      }
    }

    return [
      'label' => 'Configured models exist',
      'ok' => !$problems,
      'detail' => $problems
        ? implode(' ', $problems)
        : (count($targets) + 2) . ' model setting(s) checked, including the vector server',
    ];
  }

}
