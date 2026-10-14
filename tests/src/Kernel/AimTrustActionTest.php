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
 * The Trust and Untrust bulk actions and the permission that gates them.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimTrustActionTest extends KernelTestBase {

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
    AimScope::create(['id' => 'role', 'label' => 'Role'])->save();
    // Uid 1 bypasses every access check; keep it out of the way.
    $this->createUser([], 'root');
  }

  /**
   * Trust and Untrust flip the flag and are no-ops when already there.
   */
  public function testFlipsTheFlag(): void {
    $fact = $this->fact('site', FALSE);

    $this->apply('aim_fact_trust', $fact);
    $this->assertTrue((bool) $this->reload($fact)->get('trusted')->value);

    $changed = $this->reload($fact)->get('changed')->value;
    $this->apply('aim_fact_trust', $this->reload($fact));
    $this->assertSame($changed, $this->reload($fact)->get('changed')->value, 'already trusted: not saved again');

    $this->apply('aim_fact_untrust', $this->reload($fact));
    $this->assertFalse((bool) $this->reload($fact)->get('trusted')->value);
  }

  /**
   * The trust permission is per scope and separate from update access.
   */
  public function testTrustPermissionIsPerScope(): void {
    $site = $this->fact('site', FALSE);
    $role = $this->fact('role', FALSE);
    $reviewer = $this->createUser(['trust site aim facts']);

    foreach (['aim_fact_trust', 'aim_fact_untrust'] as $id) {
      $action = $this->action($id);
      $this->assertTrue($action->access($site, $reviewer), $id . ': own scope');
      $this->assertFalse($action->access($role, $reviewer), $id . ': other scope');
    }
    $this->assertFalse($site->access('update', $reviewer), 'trusting does not grant editing');

    $this->assertFalse($this->action('aim_fact_trust')->access($site, $this->createUser(['view site aim facts'])));
    $this->assertTrue($this->action('aim_fact_trust')->access($site, $this->createUser(['administer aim memory'])));
  }

  /**
   * Saves a fact in a scope with the given trusted flag.
   */
  protected function fact(string $scope, bool $trusted): AimFact {
    $fact = AimFact::create(['scope' => $scope, 'text' => 'A fact.', 'trusted' => $trusted, 'uid' => 1]);
    $fact->save();
    return $fact;
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
