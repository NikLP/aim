# AIM Scope: Site

Ships the `site` [aim](../../README.md) scope: memory shared site-wide.
Part of [AIM](../../README.md).

Requires `aim`. Zero PHP - just the `aim.aim_scope.site` config entity.
[aim_chatbot](../aim_chatbot/README.md) depends on it: its optional
recall-in-code subscriber reads `scope: site`. Chat's tools themselves
reach whatever scopes the visitor's permissions allow (ADR-0062).

Decision record: [ADR-0026](../../adr/resolved/0026-pluggable-scope-submodules.md)
(the submodule split).
