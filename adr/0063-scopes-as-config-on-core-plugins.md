# ADR-0063: Scopes as config on core plugins

**Status:** Built 2026-10-26, live-verified (preflight 14/14, kernel
tests 74). Supersedes
[ADR-0026](resolved/0026-pluggable-scope-submodules.md) (scopes shipped by
submodules). Keeps [ADR-0025](resolved/0025-scope-access-plugin-type.md)'s
and [ADR-0028](resolved/0028-scope-type-plugin.md)'s plugin type unchanged.
**Date:** 2026-10-26

## In plain terms

Before: each of the five scopes is its own submodule. Two of them ship
one YAML file and nothing else. The wording that tells a model which
scope to pick is a hardcoded list in `aim_tool`'s `AimScopeGuide`, and the
user scope's role-visibility matrix is a global setting with its own form
in core `aim`, although only the user scope reads it.

After: core `aim` ships the scope plugins and the five scopes as config,
the way `literals` ships its resolvers and literal types. A site switches
a scope off with its Enabled checkbox. Each scope carries its own wording
and its place in the list, editable on the scope's form. The matrix
becomes the user scope's own settings. The five submodules go.

Gains: five fewer modules and eight fewer doc files; one place to read
what a scope is; wording a site can edit and translate; two backlog items
close (below). Costs: `aim_fact` always has the entity scope's two
columns, unused on a site that disables that scope; a site's wording edit
changes how models choose a scope.

## Context

Since ADR-0028 the plugin type carries everything a scope type does
(fields, settings, view access, default subject). What is left in the
submodules:

| Submodule | Ships |
| --- | --- |
| `aim_scope_site`, `aim_scope_role` | One `aim.aim_scope.*.yml` |
| `aim_scope_case` | YAML + `AimScopeCase` |
| `aim_scope_user` | YAML + `AimScopeUser` + a `base_field_override` making `user` required |
| `aim_scope_entity` | YAML + `AimScopeEntity` + an install hook for `target_type`/`target_id` |

`literals` solves the same shape in one module: six resolver plugins in
code, six `literal_type` config entities in `config/install` (`id`,
`label`, `description`, `resolver` with a `PluginExists` constraint,
`status`), an Enabled checkbox that hides a type from new literals and
refuses while literals use it, and no add or delete form.

## Decision

1. **Plugins and scopes move into core `aim`.** The three plugin classes
   move to `src/Plugin/AimScopeType/`; the five scope YAML files and the
   user `base_field_override` move to `config/install`. The submodules,
   `ScopeUninstallValidator` and `aim_scope_entity_install()` are deleted.
   Third parties still add a scope type the usual way: a module with a
   plugin, plus a scope config naming it.
2. **A scope has `status`.** Disabled scopes are not offered for new facts
   (tool schemas, forms, ingest) and are left out of the scope guide;
   disabling a scope that has facts is refused, as in `LiteralTypeForm`.
   The existing add form and delete form stay (two scopes sharing one
   plugin with different settings, ADR-0028 piece 3); delete already
   refuses while facts exist.
3. **A scope carries `description` and `weight`.** `description` is the
   "which scope when" line now in `AimScopeGuide::GUIDE`, shipped word for
   word. `weight` replaces the guide's key order (site, role, user, case,
   entity); the scope list becomes a `DraggableListBuilder`. The short
   `SHORT` phrases go: the recall `scope` input lists scope IDs only, since
   the full wording already reaches the model through `aim_remember` and
   the MCP server instructions. `AimScopeGuide` keeps its methods and reads
   the entities.
4. **The matrix becomes the user scope's settings.**
   `user_scope_role_visibility` and `user_scope_shared_role_fallback` move
   from `aim.settings` into the user scope's `settings`, edited through
   `AimScopeUser::buildSettingsForm()`. `AimScopeUserAccessForm` and its
   route go.
5. **Scope fields are always installed.** Fields come from every plugin
   in code (`AimHooks::entityBaseFieldInfo()`), so the entity scope's two
   nullable columns exist whether or not the scope is enabled. Installing
   them when a scope is enabled would make a config save change the
   database schema, which core avoids. Their provider becomes `aim`.

## Consequences

- No update path in code (PoC rule, no `hook_update_N()`); the
  `aim_demo_library` recipe drops its four `aim_scope_*` install lines.
  This site was migrated in place on 2026-10-26, not rebuilt: the five
  modules were removed from `core.extension` without running their
  uninstall (which would have dropped the scopes and the entity columns),
  then each scope was re-saved from the shipped YAML, the matrix moved
  from `aim.settings`, and the entity fields installed with provider
  `aim`. They had never been installed here, although `aim_scope_entity`
  was listed as enabled; the entity scope config was missing too.
- Config entity queries sort one key at a time, the last sort winning,
  so `allowedScopes()` loads and sorts with `ConfigEntityBase::sort()`
  (weight, then label) instead.
- Closes BACKLOG's "Bundling recipe for the scope submodules" (ADR-0026
  piece 6) and the `drush pmu aim_scope_entity` oddity; the
  `create-aim-scope-type` skill in TODO.md shrinks to a plugin plus a
  scope YAML.
- Docs: the five submodules' README/CLAUDE/DEVELOPING files are deleted,
  not moved; what is still true goes into a short "Scopes" section in
  core's DEVELOPING.md. `aim_tool`'s README "Which scope when" table is
  labelled as the shipped defaults.
- Not decided here, and not added to the backlog: case-scope view access
  and the case picker (unchanged), and a per-scope "which subjects belong
  to this account" plugin method that would remove `aim_brief`'s
  hardcoded user and role handling.
