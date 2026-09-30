# ADR-0026: Pluggable scope submodules - config via ThirdPartySettings, behavior via the ADR-0025 plugin type

**Status:** Built (pieces 1-5, live-verified 2026-09-28/29). Piece 6, the bundling recipe, is tracked in TODO.md.
`AimScopeAccessInterface`/`AimScopeAccessPluginManager`, referenced throughout piece 4 below, were renamed `AimScopeTypeInterface`/`AimScopeTypePluginManager` on 2026-09-29 ([ADR-0028](0028-scope-type-plugin.md) piece 0).
**Date:** 2026-09-28

## Context

Today all four scopes ship as `config/install/aim.aim_scope.{user,role,
site,case}.yml` inside core `aim` itself. Adding a fifth scope needs zero
PHP (per [ADR-0001](0001-storage-and-scope-model.md)'s addendum), but
removing one of the shipped four, or installing `aim` without carrying
all four scopes' permissions/admin UI/access logic, isn't possible - the
four are baked into the one module.

Nik's ask, raised while discussing [ADR-0025](0025-scope-access-plugin-type.md):
make the scope architecture genuinely pluggable - split scopes into their
own submodules, install only the ones a given site wants, with
per-submodule behavior differences the same way the sibling Annotations
module (`web/modules/contrib/annotations`) handles its own bundle
equivalent.

Checked against Annotations' actual code rather than assumed. Two
separate mechanisms there, not one:

- **`AnnotationType`** (`annotations.annotation_type.*`, `bundle_of:
  'annotation'`) is a bare config entity in root - `id`/`label`/
  `description`/`weight`, nothing else. Root ships **no default types at
  all**; the `annotations_demo_types` recipe provides editorial/
  technical/rules. Behavior flags (`affects_coverage`, owned by
  `annotations_audit`; `in_ai_context`, owned by `annotations_context`)
  are **ThirdPartySettings** - each submodule reads/writes only its own
  key on a type it doesn't own.
- **`TargetPluginManager`** is a separate, unrelated mechanism (which
  entity types can be annotated), via `#[AnnotationsTarget]` attribute
  discovery, dedicated plugins shadowing a generic deriver-based
  fallback. This is what ADR-0025 already modeled `AimScopeAccessInterface`
  on - correctly, since ThirdPartySettings can hold only data, never a
  method body.

So the "watertight" version of Nik's ask isn't ThirdPartySettings
*instead of* ADR-0025's plugin type - it's both, split the same way
Annotations splits them: **ThirdPartySettings for scope-level config,
the plugin type for scope-level behavior.**

Two things checked in `aim`'s own code and docs that constrain this ADR
before it starts:

- **Per-bundle fields were already tried and reverted.** DEVELOPING.md's
  "Scope/bundle model": moving `subject`/`subject_uid` into
  `bundleFieldDefinitions()` broke `ai_vdb_provider_mariadb`'s
  `AiVdbProviderClientBase::isMultiple()` (assumes every field is a base
  field, threw `Table 'aim_fact_vectors__subject' doesn't exist`) and
  core's `EntityViewsData` (degraded Views columns to `Broken` handlers).
  "Don't re-attempt without a concrete reason beyond schema tidiness."
  This ADR does not reopen that - see "Explicitly out of scope" below.
- **`AimScopeDeleteForm` already refuses deleting a scope config entity
  while `aim_fact` entities of that bundle exist** (same precedent as
  core's `NodeTypeDeleteConfirm`). This is *not* Annotations' pattern
  (which cascades: deleting an `annotation_type` deletes its annotation
  rows via `hook_ENTITY_TYPE_delete()`) - `aim` already made the opposite
  choice, and this ADR keeps it rather than switching to match
  Annotations. What's unverified is whether that same refusal actually
  fires during **module uninstall** (config-dependency removal), which is
  a different code path than the direct entity-delete form - see "Open
  questions".

Two hardcoded-by-name integration points would need to stop assuming
`user` specifically (found by grep, not assumed): `AimMemoryManager.php:
1025` and `AimCommands.php:134` both branch on `$fact->bundle() ===
'user'` directly in core `aim`, rather than asking the scope for its own
requirement.

## Decision (best-guess proposal - not committed)

Six separable pieces - deliberately split so they can become independent
TODO.md/task entries rather than one large change:

1. **BUILT 2026-09-28. Core `aim` ships zero scope instances.** The
   `aim_scope`/`aim_fact` entity types, `AimMemoryManager`, Guardrails,
   and vector search stay in core; the four
   `config/install/aim.aim_scope.*.yml` files moved out. Mirrors
   Annotations shipping no default `annotation_type` at all.
2. **BUILT 2026-09-28. Four new submodules** - `aim_scope_user`,
   `aim_scope_role`, `aim_scope_site`, `aim_scope_case` - each ships
   exactly one `config/install/aim.aim_scope.<id>.yml`. A site installs
   only the scopes it wants. `role`/`site` are pure config, zero PHP,
   same as any site-added fifth scope. `case` ships one PHP plugin (see
   the subject-minting bullet below) but, like `role`/`site`, has no view
   *access control* of its own yet (an already acknowledged gap, unrelated
   to this ADR). Each config entity's `dependencies` came from a real
   `->save()`
   and export, not hand-typing (this file's own "Cross-cutting
   conventions" rule): `role`/`site`/`case` carry an `enforced` module
   dependency on themselves only (needed for `ScopeUninstallValidator` to
   find them, since `aim.aim_scope.<id>`'s name is prefixed by `aim`, the
   entity type's provider, not by the shipping submodule); `user` additionally
   carries the `requires_account` ThirdPartySetting from piece 3
   below, which pulls in the same module dependency automatically, no
   separate enforced entry needed for it specifically. Live-verified
   against a real split submodule, not just the earlier scratch-module
   test: `drush pmu aim_scope_role` is correctly blocked while role-scope
   facts exist (5 on this site).
3. **BUILT 2026-09-28. Config-level scope differences move to
   ThirdPartySettings.** ADR-0007's "subject required for scope=user"
   is now a ThirdPartySetting `aim_scope_user` owns on its own config
   entity - `requires_account`, not the earlier draft name
   `requires_subject_uid` (stale after the 2026-09-28 field rename to
   `user`) - read generically by core `aim` via
   `AimMemoryManager::scopeRequiresAccount()` instead of the
   `bundle() === 'user'`/`scope === 'user'` string checks previously in
   `AimMemoryManager.php`, `AimCommands.php`, and `aim_tool`'s
   `AimRemember` tool plugin (a third call site found once actually
   grepping for every occurrence, not just the two originally spotted
   above).
4. **BUILT 2026-09-28. Behavioral scope differences stay on ADR-0025's
   plugin type** - promoted from "seam, unbuilt" to "build it now."
   `AimUserScopeVisibility` moved out of core `aim`'s
   `src/Plugin/AimScopeAccess/` into
   `aim_scope_user/src/Plugin/AimScopeAccess/`, becoming the plugin
   type's first real dedicated implementation instead of a hardcoded
   `if ($entity->bundle() === 'user')` branch in
   `AimFactAccessControlHandler`. Renamed to `AimScopeUser` on the move
   (Nik's naming call) - `AimScope[Name]` reads as "the plugin for scope
   X" and scales to future `AimScopeRole`/`AimScopeSite`/`AimScopeCase`
   plugins, where `AimUserScopeVisibility` named one current behavior
   instead.
5. **BUILT 2026-09-29. `aim_chatbot` declares an explicit dependency on
   `aim_scope_site`** in its `.info.yml`, replacing the prior implicit
   assumption that `scope: site` exists (its tool is hardcoded to that
   scope - see [aim_chatbot's CLAUDE.md](../../modules/aim_chatbot/CLAUDE.md)).
6. **A recipe bundles "the four default scopes"** for one-step
   enablement (matching Annotations' `annotations_demo_types` and aim's
   own `aim_demo_library` precedent in `recipes/`), so a site that wants
   today's default behavior doesn't need to hand-enable five modules
   instead of one. Not yet built.

**Explicitly out of scope:** converting `subject`/`subject_uid` to
per-bundle fields. Already tried and reverted (see Context) - these stay
always-present base fields in core `aim`, unused on bundles that don't
need them, regardless of which scope submodules are installed. This ADR
makes scopes **config- and behavior-pluggable, not schema-pluggable** - a
scope submodule cannot introduce its own dedicated field.

## Consequences / risks

- Real reduction in installed footprint for a site that doesn't need
  every scope - e.g. a site with no per-user personalization doesn't
  carry `AimUserScopeVisibility`'s admin form
  (`/admin/config/aim/user-scope-access`), its permissions, or its
  plugin.
- Doesn't touch storage layout - `aim_fact` stays one entity type/table,
  `aim_fact_vectors` stays one collection table. Only the config-entity
  layer and the access-behavior layer split.
- Overhead risk: five modules to enable instead of one for the common
  case. Mitigated by the bundling recipe in piece 6 - without it, this
  ADR would make the default install experience strictly worse.
- The two integration points already known to break on bundle
  assumptions (`ai_vdb_provider_mariadb`'s `isMultiple()`, core's
  `EntityViewsData`) are **not** touched by this ADR - they broke on the
  reverted per-bundle-*field* attempt, a different thing from
  per-bundle-*config-entity* splitting. Worth stating plainly so a future
  reader doesn't conflate the two kinds of "pluggable."
- [ADR-0024](../0024-annotations-integration-target-scoped-promotion.md)'s
  proposed `scope: entity` bundle became the fifth submodule,
  `aim_scope_entity`, built 2026-09-29 per its own
  [ADR-0027](0027-entity-scope.md) - landed into this ADR's submodule
  shape exactly, not a competing design.

**Addendum 2026-09-28: follow-up audit for leftover scope bias.** Nik
asked, after pieces 1-4 shipped, what other code in core `aim` is still
bespoke to one scope without a corresponding install-time guarantee -
toward the ideal of core working correctly with *any* subset of scope
submodules installed, no inert code for the ones absent. Two kinds of
findings, not one:

- **A real gap in piece 3's own rollout, fixed:** a third
  `$fact['scope'] === 'user'` check in
  `AimMemoryManager::createFactsFromCandidates()` (the `aim:extract`
  write path) had been missed by the original grep, since it used
  array-bracket syntax rather than `bundle() ===`/`scope ===`. Now also
  `scopeRequiresAccount()`. Also renamed `requires_user_account` to
  `requires_account` (Nik's call - "account" already implies "user",
  redundant) and `AimUserScopeAccessForm` to `AimScopeUserAccessForm`
  (route/path unchanged, `getFormId()` now `aim_scope_user_access_form`)
  to match the plugin's own rename.
- **A genuinely separate class of bespoke-ness, partly fixed:** four
  places defaulted an omitted scope to `'site'` specifically -
  `aim:remember`'s CLI default, its `--file` batch fallback,
  `aim:benchmark`'s CLI default, and `aim_tool`'s `AimRemember` tool
  plugin (both its single-fact and batch-entry `scope` inputs). None of
  these are config-entity dependents the way pieces 1-3 dealt with -
  they're an *opinion* baked into core/`aim_tool` about which scope is
  most common, silently assuming `aim_scope_site` is installed. Fixed by
  requiring scope explicitly everywhere and erroring clearly when it's
  missing, rather than guessing.
- **Fixed 2026-09-28, a different shape of problem:** `case` scope's
  subject-auto-minting in `remember()` was scope-specific *behavior*
  (mint a UUID), not a config flag - `requires_account`'s ThirdPartySetting
  mechanism didn't apply. Separated by adding `defaultSubject(): ?string`
  to `AimScopeAccessInterface` itself, rather than a second plugin type -
  `AimScopeAccessPluginManager::getAccessPlugin()` already tolerates "no
  plugin registered" per scope, so `role`/`site` still ship zero PHP.
  `aim_scope_case` implements it (mints the case ID); `AimScopeUser`
  returns `NULL`. Detail in TODO.md's "Governance/scope design thread".
  The "Case ID: ..." CLI hint in `AimCommands.php` is a separate, purely
  cosmetic output check, left as-is.

## Open questions

- ~~**Does module uninstall actually respect `AimScopeDeleteForm`'s
  content-based refusal?**~~ **Resolved 2026-09-28, no.** Verified with a
  disposable scratch module (`aim_scope_zzztest`, one
  `aim.aim_scope.zzztest.yml` with `dependencies.enforced.module` pointed
  at itself, since a real scope submodule's config must declare that
  enforced dependency explicitly - the shipped `aim.aim_scope.{user,role,
  site,case}.yml` files ship `dependencies: {}` today only because they
  live in the same module (`aim`) as the `aim_scope` entity type itself,
  a shortcut a split-out submodule doesn't get). Sequence: enabled the
  module, created an `aim_fact` in that scope via `drush aim:remember`,
  ran `drush pmu aim_scope_zzztest -y`. Uninstall succeeded silently, no
  confirmation prompt, no content-count check -
  `AimScopeDeleteForm::buildForm()` only runs when a human submits the
  entity-delete confirmation route; `ConfigManager::uninstall()`'s
  dependency-removal path calls `$storage->delete()` directly and never
  instantiates that form. The `aim_fact` row survived in the database
  with `scope: zzztest`, `AimScope::load('zzztest')` returned NULL, and
  the row's `view`/`create` permission strings no longer existed for any
  role to hold - it stayed loadable and renderable (empty build, no
  error) and still appeared in the admin Views listing, but became
  permanently inaccessible to anyone without the flat `administer aim
  memory` bypass, with no delete-form route left to invoke to clean it
  up. **Fixed and shipped 2026-09-28**, ahead of the rest of this ADR:
  `Drupal\aim\ScopeUninstallValidator` (`aim.scope_uninstall_validator`
  service, tagged `module_install.uninstall_validator`) - core's own
  purpose-built mechanism for exactly this, the same one
  `field.uninstall_validator`/`FieldUninstallValidator` uses to block
  uninstalling a module with active field storage. `validate($module)`
  calls `ConfigManagerInterface::findConfigEntityDependenciesAsEntities('module',
  [$module])`, filters to `AimScope` entities, and counts `aim_fact` rows
  of that scope via the same query `AimScopeDeleteForm` already runs -
  any count > 0 returns a worded reason. Confirmed this is the right
  layer, not `hook_module_preuninstall()`: drush's own `pm:uninstall`
  calls `ModuleInstallerInterface::validateUninstall()` (which runs every
  tagged validator) from an `ARGUMENT_VALIDATOR` hook that fires *before*
  the "do you want to continue?" confirmation and before any deletion -
  same mechanism `/admin/modules/uninstall` uses, so one validator class
  covers both the UI and `drush pmu` with a real, worded reason rather
  than a bare exception.

  **A second bug caught during testing, fixed before shipping:** the
  first version fired for *any* module providing an in-use scope,
  including core `aim` itself - since all four shipped scopes carry a
  calculated dependency on `aim` (the `aim_scope`/`aim_fact` entity
  types' own provider), and this site carries 34 real facts right now,
  that would have also blocked `drush pmu aim`, directly contradicting
  this repo's own PoC reinstall workflow (CLAUDE.md/DEVELOPING.md: "just
  change and reinstall (`drush pmu`/`drush en`)" while there's no real
  data worth migrating). Fixed by skipping validation outright when
  `$module` is `aim_fact`'s own entity-type provider - uninstalling that
  module drops the `aim_fact` table wholesale, so nothing is orphaned by
  it; the real risk this validator guards is a scope surviving its own
  content's storage, only possible once a scope is provided by a
  *different* module than `aim_fact` itself. Re-verified live after the
  fix: `validate('aim')` returns `[]` with all 34 facts still in place,
  and the scratch-submodule scenario above still correctly blocks.
- ~~**ThirdPartySettings key ownership.**~~ **Resolved 2026-09-28.**
  Annotations' precedent (`annotations_audit` owns `affects_coverage` on
  an `AnnotationType` it doesn't provide) was followed: `aim_scope_user`
  owns the key on its own config entity, read but not owned by core `aim`
  via `AimMemoryManager::scopeRequiresAccount()`. Named `requires_account`,
  not the draft `requires_subject_uid` above (stale after the same-day
  field rename to `user`, and shortened from `requires_user_account`
  during the follow-up audit - "account" already implies "user").
- ~~**Build order relative to ADR-0025.**~~ **Followed in full, completed
  2026-09-28.** Built the plugin type first, in core, as ADR-0025
  proposed: `AimScopeAccessPluginManager` +
  `AimUserScopeVisibility` converted in place into the first dedicated
  plugin (`src/Plugin/AimScopeAccess/`). Piece 4's other half - relocating
  the plugin class into `aim_scope_user` - followed later the same day in
  the follow-up audit once pieces 1-3 existed for it to land in, per piece
  4's own bullet above.
- **Does this ADR supersede ADR-0025, or sit alongside it?** Recommend
  alongside - ADR-0025 stays the narrower "behavior plugin type" decision,
  this ADR is the broader "submodule split" decision that consumes it,
  matching how ADR-0024 already references ADR-0025 as a dependency
  rather than folding it in.

None of the above is validated against real code beyond the greps and
file reads cited in Context - this captures the mechanism and a task
breakdown, not a spec to build against as-is.
