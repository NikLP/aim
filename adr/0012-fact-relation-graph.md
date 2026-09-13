# ADR-0012: Fact-to-fact relation graph and multi-hop retrieval

**Status:** Proposed - exploratory best-guess estimate, not an accepted
design and not built. Written to capture what's known and flag risks
before any schema/code work starts, not to lock in a mechanism.
**Date:** 2026-09-11

## Context

`recall()` is one flat vector query with no traversal - by design, per
ADR-0010's mapping of Mem0/Zep's graph-of-context-objects concept onto
plain Entity Reference fields (no separate graph database). That storage
choice is settled; this ADR is about whether and how to actually use it
for authored, semantic fact-to-fact links, which is a different question
CLAUDE.md's "Ideas raised, not designed" list has carried since before
any evidence existed either way.

Evidence now exists. **Confirmed 2026-09-11** against the 50-fact
synthetic benchmark (see CLAUDE.md's Benchmarking/Ideas sections): a
3-hop query ("what field does Elena's brother work in?") only
half-answered, because one fact happened to name both "Elena's brother"
and his name in the same sentence - the actual answer (a separate fact
giving his field) never made the top 5. A 4-hop query (Alex to his dog's
vet's college classmate's co-author) returned nothing relevant at all -
the real four-fact chain never surfaced; an unrelated fact matched only
because it happened to mention "dog." This is expected behavior for a
flat vector query, not a bug, but it's the concrete failure mode a graph
feature would need to fix.

The one piece of graph-shaped storage that already exists is `related`
(`entity_reference` to `aim_fact`, unlimited cardinality, ADR-0005) - but
it's populated only as a side effect of consolidation's soft-supersede
step (candidate fact points at the fact it was merged/superseded by). Its
edge semantics ("this fact was replaced by that one") are not the same
thing as an authored semantic link ("this fact is related to that one for
reasoning purposes"). Reusing the same field for both would conflate two
different edge meanings in one column - a real design fork, not just "add
more rows."

## Decision (best-guess proposal - not committed)

Two separate open decisions, neither built:

**1. Where authored edges live.** A plain `entity_reference` field (like
`related`) can only carry a bare pointer, no edge metadata. If relations
need a type (parent-of, co-worker-of, ...), a confidence score, or a
record of what authored the edge, that needs a small reference entity
(e.g. an `aim_fact_relation` bundle: source fact, target fact, relation
type, provenance) rather than a base field - the standard
edge-attributes problem with using entity references as a graph
substrate, and the concrete point where Drupal's "graph for free" story
(ADR-0010) runs into a real limit. Best guess: start with a typed
relation entity rather than overloading `related`, given `related`
already has one meaning in production use.

A genuinely different alternative, seen in `mem0ai/mem0` @ `a488e19`
(2026-04-14, `feat(oss): port v3 pipeline with hybrid search, entity
extraction, and additive scoring`, PR #4805): don't link fact-to-fact at
all, link fact-to-entity. A second collection holds one row per
extracted named entity (person/place/thing), each row carrying the list
of fact IDs that mention it - a plain NER pass at write time, no LLM
relation-authoring call. Retrieval becomes "look up the entity row, get
every fact that names it" - a single indexed lookup, not a BFS. It
doesn't solve genuine multi-hop chains (the 4-hop dog-vet-classmate-
co-author case is a chain of distinct entities, not repeated mentions of
one), but it directly fixes the 3-hop "Elena's brother" case, where the
answer was already there in the corpus and only needed a byname lookup
instead of a lucky vector match. Worth having alongside, not instead of,
a typed relation entity if one gets built - they answer different
questions ("what mentions X" vs. "what does A imply about B").

**2. How `recall()` (or a new method) traverses it.** Best guess: bounded
BFS over the relation field, capped at 2-3 hops - going deeper than that
doesn't obviously help, since the 4-hop benchmark case already failed at
the seed match, before traversal depth was ever the limiting factor.
Two candidate trigger shapes, neither validated: (a) always run a second
pass expanding each vector-search hit into its directly-related facts
before handing results to the model, or (b) only traverse as a fallback
when the initial vector match's top score looks weak (a confidence
threshold). No basis yet to pick between them.

## Consequences / risks

- **Latency.** Confirmed baseline costs for a single `recall()` call are
  33-35ms local (Ollama) or 450-540ms hosted (`mistral-embed`), both
  dominated by the query-embedding network round trip, not the SQL layer
  (Benchmarking section, ADR-0010 open question 5). A bounded-depth
  entity-reference traversal needs no re-embedding, just indexed FK
  lookups - each hop should cost single-digit ms, in the same class as
  ADR-0010's existing "ordinary indexed WHERE query" characterization for
  structural lookups. The real risk isn't hop count, it's **fan-out**: a
  fact related to 10 others, each related to 10 more, grows
  combinatorially without a hard cap on total nodes visited, not just on
  hop depth. Needs a visited-node budget, not just a depth limit.
- **Storage overhead.** If edges need a dedicated entity (typed relations
  with metadata), that's a second entity type/table alongside `aim_fact`
  and its `aim_facts` vector collection table. ADR-0001's only real data
  point at PoC scale is that `aim_facts` runs ~2.5x `aim_fact`'s per-row
  size (the `VECTOR` column plus HNSW overhead) - no equivalent number
  exists yet for an edge table because none exists yet. Measure once one
  is built rather than estimating further.
- **Authoring cost.** Nothing writes these edges today. Two candidate
  sources, neither cheap: (a) ask the extraction LLM to also propose
  relations among facts it just extracted - a new "expensive" row on the
  AI dependency map, another reasoning-grade call per extraction batch;
  or (b) a dedicated post-hoc pass shaped like the consolidation queue
  worker, walking existing facts and proposing links on its own schedule
  - same cost profile, decoupled from `remember()`/`extract()` so it
    doesn't add latency to the write path. Manual admin authoring is
  cheapest to build but doesn't scale to real fact volume, and a graph
  *browsing UI* is explicitly out of scope until there's a real reason to
  browse rather than query (CLAUDE.md's existing stance on
  `revision_graph`) - worth being explicit that graph *retrieval* and
  graph *browsing* are separable, and this ADR is only about the former.
  A cheaper third option, seen in the same mem0 `a488e19` rewrite cited
  under Decision 1: fold relation-proposal into the extraction call
  that's already happening, by showing the model its own just-extracted
  candidates plus a handful of existing near-neighbor facts and asking it
  to tag IDs of related ones in the same structured-output response - no
  new reasoning-grade call added to the AI dependency map, since
  `aim:extract` already pays for one. The entity-linking alternative
  above is cheaper still (no LLM involvement at all, plain NER), at the
  cost of only covering same-entity co-mention, not authored semantic
  relations between different entities.
- **Guardrails.** An LLM-authored relation edge ("X is Y's manager") is a
  governance-relevant claim in its own right, not inert plumbing - the
  same untrusted-until-reviewed posture ADR-0002 already applies to fact
  text needs to apply to edges too, once either extraction-time or
  post-hoc edge authoring gets built. Don't treat edges as exempt
  metadata.
- **Interaction with consolidation.** Supersede (`related`+`expires`,
  ADR-0005) and authored semantic relations would coexist on the same
  fact. If fact A supersedes fact B, and something else holds an authored
  edge pointing at B, that edge needs to redirect to A or be recognized as
  stale - not resolved here. `recall()` already skip-filters superseded
  facts in PHP; a dangling authored edge needs an actual repoint/prune
  step, not just a filter, which the supersede case gets away with today.
- **Bad-seed amplification.** The 4-hop benchmark failure was a
  coincidental keyword match ("dog"), not a near-miss - a real risk is
  that traversal seeded from a bad initial vector match doesn't
  self-correct, it confidently expands into more wrong facts. The
  minimum-score cutoff already flagged as missing for the chatbot's plain
  `recall()` (CLAUDE.md's Chatbot gotchas) goes from "nice to have" to a
  precondition for this feature, since traversal has no independent way
  to sanity-check an expansion once it starts.

## Open questions

- **Directionality.** Entity reference fields are one-directional by
  default. If A relates to B, should querying B surface A? That needs
  either two fields, or a reverse-reference query - Drupal has no
  built-in reverse-reference index outside a Views relationship or a
  hand-built secondary table.
- **Relation vocabulary.** Fixed, admin-curated relation types (mirroring
  `aim_category`'s precedent - curated, never auto-created) vs. freeform
  LLM-authored strings. `aim_category`'s precedent points toward fixed,
  but not decided here.
- **API shape.** Whether traversal lives inside `recall()` itself
  (transparent to every existing caller) or as a separate opt-in method -
  same fork already open for the unrelated "fast-path typed lookup"
  idea's proposed `getState()` alongside `recall()`.

None of the above is validated against real code or real data - the
whole "Decision" section is a best guess to be replaced by an actual
design once this gets prioritized, not a spec to build against as-is.
