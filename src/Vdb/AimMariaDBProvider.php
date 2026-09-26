<?php

declare(strict_types=1);

namespace Drupal\aim\Vdb;

use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\ai_vdb_provider_mariadb\Plugin\VdbProvider\MariaDBProvider;

/**
 * The MariaDB vector provider, minus two bugs in ai_vdb_provider_mariadb.
 *
 * Swapped in by AimHooks::vdbProviderInfoAlter(). Verified against 1.0.1
 * and the 1.0.x head (2026-09-26). Remove this class and that hook once the
 * provider fixes both upstream.
 *
 * - createCollection(): every search_api_index_update calls it to make
 *   sure the table exists before ALTERing in attribute columns. The
 *   provider expects failure to surface as a CreateCollectionException it
 *   logs, but its connection runs with MYSQLI_REPORT_STRICT, so an existing
 *   table throws a bare mysqli_sql_exception out of the index's save(),
 *   after the config is written and before updateFields() runs.
 * - getVdbIds(): resolves Drupal item IDs to row IDs through querySearch()
 *   with its default limit of 10, so deleteItems() and deleteIndexItems()
 *   remove at most 10 rows per call, silently leaving the rest. A batch of
 *   retirements (ADR-0022) or a sweep of stale rows would only be purged
 *   ten at a time.
 */
class AimMariaDBProvider extends MariaDBProvider {

  // MariaDB's ER_TABLE_EXISTS_ERROR.
  const TABLE_EXISTS_ERROR = 1050;

  // Rows fetched per page when resolving item IDs to row IDs.
  const ID_PAGE_SIZE = 1000;

  /**
   * {@inheritdoc}
   */
  public function createCollection(
    string $collection_name,
    int $dimension,
    VdbSimilarityMetrics $metric_type = VdbSimilarityMetrics::CosineSimilarity,
    ?string $database = NULL,
  ): void {
    try {
      parent::createCollection($collection_name, $dimension, $metric_type, $database);
    }
    catch (\mysqli_sql_exception $e) {
      // An existing table is the expected case. Anything else is logged
      // rather than thrown, matching what the parent does for its own
      // CreateCollectionException.
      if ($e->getCode() !== self::TABLE_EXISTS_ERROR) {
        $this->getLogger(self::LOGGER_CHANNEL)->warning('Create collection error: ' . $e->getMessage());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getVdbIds(
    string $collection_name,
    array $drupalIds,
    ?string $database = NULL,
    ?string $index_id = NULL,
  ): array {
    if (empty($drupalIds)) {
      return [];
    }
    $connection = $this->getConnection($database);
    $client = $this->getClient();
    $filters = 'WHERE drupal_entity_id IN ' . $client->prepareStringArrayForSql(items: $drupalIds, connection: $connection);
    if ($index_id !== NULL) {
      $filters .= ' AND index_id = ' . $client->escapeStringForSql(string_to_escape: $index_id, connection: $connection);
    }

    $ids = [];
    $offset = 0;
    do {
      $page = $this->querySearch(
        collection_name: $collection_name,
        output_fields: ['id'],
        filters: $filters,
        limit: self::ID_PAGE_SIZE,
        offset: $offset,
        database: $database,
      );
      foreach ($page as $row) {
        $ids[] = $row['id'];
      }
      $offset += self::ID_PAGE_SIZE;
    } while (count($page) === self::ID_PAGE_SIZE);

    return $ids;
  }

}
