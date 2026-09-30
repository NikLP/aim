# AIM Scope: Entity

Ships the `entity` [aim](../../README.md) scope: memory about an
arbitrary Drupal entity, with view access mirroring that entity's own
rather than a flat per-scope permission. Part of [AIM](../../README.md).

Requires `aim`. Ships the `aim.aim_scope.entity` config entity and an
`AimScopeEntity` access plugin: a fact's `target_type`/`target_id`
(base fields on `aim_fact` itself, always present, unused by every other
scope) name the referenced entity, and `checkViewAccess()` returns that
entity's own view-access result. Install this to let facts be written
with `scope: entity` - without it, `entity` isn't a valid scope on this
site.

Create one via `drush aim:remember --scope=entity --target-type=<type>
--target-id=<id>` (single or `--file` batch), `aim_tool`'s `aim_remember`
Tool/MCP plugin (`target_type`/`target_id` inputs, single or `facts`
batch), the admin add form (`/admin/content/aim-facts/add/entity`), or a
direct `AimFact::create([...])->save()` call.

Decision records: [ADR-0025](../../adr/resolved/0025-scope-access-plugin-type.md)
(the access plugin type), [ADR-0026](../../adr/resolved/0026-pluggable-scope-submodules.md)
(the submodule split this follows), [ADR-0027](../../adr/resolved/0027-entity-scope.md)
(this scope itself).
