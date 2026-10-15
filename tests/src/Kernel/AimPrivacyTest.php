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
 * Who recall() shows a scope=user fact to.
 *
 * Runs recall() through entity access, as the real search backend does, with
 * accounts that are not uid 1. Some of these pin behavior that is by design
 * today but is not privacy in the strong sense (an administrator role sees
 * every user's facts); they exist so a change there is a decision, not an
 * accident. See TODO.md's "Privacy review".
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimPrivacyTest extends KernelTestBase {

  use FakeVectorSearchTrait;

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
    'aim_scope_user',
    'aim_scope_site',
  ];

  /**
   * The account the private fact is about.
   */
  protected User $subject;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('literal');
    $this->installEntitySchema('aim_fact');

    $path = $this->container->get('extension.list.module')->getPath('aim');
    $this->config('aim.settings')
      ->setData(Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml')))
      ->save();

    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    AimScope::create([
      'id' => 'user',
      'label' => 'User',
      'plugin' => 'user',
      'settings' => ['requires_account' => TRUE],
    ])->save();
    $this->installFakeVectorIndex();

    // Uid 1 bypasses every access check; keep it out of the way.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();

    Role::create(['id' => 'member', 'label' => 'Member'])->save();
    Role::create(['id' => 'staff', 'label' => 'Staff'])->save();
    $this->subject = $this->account('alex', ['member']);
    AimFact::create(['scope' => 'site', 'text' => 'Open at nine.', 'trusted' => TRUE, 'uid' => 1])->save();
    AimFact::create([
      'scope' => 'user',
      'user' => $this->subject->id(),
      'text' => 'Alex has a private note.',
      'trusted' => TRUE,
      'uid' => 1,
    ])->save();
  }

  /**
   * A viewer with only the site permission never sees another user's fact.
   */
  public function testStrangerSeesOnlySiteFacts(): void {
    $this->grant('staff', ['view site aim facts']);
    $this->config('aim.settings')->set('user_scope_shared_role_fallback', FALSE)->save();

    $this->actAs($this->account('sam', ['staff']));
    $this->assertSame(['Open at nine.'], $this->texts());
  }

  /**
   * Sharing a role shows the fact while the shared-role fallback is on.
   */
  public function testSharedRoleFallbackShowsFact(): void {
    $this->grant('member', ['view site aim facts']);

    $this->actAs($this->account('sam', ['member']));
    $this->assertContains('Alex has a private note.', $this->texts());
  }

  /**
   * With the fallback off, sharing a role is not enough.
   */
  public function testSharedRoleWithoutFallbackHidesFact(): void {
    $this->grant('member', ['view site aim facts']);
    $this->config('aim.settings')->set('user_scope_shared_role_fallback', FALSE)->save();

    $this->actAs($this->account('sam', ['member']));
    $this->assertNotContains('Alex has a private note.', $this->texts());
  }

  /**
   * The role-visibility matrix shows the fact to a viewer role it names.
   */
  public function testMatrixShowsFactToNamedViewerRole(): void {
    $this->grant('staff', ['view site aim facts']);
    $this->config('aim.settings')
      ->set('user_scope_shared_role_fallback', FALSE)
      ->set('user_scope_role_visibility', ['staff' => ['member']])
      ->save();

    $this->actAs($this->account('sam', ['staff']));
    $this->assertContains('Alex has a private note.', $this->texts());
  }

  /**
   * Pins today's behavior: an administrator role sees every user's facts.
   *
   * By design (admin roles hold every permission), and the gap George
   * Kastanis flagged: hiding personal notes even from admins needs its own
   * rule. If that rule is built, this test should change with it.
   */
  public function testAdministratorRoleSeesPrivateFact(): void {
    $admin = Role::create(['id' => 'site_admin', 'label' => 'Site admin']);
    $admin->setIsAdmin(TRUE)->save();
    $this->config('aim.settings')->set('user_scope_shared_role_fallback', FALSE)->save();

    $this->actAs($this->account('boss', ['site_admin']));
    $this->assertContains('Alex has a private note.', $this->texts());
  }

  /**
   * An account with no aim permissions recalls nothing.
   */
  public function testNoPermissionsSeesNothing(): void {
    $this->actAs($this->account('nobody', []));
    $this->assertSame([], $this->texts());
  }

  /**
   * Pins today's behavior: skipping the access check (Drush) shows everything.
   */
  public function testBypassShowsEverything(): void {
    $this->actAs($this->account('nobody', []));
    $rows = $this->container->get('aim.recall')->recall('anything', NULL, NULL, NULL, 10, accessCheck: FALSE);
    $this->assertCount(2, $rows);
  }

  /**
   * Creates an account holding the given roles.
   *
   * @param string $name
   *   The username.
   * @param string[] $roles
   *   Role IDs.
   *
   * @return \Drupal\user\Entity\User
   *   The saved account.
   */
  protected function account(string $name, array $roles): User {
    $account = User::create(['name' => $name, 'status' => 1, 'roles' => $roles]);
    $account->save();
    return $account;
  }

  /**
   * Adds permissions to a role.
   *
   * @param string $role
   *   The role ID.
   * @param string[] $permissions
   *   Permissions to grant.
   */
  protected function grant(string $role, array $permissions): void {
    $entity = Role::load($role);
    foreach ($permissions as $permission) {
      $entity->grantPermission($permission);
    }
    $entity->save();
  }

  /**
   * Makes an account the current user.
   *
   * @param \Drupal\user\Entity\User $account
   *   The account.
   */
  protected function actAs(User $account): void {
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * Recalls through entity access and returns the texts.
   *
   * @return string[]
   *   The recalled texts.
   */
  protected function texts(): array {
    return array_column($this->container->get('aim.recall')->recall('anything', NULL, NULL, NULL, 10), 'text');
  }

}
