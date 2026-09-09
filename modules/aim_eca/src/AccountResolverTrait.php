<?php

declare(strict_types=1);

namespace Drupal\aim_eca;

use Drupal\Core\Session\AccountInterface;

/**
 * Resolves a uid or username to a real user account.
 *
 * A scope=user aim_fact must be about an account that actually exists on
 * this site (CLAUDE.md, "User-scope facts now require a real account") -
 * shared by every aim_eca plugin that accepts a Subject value, so they
 * resolve it the same way drush aim:remember/aim:extract do rather than
 * writing or querying against a free-text string that merely looks like a
 * uid.
 *
 * Delegates to \Drupal\aim\Service\AimMemoryManager::resolveAccount() via
 * the service container rather than constructor injection: eca's
 * ActionBase/ConditionBase both declare a `final __construct()` (CLAUDE.md,
 * "ECA integration"), so a plugin extending either cannot add a new
 * injected constructor argument. The service locator call here is the
 * pragmatic way around that, not a stylistic choice.
 */
trait AccountResolverTrait {

  /**
   * Resolves a uid or username to a real user account.
   *
   * @param string $value
   *   A numeric uid, or an account name.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The matching account, or NULL if none exists.
   */
  protected function resolveAccount(string $value): ?AccountInterface {
    return \Drupal::service('aim.memory_manager')->resolveAccount($value);
  }

}
