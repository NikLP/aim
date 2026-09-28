# CLAUDE.md — AIM Scope: User

Part of the [aim](../../CLAUDE.md) project - see the root module's
CLAUDE.md for environment, architecture decisions, and the code-style/git
rules shared across all of `aim`'s submodules; this file only covers what
is specific to `aim_scope_user`.

## What lives here

- `config/install/aim.aim_scope.user.yml` - the `user` `aim_scope`
  config entity, carrying the `requires_account: true`
  ThirdPartySetting (ADR-0007's "a scope=user fact must reference a real
  account") and an `enforced` dependency on this module, needed so
  `Drupal\aim\ScopeUninstallValidator` can find this config entity as a
  dependent of `aim_scope_user` specifically - the config's own name
  (`aim.aim_scope.user`) is prefixed by `aim` (the `aim_scope` entity
  type's provider), not by this module, so without the enforced
  dependency the validator would find nothing to check and let
  `drush pmu aim_scope_user` through even with live `user`-scope facts.
  Live-verified 2026-09-28 with a sibling submodule
  (`aim_scope_role`, 5 live facts): uninstall correctly blocked.
- `config/schema/aim_scope_user.schema.yml` - schema for the
  ThirdPartySetting above, same pattern as the sibling Annotations
  suite's `annotations_audit`/`annotations_context` submodules
  (`web/modules/contrib/annotations`).
- `src/Plugin/AimScopeAccess/AimScopeUser.php` - the role-visibility
  access plugin (ADR-0025), moved here from core `aim` and renamed from
  `AimUserScopeVisibility` to match this module's own name (`AimScope` +
  the scope ID) rather than describing its one current behavior. Its
  admin settings form, `AimScopeUserAccessForm` (also renamed on the
  move, from `AimUserScopeAccessForm`, `getFormId()` now
  `aim_scope_user_access_form`)
  (`/admin/config/aim/user-scope-access`, `aim.settings:
  user_scope_role_visibility`/`user_scope_shared_role_fallback`), stays
  in core `aim` - it isn't plugin-discovered the way the access check is,
  so moving it would need its own route/permission split with no
  corresponding benefit yet. Also implements `defaultSubject()`, returning
  `NULL` - `user` has no sensible default subject, it requires a real
  account instead (see `requires_account` above). See
  [aim_scope_case](../aim_scope_case/CLAUDE.md) for a scope that does
  supply one.

## Reading `requires_account` generically

Core `aim`'s `AimMemoryManager::scopeRequiresAccount(string $scope):
bool` reads this ThirdPartySetting off the `aim_scope` config entity -
every call site that used to hardcode `$scope === 'user'`/
`$fact->bundle() === 'user'` (in `AimMemoryManager.php`, `AimCommands.php`,
and `aim_tool`'s `AimRemember` tool plugin) now calls that method instead.
A future scope wanting the same "must reference a real account" behavior
sets this ThirdPartySetting on its own config entity - no code change
needed in core `aim`.
