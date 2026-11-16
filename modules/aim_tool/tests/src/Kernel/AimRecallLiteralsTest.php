<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_tool\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\aim\Service\AimSettings;
use Drupal\aim_tool\Tool\AimRecall;
use Drupal\literals\Entity\Literal;
use Drupal\literals\Entity\LiteralType;
use Drupal\literals\LiteralFindResult;
use Drupal\literals\LiteralFinderInterface;
use Drupal\literals\LiteralLookup;
use Drupal\search_api\Backend\BackendInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Query\QueryInterface;
use Drupal\tool\ExecutableResult;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The aim_recall tool also returns exact values from the literals finder.
 *
 * The finder is a stub returning a set result, filtered by view access as
 * the real finder's pool is, so no model is called; the lookup around it
 * (value resolution, the by-key reads of ambiguous candidates) is the real
 * literals.lookup.
 * The vector search is search_api_test's backend returning every fact, as
 * in aim's AimRecallLiteralTokensTest.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimRecallLiteralsTest extends KernelTestBase {

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
    'serialization',
    'ai',
    'search_api',
    'search_api_test',
    'literals',
    'tool',
    'aim',
    'aim_tool',
  ];

  /**
   * What the stub finder returns, or NULL to throw.
   */
  protected ?LiteralFindResult $found = NULL;

  /**
   * An account that sees unrestricted literals only.
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
    $this->installEntitySchema('aim_fact');
    $this->installEntitySchema('literal');
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);
    $path = $this->container->get('extension.list.module')->getPath('aim');
    $this->config('aim.settings')
      ->setData(Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml')))
      ->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    AimScope::create([
      'id' => 'user',
      'label' => 'User',
      'settings' => ['requires_account' => TRUE],
    ])->save();
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
    $this->createUser([], 'root');
    Role::create(['id' => 'authenticated', 'label' => 'Authenticated'])
      ->grantPermission('view literals')
      ->grantPermission('view site aim facts')
      ->save();
    $this->member = $this->createUser([], 'member');
    $this->staff = $this->createUser(['view restricted literals'], 'staff');

    $this->createLiteral('main_phone', '0113 496 0000', FALSE);
    $this->createLiteral('alarm_line', '0113 496 0999', TRUE);
    AimFact::create([
      'scope' => 'site',
      'text' => 'The library is closed on bank holidays.',
      'trusted' => TRUE,
      'uid' => 1,
    ])->save();
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
      'name' => ucfirst(str_replace('_', ' ', $key)),
      'value' => $value,
      'gist' => 'The ' . str_replace('_', ' ', $key) . '.',
      'restricted' => $restricted,
      'status' => 1,
    ]);
    $violations = $literal->validate();
    $this->assertCount(0, $violations, (string) $violations);
    $literal->save();
  }

  /**
   * Builds the tool with the stub finder, as if literals_finder were enabled.
   *
   * @param bool $finderEnabled
   *   Whether literals_finder counts as enabled.
   *
   * @return \Drupal\aim_tool\Tool\AimRecall
   *   The tool.
   */
  protected function tool(bool $finderEnabled = TRUE): AimRecall {
    $test = $this;
    $finder = new class($test) implements LiteralFinderInterface {

      /**
       * Constructs the stub finder.
       *
       * @param \Drupal\Tests\aim_tool\Kernel\AimRecallLiteralsTest $test
       *   The test, whose found property is the result.
       */
      public function __construct(protected AimRecallLiteralsTest $test) {}

      /**
       * {@inheritdoc}
       */
      public function find(string $question, ?AccountInterface $account = NULL, ?string $context = NULL, ?array $thresholds = NULL): LiteralFindResult {
        $found = $this->test->found() ?? throw new \RuntimeException('Finder down.');
        // The real finder only ever chooses from literals the account may
        // view (LiteralFinder's access-filtered pool), and literals.lookup
        // relies on that for a match: keep the same contract.
        $visible = array_values(array_filter($found->literals, fn (Literal $literal): bool => $literal->access('view', $account)));
        return new LiteralFindResult($visible ? $found->outcome : 'none', $visible, $found->tier, $found->reason);
      }

    };
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(fn (string $module): bool => $module === 'literals_finder' ? $finderEnabled : TRUE);
    $currentUser = $this->container->get('current_user');
    return new AimRecall(
      $this->container->get('aim.recall'),
      $this->container->get('aim.memory_manager'),
      $this->container->get(AimSettings::class),
      $currentUser,
      new LiteralLookup(
        $this->container->get('literals.reader'),
        $this->container->get('literals.search'),
        $currentUser,
        $finder,
      ),
      $moduleHandler,
      $this->container->get('logger.channel.aim'),
    );
  }

  /**
   * Returns what the stub finder should return.
   *
   * @return \Drupal\literals\LiteralFindResult|null
   *   The result, or NULL to throw.
   */
  public function found(): ?LiteralFindResult {
    return $this->found;
  }

  /**
   * Sets the stub finder to match or tie on the given keys.
   *
   * @param string $outcome
   *   Either match or ambiguous.
   * @param string[] $keys
   *   The literal keys.
   */
  protected function finderReturns(string $outcome, array $keys): void {
    $this->found = new LiteralFindResult($outcome, array_values(Literal::loadMultiple($keys)), 'chooser');
  }

  /**
   * Returns the keys and match of each literal in a result.
   *
   * @param \Drupal\tool\ExecutableResult $result
   *   The tool result.
   *
   * @return array
   *   Key => match.
   */
  protected function literalKeys(ExecutableResult $result): array {
    return array_column($result->getContextValues()['literals'], 'match', 'key');
  }

  /**
   * A readable match comes back with its value, beside the facts.
   */
  public function testMatchReturnsValue(): void {
    $this->setCurrentUser($this->member);
    $this->finderReturns('match', ['main_phone']);
    $result = $this->tool()->recall('do you have any phone numbers');

    $this->assertTrue($result->isSuccess());
    $values = $result->getContextValues();
    $this->assertCount(1, $values['results']);
    $this->assertSame([
      [
        'key' => 'main_phone',
        'value' => '0113 496 0000',
        'label' => 'Main phone',
        'kind' => 'text',
        'gist' => 'The main phone.',
        'match' => 'match',
      ],
    ], $values['literals']);
  }

  /**
   * A restricted literal is not returned to an account that may not view it.
   */
  public function testRestrictedLiteralWithheld(): void {
    $this->finderReturns('match', ['alarm_line']);
    $this->setCurrentUser($this->member);
    $this->assertSame([], $this->literalKeys($this->tool()->recall('alarm line')));

    $this->setCurrentUser($this->staff);
    $this->assertSame(['alarm_line' => 'match'], $this->literalKeys($this->tool()->recall('alarm line')));

    // An ambiguous tie is filtered the same way, candidate by candidate.
    $this->finderReturns('ambiguous', ['main_phone', 'alarm_line']);
    $this->setCurrentUser($this->member);
    $this->assertSame(['main_phone' => 'ambiguous'], $this->literalKeys($this->tool()->recall('phone')));
    $this->setCurrentUser($this->staff);
    $this->assertSame(['main_phone' => 'ambiguous', 'alarm_line' => 'ambiguous'], $this->literalKeys($this->tool()->recall('phone')));
  }

  /**
   * Setting off or no finder: as before. A narrowed call still asks.
   */
  public function testNotAskedWhenOffOrNarrowed(): void {
    $this->setCurrentUser($this->member);
    $this->finderReturns('match', ['main_phone']);

    $this->assertSame([], $this->literalKeys($this->tool(FALSE)->recall('phone')));

    $this->config('aim.settings')->set('recall_literals', FALSE)->save();
    $this->assertSame([], $this->literalKeys($this->tool()->recall('phone')));
    $this->config('aim.settings')->set('recall_literals', TRUE)->save();

    // A literal is not always a site value (a per-user account page), so a
    // call narrowed to one scope or account still asks.
    $this->assertSame(['main_phone' => 'match'], $this->literalKeys($this->tool()->recall('phone', 'user')));
    $this->assertSame(['main_phone' => 'match'], $this->literalKeys($this->tool()->recall('phone', 'site')));

    // The config key missing (a site installed before it existed) is off.
    $this->config('aim.settings')->clear('recall_literals')->save();
    $this->assertSame([], $this->literalKeys($this->tool()->recall('phone')));
    $this->config('aim.settings')->set('recall_literals', TRUE)->save();

    // Not asked at all: the message makes no claim about exact values.
    AimFact::load(1)->delete();
    $this->config('aim.settings')->set('recall_literals', FALSE)->save();
    $this->assertSame('No relevant facts found.', (string) $this->tool()->recall('phone')->getMessage());
  }

  /**
   * A finder that errors or cannot run never fails recall.
   */
  public function testFinderErrorKeepsFacts(): void {
    $this->setCurrentUser($this->member);
    foreach ([NULL, new LiteralFindResult('none', [], 'pool', 'error')] as $found) {
      $this->found = $found;
      $result = $this->tool()->recall('phone');
      $this->assertTrue($result->isSuccess());
      $this->assertCount(1, $result->getContextValues()['results']);
      $this->assertSame([], $result->getContextValues()['literals']);
      $this->assertStringContainsString('could not be checked', (string) $result->getMessage());
    }
  }

  /**
   * The message names only what was found; a literal alone is not "none".
   */
  public function testMessages(): void {
    $this->setCurrentUser($this->member);
    $this->finderReturns('match', ['main_phone']);
    $this->assertSame('Found 1 relevant fact(s) and 1 exact value(s).', (string) $this->tool()->recall('phone')->getMessage());
    $this->found = new LiteralFindResult('none', [], 'chooser', 'chose_none');
    $this->assertSame('Found 1 relevant fact(s).', (string) $this->tool()->recall('phone')->getMessage());

    AimFact::load(1)->delete();
    $this->finderReturns('match', ['main_phone']);
    $result = $this->tool()->recall('do you have any phone numbers');
    $this->assertSame([], $result->getContextValues()['results']);
    $this->assertSame(['main_phone' => 'match'], $this->literalKeys($result));
    $this->assertSame('Found 1 exact value(s).', (string) $result->getMessage());

    // Both checked and both empty says so.
    $this->found = new LiteralFindResult('none', [], 'chooser', 'chose_none');
    $this->assertSame('No relevant facts or exact values found.', (string) $this->tool()->recall('phone')->getMessage());
  }

}
