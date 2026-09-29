# ADR-0025: Scope-specific view access as a plugin type, not hardcoded bundle branches

**Status:** Accepted - seam built and verified 2026-09-28. Its first
dedicated plugin moved out of core `aim` into `aim_scope_user`
(ADR-0026 piece 4, also 2026-09-28) and was renamed `AimScopeUser`
(was `AimUserScopeVisibility`) to match that module's own naming. A
second dedicated plugin, `AimScopeEntity`, was built 2026-09-29
([ADR-0027](0027-entity-scope.md)) - the "three data points" argument
below now rests on two shipped plugins and one still-unbuilt proposal
(`case`-scope access control). The interface and manager this ADR
defines (`AimScopeAccessInterface`/`AimScopeAccessPluginManager` below)
were themselves renamed `AimScopeTypeInterface`/`AimScopeTypePluginManager`
on 2026-09-29 ([ADR-0028](0028-scope-type-plugin.md) piece 0), once the
plugin's mandate grew past pure access-checking - read this ADR's body
with that later name in mind.
**Date:** 2026-09-28

## Context

`AimFactAccessControlHandler::checkAccess()` already special-cases one
scope: for `view` on a `scope: user` fact, it ORs the flat
`view user aim facts` permission with `AimUserScopeVisibility`'s
role-pairing matrix. Its own docblock already names the fork this ADR is
about: "a per-scope override, not a generic plugin-discovery point, since
only one scope has one today... a real plugin type is the identified next
step once a second scope needs its own rule, not before."

Two more scopes are now on a path to needing exactly that:

- [ADR-0024](0024-annotations-integration-target-scoped-promotion.md)'s
  discussion surfaced a proposed `scope: entity` bundle (a fact pointing
  at an arbitrary Drupal entity via a dynamic reference) whose natural
  access rule is "can this account view the referenced entity" - not
  expressible as a flat per-bundle permission the way `site`/`role`
  already are.
- `case` scope (support tracking) has no access control of its own yet -
  already an acknowledged gap, not new information.

Three data points (`user` today, `entity` and `case` on deck) is the
threshold this codebase's own comment already set for converting
hardcoded branches into a real plugin type, the same "don't abstract
before it's earned" instinct [DEVELOPING.md](../DEVELOPING.md)'s
"Scope/bundle model" section applies elsewhere on this entity (per-bundle
*fields* were tried once and reverted for being premature relative to a
real need - this ADR is the mirror-image case, where the need is now
real).

A working precedent for the plugin shape already exists one module over.
Annotations' `TargetPluginManager` (`plugin.manager.annotations_target`)
uses standard Drupal Plugin API attribute discovery
(`#[AnnotationsTarget]`), with `GenericTargetDeriver` producing a generic
plugin per fieldable entity type and dedicated plugins (`RoleTarget`,
`ViewTarget`, etc.) shadowing the generic one where special handling is
needed. Proven at that module's scale already; not something to redesign
from scratch here.

## Decision

**New plugin type, `AimScopeAccessInterface`, scoped to exactly the one
seam that's actually duplicated today:**

```php
interface AimScopeAccessInterface {
  public function checkViewAccess(AimFact $fact, AccountInterface $account): AccessResultInterface;
}
```

Deliberately narrow. Not folded in: guardrail-set selection or
`aim_tool`/MCP scope-param validation, both flagged as their own
scattered-switch problems in earlier analysis, each with no second
concrete example yet. Widening this interface to cover them now would be
designing for a need that hasn't shown up twice - the same discipline
that justifies building this seam at all.

**Plugin manager with a default fallback, not a mandatory plugin per
scope.** Keyed by scope (bundle) ID. A scope with no registered plugin
falls through to a no-op default that reproduces today's behavior
exactly - the flat `view {scope} aim facts` permission check alone. This
is what keeps [ADR-0001](0001-storage-and-scope-model.md)'s "a fifth
scope needs zero PHP" promise alive for any future scope that doesn't
need custom access logic (still true for `site`/`role` today). Unlike
Annotations' `GenericTargetDeriver`, which generates one derived plugin
per fieldable entity type because it faces real multiplicity, `aim` has
one flat default shared across every non-dedicated scope - no deriver
needed unless that changes.

