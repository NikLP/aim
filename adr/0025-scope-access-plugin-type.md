# ADR-0025: Scope-specific view access as a plugin type, not hardcoded bundle branches

**Status:** Proposed - exploratory, not built. Written to capture the
mechanism before either concrete driver (entity scope, case scope) is
built, not to lock in implementation details.
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

## Decision (best-guess proposal - not committed)

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

- **This ADR alone changes nothing observable.** With only `user`'s
  plugin registered, the refactor is pure indirection until a second
  dedicated plugin (entity scope, or case scope) actually exists. The
  "three data points justify a plugin type" argument rests on two of
  those three points being still-unbuilt proposals, not shipped code -
  worth being honest that this is refactor-ahead-of-need in the narrow
  sense, justified by the *shape* of what's coming, not by present
  duplication alone.
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

- **Discovery mechanism.** Standard attribute-based Plugin API discovery
  (`#[AimScopeAccess]`, directly mirroring `#[AnnotationsTarget]`) versus
  a tagged-service compiler pass. Following Annotations' precedent
  directly is the more defensible default; not committed here.
- **Ownership.** Core `aim` module - `AimFactAccessControlHandler`
  already lives there, and this isn't scope-specific enough to belong in
  a submodule the way `aim_annotations` would (per ADR-0024).
- **Whether a generic/derived plugin is ever needed**, or whether the
  single shared default fallback is sufficient permanently given `aim`
  doesn't face the same per-entity-type multiplicity Annotations does.

None of the above is validated against real code - this captures the
mechanism and its justification, not a spec to build against as-is.
