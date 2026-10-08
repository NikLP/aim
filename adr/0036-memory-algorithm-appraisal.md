# ADR-0036: Memory algorithm appraisal - what to borrow from the literature, and what aim already does badly

**Status:** Proposed 2026-10-02 - research only, nothing built. Each item
under "Candidates" is a separate decision if taken up.
**Date:** 2026-10-02

## Context

Prior comparisons ([ADR-0010](resolved/0010-drupal-native-agent-memory-rationale.md),
the mem0 code read, [ADR-0012](0012-fact-relation-graph.md)) compared
*products*. This ADR looks at the underlying *algorithms* and cognitive
models, and checks each against what `AimMemoryManager` does today
(`recall()`, `consolidate()`, `findNearestNeighbor()`).

Sources checked (web, 2026-10-02; claims below are from the papers'
own abstracts and summaries, not re-run by us):

- Generative Agents, Park et al. 2023 (arXiv 2304.03442): retrieval
  score is a weighted sum of recency (exponential decay, 0.995 per
  game hour since last access), importance (LLM-rated 1-10 at write
  time) and relevance (cosine); periodic "reflection" writes
  higher-level syntheses back into the store.
- Zep / Graphiti (arXiv 2501.13956): bi-temporal edges (when true in
  the world vs when recorded); contradicted facts are invalidated, not
  deleted. Reports up to 18.5% gain on LongMemEval and 90% lower latency
  vs full context.
- Hindsight (arXiv 2512.12818): four parallel retrieval paths (vector,
  BM25, entity/causal/temporal graph, explicit time filter) fused with
  Reciprocal Rank Fusion, then reranked; retain/recall/reflect
  operations. Reports 91.4% on LongMemEval at 120B.
- MemoryBank (arXiv 2305.10250): Ebbinghaus decay R = e^(-t/S) where
  strength S grows each time a memory is recalled.
- A-MEM (arXiv 2502.12110): Zettelkasten-style notes; a new note links
  to related ones and may rewrite their context.
- HippoRAG (arXiv 2405.14831): Personalized PageRank over an
  entity graph, seeded from vector hits, to answer multi-hop queries.
- MemGPT/Letta: tiered core/archival memory, the model pages between
  them. Not re-researched; recalled from prior knowledge.

Not verified: whether MariaDB FULLTEXT can sit beside the HNSW index in
the same Search API index (needed for BM25-style fusion).

## What aim does today (verified in code)

- `recall()` ranks by cosine distance only. `asserted`, `created`,
  `state` and `category` play no part in ordering.
- `recall()` rows return no dates at all (keys: id, score, scope,
  subject, text, source, state, trusted). The model cannot tell a fact
  from last week from one from three years ago.
- `asserted` (valid-time start) is collected by `remember()` and the
  Drush commands and then never read by any code path.
- Consolidation compares each fact to its single nearest neighbor
  (`findNearestNeighbor()` returns the first eligible hit).
- No access tracking: nothing records that a fact was recalled.
- `related` only ever holds the supersede edge (ADR-0012).

## Findings

### Cheap and clearly worth doing