**`AimFactAccessControlHandler::checkAccess()` refactored to ask the
plugin manager uniformly**, replacing the `if ($entity->bundle() ===
'user')` branch with a lookup against the fact's own bundle. No behavior
change for `user` scope, `site`, or `role`.

**`AimUserScopeVisibility` becomes the first dedicated implementation**,
not a new class. It already backs a real, tested admin UI
(`/admin/config/aim/user-scope-access`) - this ADR re-wires its calling
convention, it doesn't touch its logic.

## Consequences / risks

- ~~**This ADR alone changes nothing observable.**~~ **No longer true as
  of 2026-09-29.** With only `user`'s plugin registered, the refactor was
  pure indirection until a second dedicated plugin actually existed - it
  now does (`AimScopeEntity`, [ADR-0027](0027-entity-scope.md)), and its
  "replace, not widen" combinator (see that ADR) is genuinely different
  administrative behavior from `AimScopeUser`'s "widen," not just a second
  copy of the same shape. `case`-scope access control remains the one
  still-unbuilt proposal.
- **Low-risk mechanical refactor**, same category as the 2026-09-14
  scope-to-bundle conversion (see `project_aim_scope_bundle_conversion_tradeoffs`
  memory) - a calling-convention change, not a logic change, for the one
  scope with existing behavior.
- **Cacheable metadata discipline carries over from Annotations.** A
  plugin's `checkViewAccess()` can introduce new cache contexts (e.g. an
  entity-scope plugin checking the referenced entity's own access needs
  that entity's cache tags/contexts folded into the `AccessResult`, not
  silently dropped) - the same requirement Annotations' own CLAUDE.md
  states for its context-assembly code, worth importing deliberately
  rather than re-discovering by way of a stale-cache bug.
- **`checkCreateAccess()` has no equivalent hook today** - only the flat
  `create {scope} aim facts` permission. Whether create-time logic (e.g.
  entity-scope requiring edit access on the referenced entity, not just
  view) ever needs the same plugin seam is a real open question, not
  addressed by this ADR.
- **This ADR builds the seam only.** The actual `entity`-scope access
  plugin (per-referenced-entity view derivation, per ADR-0024's
  discussion) and any future `case`-scope plugin remain separately
  unbuilt, each needing its own design once undertaken.

## Open questions

- ~~**Discovery mechanism.**~~ **Resolved 2026-09-28.** Built as standard
  attribute-based Plugin API discovery (`#[AimScopeAccess]`, directly
  mirroring `#[AnnotationsTarget]`), not a tagged-service compiler pass -
  `Drupal\aim\AimScopeAccessPluginManager` (`plugin.manager.aim_scope_access`),
  plugins under `src/Plugin/AimScopeAccess/`.
- ~~**Ownership.**~~ **Resolved 2026-09-28.** Core `aim` module, as
  expected - `AimFactAccessControlHandler` already lived there.
- **Whether a generic/derived plugin is ever needed**, or whether the
  single shared default fallback is sufficient permanently given `aim`
  doesn't face the same per-entity-type multiplicity Annotations does.
  Still open - `getAccessPlugin()` returns NULL for any unregistered
  scope rather than instantiating a generic plugin, matching the "no
  deriver needed unless that changes" call above; unchanged by this
  build.

Verified live 2026-09-28: `AimFactAccessControlHandler::checkAccess()`
looks up `getAccessPlugin($entity->bundle())` uniformly (no more
hardcoded `bundle() === 'user'` branch), a disposable scope=user fact
confirmed a viewer with no flat permission gets view access solely
through `AimUserScopeVisibility`'s shared-role fallback, and a viewer
sharing no role was correctly denied - same behavior as the
pre-refactor code, not a logic change.
