<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AimScopeType;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\aim\Entity\AimFact;

/**
 * Interface for AimScopeType plugins.
 *
 * A plugin is everything specific to one kind of scope: extra view access
 * (checkViewAccess()), the stored subject (resolveSubject()), base fields
 * on aim_fact (getBaseFieldDefinitions()) and its settings
 * (defaultSettings()/buildSettingsForm()). Each aim_scope config names
 * the plugin it uses in `plugin` (or none), and the manager hands the
 * plugin that scope's `settings` as its configuration
 * (AimScopeTypePluginManager::getTypePlugin()). aim ships the user, case
 * and entity plugins; another module adds one in its own
 * src/Plugin/AimScopeType with the #[AimScopeType] attribute (ADR-0063).
 *
 * @see \Drupal\aim\Attribute\AimScopeType
 * @see \Drupal\aim\AimScopeTypePluginManager
 */
interface AimScopeTypeInterface {

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
   *   The fact being checked, of a scope that uses this plugin.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account requesting view access.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed if this plugin's own logic grants access, neutral otherwise.
   */
  public function checkViewAccess(AimFact $fact, AccountInterface $account): AccessResultInterface;

  /**
   * Returns the subject a new fact of this scope is stored under.
   *
   * Called by AimHooks::factPresave() for every new fact (and an edited
   * subject), whatever the write path, after the caller's own subject
   * (empty when omitted) is in place. A scope that requires a real account
   * keeps subject empty (see scopeRequiresAccount()). Most scopes return
   * the subject unchanged; the case plugin mints an ID for an empty one
   * and turns a name into one.
   *
   * @param string $subject
   *   The subject the caller gave, or an empty string.
   * @param string $scopeId
   *   The ID of the aim_scope the fact belongs to.
   *
   * @return string
   *   The subject to store.
   */
  public function resolveSubject(string $subject, string $scopeId): string;

  /**
   * Returns base field definitions this scope type needs on aim_fact.
   *
   * Merged by AimHooks::entityBaseFieldInfo() from every plugin in code,
   * so the columns exist whether or not a scope of this type is enabled.
   * A plugin in another module must call ->setProvider() with its own
   * module on each definition, or the merge stamps it with aim, and must
   * install the field storage in its hook_install(); core's uninstall
   * removes it. Most types return an empty array.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition[]
   *   Field definitions keyed by field name.
   */
  public function getBaseFieldDefinitions(): array;

  /**
   * Returns default settings for a scope of this type.
   *
   * Used for a new scope, and under any setting a saved scope lacks.
   *
   * @return array
   *   Default settings, keyed by setting name.
   */
  public function defaultSettings(): array;

  /**
   * Builds the settings sub-form AimScopeForm renders for this type.
   *
   * Called with $form already namespaced under 'settings' (so element
   * keys need no further prefixing) and rebuilt via AJAX whenever the
   * admin changes AimScopeForm's type selector. A type with no settings
   * of its own (most of them) returns $form unchanged.
   *
   * @param array $form
   *   The 'settings' sub-form array to add elements to.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the parent AimScopeForm.
   * @param array $settings
   *   The instance's current settings, to prefill #default_value.
   *
   * @return array
   *   The built sub-form.
   */
  public function buildSettingsForm(array $form, FormStateInterface $form_state, array $settings): array;

}
