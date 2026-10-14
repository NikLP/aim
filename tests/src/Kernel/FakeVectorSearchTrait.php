<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;

/**
 * Installs the aim vector index on a test search backend (FakeVectorSearch).
 *
 * The test class needs 'search_api', 'search_api_test' and 'aim' in its
 * modules, and the aim_fact, search_api_task and literal entity schemas.
 */
trait FakeVectorSearchTrait {

  /**
   * Creates the server and index, and points the backend at the fake.
   */
  protected function installFakeVectorIndex(): void {
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);

    Server::create([
      'id' => 'aim_test',
      'name' => 'AIM test',
      'status' => TRUE,
      'backend' => 'search_api_test',
    ])->save();
    Index::create([
      'id' => 'aim_vector_index',
      'name' => 'AIM vector index',
      'status' => TRUE,
      'server' => 'aim_test',
      'datasource_settings' => ['entity:aim_fact' => []],
      'tracker_settings' => ['default' => []],
      'options' => ['index_directly' => FALSE],
    ])->save();
    $this->container->get('state')->set('search_api_test.backend.method.search', [FakeVectorSearch::class, 'search']);
  }

  /**
   * Sets the score the fake search gives each fact.
   *
   * @param array $scores
   *   Scores keyed by fact ID. Unlisted facts score 0.1.
   */
  protected function setScores(array $scores): void {
    $this->container->get('state')->set('aim_recall_test.scores', $scores);
  }

}
