# ADR-0027: `scope: entity` - facts about an arbitrary Drupal entity

**Status:** Built and verified 2026-09-29, including CLI/Tool API wiring
(`aim:remember --target-type/--target-id`, its `--file` batch path, and
`aim_tool`'s `AimRemember` plugin) - see "Not yet built" below for what's
still deliberately left out. `AimScopeAccessInterface`, implemented below
by `AimScopeEntity`, was renamed `AimScopeTypeInterface` later the same
day ([ADR-0028](0028-scope-type-plugin.md) piece 0). **This ADR's
Decision section below also states `target_type`/`target_id` "has to
stay an always-present base field... not a field owned by a scope
submodule" - that base-field-ownership reasoning was wrong and is
corrected by ADR-0028 (piece 1, also built 2026-09-29): the fields moved
into `aim_scope_entity`'s own `AimScopeEntity::getBaseFieldDefinitions()`
the same day. Left as originally written below for the record; treat
ADR-0028 as authoritative on where these fields actually live.**
**Date:** 2026-09-29

## Context

[ADR-0024](../0024-annotations-integration-target-scoped-promotion.md)'s
discussion proposed a `scope: entity` bundle (a fact pointing at an
arbitrary Drupal entity) whose natural view-access rule is "can this
account view the referenced entity" - not expressible as the flat
per-bundle permission `site`/`role` already use. [ADR-0025](0025-scope-access-plugin-type.md)
named this as one of the two still-unbuilt data points its "three
scopes need custom access logic" argument rested on (the other being
`case`, still unbuilt). This ADR builds it.

**Field mechanism.** A core `entity_reference` field requires one fixed
`target_type` per field instance - it cannot point at "any entity type"
in a single field. `drupal/dynamic_entity_reference` solves this
generically with its own field type, widget, formatter, and Views
integration. Checked against this codebase's own history before picking
either: [DEVELOPING.md](../../DEVELOPING.md)'s "Scope/bundle model" records
that per-bundle fields on `aim_fact` were tried once and reverted -
`ai_vdb_provider_mariadb`'s `AiVdbProviderClientBase::isMultiple()`
assumes every field is a base field, and core's `EntityViewsData`
degrades Views columns to `Broken` handlers otherwise. Whatever field
holds the reference, it has to stay an always-present base field in core
`aim` (the `subject`/`user` pattern), not a field owned by a scope
submodule - `aim` doesn't get to reopen that lesson for one bundle's
convenience.

That constrains the dependency question raised discussing this ADR: Nik
asked whether a `dynamic_entity_reference` dependency could be scoped to
just the new submodule rather than core `aim`. It can't, precisely
because the field must live in `AimFact::baseFieldDefinitions()` (core)
to stay a base field rather than a bundle field - a base field's type
must be resolvable whether or not the owning submodule is installed.
Pulling a new package dependency into core `aim`, for the benefit of one
scope submodule that might not even be installed, is the wrong trade.
`dynamic_entity_reference`'s field type, widget, formatter, and Views
integration are also more than this needs - `aim_fact` isn't
end-user-administered through an entity-reference autocomplete for "any
entity"; facts are agent-written. Hand-rolling the reference as two
plain base fields sidesteps both problems at once, at the cost of no
autocomplete widget - a real cost, deferred, see below.

## Decision

**Two new always-present base fields on `aim_fact`, in core `aim`:**
`target_type` (string, max length 32, the Drupal entity type ID, e.g.
`node`) and `target_id` (string, max length 255, the referenced entity's
ID - string rather than integer since not every entity type keys on an
integer, e.g. config entities). Empty and unused on every scope but
`entity`, exactly the `subject`/`user` precedent. No new package
dependency, in core `aim` or anywhere else.

**New submodule `aim_scope_entity`**, same shape as `aim_scope_case`:
ships `config/install/aim.aim_scope.entity.yml` (an `enforced` module
dependency on itself, for `ScopeUninstallValidator` to find it) and
`src/Plugin/AimScopeAccess/AimScopeEntity.php` - the second real
`AimScopeAccessInterface` implementation (`AimScopeUser` was the first).
No ThirdPartySetting - unlike `aim_scope_user`'s `requires_account`, this
scope has no config-shaped flag to carry, only behavior.