1. **Return `created` and `asserted` (and `expires` if set) in
   `recall()` rows, and format them in the `aim_chatbot` and MCP
   output.** Nearly free: the entity is already loaded for every row.
   Valid-time storage already exists (Zep's headline idea) but is
   invisible to the only reader that matters, the LLM. Temporal
   questions ("where does X live now?", "what did we decide last
   month?") are the category Zep and Hindsight both benchmark on, and a
   flat list of undated facts cannot answer them. No schema change.
2. **Recency/importance as a re-rank over the vector hits, not a new
   index.** Over-fetch (`$limit * 3`), then order by
   `distance - w_r * recency_boost - w_i * importance`. `asserted ??
   created` gives recency with no new field. Importance can start as a
   tri-state from existing `category`/`state` (cheap) before any LLM
   rating. Keep the `recall_max_distance` cutoff (ADR-0019) applied
   to raw distance first so ranking tweaks cannot reintroduce poor
   matches. Default weights 0 so behavior is unchanged until measured
   against the benchmark harness.

### Worth doing, moderate cost

3. **Access counter + last-recalled timestamp** (MemoryBank / Generative
   Agents). Two base fields updated on recall. Gives decay a real basis
   and feeds the retirement sweep already planned in TODO.md
   ("sell-by" `expires`). Cost: a write on a read path. Do it in
   `kernel.terminate` or the queue, never inline. Skip for anonymous
   read-only callers (ADR-0008 addendum).
4. **Consolidate against top-k neighbors, not top-1** (mem0 does top-k).
   A fact contradicted by the second-nearest fact is never seen today.
   Cost is more classification calls in the ambiguous band only.
5. **Reciprocal Rank Fusion with a keyword path** (Hindsight, mem0 v3).
   Fixes the failure vectors are worst at: exact names, IDs, rare
   tokens ("Elena's brother"). Blocked on the unverified FULLTEXT
   question above. RRF itself is ~15 lines of PHP over two ranked
   lists, no score calibration needed, which matters because our
   distance scale is embedding-model specific (ADR-0005, ADR-0019).
6. **Entity-link lookup instead of a graph** (ADR-0012 already leans
   here). HippoRAG's Personalized PageRank is the principled version,
   but a bounded 1-2 hop expansion over an entity table captures most
   of it at a fraction of the cost.

### Not worth it for now

- **Reflection (Generative Agents / Hindsight CARA):** needs an LLM
  call per synthesis and writes inferred facts into a store whose
  governance gate (ADR-0002) is deferred. Revisit after the trusted
  gate exists; inferred facts must start untrusted.
- **A-MEM note rewriting:** mutating existing facts' text on new
  evidence conflicts with aim's non-destructive supersede stance.
- **MemGPT paging:** needs the model to manage its own memory; aim's
  value is the governed store, not agent self-management.
- **Full Ebbinghaus decay that auto-deletes:** retirement must stay
  soft and reviewable. Use decay as a ranking weight only.

## Things already implemented that are a mess

Candidly, in order of severity:

1. **`asserted` is collected but read by nothing.** Intended use is
   "when this was established/proven" (valid time), which is exactly what
   findings 1-2 would surface. Its field description says "when it became
   true in reality", a slightly different meaning; pick one and fix the
   description.
2. **`expires` means "retired".** Already agreed and tracked in TODO.md
   (`retired` timestamp); listed here only because finding 3 and
   ADR-0032 depend on it.
3. **Consolidation saw one neighbor** (finding 4). Fixed the same day:
   top-3, see the ADR-0005 addendum.
4. **ADR-0005 was stale.** Current-state addendum added 2026-10-02.
5. **`related` is overloaded** and nothing authors semantic edges; the
   multi-hop failure measured in ADR-0012 stands.

Not a mess: scope plugin architecture, the Guardrails-on-every-write
design, soft supersede, the distance cutoff (ADR-0019). These match or
beat what the cited systems do.

## Decision

None yet. Recommended order if accepted: 1, then 2 (measured against
`aim:benchmark` and a temporal gold set), then the `retired`/`expires`
split, then 4. Items 5-6 wait on the FULLTEXT check and the entity table
decision in ADR-0012.

## Consequences / risks

- Re-ranking changes what the LLM sees; tune weights against a gold set,
  not by feel (ties into the model-eval work already queued).
- Access tracking makes reads write; privacy-wise it is metadata about
  who looks at what, so keep it per-fact, not per-user.
- LongMemEval/Hindsight numbers are vendor-reported on different models
  and not comparable to our nomic-embed-text setup.

## Pinned 2026-10-02: `verified` / `verified_by`

`asserted` stays valid time ("true since", per its description and the
`--asserted` option). A separate "we proved this on <date>" needs two new
nullable base fields, `verified` (timestamp) and `verified_by` (uid), set
by a human review action, left empty by auto-trust. Do not repurpose
`trusted`: it is a policy gate that can be granted without any check
(`default_trusted`, caller override), so a timestamp there would mean
"let in", not "verified". Changing its type would also be a
boolean-to-timestamp base-field migration, the awkward path the
`subject_uid` rename took. A `trusted_at` timestamp beside the boolean is
the minimal alternative if only "when" is wanted. Not built.

## Pinned 2026-10-02: recency/importance re-rank (finding 2)

Not to be built until the top-3 consolidation change and the dated
`recall()` output have been tested on real use. Weights default to 0, and
need a gold set first.

## Found 2026-10-02: merging a time-limited fact into a permanent one

The top-3 dry run proposed merging "the library opens an hour earlier on
Tuesdays for two months starting from when this was noted" into the
standing opening hours. The merged text keeps the relative phrase but
drops its anchor date, so it becomes wrong once the two months pass.
Facts with an end or a relative date are a poor fit for UPDATE. Options,
none built: have the classifier answer ADD for time-limited facts, give
such facts a real `expires` sell-by date (TODO's `retired`/`expires`
split), or have the merge writer resolve relative dates against
`created`/`asserted`.

## Checked 2026-10-02: RRF keyword path is feasible without MariaDB FULLTEXT

The unverified question under Context is answered: `search_api_db`
ships inside the already-installed `search_api` package (not enabled
here). The route is a second, keyword-only index on a Search API DB server
over the same `aim_fact` text, queried alongside the vector index, with the
two ranked lists fused by RRF in PHP: score = sum of 1/(60 + rank) per
list, no calibration between distance and keyword scores. Notes:

- The DB backend's relevance is its own tf-idf style scoring, not true
  BM25. Fine for fusion since only rank order is used.
- Both indexes need the same access/trusted/retired conditions, and
  `ExcludeRetired` applied to the second index too.
- The distance cutoff (ADR-0019) cannot apply to keyword-only hits.
  Decide whether a keyword hit with no vector match under the cutoff may
  surface; start by requiring one list's hit to also pass the vector
  cutoff, or accept keyword-only hits and measure.
- Costs a second index to keep in step with writes and a second query per
  recall; keyword lookup is local SQL, no embedding call.
- Trigger: build when a real recall miss on a name, code or rare term is
  observed that the vector search could not find, or when the benchmark
  gold set shows one. Not before.
