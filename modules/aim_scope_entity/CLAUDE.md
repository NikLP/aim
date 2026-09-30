# CLAUDE.md — AIM Scope: Entity

Part of the [aim](../../CLAUDE.md) project - see the root module's
CLAUDE.md for environment, architecture decisions, and the code-style/git
rules shared across all of `aim`'s submodules; this file only covers what
is specific to `aim_scope_entity`.

## What lives here

- `config/install/aim.aim_scope.entity.yml` - the `entity` `aim_scope`
  config entity (`plugin: entity`). No ThirdPartySetting and no
  `settings` of its own - this scope has no config-shaped flag or
  instance setting to carry, only behavior and fields.
- `src/Plugin/AimScopeType/AimScopeEntity.php` - the
  `AimScopeTypeInterface` plugin (ADR-0025, this scope built in
  ADR-0027). `checkViewAccess()` loads the entity named by the fact's
  `target_type`/`target_id` and returns that entity's own
  `access('view', $account, TRUE)` result, inheriting its cacheability for
  free. Neutral (never forbidden) when the target is missing, unknown, or
  fails to load. `defaultSubject()` returns `NULL` - a referenced entity
  can't be auto-minted. `defaultSettings()`/`buildSettingsForm()` are
  both no-ops - nothing to configure per instance yet.
  `getBaseFieldDefinitions()` declares `target_type`/`target_id`
  themselves (ADR-0028 piece 1, moved out of core `aim`'s
  `AimFact::baseFieldDefinitions()` - see "Field ownership" below).
- `aim_scope_entity.install` - `hook_install()` calls
  `installFieldStorageDefinition()` for both fields, using this same
  plugin's `getBaseFieldDefinitions()` so the two never drift apart. No
  `hook_uninstall()`: core's own `ModuleInstaller::uninstall()` already
  removes any field whose provider matches the module being uninstalled
  - a hand-written one duplicating that call was tried and found to race
  it (see ADR-0028's Consequences for the exact crash).

## Field ownership - corrected by ADR-0028, was wrong in ADR-0027

ADR-0027 originally put `target_type`/`target_id` in core `aim`'s own
`AimFact::baseFieldDefinitions()`, reasoning that a base field's type
must resolve whether or not this module is installed and therefore
"has to live in core." [ADR-0028](../../adr/resolved/0028-scope-type-plugin.md)
found that reasoning conflated two different things: a base field
declared via `hook_entity_base_field_info()` **by a different module**
is just as always-present and just as invisible to
`ai_vdb_provider_mariadb`'s `isMultiple()`/core's `EntityViewsData` as
one declared in the entity's own class (verified by reading both
call sites directly - both key off `EntityFieldManager::
getFieldStorageDefinitions()`, which doesn't distinguish by declaring
module). The fields moved here; core `aim` implements one generic
`hook_entity_base_field_info()` (`AimHooks::entityBaseFieldInfo()`) that
merges every registered scope type's own fields, this one included.

**One easy-to-miss requirement if this pattern is copied for a future
scope type**: each returned `BaseFieldDefinition` must call
`->setProvider('aim_scope_entity')` on itself. Since the merge point
lives in core `aim`, not here, `EntityFieldManager`'s own merge logic
would otherwise stamp every field with `aim` as its provider - it only
defers to an already-set provider, never infers one from which plugin
built the definition. Get this wrong and `hook_install()`/core's
generic uninstall pass both still "work" on the surface, but field
ownership (and anything that keys off it, like the uninstall pass
itself) silently points at the wrong module.

## `drupal/dynamic_entity_reference` - still not adopted, now a real option

A core `entity_reference` field needs one fixed `target_type` per field
instance, so it can't hold "any entity type" - which is exactly why
`dynamic_entity_reference` exists as a contrib solution. `aim_fact`
already learned the hard way (see root DEVELOPING.md's "Scope/bundle
model") that a scope-specific field can't be a *bundle* field on
`aim_fact` - it breaks `ai_vdb_provider_mariadb`'s `isMultiple()` and
core's `EntityViewsData`; that lesson is real and unaffected by the
correction above (it's specific to *bundle*-conditional fields, not to
which module declares a base field). ADR-0027 additionally rejected
`dynamic_entity_reference` as this module's own dependency on the belief
that the field had to live in core `aim`, ruling out a submodule-scoped
package dependency - **that specific reasoning no longer holds now that
the fields live here**, so adopting `dynamic_entity_reference` for its
autocomplete widget is a real, undecided option again, not foreclosed.
Not done - `target_type`/`target_id` are still two plain `string` base
fields, resolved by hand in `AimScopeEntity::checkViewAccess()`, at the
cost of no autocomplete widget on the admin form. Full reasoning in
[ADR-0027](../../adr/resolved/0027-entity-scope.md) (the original rejection) and
[ADR-0028](../../adr/resolved/0028-scope-type-plugin.md) (the correction).

## "Replace, not widen" - what that means in practice

`AimFactAccessControlHandler::checkAccess()` always ORs a scope's plugin
result against the flat `view {scope} aim facts` permission - that's not
something a plugin can opt out of. This scope gets "replace" behavior
(the referenced entity's own access is the only real grant) simply by
this module never granting `view entity aim facts` to any role itself -
same lever `aim_scope_user`'s docs already describe for the opposite
("widen") case. A site that wants the flat permission to also work as a
broad override for this scope can grant it to a role; nothing in the
plugin needs to change either way.
