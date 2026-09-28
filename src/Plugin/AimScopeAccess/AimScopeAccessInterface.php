<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AimScopeAccess;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\aim\Entity\AimFact;

/**
 * Interface for AimScopeAccess plugins.
 *
 * A plugin supplies scope-specific behavior for one aim_scope bundle:
 * extra view-access logic beyond the flat "view {scope} aim facts"
 * permission (checkViewAccess()), and/or a default subject for a new
 * fact of this scope (defaultSubject()). Plugins are placed in
 * src/Plugin/AimScopeAccess of any enabled module and declare the
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
   * grant it knows nothing about. This is a deliberate choice, not a
   * technical ceiling (AccessResult::orIf() does let forbidden win over
   * allowed) - see TODO.md's "Governance/scope design thread" if a scope
   * ever needs to narrow the flat permission rather than widen it.
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

  /**
   * Returns a default subject for a new fact of this scope, if any.
   *
   * Called by AimMemoryManager::remember() only when the caller omitted
   * subject and this scope doesn't require a real account (that case is
   * handled separately, see scopeRequiresAccount()). Most scopes have
   * nothing sensible to default to and should return NULL, leaving
   * subject empty as before this method existed.
   *
   * @return string|null
   *   A default subject value, or NULL if this scope has none.
   */
  public function defaultSubject(): ?string;

}
