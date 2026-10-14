<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Retire and Unretire bulk actions and who may run them.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimRetireActionTest extends KernelTestBase {

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
    'literals',
    'aim',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aim_fact');
    $path = $this->container->get('extension.list.module')->getPath('aim');
    $this->config('aim.settings')
      ->setData(Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml')))
      ->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    // Uid 1 bypasses every access check; keep it out of the way.
    $this->createUser([], 'root');
  }

  /**
   * Retire stamps the time once; Unretire clears it; repeats are no-ops.
   */
  public function testRetireAndUnretire(): void {
    $fact = AimFact::create(['scope' => 'site', 'text' => 'A fact.', 'uid' => 1]);
    $fact->save();

    $this->apply('aim_fact_retire', $fact);
    $stamp = $this->reload($fact)->get('retired')->value;
    $this->assertNotEmpty($stamp);

    $this->apply('aim_fact_retire', $this->reload($fact));
    $this->assertSame($stamp, $this->reload($fact)->get('retired')->value, 'retiring again keeps the original time');

    $this->apply('aim_fact_unretire', $this->reload($fact));
    $this->assertEmpty($this->reload($fact)->get('retired')->value);
  }

  /**
   * Only an account that can update the fact may retire or unretire it.
   */
  public function testRetireNeedsUpdateAccess(): void {
    $fact = AimFact::create(['scope' => 'site', 'text' => 'A fact.', 'uid' => 1]);
    $fact->save();

    foreach (['aim_fact_retire', 'aim_fact_unretire'] as $id) {
      $action = $this->action($id);
      $this->assertFalse($action->access($fact, $this->createUser(['view site aim facts'])), $id . ': viewer');
      $this->assertFalse($action->access($fact, $this->createUser(['trust site aim facts'])), $id . ': trust reviewer');
      $this->assertTrue($action->access($fact, $this->createUser(['administer aim'])), $id . ': admin');
    }
  }

  /**
   * Creates an action plugin instance.
   *
   * @return \Drupal\Core\Action\ActionInterface
   *   The action.
   */
  protected function action(string $id) {
    return $this->container->get('plugin.manager.action')->createInstance($id);
  }

  /**
   * Runs an action on a fact.
   */
  protected function apply(string $id, AimFact $fact): void {
    $this->action($id)->execute($fact);
  }

  /**
   * Reloads a fact from storage.
   */
  protected function reload(AimFact $fact): AimFact {
    $this->container->get('entity_type.manager')->getStorage('aim_fact')->resetCache([$fact->id()]);
    return AimFact::load($fact->id());
  }

}
