# ADR-0028: Widen the scope-access plugin into a scope-type plugin - fields, settings, and behavior in one seam

**Status:** Built and verified 2026-09-29 - pieces 0-3 (see "Decision"
below). Piece 4, added the same day once pieces 1-3 exposed it, is design
only - see its own entry.
**Date:** 2026-09-29

## Context

Nik's intended shape for the scope system, stated directly: a base table
with a handful of always-present fields; install a scope submodule;
**add a scope by picking that submodule's type in a form**, which also
asks for type-specific settings (a prefix, which entity types it can
reference) and a name; the relevant columns get added to the database
for that type; `recall()` queries generically off the base fields;
`remember()` farms its type-specific work out to the scope's own class;
an entity-scope type owns `drupal/dynamic_entity_reference` as its own
dependency, fields, and widget; a scope module can be uninstalled
cleanly once nothing uses it any more.

What ADR-0025/0026/0027 actually built is narrower. `AimScope`
(`src/Entity/AimScope.php`) is `id`/`label` only, and each of
`aim_scope_user`/`role`/`site`/`case`/`entity` ships exactly one
hardcoded instance via `config/install/aim.aim_scope.<id>.yml` - a
submodule provides one scope, not a scope *type* a form can offer
repeatedly. `AimScopeForm` (`src/Form/AimScopeForm.php`) confirmed to
carry only `label` and `id` - no type selector, no settings of any kind.
Config-shaped per-scope differences (`aim_scope_user`'s
`requires_account`) live as one-off ThirdPartySettings keys, each
documented only in code comments, not a settings schema tied to a type.
Behavioral differences go through a real plugin type (originally named
`AimScopeAccessInterface`/`AimScopeAccessPluginManager`, see "Piece 0"
below for its rename) - but that interface is deliberately narrow
(`checkViewAccess()`, `defaultSubject()` only, ADR-0025's own "don't
abstract before it's earned" call), and was never asked to own field
declarations or settings.

**The concrete mistake, isolated.** ADR-0026's Context correctly
identifies that *bundle* fields (`hook_entity_bundle_field_info()`/Field
API config fields scoped to one bundle) broke two things when tried on
`subject`/`subject_uid`: `ai_vdb_provider_mariadb`'s
`AiVdbProviderClientBase::isMultiple()`, which assumes every field is a
base field, and core's `EntityViewsData`, which degraded the bundle
field's Views column to a `Broken` handler. That revert stands - nothing
here reopens it.

ADR-0027 then over-applied that lesson. Deciding `target_type`/
`target_id` "has to stay an always-present base field... not a field
owned by a scope submodule" is right about the first half (must be a
base field) and wrong about the second: a **base field declared by a
module other than the entity type's own provider**, via
`hook_entity_base_field_info()` and installed/removed with
`\Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition()`/
`uninstallFieldStorageDefinition()`, is a genuine, always-present column
on `aim_fact`'s base table - indistinguishable to `isMultiple()` or
`EntityViewsData` from one core declares itself. It never hits either
bug, because both bugs are about bundle-conditionality, not about which
module owns the declaration. ADR-0027's rejection of
`dynamic_entity_reference` as `aim_scope_entity`'s own dependency was
downstream of this same conflation - "the field must live in core" was
the false premise, not "the dependency is too big for one scope."

The plugin type is therefore the right seam to widen, not a reason to
add a second plugin type beside it. Its own naming already anticipated a
broader mandate than pure access-checking: ADR-0026 piece 4 renamed
`AimUserScopeVisibility` to `AimScopeUser` specifically because
"`AimScope[Name]` reads as 'the plugin for scope X'... and scales to
future `AimScopeRole`/`AimScopeSite`/`AimScopeCase` plugins" - not "the
access plugin for scope X."

## Decision

**Piece 0: rename the plugin type - BUILT 2026-09-29, ahead of the rest
of this ADR.** `AimScopeAccessInterface` undersold the job even before
this ADR's widening - once it owns field declarations and settings too,
"Access" is actively misleading. Renamed, mechanical only, no behavior
change, same category as the `AimUserScopeVisibility` -> `AimScopeUser`
rename ADR-0026 already made for the identical reason:

