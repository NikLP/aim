# ADR-0030: Fact groups - a scope-agnostic `group` field, shipped as an `aim_group` submodule

**Status:** Accepted 2026-09-30 - design only, nothing built.
**Date:** 2026-09-30

## Context

Consolidation and `recall()` compare facts within one scope and, for
non-account scopes, one `subject` (`AimMemoryManager::findNearestNeighbor()`
filters on `scope`, then `user` or `subject`). Two consequences surfaced
while planning repeated grill sessions ([aim-discovery](../../../../../.claude/skills/aim-discovery/)):

- Each session that omits a subject mints a fresh case ID
  (`AimScopeCase::defaultSubject()`), so three sessions about one project
  are three unrelated cases and never see each other. Part of this is
  caller behavior: passing the same case subject would already join them.
- A fact has exactly one scope, so a `user` fact and a `site` fact from
  the same project cannot be tied together at all. A case only groups
  facts that are themselves `scope=case`.

ADR-0029's `batch_id` does not cover this: it is per-utterance provenance
("what else came out of that turn") and explicitly does not affect
retrieval. ADR-0012's fact-to-fact links relate two facts, not a set.

Cross-scope **merging** is deliberately out of scope. A `user` fact
absorbed into a `site` fact would leak private content, and an `entity`
fact would lose its access model. Any cross-scope synthesis is left to a
reading LLM at recall time.

## Decision

### 1. A nullable, scope-agnostic `group` base field on `aim_fact`

A plain string (a stable group ID, same shape as a minted case ID:
`grp-` + 8 hex chars when the group is created without a caller-supplied
one). Orthogonal to `scope`: any scope's facts may carry it.

- **No effect on access.** Each fact's view access is still decided by
  its own scope. A viewer of a group sees only the members they could
  see anyway.
- **No effect on consolidation.** It stays within scope plus
  subject/user, unchanged. Two facts in the same group but different
  scopes are never compared. Within one scope, `group` is an additional
  match condition only when set on the fact being consolidated, so
  three sessions sharing a group consolidate against each other even if
  their case subjects differ.
- **Not a scope.** Scopes decide access and behavior; a group decides
  only "these belong together". Keeping the two orthogonal is the point.

### 2. Shipped as an `aim_group` submodule

Core `aim` stays free of it; a site that does not want groups does not
carry the column.

- `aim_group` declares the base field through
  `hook_entity_base_field_info()` itself (it is not a scope type, so
  ADR-0028's plugin merge does not apply) and adds `group` to
  `aim_vector_index` as an indexed attribute with a BTREE index,
  following ADR-0018's pattern for `user`.
- **Uninstall:** follows `ScopeUninstallValidator`'s precedent. Refuse
  `pmu aim_group` while any fact has a non-empty `group`, so data is not
  dropped silently.

### 3. A small generic filter seam in core `recall()`

A submodule cannot filter recall results usefully without core help:
post-filtering breaks the result limit and ranking. Core `recall()` gains
a generic `extra_filters` argument (attribute name => value) that adds
conditions to the vector query, honored only for attributes present on
the index. `aim_group` supplies `group` through it. No `group`-specific
code lives in core. The same seam serves any future submodule-declared
indexed field.

### 4. Surface

`aim_group` exposes `group` on the write and read paths that already
exist: `remember()`/`extract()` accept it, `recall(group: X)` filters on
it, and the Tool API/MCP `aim_remember`/`aim_recall` schemas widen the
same way (ADR-0028 piece 4's pluggable-schema mechanism). A group has no
entity, title, or membership in this ADR; it is a tag. A titled,
access-controlled "project" is a case-scope question (ADR-0029 piece 5)
and is not decided here.

## Open questions

1. **Group titles:** a bare ID is unfriendly in a console chip. A
   `title` needs a home (a small config or content entity, or the
   ADR-0029 case-title work reused). Deferred; the tag is useful without.
2. **`group` vs case title, long term:** if ADR-0029 piece 5 gives cases
   titles and membership, some group uses may migrate to a case. This
   ADR does not preclude that: a group value can later become an entity
   reference.
3. **Seam shape:** `extra_filters` on `recall()` versus an event the
   submodule subscribes to. Decide at build time; argument form is
   simpler and is the default.

## Consequences

- Solves "three grill sessions cannot consolidate" and "tie facts of
  different scopes together" with one nullable column.
- Adds one base field, one index attribute (requires an index rebuild),
  an update hook, and a generic recall seam in core.
- Cross-scope grouping is retrieval-only. Merging across scopes stays
  out, by design.
- Existing behavior is unchanged for facts with no group.

## Related

[ADR-0001](resolved/0001-storage-and-scope-model.md),
[ADR-0012](0012-fact-relation-graph.md),
[ADR-0018](resolved/0018-index-subject-uid-with-btree.md),
[ADR-0026](resolved/0026-pluggable-scope-submodules.md),
[ADR-0028](resolved/0028-scope-type-plugin.md),
[ADR-0029](0029-context-carrying-turns.md) (`batch_id` is per-turn
provenance, distinct from `group`; piece 5's case titles and membership
overlap with open question 2).
