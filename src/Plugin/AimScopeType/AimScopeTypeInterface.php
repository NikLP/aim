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
 * A plugin supplies everything specific to one scope *type*: extra
 * view-access logic beyond the flat "view {scope} aim facts" permission
 * (checkViewAccess()), a default subject for a new fact of this scope
 * (defaultSubject()), the base fields this type needs on aim_fact
 * (getBaseFieldDefinitions()), and the instance-level settings a scope of
 * this type takes (defaultSettings()/buildSettingsForm()) - ADR-0028
 * widened this from an access-only seam (ADR-0025) to the full definition
 * of a scope type's shape, once ADR-0027's target_type/target_id showed
 * fields belong with the plugin that needs them, not hardcoded in core.
 * Plugins are placed in src/Plugin/AimScopeType of any enabled module and
 * declare the #[AimScopeType] attribute; discovery is automatic. Since
 * ADR-0028 piece 3, the plugin ID and the aim_scope config entity ID it
 * serves are no longer required to match - see AimScope::get('plugin')
 * and AimScopeTypePluginManager::getTypePlugin().
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

  /**
   * Returns base field definitions this scope type needs on aim_fact.
   *
   * Merged by aim's hook_entity_base_field_info() (AimHooks) with every
   * other registered type's fields into aim_fact's single base table -
   * the columns exist regardless of which plugin happens to be servicing
   * a given request, but the code and dependency for a field lives with
   * the plugin that declared it (ADR-0028 piece 1). Called once per
   * plugin *type*, not once per aim_scope instance - two instances of
   * the same type (ADR-0028 piece 3) share one set of columns. A type
   * with no fields of its own (most of them) returns an empty array.
   *
   * Each returned definition MUST call ->setProvider('your_module') on
   * itself. Since every plugin's fields are merged through one shared
   * hook_entity_base_field_info() implementation (living in core aim,
   * not each plugin's own module), Drupal's own merge logic
   * (EntityFieldManager::buildBaseFieldDefinitions()) would otherwise
   * stamp every field with aim as its provider - it only defers to an
   * already-set provider, never infers one from which plugin built the
   * definition. See AimScopeEntity::getBaseFieldDefinitions() for the
   * reference implementation.
   *
   * The owning module's hook_install() must call
   * \Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition()
   * for each field this method declares - the physical column does not
   * appear on its own just because the field is now merged into
   * aim_fact's live definitions. No matching hook_uninstall() is needed:
   * core's own \Drupal\Core\Extension\ModuleInstaller::uninstall()
   * already calls uninstallFieldStorageDefinition() generically for any
   * field whose getProvider() matches the module being uninstalled - a
   * hand-written hook_uninstall() doing the same call races that generic
   * pass and crashes (verified 2026-09-29: the manual call drops the
   * column first, then core's pass finds the still-code-declared field
   * and queries the now-missing column). See
   * aim_scope_entity.install for the reference hook_install().
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition[]
   *   Field definitions keyed by field name.
   */
  public function getBaseFieldDefinitions(): array;

  /**
   * Returns default settings for a new aim_scope instance of this type.
   *
   * Read into the instance's own `settings` property (AimScope) when a
   * scope of this type is first created via AimScopeForm's type
   * selector. A type with no settings of its own (most of them) returns
   * an empty array - this does not replace an existing
   * ThirdPartySetting-based config flag on its own (see
   * aim_scope_user's requires_account, still ThirdPartySetting-based -
   * ADR-0028 treats migrating it to this mechanism as a nice-to-have,
   * not a requirement).
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
