<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai\AiVdbProviderInterface;
use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\ai_search\AiVdbProviderSearchApiInterface;
use Drupal\search_api\IndexInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The vector collection behind aim_vector_index, through its VDB provider.
 *
 * Reads and drops the collection through drupal/ai's provider interface,
 * never as a table, so the status checks and uninstall work on whichever
 * provider the index's server names (backend_config.database), not only
 * MariaDB's in-database mode.
 */
class AimVectorStore {

  /**
   * Rows fetched per page when listing the collection.
   */
  public const PAGE_SIZE = 1000;

  /**
   * Constructs an AimVectorStore object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, to load the vector index.
   * @param \Drupal\ai\AiVdbProviderPluginManager $vdbProviders
   *   The VDB provider plugin manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'ai.vdb_provider')]
    protected AiVdbProviderPluginManager $vdbProviders,
  ) {}

  /**
   * Returns the VDB provider plugin ID the vector server uses.
   *
   * @return string|null
   *   The provider ID (for example "mariadb"), or NULL when the index, its
   *   server or the setting is missing.
   */
  public function getProviderId(): ?string {
    $config = $this->getBackendConfig();
    return empty($config['database']) ? NULL : (string) $config['database'];
  }

  /**
   * Lists the Search API item ID of every row in the collection.
   *
   * One entry per row, so an item stored as several chunks appears once
   * per chunk. Only this index's rows, as the provider's own filters for
   * an unconditioned query on it select them.
   *
   * @return string[]|null
   *   The item IDs, or NULL when the collection can't be read through the
   *   provider (not set up, or a provider without Search API support).
   */
  public function listItemIds(): ?array {
    $index = $this->loadIndex();
    $provider = $this->getProvider();
    $settings = $this->getBackendConfig()['database_settings'] ?? [];
    if (!$index || !$provider instanceof AiVdbProviderSearchApiInterface || empty($settings['collection'])) {
      return NULL;
    }
    $filters = $provider->prepareFilters($index->query());
    if (!is_string($filters)) {
      return NULL;
    }

    $ids = [];
    $offset = 0;
    do {
      $page = $provider->querySearch(
        collection_name: $settings['collection'],
        output_fields: ['drupal_entity_id'],
        filters: $filters,
        limit: self::PAGE_SIZE,
        offset: $offset,
        database: $settings['database_name'] ?? 'default',
      );
      foreach ($page as $row) {
        $ids[] = (string) $row['drupal_entity_id'];
      }
      $offset += self::PAGE_SIZE;
    } while (count($page) === self::PAGE_SIZE);

    return $ids;
  }

  /**
   * Drops the collection, for aim's uninstall.
   *
   * Runs before Drupal removes the index and server config, which it still
   * reads. Does nothing when either is already gone.
   */
  public function dropCollection(): void {
    $provider = $this->getProvider();
    $settings = $this->getBackendConfig()['database_settings'] ?? [];
    if ($provider && !empty($settings['collection'])) {
      $provider->dropCollection($settings['collection'], $settings['database_name'] ?? 'default');
    }
  }

  /**
   * Returns the provider plugin the vector server uses.
   *
   * @return \Drupal\ai\AiVdbProviderInterface|null
   *   The provider, or NULL when not set up.
   */
  protected function getProvider(): ?AiVdbProviderInterface {
    $id = $this->getProviderId();
    if ($id === NULL || !$this->vdbProviders->hasDefinition($id)) {
      return NULL;
    }
    $provider = $this->vdbProviders->createInstance($id);
    return $provider instanceof AiVdbProviderInterface ? $provider : NULL;
  }

  /**
   * Returns the vector server's backend config.
   *
   * @return array
   *   The backend config, empty when the index or its server is missing.
   */
  protected function getBackendConfig(): array {
    $index = $this->loadIndex();
    if (!$index || !$index->hasValidServer()) {
      return [];
    }
    return $index->getServerInstance()->getBackendConfig();
  }

  /**
   * Loads aim_vector_index.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index, or NULL when it does not exist.
   */
  protected function loadIndex(): ?IndexInterface {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load('aim_vector_index');
    return $index instanceof IndexInterface ? $index : NULL;
  }

}
