<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AimScopeAccess;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\aim\Entity\AimFact;

/**
 * Interface for AimScopeAccess plugins.
 *
 * A plugin supplies extra view-access logic for one aim_scope bundle,
 * beyond the flat "view {scope} aim facts" permission. Plugins are placed
 * in src/Plugin/AimScopeAccess of any enabled module and declare the
 * #[AimScopeAccess] attribute; discovery is automatic, keyed by the
 * aim_scope ID the plugin handles.
 *
 * @see \Drupal\aim\Attribute\AimScopeAccess
 * @see \Drupal\aim\AimScopeAccessPluginManager
 */
interface AimScopeAccessInterface {

  /**
   * Checks whether $account may view $fact, beyond the flat permission.
   *
   * AimFactAccessControlHandler ORs this against the flat "view {scope}
   * aim facts" permission, so an implementation should only ever return
   * allowed or neutral, never forbidden - a plugin has no way to revoke a
   * grant it knows nothing about.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact being checked, whose bundle matches this plugin's ID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account requesting view access.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed if this plugin's own logic grants access, neutral otherwise.
   */
  public function checkViewAccess(AimFact $fact, AccountInterface $account): AccessResultInterface;

}
