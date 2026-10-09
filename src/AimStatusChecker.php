<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Vdb\AimMariaDBProvider;

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
   */
  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected AiVdbProviderPluginManager $vdbProviders,
    protected EntityTypeManagerInterface $entityTypeManager,
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
      $detail = 'not set, silently using the in-code default ' . AimMemoryManager::DEFAULT_RECALL_MAX_DISTANCE . ' - recalibrate for this site\'s dataset (ADR-0019)';
    }

    return [
      'label' => 'Recall cutoff configured',
      'ok' => $ok,
      'detail' => $detail,
    ];
  }

}
