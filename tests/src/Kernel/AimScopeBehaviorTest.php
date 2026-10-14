<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Scope plugin behavior, role visibility and expiry.
 *
 * These paths fail silently when config drifts (a scope reverted to
 * plugin: null mints no case ID and raises no error), so each one is pinned
 * here against the config the scope submodules ship.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimScopeBehaviorTest extends KernelTestBase {

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
    'aim_scope_user',
    'aim_scope_role',
    'aim_scope_site',
    'aim_scope_case',
    'aim_scope_entity',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aim_fact');

    $path = $this->container->get('extension.list.module')->getPath('aim');
    $settings = Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml'));
    $this->config('aim.settings')->setData($settings)->save();

    foreach (array_keys($this->shippedScopes()) as $id) {
      $data = $this->shippedScopes()[$id];
      AimScope::create([
        'id' => $data['id'],
        'label' => $data['label'],
        'plugin' => $data['plugin'],
        'settings' => $data['settings'],
      ])->save();
    }

    // Uid 1 is the superuser, which bypasses every access check.
    User::create(['name' => 'root', 'status' => 1])->save();
  }

  /**
   * The shipped scope config carries the plugin and settings behavior needs.
   */
  public function testShippedScopeConfig(): void {
    $scopes = $this->shippedScopes();
    $this->assertSame('user', $scopes['user']['plugin']);
    $this->assertTrue($scopes['user']['settings']['requires_account']);
    $this->assertSame('case', $scopes['case']['plugin']);
    $this->assertSame('entity', $scopes['entity']['plugin']);
    $this->assertNull($scopes['role']['plugin']);
    $this->assertNull($scopes['site']['plugin']);
  }

  /**
   * A case fact written without a subject gets a minted case ID.
   */
  public function testCaseMintsSubject(): void {
    $a = $this->memory()->remember('First case note.', 'case');
    $b = $this->memory()->remember('Second case note.', 'case');
    $this->assertMatchesRegularExpression('/^case-[0-9a-f]{8}$/', $a->get('subject')->value);
    $this->assertNotSame($a->get('subject')->value, $b->get('subject')->value);

    $kept = $this->memory()->remember('Named case note.', 'case', 'case-fixed');
    $this->assertSame('case-fixed', $kept->get('subject')->value);
  }

  /**
   * A scope with no plugin mints nothing and requires no account.
   */
  public function testPluginlessScopesAreNeutral(): void {
    $site = $this->memory()->remember('A site fact.', 'site');
    $this->assertSame('', (string) $site->get('subject')->value);
    $this->assertFalse($this->memory()->scopeRequiresAccount('site'));
    $this->assertFalse($this->memory()->scopeRequiresAccount('role'));
    $this->assertTrue($this->memory()->scopeRequiresAccount('user'));
  }

  /**
   * A user fact needs a real account uid.
   */
  public function testUserScopeRequiresAccount(): void {
    $account = $this->makeUser('alex');

    $fact = $this->memory()->remember('Alex prefers email.', 'user', (string) $account->id());
    $this->assertSame((int) $account->id(), (int) $fact->get('user')->target_id);

    foreach ([NULL, '', '9999', 'alex'] as $bad) {
      try {
        $this->memory()->remember('Nobody.', 'user', $bad);
        $this->fail('Expected a rejected subject: ' . var_export($bad, TRUE));
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * An unknown scope is rejected.
   */
  public function testUnknownScopeRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->memory()->remember('Text.', 'nonsense');
  }

  /**
   * Entity-scope view access mirrors the referenced entity's own.
   */
  public function testEntityScopeFollowsTargetAccess(): void {
    $target = $this->makeUser('target');
    $fact = $this->memory()->remember('About the target.', 'entity', NULL, 'test', targetType: 'user', targetId: (string) $target->id());

    $this->grant('profiles', ['access user profiles']);
    $this->assertTrue($fact->access('view', $this->makeUser('seer', ['profiles'])));
    $this->assertFalse($fact->access('view', $this->makeUser('blind')));

    // A dangling target is neutral, so nobody is granted access.
    $orphan = $this->memory()->remember('About nothing.', 'entity', NULL, 'test', targetType: 'user', targetId: '9999');
    $this->assertFalse($orphan->access('view', $this->makeUser('seer2', ['profiles'])));
  }

  /**
   * User-scope visibility: the role matrix, then the shared-role fallback.
   */
  public function testUserScopeRoleVisibility(): void {
    $this->grant('staff', []);
    $this->grant('manager', []);
    $this->grant('other', []);
    $subject = $this->makeUser('subject', ['staff']);
    $fact = $this->memory()->remember('Subject fact.', 'user', (string) $subject->id());

    $manager = $this->makeUser('boss', ['manager']);
    $peer = $this->makeUser('peer', ['staff']);
    $stranger = $this->makeUser('stranger', ['other']);

    $this->setSettings([
      'user_scope_role_visibility' => ['manager' => ['staff']],
      'user_scope_shared_role_fallback' => FALSE,
    ]);
    $this->assertTrue($fact->access('view', $manager), 'matrix pairing grants');
    $this->assertFalse($fact->access('view', $peer), 'fallback off: shared role alone is not enough');
    $this->assertFalse($fact->access('view', $stranger));

    // The access handler caches results per entity and account.
    $this->container->get('entity_type.manager')->getAccessControlHandler('aim_fact')->resetCache();
    $this->setSettings(['user_scope_shared_role_fallback' => TRUE]);
    $this->assertTrue($fact->access('view', $peer), 'fallback on: shared real role grants');
    $this->assertFalse($fact->access('view', $stranger), 'the authenticated role alone is not shared');
  }

  /**
   * Expiry retires past-due facts only, and only once.
   */
  public function testRetireExpired(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $due = $this->plainFact('Due.', $now - 100);
    $later = $this->plainFact('Later.', $now + 86400);
    $forever = $this->plainFact('Forever.', NULL);

    $this->assertSame(1, $this->memory()->retireExpired());
    $this->assertNotEmpty($this->reload($due)->get('retired')->value);
    $this->assertEmpty($this->reload($later)->get('retired')->value);
    $this->assertEmpty($this->reload($forever)->get('retired')->value);

    $this->assertSame(0, $this->memory()->retireExpired());
  }

  /**
   * An already-past expiry is refused at write time.
   */
  public function testRememberRejectsPastExpiry(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->memory()->remember('Late.', 'site', NULL, 'test', expires: 1000);
  }

  /**
   * Returns the memory manager.
   *
   * @return \Drupal\aim\Service\AimMemoryManager
   *   The service.
   */
  protected function memory() {
    return $this->container->get('aim.memory_manager');
  }

  /**
   * Reads the scope config every scope submodule ships.
   *
   * @return array[]
   *   Scope config arrays keyed by scope ID.
   */
  protected function shippedScopes(): array {
    $scopes = [];
    $list = $this->container->get('extension.list.module');
    foreach (['user', 'role', 'site', 'case', 'entity'] as $id) {
      $file = $list->getPath('aim_scope_' . $id) . '/config/install/aim.aim_scope.' . $id . '.yml';
      $scopes[$id] = Yaml::decode(file_get_contents($file));
    }
    return $scopes;
  }

  /**
   * Creates a role with the given permissions.
   *
   * @param string $id
   *   The role ID.
   * @param string[] $permissions
   *   Permissions to grant.
   */
  protected function grant(string $id, array $permissions): void {
    $role = Role::create(['id' => $id, 'label' => $id]);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
  }

  /**
   * Creates an active user.
   *
   * @param string $name
   *   The username.
   * @param string[] $roles
   *   Role IDs.
   *
   * @return \Drupal\user\Entity\User
   *   The saved user.
   */
  protected function makeUser(string $name, array $roles = []): User {
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $user->save();
    return $user;
  }

  /**
   * Merges values into aim.settings.
   *
   * @param array $values
   *   Top-level keys to set.
   */
  protected function setSettings(array $values): void {
    $config = $this->config('aim.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Saves a site fact with an expiry, bypassing remember()'s past-date check.
   *
   * @param string $text
   *   The fact text.
   * @param int|null $expires
   *   The expiry timestamp, or NULL.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The saved fact.
   */
  protected function plainFact(string $text, ?int $expires): AimFact {
    $fact = AimFact::create(['scope' => 'site', 'text' => $text, 'expires' => $expires]);
    $fact->save();
    return $fact;
  }

  /**
   * Reloads a fact from storage.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The fresh copy.
   */
  protected function reload(AimFact $fact): AimFact {
    $this->container->get('entity_type.manager')->getStorage('aim_fact')->resetCache([$fact->id()]);
    return AimFact::load($fact->id());
  }

}