**`AimScopeEntity::checkViewAccess()` loads the referenced entity and
returns its own view-access result directly** -
`$target->access('view', $account, TRUE)` - with no further logic and no
attempt to OR anything in itself. `$account`'s access to the fact
therefore tracks the target entity's own access model whatever that is
(published/unpublished, per-role, per-user grants, a bespoke access
handler on the target's entity type), for free, and inherits that
entity's own cacheability metadata automatically (`access(...,
$return_as_object = TRUE)` already folds in the entity's cache tags and
any contexts its own access handler added - no manual
`CacheableMetadata` assembly needed, unlike `AimScopeUser`'s
`user.roles` context). Missing/empty `target_type`/`target_id`, an
unknown entity type, or a target that fails to load all return
`AccessResult::neutral()` - the same "allowed or neutral, never
forbidden" contract every `AimScopeAccessInterface` plugin follows,
satisfied here by construction rather than by instruction.

**Nik's "replace, not widen" call, and what it actually means given
`AimFactAccessControlHandler` today.** `checkAccess()`
(`src/AimFactAccessControlHandler.php:51-58`) unconditionally ORs
whatever the scope's plugin returns against the flat `view {scope} aim
facts` permission - that's a handler-level combinator, not a per-plugin
choice, and no plugin can opt out of it by itself. "Replace" for
`scope: entity` is therefore the same pattern `AimScopeUser`'s own docs
already describe for narrowing: **don't grant `view entity aim facts`
broadly**. With nobody holding that permission, the OR is against a
result that's never allowed, so in practice only the plugin's own
referenced-entity check grants access - the plugin code itself doesn't
need to know or care that this is the intent. A site that instead wants
"widen" behavior for this scope (flat permission OR referenced-entity
access, `AimScopeUser`'s shape) gets it by granting the permission
broadly instead - no code difference either way. Confirms the answer to
Nik's second question directly: the combinator a scope ends up with is
an administrative/config choice (who holds the flat permission), not
something baked per-plugin, and every scope in this codebase can pick
independently by that same lever. Genuine narrowing (AND - a flat
permission holder still *denied* without referenced-entity access) is
not achievable this way and stays the open question TODO.md's
"Governance/scope design thread" already tracks - unchanged by this ADR.

**`defaultSubject()` returns `NULL`** - a referenced entity can't be
auto-minted the way `case` mints a subject ID; a caller has to supply
`target_type`/`target_id` explicitly.

## Not yet built

- **CLI/Tool wiring - BUILT 2026-09-29, same day, once asked why it
  wasn't already done.** Turned out to be a small, well-patterned
  addition, not a real blocker: `AimMemoryManager::remember()` gained
  trailing `?string $targetType = NULL, ?string $targetId = NULL`
  parameters (backward compatible - every existing positional caller is
  unaffected), `aim:remember` gained `--target-type`/`--target-id`
  options wired through both the single-fact and `--file` batch paths,
  and `aim_tool`'s `AimRemember` Tool API plugin gained matching
  `target_type`/`target_id` inputs on both the single-call and `facts`
  batch shapes. Verified live via all three paths (drush single, drush
  `--file`, and a direct `plugin.manager.tool` invocation of
  `aim_remember`), each producing a fact with the right
  `target_type`/`target_id` stored, then cleaned up. The admin form and a
  direct `AimFact::create([...])->save()` call remain valid ways to create
  one too.
- **Write-time validation that an entity-scope fact actually carries a
  target.** Now that CLI/Tool wiring exists, omitting `--target-type`/
  `--target-id` (or the Tool inputs) is exactly the easy accidental
  mistake flagged below as the trigger to revisit this - but still not
  built this pass. ADR-0007/ADR-0026 built `requires_account` (a
  ThirdPartySetting + `AimMemoryManager::scopeRequiresAccount()`) for
  exactly this shape of problem on `scope: user`, but reusing it here
  would mean generalizing a mechanism named and shaped around one field
  pair to a different one, for a second scope, before any third case
  exists - the same "don't abstract before it's earned" call ADR-0025
  itself made about the access plugin type. Today a `scope: entity` fact
  saved with no target simply gets a `checkViewAccess()` of `neutral()`
  forever (falls back to the flat permission alone) - an unhelpful UX for
  a malformed fact, not a security gap.
- **Autocomplete/reference-picker UX for `target_type`/`target_id`** on the
  admin add/edit form - the cost of not depending on
  `dynamic_entity_reference`, accepted deliberately above. A custom widget
  pairing an entity-type select with a `dynamic_entity_reference`-less
  autocomplete is possible later without changing the storage shape.
- **`aim_annotations` bridge module itself** ([ADR-0024](../0024-annotations-integration-target-scoped-promotion.md))
  - this ADR clears one of its two blockers (`scope: entity` existing with
    a working access plugin); the `trusted`/draft-to-trusted blocker is
    separately already resolved (see ADR-0002's addendum); the bridge
    module's write path is still unbuilt.

## Consequences / risks

- Storage-neutral: `aim_fact` stays one entity/table, no new
  package dependency anywhere in `aim` or its submodules.
- `target_type`/`target_id` join `subject`/`user` as base fields carrying
  scope-specific meaning only some bundles use - accepted cost, same
  precedent, not a new pattern.
- A site that enables `aim_scope_entity` without changing the default
  permission grants gets "replace" semantics automatically, since
  `view entity aim facts` isn't granted to any role by `aim_scope_entity`
  itself (no `config/install/user.role.*.yml` - permissions are opt-in,
  same as every other scope). Worth stating plainly since it's easy to
  assume the flat permission is the primary grant path by analogy with
  `site`/`role`, when for this scope it's deliberately the opposite.
- Verified live 2026-09-29: an `aim_fact` created with `scope: entity`,
  `target_type: node`, `target_id: <a real unpublished node's id>` -
  an account holding that node's own view grant (owner, or a role with
  `view own unpublished content`) could view the fact; an account with
  neither that grant nor `view entity aim facts` could not; granting the
  flat permission restored access for everyone, confirming the
  handler's OR still works exactly as documented above.
- Verified live 2026-09-29: all three write paths (drush `aim:remember
  --target-type/--target-id`, its `--file` batch equivalent, and
  `plugin.manager.tool`'s `aim_remember` invoked directly) each produced
  a fact with the correct `target_type`/`target_id` in the database.
