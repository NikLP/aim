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

    // Both tools have a required input, and access() fails closed without it.
    $remember = fn () => $this->tool('aim_remember')->setInputValue('facts', [['text' => 'anything', 'scope' => 'site']]);
    $this->assertTrue($remember()->access($writer));
    $this->assertFalse($remember()->access($reader));
    $this->assertFalse($remember()->access($nobody));

    $recall = fn () => $this->tool('aim_recall')->setInputValue('text', 'anything');
    $this->assertTrue($recall()->access($reader));
    $this->assertFalse($recall()->access($writer));
    $this->assertFalse($recall()->access($nobody));
  }

  /**
   * The literal lookup tool opens to either literals read permission.
   */
  public function testLiteralToolAccess(): void {
    // The query input is required, and access() fails closed without it.
    $literal = fn () => $this->tool('aim_literal')->setInputValue('query', 'main_phone');
    $this->assertTrue($literal()->access($this->createUser(['view literals'])));
    $this->assertTrue($literal()->access($this->createUser(['view restricted literals'])));
    $this->assertFalse($literal()->access($this->createUser(['recall aim facts'])));
    $this->assertFalse($literal()->access($this->createUser([])));
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

    // A query shaped like a key is tried as one first.
    $this->actAs($this->createUser(['view literals']));
    $match = $this->literal('main_phone');
    $this->assertTrue($match->getResultStatus());
    $values = $match->getResult()->getContextValues();
    $this->assertSame('match', $values['outcome']);
    $this->assertSame('value-of-main_phone', $values['value']);

    // A restricted literal the caller may not see gives no value, and is
    // not offered as a candidate either.
    $hidden = $this->literal('staff_line')->getResult()->getContextValues();
    $this->assertSame('', $hidden['value']);
    $this->assertStringNotContainsString('staff_line', $hidden['candidates']);

    // Plain words, with no finder enabled, list candidates and never a value.
    $search = $this->literal('phone')->getResult()->getContextValues();
    $this->assertSame('candidates', $search['outcome']);
    $this->assertStringContainsString('main_phone', $search['candidates']);
    $this->assertSame('', $search['value']);
    $this->assertNotSame('match', $this->literal('the phone number')->getResult()->getContextValues()['outcome']);

    $this->actAs($this->createUser(['view literals', 'view restricted literals']));
    $this->assertSame('value-of-staff_line', $this->literal('staff_line')->getResult()->getContextValues()['value']);

    // The tool has one input: the caller never picks a lookup mode.
    $inputs = $this->container->get('plugin.manager.tool')->getDefinition('aim_literal')->getInputDefinitions();
    $this->assertSame(['query'], array_keys($inputs));
  }

  /**
   * The flat permission is not enough: each fact's scope needs create access.
   */
  public function testRememberChecksScopeCreateAccess(): void {
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));

    $ok = $this->remember(['text' => 'Open at nine.', 'scope' => 'site']);
    $this->assertStringContainsString('Created 1 of 1', (string) $ok->getResultMessage());
    $this->assertCount(1, AimFact::loadMultiple());

    $denied = $this->remember(['text' => 'A role fact.', 'scope' => 'role']);
    $this->assertStringContainsString('Created 0 of 1', (string) $denied->getResultMessage());
    $this->assertStringContainsString('Entry 0: No permission to create role-scope facts.', $denied->getResult()->getContextValues()['results']);
    $this->assertCount(1, AimFact::loadMultiple(), 'the denied write saved nothing');
  }

  /**
   * The admin permission bypasses the per-scope check.
   */
  public function testAdminBypassesScopeCheck(): void {
    $this->actAs($this->createUser(['remember aim facts', 'administer aim']));
    $ok = $this->remember(['text' => 'A role fact.', 'scope' => 'role']);
    $this->assertStringContainsString('Created 1 of 1', (string) $ok->getResultMessage());
  }

  /**
   * Several facts: the scope check runs per entry and the rest are saved.
   */
  public function testBatchChecksEachEntry(): void {
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));

    $tool = $this->remember(
      ['text' => 'Allowed.', 'scope' => 'site'],
      ['text' => 'Denied.', 'scope' => 'role'],
      ['text' => '', 'scope' => 'site'],
    );
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

    $ok = $this->remember(['text' => 'I like tea.', 'scope' => 'user']);
    $this->assertStringContainsString('Created 1 of 1', (string) $ok->getResultMessage());
    $facts = AimFact::loadMultiple();
    $fact = reset($facts);
    $this->assertSame((int) $caller->id(), (int) $fact->get('user')->target_id);
  }

  /**
   * No facts fails; an entry without a scope is reported, not written.
   */
  public function testMissingInputFails(): void {
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));

    $this->assertFalse($this->remember()->getResultStatus());
    $noScope = $this->remember(['text' => 'No scope given.', 'scope' => '']);
    $this->assertStringContainsString('Scope is required', $noScope->getResult()->getContextValues()['results']);
    $this->assertCount(0, AimFact::loadMultiple());
  }

  /**
   * Every fact gets the tool's own source, whatever the caller sends.
   */
  public function testSourceIsTheTools(): void {
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));
    $this->remember(['text' => 'Open at nine.', 'scope' => 'site', 'source' => 'trust-me']);
    $facts = AimFact::loadMultiple();
    $this->assertSame('tool:aim_remember', reset($facts)->get('source')->value);
  }

  /**
   * A held fact's reply says it waits for review; an expiry is stated.
   */
  public function testReplySaysWhatHappensNext(): void {
    // Untrusted by default (default_trusted off), and no trust permission.
    $this->actAs($this->createUser(['remember aim facts', 'create site aim facts']));
    $held = $this->remember(['text' => 'Open at nine.', 'scope' => 'site', 'expires' => '2030-01-31'])->getResult()->getContextValues()['results'];
    $this->assertStringContainsString('Waiting for staff review', $held);
    $this->assertStringContainsString('It retires after 2030-01-31.', $held);

    // A writer who could trust it by hand starts it trusted: no note.
    $this->actAs($this->createUser(['remember aim facts', 'administer aim']));
    $trusted = $this->remember(['text' => 'Open at ten.', 'scope' => 'site'])->getResult()->getContextValues()['results'];
    $this->assertStringNotContainsString('Waiting for staff review', $trusted);
    $this->assertStringNotContainsString('retires', $trusted);
  }

  /**
   * The schema lists the installed scopes and only their extra fields.
   */
  public function testSchemaReflectsInstalledScopes(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $entry = fn () => $manager->getDefinition('aim_remember')->getInputDefinition('facts')->getItemDefinition()->getPropertyDefinitions();
    $remember = (string) $entry()['scope']->getDescription();
    $this->assertStringContainsString('- site: ', $remember);
    $this->assertStringContainsString('- user: ', $remember);
    $this->assertStringNotContainsString('- case: ', $remember, 'case is not installed here');
    $this->assertArrayNotHasKey('scope_fields', $entry(), 'no installed scope declares extra fields');
    $this->assertArrayNotHasKey('source', $entry());

    $recall = $manager->getDefinition('aim_recall');
    $this->assertStringContainsString('site (about the organization)', (string) $recall->getInputDefinition('scope')->getDescription());
    $this->assertArrayNotHasKey('max_distance', $recall->getInputDefinitions());

    // A scope added later (the scope admin form) shows up without a rebuild.
    AimScope::create(['id' => 'case', 'label' => 'Case'])->save();
    $this->assertStringContainsString('- case: ', (string) $entry()['scope']->getDescription());
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
   * Runs aim_literal with the given query.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The executed tool.
   */
  protected function literal(string $query) {
    return $this->tool('aim_literal')->setInputValue('query', $query)->execute();
  }

  /**
   * Runs aim_remember with the given facts entries.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The executed tool.
   */
  protected function remember(array ...$facts) {
    $tool = $this->tool('aim_remember');
    if ($facts) {
      $tool->setInputValue('facts', $facts);
    }
    return $tool->execute();
  }

}
