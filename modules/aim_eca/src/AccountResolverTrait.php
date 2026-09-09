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
    $storage = $this->entityTypeManager->getStorage('user');

    if (ctype_digit($value)) {
      $account = $storage->load((int) $value);
      return $account instanceof AccountInterface ? $account : NULL;
    }

    $accounts = $storage->loadByProperties(['name' => $value]);
    $account = reset($accounts);
    return $account instanceof AccountInterface ? $account : NULL;
  }

}
