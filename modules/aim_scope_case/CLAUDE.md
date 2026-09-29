# CLAUDE.md — AIM Scope: Case

Part of the [aim](../../CLAUDE.md) project - see the root module's
CLAUDE.md for environment, architecture decisions, and the code-style/git
rules shared across all of `aim`'s submodules; this file only covers what
is specific to `aim_scope_case`.

## What lives here

- `config/install/aim.aim_scope.case.yml` - the `case` `aim_scope`
  config entity.
- `src/Plugin/AimScopeType/AimScopeCase.php` - the
  `AimScopeTypeInterface` plugin (ADR-0025). `defaultSubject()` mints a
  new case ID (`case-` + 8 hex chars) when a `scope=case` fact is created
  with no caller-supplied subject, moved here from a hardcoded
  `$scope === 'case'` branch in core `aim`'s `AimMemoryManager::remember()`
  (ADR-0026's follow-up audit). `checkViewAccess()` stays neutral -
  case-scope access control is a separate, still-unbuilt problem (who may
  view a case-scoped fact isn't designed yet), not addressed by this
  plugin.

## Why this needed a new interface method

`requires_account` (see `aim_scope_user`'s CLAUDE.md) is a static config
flag, read generically off the `aim_scope` config entity. Case's
subject-minting is actual behavior - "generate a UUID and use it as the
subject" - which needs code to run, not a flag to check. That's why
`AimScopeTypeInterface` grew a `defaultSubject(): ?string` method
instead of a ThirdPartySetting: any scope can now supply a default
subject by implementing this method (`aim_scope_user`'s plugin returns
`NULL` - `user` has no sensible default and requires a real account
instead), with no core `aim` code change needed for a future scope that
wants the same thing.
