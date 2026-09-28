# AIM Scope: Site

Ships the `site` [aim](../../README.md) scope: memory shared site-wide.
Part of [AIM](../../README.md).

Requires `aim`. Zero PHP - just the `aim.aim_scope.site` config entity.
[aim_chatbot](../aim_chatbot/README.md)'s tools are hardcoded to
`scope: site`, so any site running `aim_chatbot` needs this installed
(not yet an explicit module dependency - ADR-0026 piece 5, unbuilt).

Decision record: [ADR-0026](../../adr/0026-pluggable-scope-submodules.md)
(the submodule split).
