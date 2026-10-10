<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\literals\Entity\Literal;
use Drupal\literals\Entity\LiteralType;
use Drupal\literals\LiteralReader;
use Drupal\search_api\Backend\BackendInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Query\QueryInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that recall() resolves [literal:key] tokens as the viewing account.
 *
 * The vector search is not under test: aim_vector_index sits on
 * search_api_test's backend, overridden to return every fact, so recall()
 * runs unchanged from the search call on.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimRecallLiteralTokensTest extends KernelTestBase {

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
    'search_api',
    'search_api_test',
    'literals',
    'aim',
  ];

  /**
   * A signed-in account that sees unrestricted literals only.
   */
  protected UserInterface $member;

  /**
   * An account that also sees restricted literals.
   */
  protected UserInterface $staff;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('literal');
    $this->installEntitySchema('aim_fact');
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);

    // Only aim.settings from aim's config: the rest is the real vector
    // server and index, which would need a MariaDB vector store.
    $path = $this->container->get('extension.list.module')->getPath('aim');
    $this->config('aim.settings')
      ->setData(Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml')))
      ->save();

    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    LiteralType::create(['id' => 'text', 'label' => 'Text', 'resolver' => 'text'])->save();

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
    \Drupal::state()->set('search_api_test.backend.method.search', [static::class, 'searchAllFacts']);

    // Uid 1 bypasses every access check; keep it out of the way.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    foreach (['anonymous' => 'Anonymous', 'authenticated' => 'Authenticated'] as $rid => $label) {
      Role::create(['id' => $rid, 'label' => $label])->grantPermission('view literals')->save();
    }
    $this->member = $this->createUser([], 'member');
    $this->staff = $this->createUser(['view restricted literals'], 'staff');

    $this->createLiteral('main_phone', '0113 496 0000', FALSE);
    $this->createLiteral('alarm_line', '0113 496 0999', TRUE);
    $this->createFact('Call the library on [literal:main_phone].');
    $this->createFact('Staff report a false alarm on [literal:alarm_line].');
    $this->createFact('The library is closed on bank holidays.');
  }

  /**
   * Search backend override: every fact matches, in ID order.
   *
   * @param \Drupal\search_api\Backend\BackendInterface $backend
   *   The test backend.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query recall() built.
   */
  public static function searchAllFacts(BackendInterface $backend, QueryInterface $query): void {
    $index = $query->getIndex();
    $fieldsHelper = \Drupal::service('search_api.fields_helper');
    $items = [];
    foreach (AimFact::loadMultiple() as $fact) {
      $item = $fieldsHelper->createItemFromObject($index, EntityAdapter::createFromEntity($fact), NULL, $index->getDatasource('entity:aim_fact'));
      $item->setScore(0.1);
      $items[$item->getId()] = $item;
    }
    $query->getResults()->setResultItems($items)->setResultCount(count($items));
  }

  /**
   * Creates and saves a published literal, validating first.
   *
   * @param string $key
   *   The key.
   * @param string $value
   *   The value.
   * @param bool $restricted
   *   Whether only "view restricted literals" may read it.
   */
  protected function createLiteral(string $key, string $value, bool $restricted): void {
    $literal = Literal::create([
      'type' => 'text',
      'id' => $key,
      'name' => ucfirst($key),
      'value' => $value,
      'restricted' => $restricted,
      'status' => 1,
    ]);
    $violations = $literal->validate();
    $this->assertCount(0, $violations, (string) $violations);
    $literal->save();
  }

  /**
   * Creates and saves a trusted site fact.
   *
   * @param string $text
   *   The fact text, tokens and all.
   */
  protected function createFact(string $text): void {
    AimFact::create([
      'scope' => 'site',
      'text' => $text,
      'trusted' => TRUE,
      'uid' => 1,
    ])->save();
  }

  /**
   * Recalls every site fact as the current user.
   *
   * @return string[]
   *   The recalled texts, in fact ID order.
   */
  protected function recallTexts(): array {
    $rows = $this->container->get('aim.memory_manager')->recall('library phone', 'site', NULL, NULL, 10);
    return array_column($rows, 'text');
  }

  /**
   * Each viewer reads the values they may see; a fact they may not is withheld.
   */
  public function testTokensResolvePerViewer(): void {
    $this->setCurrentUser($this->staff);
    $this->assertSame([
      'Call the library on 0113 496 0000.',
      'Staff report a false alarm on 0113 496 0999.',
      'The library is closed on bank holidays.',
    ], $this->recallTexts());

    $this->setCurrentUser($this->member);
    $this->assertSame([
      'Call the library on 0113 496 0000.',
      'The library is closed on bank holidays.',
    ], $this->recallTexts());

    $this->setCurrentUser(User::getAnonymousUser());
    $this->assertSame([
      'Call the library on 0113 496 0000.',
      'The library is closed on bank holidays.',
    ], $this->recallTexts());

    // The value is resolved at read time, never written into the fact.
    $stored = array_map(fn (AimFact $fact): string => $fact->get('text')->value, AimFact::loadMultiple());
    $this->assertContains('Call the library on [literal:main_phone].', $stored);
    $this->assertNotContains('Call the library on 0113 496 0000.', $stored);
  }

  /**
   * With show_redacted_facts on, a hidden value reads [redacted] instead.
   */
  public function testRedactedWhenDebugSettingOn(): void {
    $this->config('aim.settings')->set('show_redacted_facts', TRUE)->save();

    $this->setCurrentUser($this->member);
    $this->assertSame([
      'Call the library on 0113 496 0000.',
      'Staff report a false alarm on ' . LiteralReader::REDACTED . '.',
      'The library is closed on bank holidays.',
    ], $this->recallTexts());

    // The setting changes how a hidden value shows, not who sees it.
    $this->setCurrentUser($this->staff);
    $this->assertContains('Staff report a false alarm on 0113 496 0999.', $this->recallTexts());
  }

  /**
   * A changed, unpublished or deleted literal is read as it is now.
   */
  public function testLiteralChangesReadLive(): void {
    $this->setCurrentUser($this->member);
    $literal = Literal::load('main_phone');

    $literal->set('value', '0113 496 0001')->save();
    $this->assertContains('Call the library on 0113 496 0001.', $this->recallTexts());

    $literal->set('status', 0)->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('literal')->resetCache();
    $this->assertSame(['The library is closed on bank holidays.'], $this->recallTexts());

    // A fact naming a deleted literal is withheld; recall() does not fail.
    $literal->delete();
    $this->setCurrentUser($this->staff);
    $this->assertSame([
      'Staff report a false alarm on 0113 496 0999.',
      'The library is closed on bank holidays.',
    ], $this->recallTexts());
  }

}
