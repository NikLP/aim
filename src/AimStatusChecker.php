<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\QueueFactory;
use Drupal\ai\AiProviderPluginManager;
use Drupal\aim\Service\AimSettings;
use Drupal\aim\Service\AimVectorStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reports on the database-side state the vector search feature depends on.
 *
 * `config:status` only diffs config, not runtime DB state - a fact can fall
 * out of the vector index, an existing collection table keeps whatever
 * HNSW M it was built with
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
   *   The default database connection, for the fact IDs behind the orphan
   *   check and, on MariaDB only, the collection table's HNSW M.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to read the live HNSW and recall cutoff
   *   settings.
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
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The aim settings reader, for the required keys the enabled checks
   *   read.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, to tell whether literals_finder needs the default
   *   decision model.
   * @param \Drupal\aim\Service\AimVectorStore $vectorStore
   *   The vector collection, read through its provider for the parity and
   *   orphan checks.
   */
  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    #[Autowire(service: 'ai.provider')]
    protected AiProviderPluginManager $aiProvider,
    protected QueueFactory $queueFactory,
    #[Autowire(service: 'aim.settings_reader')]
    protected AimSettings $settings,
    protected ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'aim.vector_store')]
    protected AimVectorStore $vectorStore,
  ) {}

  /**
   * The collection's item IDs for this run, FALSE until listed.
   *
   * @var string[]|null|false
   */
  private array|null|false $vectorItemIds = FALSE;

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
    $this->vectorItemIds = FALSE;
    return [
      'index_parity' => $this->checkIndexParity(),
      'orphan_vector_rows' => $this->checkOrphanVectorRows(),
      'hnsw_tuning' => $this->checkHnswTuning(),
      'required_settings' => $this->checkRequiredSettings(),
      'expiry_sweep' => $this->checkExpirySweep(),
      'consolidation_queue' => $this->checkConsolidationQueue(),
      'untrusted_facts' => $this->checkUntrustedFacts(),
      'models' => $this->checkModels(),
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
    $itemIds = $this->vectorItemIds();
    if ($itemIds === NULL) {
      return $this->vectorStoreUnreadable('Index parity');
    }
    $vectorRows = count($itemIds);

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
    $itemIds = $this->vectorItemIds();
    if ($itemIds === NULL) {
      return $this->vectorStoreUnreadable('Orphan vector rows');
    }
    // The item ID ends in the fact's own langcode (the site's single
    // operating language, not necessarily "en"), so read it with the ID.
    $entityType = $this->entityTypeManager->getDefinition('aim_fact');
    $idKey = $entityType->getKey('id');
    $langKey = $entityType->getKey('langcode');
    $facts = $this->database->select($entityType->getBaseTable(), 'f')
      ->fields('f', [$idKey, $langKey])
      ->execute()
      ->fetchAll(\PDO::FETCH_NUM);
    $known = [];
    foreach ($facts as $row) {
      $known["entity:aim_fact/$row[0]:$row[1]"] = TRUE;
    }

    $orphans = 0;
    foreach ($itemIds as $itemId) {
      if (!isset($known[$itemId])) {
        $orphans++;
      }
    }

    return [
      'label' => 'Orphan vector rows',
      'ok' => $orphans === 0,
      'detail' => $orphans === 0 ? 'none' : "$orphans orphan row(s) - search_api self-heals these on the next query that returns one, or reindex now: drush search-api:index aim_vector_index",
    ];
  }

  /**
   * Returns the collection's item IDs, listed once per checkAll() run.
   *
   * @return string[]|null
   *   One item ID per row, or NULL when the provider can't list them.
   */
  private function vectorItemIds(): ?array {
    if ($this->vectorItemIds === FALSE) {
      $this->vectorItemIds = $this->vectorStore->listItemIds();
    }
    return $this->vectorItemIds;
  }

  /**
   * Builds the result for a check that needs the collection's rows.
   *
   * @param string $label
   *   The check's label.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function vectorStoreUnreadable(string $label): array {
    return [
      'label' => $label,
      'ok' => FALSE,
      'detail' => 'the vector collection could not be listed through provider "' . ($this->vectorStore->getProviderId() ?? 'none') . '" - check search_api.server.aim_vector is set up (DEVELOPING.md, "Setting up vector search")',
    ];
  }

  /**
   * Checks the collection table's build-time M and the server's ef_search.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkHnswTuning(): array {
    // M and the table it is read from are MariaDB's; another provider has
    // its own tuning, not checked here.
    $providerId = $this->vectorStore->getProviderId();
    if ($providerId !== 'mariadb') {
      return [
        'label' => 'HNSW tuning',
        'ok' => TRUE,
        'detail' => 'not checked: the vector server uses provider "' . ($providerId ?? 'none') . '", not mariadb',
      ];
    }
    // MariaDB drops the VECTOR KEY's M=/DISTANCE= suffix from SHOW CREATE
    // TABLE under this connection's own sql_mode ('ANSI,TRADITIONAL', set
    // by Drupal's mysql driver) - present under ANSI or TRADITIONAL alone,
    // only their combination hides it (checked against 2026-09-27 live
    // data). ANSI_QUOTES alone keeps it and still double-quotes
    // identifiers, matching what {aim_fact_vectors} substitutes to here, so
    // swap to that for this one query and restore afterward. The M
    // attribute's case also varies (`M` in DDL written explicitly, as the
    // provider does since 1.0.2, `m` when MariaDB applied a session
    // mhnsw_default_m, as aim's former shim did) - the match below is
    // case-insensitive.
    $originalMode = (string) $this->database->query('SELECT @@SESSION.sql_mode')->fetchField();
    $this->database->query('SET SESSION sql_mode = :mode', [':mode' => 'ANSI_QUOTES']);

    try {
      $createTable = (string) $this->database->query('SHOW CREATE TABLE {aim_fact_vectors}')->fetchField(1);
    }
    finally {
      $this->database->query('SET SESSION sql_mode = :mode', [':mode' => $originalMode]);
    }

    $m = preg_match('/VECTOR KEY[^\n]*["`]M["`]=\'?(\d+)/i', $createTable, $matches) ? (int) $matches[1] : NULL;
    $databaseSettings = $this->configFactory->get('search_api.server.aim_vector')->get('backend_config.database_settings') ?? [];
    $efSearch = $databaseSettings['mhnsw_ef_search'] ?? NULL;
    // The provider builds new collections with the server's `m`, 16 unset.
    $expectedM = (int) ($databaseSettings['m'] ?? 16);

    $problems = [];
    if ($m === NULL) {
      $problems[] = "M not found in aim_fact_vectors' VECTOR KEY";
    }
    elseif ($m !== $expectedM) {
      $problems[] = "M=$m, expected $expectedM - rebuild the index (DEVELOPING.md \"Tuning vector search accuracy\")";
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
   * Checks that every key with no default in code that is read is set.
   *
   * A getter in AimSettings throws on a missing one, so this names them
   * before recall or consolidation fails on it. A threshold for a check
   * that is off is not read, so it is not asked for.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkRequiredSettings(): array {
    $config = $this->configFactory->get('aim.settings');
    $missing = array_values(array_filter($this->settings->requiredKeysInUse(), fn (string $key): bool => $config->get($key) === NULL));

    return [
      'label' => 'Required settings present',
      'ok' => empty($missing),
      'detail' => empty($missing)
        ? 'recall_max_distance ' . $config->get('recall_max_distance') . ' (recalibrate per dataset, ADR-0019)'
        : 'missing: ' . implode(', ', $missing) . ' - each ships in config/install/aim.settings.yml',
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
    // aim's activities name their decision models; the site default is only
    // read by literals_finder's chooser.
    $operations = ['chat', 'embeddings'];
    if ($this->moduleHandler->moduleExists('literals_finder')) {
      $operations[] = 'decision';
    }

    foreach ($operations as $operation) {
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
    // "Setting up vector search"): aim ships them empty and the
    // aimUseDefaultModels config action fills them from the site's defaults,
    // so a site that had none set at install time has to set them.
    // chat_model is checked the way the backend's own status page does
    // (against the option list);
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
