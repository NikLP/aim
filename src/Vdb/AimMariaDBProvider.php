<?php

declare(strict_types=1);

namespace Drupal\aim\Vdb;

use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\ai_search\EmbeddingStrategyInterface;
use Drupal\ai_vdb_provider_mariadb\Exception\CreateCollectionException;
use Drupal\ai_vdb_provider_mariadb\Plugin\VdbProvider\MariaDBProvider;

/**
 * The MariaDB vector provider, minus four bugs and plus HNSW tuning.
 *
 * A shim over ai_vdb_provider_mariadb, swapped in by
 * AimHooks::vdbProviderInfoAlter(). Verified against 1.0.1 and the 1.0.x
 * head (2026-09-26). Keep it thin (ADR-0023): every override here must
 * have an upstream issue listed in TODO.md, and is deleted, with this class
 * and that hook once none remain, when the provider releases the fix.
 * Generic features belong upstream, not here. The one aim-specific method,
 * ensureColumnIndex(), is called from AimHooks (ADR-0018).
 *
 * - createCollection() (drupal.org #3609961): every
 *   search_api_index_update calls it to make sure the table exists before
 *   ALTERing in attribute columns. The provider expects failure to surface
 *   as a CreateCollectionException it logs, but its connection runs with
 *   MYSQLI_REPORT_STRICT, so an existing table throws a bare
 *   mysqli_sql_exception out of the index's save(), after the config is
 *   written and before updateFields() runs. That issue's proposed fix drops
 *   the collection on every call, which would wipe the vectors on every
 *   index save; tolerating error 1050 here is the safe one.
 * - getVdbIds() (drupal.org #3626257): resolves Drupal item IDs to row IDs
 *   through querySearch() with its default limit of 10, so deleteItems()
 *   and deleteIndexItems() remove at most 10 rows per call, silently
 *   leaving the rest. A batch of retirements (ADR-0022) or a sweep of stale
 *   rows would only be purged ten at a time.
 * - insertIntoCollection() (drupal.org #3626262): an empty search_api value
 *   reaches it as `''` (ai_search's EmbeddingBase::getValue()), which
 *   MariaDB's strict mode rejects in an INT, DECIMAL or BIGINT column
 *   (ERROR 1366), so an integer attribute that is empty for some facts,
 *   like subject_uid, would stop those facts indexing. It becomes NULL
 *   here (ADR-0018).
 * - insertIntoCollection() and string attributes: ai_search's
 *   EmbeddingBase::getValue() passes every single-value string attribute
 *   through an HTML-to-Markdown converter, which escapes `_` as `\_` (and
 *   other Markdown characters). The stored value then never equals the
 *   value a query filters on, so `subject = 'content_editor'` matches
 *   nothing, and consolidation's neighbor search silently finds no
 *   neighbors for such a fact. The raw Search API value is written instead.
 *   Fixed upstream in ai_search by #3572801 (ai_search 1.3.0-alpha5 and
 *   2.0.0-alpha2, and drupal/ai's 1.x head), but not in the ai_search code
 *   bundled with drupal/ai 1.4.9 or 1.5.0, so it stays until the site has
 *   that code.
 * - createCollection() and vectorSearch(): MariaDB's HNSW defaults (M=6,
 *   ef_search=20) missed 3-12% of the true nearest facts in testing. M=16
 *   is applied to new collections and mhnsw_ef_search from the server's
 *   `database_settings` to every query (ADR-0023). The provider's 1.0.x
 *   head adds the same two settings under the same keys, so aim's shipped
 *   config carries over unchanged when this is removed.
 */
class AimMariaDBProvider extends MariaDBProvider {

  // MariaDB's ER_TABLE_EXISTS_ERROR.
  const TABLE_EXISTS_ERROR = 1050;

  // Rows fetched per page when resolving item IDs to row IDs.
  const ID_PAGE_SIZE = 1000;

  // HNSW graph connectivity for a new collection. Build-time: an existing
  // table keeps its M until its vector index is rebuilt (DEVELOPING.md).
  // 16 is also the default in the provider's 1.0.x head.
  const HNSW_M = 16;

  // Search API field types the provider maps to numeric columns, where an
  // empty string is not a valid value.
  const NUMERIC_FIELD_TYPES = ['integer', 'decimal', 'date', 'boolean'];

  /**
   * The index being written by indexItems(), for insertIntoCollection().
   *
   * @var \Drupal\search_api\IndexInterface|null
   */
  protected $indexBeingWritten;

  /**
   * The items being written by indexItems(), keyed by Search API item ID.
   *
   * @var \Drupal\search_api\Item\ItemInterface[]
   */
  protected array $itemsBeingWritten = [];

