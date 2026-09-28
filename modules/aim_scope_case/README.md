# AIM Scope: Case

Ships the `case` [aim](../../README.md) scope: memory scoped to a single
case/incident/thread, with an auto-minted `subject` when none is given.
Part of [AIM](../../README.md).

Requires `aim`. Zero PHP - just the `aim.aim_scope.case` config entity.
Install this to let facts be written with `scope: case` - without it,
`case` isn't a valid scope on this site. Has no `AimScopeAccess` plugin
of its own yet - an already-acknowledged gap, unrelated to the submodule
split.

Decision record: [ADR-0026](../../adr/0026-pluggable-scope-submodules.md)
(the submodule split).
