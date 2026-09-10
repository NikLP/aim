# ADR-0005: Consolidation algorithm - ADD/UPDATE/DELETE/NOOP with soft supersede

**Status:** Accepted
**Date:** 2026-09-09

## Context

Real usage will produce duplicate and near-duplicate facts - the same
statement extracted twice, or restated differently across sessions. Left
unchecked this both wastes retrieval quality (near-duplicates competing
in search results) and grows the corpus unboundedly. The AI dependency
map already splits this work by cost: pure similarity-threshold cases are
vector math only (free), but genuine conflict/merge judgment needs a
reasoning-grade LLM call. What was missing was the algorithm connecting
the two, and whether resolving a conflict should ever delete data
outright.

Two precedents were checked before designing this, not invented from
scratch: **Mem0**'s extraction-then-"update" stage, which picks one of
ADD/UPDATE/DELETE/NOOP per candidate against retrieved neighbors - that
vocabulary is adopted directly. **Hindsight**, whose "LLM-powered
consolidation" validated the existing reasoning/vector-math cost split
rather than requiring a fix to it, and which skips hard-deleting on
contradiction, letting a superseded fact fade via recency-weighted
retrieval instead - the precedent for soft-supersede over `DELETE` here.

## Decision

For each fact without `expires` already set, compare it against its
nearest neighbor (same scope+subject) via the existing vector index:

- **Score above `--ambiguous-threshold`:** not related enough, skip, no
  cost.
- **Score at or below `--auto-threshold`:** obvious duplicate, no LLM
  call - candidate gets `expires` set and `related` pointing at the kept
  fact (NOOP shape).
- **Otherwise (the ambiguous band):** one structured-output chat call
  asking the model to choose ADD/UPDATE/DELETE/NOOP for the specific
  pair. **ADD** - both facts stand. **UPDATE** - the kept fact's text is
  overwritten with the model's merged statement (after passing
  `runGuardrails()`, per ADR-0002 - a rejection downgrades to a synthetic
  `BLOCKED` outcome, leaving both facts untouched, rather than falling
  back to NOOP and retiring the candidate on unapproved text); the
  candidate is soft-superseded. **NOOP** - candidate soft-superseded,
  kept fact's text untouched (pure restatement). **DELETE** - candidate
  is hard deleted; reserved for cases the model judges shouldn't exist as
  a memory at all, not the default outcome.

Superseding is always soft: two nullable base fields, `expires`
(timestamp - when set, the fact is superseded, preserving an audit trail
per ADR-0002's governance concerns) and `related` (`entity_reference` to
`aim_fact`, unlimited cardinality - the fact(s) this one links to). This
doubles as the "explicit graph" half of aim's fact-to-fact relations idea
(CLAUDE.md, "Ideas raised, not designed") - populated by consolidation as
a side effect, not authored by hand.

## Consequences

- Threshold defaults must be set empirically against the site's actual
  embedding model, not borrowed from Mem0's/Hindsight's own (different)
  models - and must be **re-checked whenever the embeddings provider
  changes** (see ADR-0004), not treated as a one-time calibration. This
  was proven the hard way: thresholds set against `titan-embed-text-v2:0`
  (0.35/0.65) went stale the moment the embeddings model was swapped to
  `mistral-embed` mid-project, silently collapsing the entire observed
  score range under the old auto-threshold - every pair looked like an
  "obvious duplicate, no LLM review" case regardless of actual content.
  Caught via `--dry-run` before any real data was damaged, and
  recalibrated to `DEFAULT_AUTO_THRESHOLD = 0.05` /
  `DEFAULT_AMBIGUOUS_THRESHOLD = 0.20` as public constants on
  `AimMemoryManager` (single source for both the CLI command and the
  queue worker), closing the same class of drift the provider/model
  hardcoded-default problem in ADR-0004 already hit twice.
- The vector index has no idea `expires` exists (it isn't an indexed
  attribute) - a retired fact kept resurfacing as everyone's nearest
  neighbor until `findNearestNeighbor()` and `recall()` were both fixed to
  filter out anything with `expires` already set. Without this, retiring
  a fact didn't stop it from still fully answering live queries.
- A real duplicate pair (two facts about the same person, one addressed
  by uid and one by name) never got compared, because neighbor search
  originally scoped by the free-text `subject` string - see ADR-0007 for
  the actual fix (a real account reference, not a consolidation-side
  patch).
