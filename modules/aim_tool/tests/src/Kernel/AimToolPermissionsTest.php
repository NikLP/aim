<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_tool\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\literals\Entity\Literal;
use Drupal\literals\Entity\LiteralType;
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
    $this->installEntitySchema('literal');
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
   * The literal tool returns a value through literals.lookup, per viewer.
   */
  public function testLiteralToolLooksUp(): void {
    LiteralType::create(['id' => 'text', 'label' => 'Text', 'resolver' => 'text'])->save();
    foreach (['main_phone' => FALSE, 'staff_line' => TRUE] as $key => $restricted) {
      Literal::create([
        'type' => 'text',
        'name' => ucfirst($key),
        'id' => $key,
        'value' => "value-of-$key",
        'gist' => "The $key",
        'restricted' => $restricted,
        'status' => 1,
      ])->save();
    }

    $this->actAs($this->createUser(['view literals']));
    $match = $this->literal(['key' => 'main_phone']);
    $this->assertTrue($match->getResultStatus());
    $values = $match->getResult()->getContextValues();
    $this->assertSame('match', $values['outcome']);
    $this->assertSame('value-of-main_phone', $values['value']);

    // A restricted literal the caller may not see reads as "none".
    $hidden = $this->literal(['key' => 'staff_line']);
    $this->assertSame('none', $hidden->getResult()->getContextValues()['outcome']);
    $this->assertSame('', $hidden->getResult()->getContextValues()['value']);

    // Search lists candidates and never a value.
    $search = $this->literal(['search' => 'phone'])->getResult()->getContextValues();
    $this->assertSame('candidates', $search['outcome']);
    $this->assertStringContainsString('main_phone', $search['candidates']);
    $this->assertSame('', $search['value']);

    $this->actAs($this->createUser(['view literals', 'view restricted literals']));
    $this->assertSame('value-of-staff_line', $this->literal(['key' => 'staff_line'])->getResult()->getContextValues()['value']);

    // A question needs the finder, which is not enabled: failure, not a guess.
    $this->assertFalse($this->literal(['question' => 'the phone number'])->getResultStatus());
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
   * A held fact's reply says it waits for review; an expiry is stated.
   */
  public function testReplySaysWhatHappensNext(): void {
    // Untrusted by default (default_trusted off), and no trust permission.
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));
    $held = (string) $this->remember(['text' => 'Open at nine.', 'scope' => 'site', 'expires' => '2030-01-31'])->getResultMessage();
    $this->assertStringContainsString('Waiting for staff review', $held);
    $this->assertStringContainsString('It retires after 2030-01-31.', $held);

    $batch = $this->remember(['facts' => [['text' => 'Closed on Mondays.', 'scope' => 'site']]]);
    $this->assertStringContainsString('Waiting for staff review', $batch->getResult()->getContextValues()['results']);

    // A writer who could trust it by hand starts it trusted: no note.
    $this->actAs($this->createUser(['remember aim facts', 'administer aim']));
    $trusted = (string) $this->remember(['text' => 'Open at ten.', 'scope' => 'site'])->getResultMessage();
    $this->assertStringNotContainsString('Waiting for staff review', $trusted);
    $this->assertStringNotContainsString('retires', $trusted);
  }

  /**
   * The scope inputs list the installed scopes; recall has no cutoff input.
   */
  public function testSchemaReflectsInstalledScopes(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $remember = (string) $manager->getDefinition('aim_remember')->getInputDefinition('scope')->getDescription();
    $this->assertStringContainsString('- site: ', $remember);
    $this->assertStringContainsString('- user: ', $remember);
    $this->assertStringNotContainsString('- case: ', $remember, 'case is not installed here');

    $recall = $manager->getDefinition('aim_recall');
    $this->assertStringContainsString('site (about the organization)', (string) $recall->getInputDefinition('scope')->getDescription());
    $this->assertArrayNotHasKey('max_distance', $recall->getInputDefinitions());

    // A scope added later (the scope admin form) shows up without a rebuild.
    AimScope::create(['id' => 'case', 'label' => 'Case'])->save();
    $remember = (string) $manager->getDefinition('aim_remember')->getInputDefinition('scope')->getDescription();
    $this->assertStringContainsString('- case: ', $remember);
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
   * Runs aim_literal with the given inputs.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The executed tool.
   */
  protected function literal(array $inputs) {
    $tool = $this->tool('aim_literal');
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    return $tool->execute();
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
