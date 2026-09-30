# AIM Scope: Role

Ships the `role` [aim](../../README.md) scope: memory shared across
everyone holding a given Drupal role. Part of [AIM](../../README.md).

Requires `aim`. Zero PHP - just the `aim.aim_scope.role` config entity.
Install this to let facts be written with `scope: role` - without it,
`role` isn't a valid scope on this site.

Decision record: [ADR-0026](../../adr/resolved/0026-pluggable-scope-submodules.md)
(the submodule split).
