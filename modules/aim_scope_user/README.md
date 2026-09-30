# AIM Scope: User

Ships the `user` [aim](../../README.md) scope: per-user memory tied to a
real Drupal account. Part of [AIM](../../README.md).

Requires `aim`. Install this to let facts be written with `scope: user`
- without it, `user` isn't a valid scope on this site.

Decision records: [ADR-0007](../../adr/resolved/0007-user-scope-requires-real-account.md)
(the account requirement), [ADR-0025](../../adr/resolved/0025-scope-access-plugin-type.md)
(the access plugin), [ADR-0026](../../adr/resolved/0026-pluggable-scope-submodules.md)
(this submodule split).
