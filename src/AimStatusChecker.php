<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\aim\Backend\ActivityBackendInterface;
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
      'model_call_logging' => $this->checkModelCallLogging(),
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
    $query = $this->database->select('queue', 'q');
    $query->condition('q.name', 'aim_consolidate');
    $query->addExpression('COUNT(*)', 'items');
    $query->addExpression('MIN(q.created)', 'oldest');
    $result = $query->execute()->fetchAssoc();
    $items = (int) ($result['items'] ?? 0);
    $age = $items > 0 ? $this->time->getRequestTime() - (int) $result['oldest'] : 0;

    return [
      'label' => 'Consolidation queue draining',
      'ok' => $items === 0 || $age < 7200,
      'detail' => $items === 0
        ? 'queue is empty'
        : ($age < 7200
          ? "$items item(s) waiting, oldest " . (int) ($age / 60) . ' minute(s) old'
          : "$items item(s) waiting, the oldest " . (int) ($age / 3600) . ' hour(s) old - add `drush queue:run aim_consolidate` to the crontab (ADR-0003)'),
    ];
  }

  /**
   * Reports how many live facts are untrusted and so held out of recall.
   *
   * Informational: always ok. The trust policy and the grounded check decide
   * how many land here (ADR-0037 addendum 2026-10-18).
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkUntrustedFacts(): array {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $untrusted = (int) $storage->getQuery()->accessCheck(FALSE)->notExists('retired')->condition('trusted', 0)->count()->execute();
    $unscored = (int) $storage->getQuery()->accessCheck(FALSE)->notExists('retired')->condition('trusted', 0)->notExists('grounding_score')->count()->execute();

    return [
      'label' => 'Untrusted facts awaiting review',
      'ok' => TRUE,
      'detail' => $untrusted === 0
        ? 'none'
        : "$untrusted untrusted ($unscored without a grounding score: held by policy, the rest below the threshold)",
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
    $factIds = $this->entityTypeManager->getStorage('aim_fact')->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    $query = $this->database->select('aim_fact_vectors', 'v');
    if ($factIds) {
      $entityIds = array_map(static fn (int|string $id): string => "entity:aim_fact/$id:en", $factIds);
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
      $targets["default $operation"] = [
        $operation,
        (string) ($default['provider_id'] ?? ''),
        (string) ($default['model_id'] ?? ''),
      ];
    }
    $activities = $this->configFactory->get('aim.settings')->get('activities') ?? [];
    foreach ($activities as $name => $activity) {
      if (!empty($activity['provider']) && !empty($activity['model'])) {
        $operation = ($activity['backend'] ?? 'chat') === 'decision' ? 'decision' : 'chat';
        $targets["activity $name"] = [$operation, (string) $activity['provider'], (string) $activity['model']];
      }
    }

    $problems = [];
    foreach ($targets as $label => [$operation, $providerId, $modelId]) {
      if ($providerId === '' || $modelId === '') {
        $problems[] = "$label: no provider or model set";
        continue;
      }
      try {
        $models = $this->aiProvider->createInstance($providerId)->getConfiguredModels($operation);
      }
      catch (\Throwable) {
        $problems[] = "$label: provider $providerId is not usable";
        continue;
      }
      if (!isset($models[$modelId])) {
        $problems[] = "$label: $providerId does not list model $modelId";
      }
    }

    return [
      'label' => 'Configured models exist',
      'ok' => !$problems,
      'detail' => $problems
        ? implode('; ', $problems) . ' - fix at /admin/config/ai/settings (defaults) or the aim settings (activities)'
        : count($targets) . ' model setting(s) checked',
    ];
  }

  /**
   * Checks that ai_logging, when on, records every aim model call.
   *
   * The settings are site-wide and aim does not write them (ADR-0060), so
   * logging off is a site's choice and passes. On, it fails when a tag in
   * ActivityBackendInterface::LOGGED_TAGS would not be recorded (the tag list
   * is an exact match, and an empty list records every call), when replies
   * are not recorded, or when entries are pruned before the 90 days eval
   * work wants.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkModelCallLogging(): array {
    $settings = $this->configFactory->get('ai_logging.settings');
    if (!$settings->get('prompt_logging')) {
      return [
        'label' => 'Model-call logging',
        'ok' => TRUE,
        'detail' => 'off, model prompts and replies are not recorded - turn on at /admin/config/ai/logging/settings if wanted (DEVELOPING.md "Model-call logging")',
      ];
    }

    // Split and normalize the way ai_logging's shouldLoggingHappen() does.
    $split = static fn (?string $list): array => array_values(array_filter(
      array_map(static fn (string $tag): string => strtolower(trim($tag)), explode(',', (string) $list)),
      static fn (string $tag): bool => $tag !== '',
    ));
    $included = $split($settings->get('prompt_logging_tags'));
    $excluded = $split($settings->get('prompt_logging_excluded_tags'));
    $missing = array_filter(
      ActivityBackendInterface::LOGGED_TAGS,
      static fn (string $tag): bool => in_array($tag, $excluded, TRUE) || ($included && !in_array($tag, $included, TRUE)),
    );
    $maxAge = (int) $settings->get('prompt_logging_max_age');

    $problems = [];
    if ($missing) {
      $problems[] = 'not recorded: ' . implode(', ', $missing) . ' - add to "Restrict automated logging by request tags" and take out of the excluded tags';
    }
    if (!$settings->get('prompt_logging_output')) {
      $problems[] = 'replies are not recorded - turn on "Automatically log responses"';
    }
    if ($maxAge > 0 && $maxAge < 90) {
      $problems[] = "entries are kept $maxAge day(s), under the 90 eval work wants";
    }

    $captured = $included ? 'on, all ' . count(ActivityBackendInterface::LOGGED_TAGS) . ' aim tags recorded' : 'on for every model call';
    $kept = $maxAge > 0 ? "$maxAge day(s)" : 'with no age limit';

    return [
      'label' => 'Model-call logging',
      'ok' => !$problems,
      'detail' => $problems
        ? implode('; ', $problems) . ' - fix at /admin/config/ai/logging/settings'
        : "$captured, kept $kept",
    ];
  }

}
