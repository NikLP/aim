# AIM Scope: Case

Ships the `case` [aim](../../README.md) scope: memory scoped to a single
case/incident/thread, with an auto-minted `subject` when none is given.
Part of [AIM](../../README.md).

Requires `aim`. Ships the `aim.aim_scope.case` config entity and an
`AimScopeCase` access plugin that mints the auto-subject above. Install
this to let facts be written with `scope: case` - without it, `case`
isn't a valid scope on this site. Case-scope access control
(`checkViewAccess()`) is still unbuilt - the plugin stays neutral there,
an already-acknowledged gap.

Decision records: [ADR-0025](../../adr/resolved/0025-scope-access-plugin-type.md)
(the access plugin type), [ADR-0026](../../adr/resolved/0026-pluggable-scope-submodules.md)
(this submodule split).
