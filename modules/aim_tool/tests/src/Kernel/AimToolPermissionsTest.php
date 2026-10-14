<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_tool\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Who may call the aim Tool API tools, and what aim_remember writes for them.
 *
 * Two layers: the flat permission that gates using a tool at all, and for
 * aim_remember the per-scope "create {scope} aim facts" check on top.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimToolPermissionsTest extends KernelTestBase {

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
    'literals',
    'tool',
    'aim',
    'aim_tool',
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
    AimScope::create([
      'id' => 'user',
      'label' => 'User',
      'settings' => ['requires_account' => TRUE],
    ])->save();
    // Uid 1 bypasses every access check; keep it out of the way.
    $this->createUser([], 'root');
  }

  /**
   * Each tool needs its own flat permission, and the two are independent.
   */
  public function testFlatPermissions(): void {
    $writer = $this->createUser(['remember aim facts']);
    $reader = $this->createUser(['recall aim facts']);
    $nobody = $this->createUser([]);

    $this->assertTrue($this->tool('aim_remember')->access($writer));
    $this->assertFalse($this->tool('aim_remember')->access($reader));
    $this->assertFalse($this->tool('aim_remember')->access($nobody));

    // aim_recall has a required input, and access() fails closed without it.
    $recall = fn () => $this->tool('aim_recall')->setInputValue('text', 'anything');
    $this->assertTrue($recall()->access($reader));
    $this->assertFalse($recall()->access($writer));
    $this->assertFalse($recall()->access($nobody));
  }

  /**
   * The literal lookup tool opens to either literals read permission.
   */
  public function testLiteralToolAccess(): void {
    $this->assertTrue($this->tool('aim_literal')->access($this->createUser(['view literals'])));
    $this->assertTrue($this->tool('aim_literal')->access($this->createUser(['view restricted literals'])));
    $this->assertFalse($this->tool('aim_literal')->access($this->createUser(['recall aim facts'])));
    $this->assertFalse($this->tool('aim_literal')->access($this->createUser([])));
  }

  /**
   * The flat permission is not enough: the fact's scope needs create access.
   */
  public function testRememberChecksScopeCreateAccess(): void {
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));

    $ok = $this->remember(['text' => 'Open at nine.', 'scope' => 'site']);
    $this->assertTrue($ok->getResultStatus());
    $this->assertCount(1, AimFact::loadMultiple());

    $denied = $this->remember(['text' => 'A role fact.', 'scope' => 'role']);
    $this->assertFalse($denied->getResultStatus());
    $this->assertStringContainsString('No permission to create role-scope facts', (string) $denied->getResultMessage());
    $this->assertCount(1, AimFact::loadMultiple(), 'the denied write saved nothing');
  }

  /**
   * The admin permission bypasses the per-scope check.
   */
  public function testAdminBypassesScopeCheck(): void {
    $this->actAs($this->createUser(['remember aim facts', 'administer aim']));
    $this->assertTrue($this->remember(['text' => 'A role fact.', 'scope' => 'role'])->getResultStatus());
  }

  /**
   * A batch applies the scope check per entry and keeps going.
   */
  public function testBatchChecksEachEntry(): void {
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));

    $tool = $this->remember([
      'facts' => [
      ['text' => 'Allowed.', 'scope' => 'site'],
      ['text' => 'Denied.', 'scope' => 'role'],
      ['scope' => 'site'],
      ],
    ]);
    $this->assertTrue($tool->getResultStatus());
    $this->assertStringContainsString('Created 1 of 3', (string) $tool->getResultMessage());
    $lines = $tool->getResult()->getContextValues()['results'];
    $this->assertStringContainsString('Entry 1: No permission to create role-scope facts.', $lines);
    $this->assertStringContainsString('Entry 2: missing text, skipped.', $lines);
    $this->assertCount(1, AimFact::loadMultiple());
  }

  /**
   * A user-scope fact with no subject is about the calling account.
   */
  public function testUserScopeDefaultsToCaller(): void {
    $caller = $this->createUser(['remember aim facts', 'create user aim facts']);
    $this->actAs($caller);

    $this->assertTrue($this->remember(['text' => 'I like tea.', 'scope' => 'user'])->getResultStatus());
    $facts = AimFact::loadMultiple();
    $fact = reset($facts);
    $this->assertSame((int) $caller->id(), (int) $fact->get('user')->target_id);
  }

  /**
   * A call with neither text nor a scope fails with a message, not a write.
   */
  public function testMissingInputFails(): void {
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));

    $this->assertFalse($this->remember([])->getResultStatus());
    $noScope = $this->remember(['text' => 'No scope given.']);
    $this->assertFalse($noScope->getResultStatus());
    $this->assertStringContainsString('Scope is required', (string) $noScope->getResultMessage());
    $this->assertCount(0, AimFact::loadMultiple());
  }

  /**
   * Makes the account the current user, which the tools read.
   */
  protected function actAs(UserInterface $account): void {
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Creates a tool instance.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The tool.
   */
  protected function tool(string $id) {
    return $this->container->get('plugin.manager.tool')->createInstance($id);
  }

  /**
   * Runs aim_remember with the given inputs.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The executed tool.
   */
  protected function remember(array $inputs) {
    $tool = $this->tool('aim_remember');
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    return $tool->execute();
  }

}
