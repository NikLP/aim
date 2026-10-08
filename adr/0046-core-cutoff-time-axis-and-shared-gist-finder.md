# ADR-0046: Where core stops - time as a retrieval axis, sibling modules for other semantics, and a shared gist finder

**Status:** Proposed 2026-10-04, updated 2026-10-08 - pieces 1-3 (category
stays, time as a retrieval axis, the core cutoff rule) are design only,
nothing built; piece 4 is done (literals are standalone,
[ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md));
piece 5 became `literals_finder` plus the parked
[ADR-0049](0049-finder-family-parked.md). Absorbs the former ADR-0045
(memory kinds), kept below as the Background.
**Date:** 2026-10-04

## Context

The memory-kinds assessment (Background below, formerly ADR-0045) found that `aim_fact` is a semantic store, and that live data is
all semantic (plus human-authored rules). Discussion of that finding raised
four further points:

1. `category` is legitimate as it stands: a user-facing organizing axis (a
   solo knowledge store would use it to file arbitrary things). It is not a
   memory kind, and the earlier wording that it was "doing a memory-kind job"
   is corrected here.
2. With no episodic memory the module is less useful to people, who think
   in time.
3. The aim is a generic module, and it is drifting toward over-complication.
   A PoC may be broad, but "extensible" needs a stated cutoff for what
   belongs in core.
4. Literals ([ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md))
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

**Done:** literals shipped as a standalone module with no `scope=literal`
and no mirror of gists into `aim_fact`; `aim` is to consume the finder
(ADR-0040 section 9, ADR-0052 addendum). The text below is the original
proposal. Today a literal's gist is mirrored into an `aim_fact` with
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

**Outcome:** the finder exists as `literals_finder` (a Decision API chooser
over the whole menu; the vector shortlist was built and removed, ADR-0040).
The tool picker and meaning navigator are parked in
[ADR-0049](0049-finder-family-parked.md). The original proposal follows.

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

## Background: memory kinds (formerly ADR-0045, merged 2026-10-08)

**Question.** Should `aim_fact` distinguish semantic ("what is true"),
episodic ("what happened, and when") and procedural ("what works best")
memory? [ADR-0001](resolved/0001-storage-and-scope-model.md) split memory by
whose it is (scope), never by kind. `aim_fact` is semantic by design: `text`
is one short statement, and consolidation, `superseded_by` and `expires`
assume a fact is a claim a newer claim can replace. Columns that picked up
type-like meaning without a decision: `category` (a filing axis, legitimate,
not a kind), `state` (a flag, semantic), `superseded_by`/`expires` (wrong
for append-only episodic and revised-in-place procedural), `asserted` (one
validity point; episodic would need an event time or duration), `source`
(a pointer, not the episode), `scope` (orthogonal).

**Live assessment (2026-10-04, 63 facts, 58 live).** A 25-fact sample was
overwhelmingly semantic; nothing recorded something that happened (the dated
items are future or time-bounded claims: semantic with a validity window,
the job `asserted`/`expires` was built for, and `asserted` was unused);
the "procedural" items were authored policy ("editors must add alt text"),
which belongs with [ADR-0035](0035-standing-constraints-action-gate.md)'s
standing constraints, not a new kind. The module's real use is governed,
scoped, retrievable statements of site and organizational truth: one kind.

**If kinds were built.** A `kind` field would change behavior: consolidation
skipping episodic and revising procedural in place; recall ranked by
recency for episodic and by trigger for procedural; episodic needing an
event time and procedural a trigger and an outcome
([ADR-0012](0012-fact-relation-graph.md)). Costs: an update hook, a vector
index schema change if filterable, classifying extraction prompts (a new
hallucination surface on every write), consolidation branching, admin UI.
Benefit today: near zero, because no live data would populate it.

**Decision.** Document only: `aim_fact` is semantic memory, `category` is
not a kind tag, policy-style rules go to ADR-0035. Revisit when a first
non-semantic consumer lands (support tracking, a CMS-publishing integration),
and then decide whether episodic belongs in `aim_fact` at all or in a sibling
entity, since append-only event logs have different volume, retention and
indexing needs. Open: whether support tracking is its own entity (leaning
yes); whether any procedural memory is ever learned here rather than
human-authored; whether `asserted` and `state`, both unused, should be
exercised by a real caller before anything else is added.

## Consequences

- No `aim_fact` schema change is required by this ADR. A `kind`
  field stays deferred (see Background).
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