  /**
   * {@inheritdoc}
   */
  public function createCollection(
    string $collection_name,
    int $dimension,
    VdbSimilarityMetrics $metric_type = VdbSimilarityMetrics::CosineSimilarity,
    ?string $database = NULL,
  ): void {
    // The connection is opened here, not inside the parent, so the session
    // default for M applies to the connection that runs the CREATE TABLE.
    $connection = $this->getConnection($database);
    try {
      $connection->query('SET SESSION mhnsw_default_m = ' . self::HNSW_M);
      $this->getClient()->createCollection(
        collection_name: $collection_name,
        dimension: $dimension,
        metric_type: $metric_type,
        connection: $connection,
      );
    }
    catch (\mysqli_sql_exception | CreateCollectionException $e) {
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
  public function vectorSearch(
    string $collection_name,
    array $vector_input,
    array $output_fields,
    $query,
    string $filters = '',
    int $limit = 10,
    int $offset = 0,
    ?string $database = NULL,
  ): array {
    $settings = $query->getIndex()->getServerInstance()->getBackendConfig()['database_settings'];
    // Opened here so the session setting applies to the connection that
    // runs the search.
    $connection = $this->getConnection($database);
    $ef_search = $settings['mhnsw_ef_search'] ?? NULL;
    if ($ef_search !== NULL && $ef_search !== '') {
      $connection->query('SET SESSION mhnsw_ef_search = ' . max(1, (int) $ef_search));
    }
    return $this->getClient()->vectorSearch(
      collection_name: $collection_name,
      vector_input: $vector_input,
      output_fields: $output_fields,
      filters: $filters,
      limit: $limit,
      offset: $offset,
      metric_type: VdbSimilarityMetrics::from($settings['metric']),
      connection: $connection,
    );
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

  /**
   * {@inheritdoc}
   */
  public function indexItems(
    array $configuration,
    $index,
    array $items,
    EmbeddingStrategyInterface $embedding_strategy,
  ): array {
    $this->indexBeingWritten = $index;
    $this->itemsBeingWritten = [];
    foreach ($items as $item) {
      $this->itemsBeingWritten[$item->getId()] = $item;
    }
    try {
      return parent::indexItems($configuration, $index, $items, $embedding_strategy);
    }
    finally {
      $this->indexBeingWritten = NULL;
      $this->itemsBeingWritten = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function insertIntoCollection(
    string $collection_name,
    array $data,
    ?string $database = NULL,
  ): void {
    if ($this->indexBeingWritten) {
      $item = $this->itemsBeingWritten[$data['drupal_entity_id']['value'] ?? ''] ?? NULL;
      foreach ($this->indexBeingWritten->getFields() as $field_id => $field) {
        if (!isset($data[$field_id]) || $data[$field_id]['is_multiple']) {
          continue;
        }
        if ($data[$field_id]['value'] === '' && in_array($field->getType(), self::NUMERIC_FIELD_TYPES, TRUE)) {
          $data[$field_id]['value'] = NULL;
        }
        elseif ($field->getType() === 'string' && $item?->getField($field_id)) {
          // The raw value, not the Markdown-escaped one (class docblock).
          $values = $item->getField($field_id)->getValues();
          if (count($values) === 1) {
            $data[$field_id]['value'] = mb_substr((string) reset($values), 0, 255);
          }
        }
      }
    }
    parent::insertIntoCollection($collection_name, $data, $database);
  }

  /**
   * Adds a BTREE index on a collection column, if the column exists.
   *
   * MariaDB's HNSW index post-filters, so a selective condition on an
   * attribute column returns short results until the column has a BTREE
   * index, which makes the optimizer pre-filter (ADR-0018, finding 4). The
   * provider adds plain columns and never a secondary index. Idempotent.
   *
   * @param string $collection_name
   *   The collection (table) name.
   * @param string $column
   *   The attribute column to index.
   * @param string|null $database
   *   The database name, or NULL for the default connection.
   *
   * @return bool
   *   TRUE if the index exists afterward, FALSE if the column does not.
   */
  public function ensureColumnIndex(string $collection_name, string $column, ?string $database = NULL): bool {
    $connection = $this->getConnection($database);
    $client = $this->getClient();
    $table = $client->escapeIdentifierForSql(identifier_to_escape: $collection_name, connection: $connection);
    $column_literal = $client->escapeStringForSql(string_to_escape: $column, connection: $connection);
    $result = $connection->query("SHOW COLUMNS FROM {$table} LIKE {$column_literal}");
    if (!$result || $result->num_rows === 0) {
      return FALSE;
    }
    $column_id = $client->escapeIdentifierForSql(identifier_to_escape: $column, connection: $connection);
    $index_id = $client->escapeIdentifierForSql(identifier_to_escape: 'idx_' . $column, connection: $connection);
    $connection->query("ALTER TABLE {$table} ADD INDEX IF NOT EXISTS {$index_id} ({$column_id})");
    return TRUE;
  }

}