- `AimScopeAccessInterface` -> `AimScopeTypeInterface`
  (`src/Plugin/AimScopeType/AimScopeTypeInterface.php`, moved from
  `src/Plugin/AimScopeAccess/`)
- `AimScopeAccessPluginManager`/`AimScopeAccessPluginManagerInterface` ->
  `AimScopeTypePluginManager`/`AimScopeTypePluginManagerInterface`
- `#[AimScopeAccess]` attribute -> `#[AimScopeType]`
  (`src/Attribute/AimScopeType.php`)
- `src/Plugin/AimScopeAccess/` -> `src/Plugin/AimScopeType/` (core and
  every submodule plugin: `AimScopeUser`, `AimScopeEntity`,
  `AimScopeCase`)
- Service `plugin.manager.aim_scope_access` ->
  `plugin.manager.aim_scope_type`; cache bin `aim_scope_access_plugins`
  -> `aim_scope_type_plugins`; `alterInfo()` hook `aim_scope_access_info`
  -> `aim_scope_type_info`
- Manager method `getAccessPlugin()` -> `getTypePlugin()`, for the same
  reason as the class rename - it no longer returns "the access plugin,"
  it returns the scope's type plugin, of which access is one facet
- `checkViewAccess()`/`defaultSubject()` (the interface's own methods)
  are unchanged - those names are still accurate for what they do

Pieces 1-3 below are **built and verified 2026-09-29**. They widen the
renamed `AimScopeTypeInterface`, keeping its discovery machinery
(`#[AimScopeType]` attribute, `AimScopeTypePluginManager`,
`src/Plugin/AimScopeType/`) as-is, with new responsibilities alongside
the existing `checkViewAccess()`/`defaultSubject()`:

1. **Field declaration - built.** `AimScopeTypeInterface::
   getBaseFieldDefinitions(): array`, returning the `BaseFieldDefinition[]`
   this scope type needs on `aim_fact`, keyed by field name (the
   placeholder name in the original proposal, kept as-is). Core `aim`
   implements `hook_entity_base_field_info()` (`AimHooks::
   entityBaseFieldInfo()`) that iterates every registered plugin
   *definition* (not `aim_scope` instance - see piece 3) and merges each
   one's field set - the columns exist regardless of which plugin happens
   to be servicing a given request, but the *code and dependency* for a
   field lives with the plugin that declared it. `aim_scope_entity` moved
   `target_type`/`target_id` out of `AimFact::baseFieldDefinitions()`
   into `AimScopeEntity::getBaseFieldDefinitions()`; `user` (the
   equivalent field for `scope=user`) deliberately stayed in core `aim`,
   the same non-requirement piece 2 below states for `requires_account` -
   this ADR enables that migration, it doesn't mandate it.
   `dynamic_entity_reference` as `aim_scope_entity`'s own dependency for
   an autocomplete widget (the deferred cost ADR-0027 named) is still not
   adopted - orthogonal to this piece, revisit separately if wanted.
2. **Settings schema - built.** `AimScopeTypeInterface::
   defaultSettings(): array` plus `buildSettingsForm(array $form,
   FormStateInterface $form_state, array $settings): array` (the
   settings-form builder's name, settled here). `AimScopeForm` grew a
   `plugin` type selector (options from
   `AimScopeTypePluginManager::getDefinitions()`, plus an explicit "None
   (config-only scope)" option - see piece 3's `getTypePlugin()` change
   for why that option exists instead of a generic/default plugin
   instance) with an AJAX-rebuilt `settings` sub-form calling the chosen
   type's `buildSettingsForm()`. `requires_account` was **not** migrated
   off its `aim_scope_user` ThirdPartySetting - confirmed still a
   nice-to-have, not a requirement (see Consequences), so
   `aim_scope_user`'s `defaultSettings()`/`buildSettingsForm()` are both
   no-ops for now.
3. **Decouple plugin ID from scope ID - built.** Confirmed needed, not
   speculative: Nik's real case was two differently-configured scopes of
   the same type (e.g. a "project" entity-scope and a "document"
   entity-scope, both running `AimScopeEntity`'s code, each with its own
   name and settings). `AimScope` gained a nullable `plugin` property
   distinct from its `id` (`config_export` grew from `[id, label]` to
   `[id, label, plugin, settings]`, `settings` being piece 2's storage);
   `AimScopeForm`'s type selector sets `plugin`, `id`/`label` stay the
   instance's own machine name/label as before.
   `AimScopeTypePluginManager::getTypePlugin(string $scopeId)` now loads
   the `aim_scope` entity and resolves by `$scope->get('plugin')`, not by
   `$scopeId` itself - callers (`AimFactAccessControlHandler`,
   `AimMemoryManager::remember()`) are unchanged, since the method's own
   signature didn't need to. Multiple instances of one type share that
   type's base-table columns - not a new cost, the same
   `target_type`/`target_id`-empty-on-other-scopes precedent already
   accepted; what differs per instance is the *settings* (piece 2), not
   the schema. `role`/`site` ship with `plugin: null` - resolved (see
   Consequences) rather than pointed at a generic/default plugin.

**Field lifecycle tracks module state**, the same pattern core's own
`FieldUninstallValidator` protects for ordinary fields:
`aim_scope_entity.install`'s `hook_install()` calls
`installFieldStorageDefinition()` for each field `AimScopeEntity::
getBaseFieldDefinitions()` returns; `hook_uninstall()` calls
`uninstallFieldStorageDefinition()` for the same two, unconditionally -
safe because `ScopeUninstallValidator` already blocks uninstalling this
module while any `scope=entity` fact exists, and no other scope ever
writes to these columns. Verified live: uninstalling and reinstalling
`aim_scope_entity` recreates `target_type`/`target_id` correctly (see
Consequences for the full verification note).

**Generic write-time required-field validation falls out of this for
free - still not built.** ADR-0026's `scopeRequiresAccount()` (a bespoke
method name and call-site pattern, four places patched in one at a time)
and ADR-0027's still-open "no validation that an entity-scope fact
carries a target" gap are the same underlying problem: a scope-specific
required field that nothing generic enforces. Once a plugin declares its
own fields with `->setRequired(TRUE)`, `AimMemoryManager::remember()`
could check required-ness generically against the requesting scope's
plugin declarations instead of a hardcoded `scopeRequiresAccount()`
special case - one mechanism instead of two. Piece 1 (built) makes this
possible; the `remember()` change itself is not part of this ADR's build
and remains open, tracked in TODO.md.

**Piece 4: the Tool API/MCP endpoint shape is still not pluggable -
found 2026-09-29, design only.** Pieces 1-3 make the *entity* and
*admin form* shape of a scope type pluggable, but `aim_tool`'s
`AimRemember` `#[Tool]` attribute still statically lists `target_type`/
`target_id` in its `input_definitions` - a plain PHP attribute, read
once to build the schema MCP's `tools/list` advertises. No scope-type
plugin can inject a new named input into that schema at call time, so
today's `input_definitions` bakes in `scope=entity`'s shape regardless
of whether `aim_scope_entity` is even installed, and a hypothetical
future scope type with its own fields would need `AimRemember` hand-
patched again, exactly the coupling pieces 1-3 removed from the entity
and the form.

The Tool API's own extension point,
`Drupal\tool\TypedData\InputDefinitionRefinerInterface`
(`input_definition_refiners` on `#[Tool]`), does not solve this as-is:
by contract (see the interface's own docblock) a refiner may only
*narrow* a definition already present in the static advertisement - add
constraints, declare properties on an already-declared free map, tighten
`required`, set a default - never introduce a whole new named input
unadvertised. The concrete mechanism this piece would need: replace the
scope-specific named inputs (`target_type`/`target_id` today) with one
generic, statically-declared free-map input (e.g. `scope_fields`,
untyped/no fixed properties), and have `AimRemember` implement
`InputDefinitionRefinerInterface` so each request's chosen scope's
plugin (via a new interface method, name TBD, something like
`refineToolInput(): array` returning property definitions) declares its
own properties onto that map. Not built. Also applies to `aim_recall`'s
read-side filters and to any future scope-type-specific Tool input, not
just `AimRemember`'s write side.

## Consequences / risks

- `AimScopeTypeInterface` stops being the deliberately-thin seam
  ADR-0025 designed ("don't abstract before it's earned") and becomes
  the full definition of a scope type's shape - fields, settings, and
  behavior together. A materially bigger interface (7 methods now),
  worth naming plainly rather than discovering by surprise mid-build.
- **ADR-0001's "a fifth scope needs zero PHP" promise - resolved.**
  Considered a generic/default no-op plugin for `role`/`site` and
  rejected it: `checkViewAccess()` returning neutral,
  `defaultSubject()` returning `NULL`, and the three new methods all
  returning nothing are exactly what `getTypePlugin()` returning `NULL`
  already produces at every call site (each already null-safe, e.g.
  `$plugin?->checkViewAccess(...)`), so a generic plugin class would add
  a real class, an attribute, and a registration with zero behavioral
  difference from `NULL` - ceremony, not a feature. `plugin` stayed
  nullable; `role`/`site` ship with `plugin: null`, chosen in
  `AimScopeForm`'s type selector via its explicit "None (config-only
  scope)" option. Zero PHP for a fifth plain scope is exactly today's
  `role`/`site` shape, unchanged.
- **The two claims this ADR's safety argument rested on - verified
  against real code, both true.**
  `AiVdbProviderClientBase::isMultiple()`
  (`web/modules/contrib/ai/src/Base/AiVdbProviderClientBase.php:453`)
  calls `$this->entityFieldManager->getFieldStorageDefinitions($entity_type)`
  - entity-type-wide, not entity-class-wide, so it cannot distinguish a
  field declared in `AimFact::baseFieldDefinitions()` from one declared
  via another module's `hook_entity_base_field_info()`; both are merged
  into the same result by core's `EntityFieldManager` before either
  caller ever sees them. `EntityViewsData::getFieldStorageDefinitions()`
  (`web/core/modules/views/src/EntityViewsData.php:134`) calls the exact
  same `entityFieldManager->getFieldStorageDefinitions($entity_type_id)`
  API - same non-distinction, same conclusion. The `Broken` Views handler
  ADR-0026 hit was specific to *bundle* fields (cardinality/definition
  conditional on the bundle), which this mechanism never produces; it did
  not reopen here.
- Removing a scope type's field on module uninstall needs its own
  data-safety check, in the same spirit as `ScopeUninstallValidator`
  (`src/ScopeUninstallValidator.php`) but distinct from it -
  `ScopeUninstallValidator` counts `aim_fact` rows of a bundle;
  uninstalling a field needs to confirm the *column* is empty across
  every bundle that might have written to it. `aim_scope_entity.install`
  takes the simpler route available today: since `ScopeUninstallValidator`
  already blocks uninstalling this module while any `scope=entity` fact
  exists, and no other scope ever writes `target_type`/`target_id`, its
  `hook_uninstall()` drops both columns unconditionally rather than
  re-deriving that same safety check. A scope type sharing columns with
  a scope that *doesn't* block its own uninstall this way would need the
  distinct check described above - not a case that exists yet.
- Migrating `aim_scope_user`'s existing `requires_account`
  ThirdPartySetting to the new settings mechanism is still a nice-to-have
  cleanup this ADR enables, not a requirement of it - confirmed not done
  as part of this build; the two mechanisms coexist.
- Piece 0's rename touched every then-current caller:
  `AimFactAccessControlHandler`, `AimMemoryManager::remember()`,
  `aim.services.yml`, and all three existing plugins (`AimScopeUser`,
  `AimScopeEntity`, `AimScopeCase`). Mechanical, no logic change -
  phpcs/phpstan clean after the move, and
  `plugin.manager.aim_scope_type`/`getTypePlugin()` verified live via
  `drush php:eval` to still resolve the correct plugin class for every
  scope (`user`/`case`/`entity` return their dedicated plugin,
  `role`/`site` correctly `NULL`).
- **Pieces 1-3's live verification - two real problems found and fixed
  during this pass, not just a clean run.** phpcs/phpstan clean.
  `remember()`/`recall()`/`checkViewAccess()` round-tripped correctly for
  a real `scope=entity` fact (created against a real node, recalled by
  text search, access-checked for uid 1) with no call-site changes
  needed anywhere in `AimMemoryManager` or
  `AimFactAccessControlHandler`. `AimScopeForm` builds correctly for
  both a scope with a plugin (`entity`, `plugin` defaults to `'entity'`)
  and one without (`role`, defaults to the blank "None" option; the
  `plugin`/`case`/`entity`/`user` option list is exactly the registered
  plugin set). Every `aim.aim_scope.*.yml`'s hand-typed `plugin`/
  `settings` values matched a live save's real serialization exactly
  (`config:get` after `->save()`, not re-exported by hand).
  - **Field provider bug.** `AimScopeEntity::getBaseFieldDefinitions()`'s
    first version didn't call `->setProvider()` - since all plugins'
    fields are merged through core `aim`'s one
    `hook_entity_base_field_info()`, `EntityFieldManager::
    buildBaseFieldDefinitions()` stamped every merged field with the
    *hook's* module (`aim`) as provider, not the declaring plugin's
    module, confirmed live via `entity_field.manager`'s
    `getFieldStorageDefinitions()`. Fixed by having each field call
    `->setProvider('aim_scope_entity')` on itself - core's merge only
    defers to an already-set provider, never infers one. Interface
    docblock updated to state this as a required contract for any future
    plugin.
  - **hook_uninstall() was actively wrong, not just unnecessary.**
    Discovered while testing the uninstall/reinstall cycle: core's own
    `ModuleInstaller::uninstall()` already calls
    `uninstallFieldStorageDefinition()` generically for every field whose
    `getProvider()` matches the module being uninstalled - once the
    provider bug above was fixed, `aim_scope_entity`'s own hand-written
    `hook_uninstall()` was racing that generic pass over the same two
    columns and threw `SQLSTATE[42S22]: Column not found`. Removed
    entirely; `hook_install()` alone (declaring the columns' initial
    creation, which core has no generic pass for) is correct and
    sufficient.
  - **A real, separately-isolated Drush oddity, not an aim bug: `drush
    pmu aim_scope_entity` still throws that same SQLSTATE error even
    after the fix above**, but calling
    `\Drupal::service('module_installer')->uninstall(['aim_scope_entity'])`
    directly (the exact same core code path, confirmed by reading the
    stack trace: `Drush\Commands\pm\PmCommands->uninstall()` ->
    `ModuleInstaller->uninstall()` -> `EntityDefinitionUpdateManager->
    uninstallFieldStorageDefinition()`, the same three frames whether
    triggered via Drush or called directly) completes cleanly, drops
    both columns, and round-trips through `->install()` the same way.
    Isolated to Drush's `pm:uninstall` command layer specifically - not
    filed upstream yet, not further diagnosed, not blocking (verification
    used the direct service call instead). A site operator uninstalling
    a scope-field-providing submodule via `drush pmu` should expect to
    hit this until it's understood; noted in TODO.md.
  - Live data was never at risk: `ScopeUninstallValidator` was checked
    for zero `scope=entity` facts before any uninstall attempt, and the
    columns/config were manually restored to their pre-test state
    (matching `config/install`) between attempts using the same
    `installFieldStorageDefinition()`/entity-create calls `hook_install()`
    itself uses, not a snapshot restore.

## Open questions

- ~~**Does `AimScopeAccessInterface`/`AimScopeAccessPluginManager` get
  renamed?**~~ **Resolved 2026-09-29, yes - see piece 0.**
- ~~**Does this supersede ADR-0025/0026/0027, or amend them?**~~
  **Resolved 2026-09-29: amends ADR-0027** (corrects its
  base-field-ownership reasoning specifically) **and extends
  ADR-0025/0026** (same plugin type, wider mandate, now renamed) - none
  of the three was wrong about anything except the one conflation
  identified above.
- ~~Exact method names/signatures for field declaration and settings are
  placeholders, not committed.~~ **Resolved 2026-09-29** - see pieces
  1-2 above: `getBaseFieldDefinitions()`, `defaultSettings()`,
  `buildSettingsForm()`.
- **New, from piece 4.** Exact shape of the generic free-map Tool input
  and the interface method a plugin would implement to refine it are
  not settled - first real implementation (most likely `aim_remember`,
  since it already has a concrete field pair, `target_type`/`target_id`,
  to migrate off named inputs) would settle them, the same way
  `aim_scope_entity` settled pieces 1-2's naming.

Piece 0 (the rename) and pieces 1-3 (field declaration, settings schema,
plugin/scope decoupling) are built and verified. Piece 4 (the Tool
API/MCP endpoint shape) is a named gap, design only - not part of this
build.
