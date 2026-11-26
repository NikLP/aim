<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\aim\Entity\AimScope;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The shipped vector index on the real MariaDB provider.
 *
 * Builds aim_vector_index from aim's shipped index and ai_search config on a
 * server using the MariaDB provider, with every embeddings call answered by
 * a fixed vector, so what is tested is the collection table: which fields
 * become columns and whether a fact's row can be written. Needs a MariaDB
 * test database with vector support; skipped on any other driver.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimVectorIndexSourceTest extends AimKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'key',
    'taxonomy',
    'views',
    'ai',
    'ai_test',
    'ai_search',
    'ai_vdb_provider_mariadb',
    'search_api',
    'literals',
    'aim',
  ];

  /**
   * The collection table, unique per run.
   */
  protected string $collection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if ($this->container->get('database')->driver() !== 'mysql') {
      $this->markTestSkipped('Requires a MariaDB vector-capable test database.');
    }
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);
    // ai_search's chunk-tracking columns on search_api_item.
    $this->container->get('module_handler')->loadInclude('ai_search', 'install');
    ai_search_install();

    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    $this->setUpCurrentUser([], ['create site aim facts', 'view site aim facts', 'trust site aim facts']);

    // Every embeddings call, indexing and query alike, gets the same vector.
    $this->container->get('event_dispatcher')->addListener(PreGenerateResponseEvent::EVENT_NAME, static function (PreGenerateResponseEvent $event): void {
      if ($event->getOperationType() === 'embeddings') {
        $event->setForcedOutputObject(new EmbeddingsOutput([1, 2], NULL, []));
      }
    });

    $path = $this->container->get('extension.list.module')->getPath('aim') . '/config/install';
    $this->collection = 'aim_test_vectors_' . bin2hex(random_bytes(6));
    $server = Yaml::decode(file_get_contents($path . '/search_api.server.aim_vector.yml'));
    $server['backend_config']['database_settings']['collection'] = $this->collection;
    $server['backend_config']['database_settings']['database_name'] = NULL;
    $server['backend_config']['embeddings_engine'] = 'echoai__gpt-test';
    $server['backend_config']['embeddings_engine_configuration'] = ['set_dimensions' => TRUE, 'dimensions' => 2];
    unset($server['dependencies']);
    Server::create($server)->save();

    // ai_search's tracker has no config schema, which strict schema checking
    // refuses; the tracker plays no part in what is tested here.
    $index = Yaml::decode(file_get_contents($path . '/search_api.index.aim_vector_index.yml'));
    $index['tracker_settings'] = ['default' => []];
    unset($index['dependencies']);
    $index = Index::create($index);
    $index->save();
    $this->config('ai_search.index.aim_vector_index')
      ->setData(Yaml::decode(file_get_contents($path . '/ai_search.index.aim_vector_index.yml')))
      ->save();
    // The attribute columns are added on update, not create.
    $index->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->collection)) {
      $this->container->get('database')->query('DROP TABLE IF EXISTS ' . $this->collection);
    }
    parent::tearDown();
  }

  /**
   * A fact with a source longer than 255 characters is indexed and recalled.
   */
  public function testLongSourceIndexedAndRecalled(): void {
    $source = 'upload:' . str_repeat('a', 293);
    $this->assertSame(300, strlen($source));

    $fact = $this->container->get('aim.remember')->remember('The library opens at nine.', 'site', NULL, $source);
    $this->assertSame($source, $fact->get('source')->value);

    $index = Index::load('aim_vector_index');
    $this->assertSame(1, $index->indexItems());
    $this->assertSame(0, $index->getTrackerInstance()->getRemainingItemsCount());

    $rows = $this->container->get('aim.recall')->recall('When does the library open?', 'site', NULL, NULL, 5);
    $this->assertSame([(int) $fact->id()], array_map('intval', array_column($rows, 'id')));
    $this->assertSame($source, $rows[0]['source']);
  }

}
