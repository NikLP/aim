# ADR-0046: Where core stops - time as a retrieval axis, sibling modules for other semantics, and a shared gist finder

**Status:** Proposed 2026-10-04 - design only, nothing built. Follows from
[ADR-0045](0045-memory-kinds-episodic-semantic-procedural.md); amends
[ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md) (see
piece 4).
**Date:** 2026-10-04

## Context

ADR-0045 found that `aim_fact` is a semantic store, and that live data is
all semantic (plus human-authored rules). Discussion of that finding raised
four further points:

1. `category` is legitimate as it stands: a user-facing organizing axis (a
   solo knowledge store would use it to file arbitrary things). It is not a
   memory kind, and ADR-0045's wording that it was "doing a memory-kind job"
   is corrected here.
2. With no episodic memory the module is less useful to people, who think
   in time.
3. The aim is a generic module, and it is drifting toward over-complication.
   A PoC may be broad, but "extensible" needs a stated cutoff for what
   belongs in core.
4. Literals ([ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md))
   have value on their own, but are reachable today only through aim. The
   "gist" idea (a short description embedded so a thing can be shortlisted)
   may apply beyond literals, e.g. to choosing among tools.

## Decision (proposed)

### 1. `category` stays a user-facing taxonomy

Unchanged. Documented as: a free, user-chosen way to file facts, orthogonal
to scope and to any notion of memory kind. Nothing infers behavior from it.

### 2. Mitigate the missing episodic kind with time, not a kind

Make time a retrieval axis for every fact instead of adding a `kind` field:

- **Time-window recall:** `since`/`until` filters and an order-by-time
  option on `recall()` (event time `asserted`, falling back to `created`).
  [ADR-0032](0032-dated-category-listing-for-quick-notes.md)'s dated
  listing is the starting point.
- **Append-only events:** the verbatim opt-out
  ([ADR-0020](0020-verbatim-facts-consolidation-opt-out.md)) already keeps
  consolidation from merging near-duplicate events. Document it as the
  per-write way to record an event, and let callers set it from the write
  tools.
- **Recency weighting:** a recency weight as an optional recall parameter.

This covers most of the benefit of episodic memory with no schema change.
It also gives the currently unused `asserted` field a real caller.

### 3. The core cutoff rule

A feature belongs in `aim` core only if it changes write or retrieval for
**every** scope. Anything with its own retrieval semantics lives in a
sibling module or plugin on seams that already exist: scope-type plugins
([ADR-0028](resolved/0028-scope-type-plugin.md)), field types, Tool API
plugins.

| In core | Outside core |
| --- | --- |
| Scoped, Guardrail-checked text statements | Exact values with a fuzzy address (literals) |
| Time (`asserted`, windows, recency), provenance, category, verbatim | Event logs with workflow (support tracking) |
| Consolidation, supersede, recall, vector search | Rule enforcement ([ADR-0035](0035-standing-constraints-action-gate.md)) |

Procedural memory is, until a learned-practice use case exists, covered by
ADR-0035 (authored rules), so it is not a core concern.

### 4. Literals become a standalone module

Amends ADR-0040. Today a literal's gist is mirrored into an `aim_fact` with
`scope=literal`, so literals depend on aim. Invert it:

- **Standalone:** literals depend on Search API / `ai_search` (for the gist
  index) and Tool API, not on aim. The chooser model is optional.
- **Optional bridge:** aim can include literals in recall when both are
  installed.
- **Agent-facing contract:** a Tool API tool whose description is the
  trigger ("the user wants an exact value that cannot be inferred"). The
  agent gets a deterministic path to the value instead of relying on fuzzy
  recall. This is exact lookup with a fuzzy address, not a reduced aim.

### 5. One shared gist finder

A gist is a short natural-language statement of what a thing is for,
embedded so it can be shortlisted. The same finder serves any registered
item:

1. **Register** an item with an id, a gist, and an access check.
2. **Shortlist** by vector similarity, dropping anything the caller cannot
   access (before any model sees it).
3. **Choose** (optional) with a probabilistic check: given the query and the
   shortlist, which item best fits, or ambiguous / none.

Registrants:

- **Literals:** gist describes which value a question means.
- **Tool picker:** given a list of tools, use a probabilistic check to
  determine which one likely best fits. A tool's description is already a
  gist; the picker is this finder pointed at tool definitions.

The service holds no domain data. Whether it lives in literals, in its own
small module, or in aim is open (see below).

## Consequences

- No `aim_fact` schema change is required by this ADR. ADR-0045's `kind`
  field stays deferred.
- `recall()` gains time parameters; the write tools expose the verbatim
  flag; both are additive.
- ADR-0040 needs an addendum for piece 4, and its finder design is the
  basis for piece 5.
- The cutoff rule gives a single question to ask of any new idea: does it
  change write or retrieval for every scope?

## Open questions

- Where does the shared gist finder live: literals, its own module, or aim?
  (Leaning its own small module, so literals and the tool picker both
  depend on it without depending on each other or on aim.)
- Is the tool picker needed beyond Tool API's own tool selection, or only
  useful when the tool list is large?
- Should recency weighting be per-call, per-scope, or a site setting?
- Does anything need a hard event timestamp distinct from `asserted`
  (e.g. duration), or is one time field enough?
