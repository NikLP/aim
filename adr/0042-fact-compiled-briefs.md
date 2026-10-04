# ADR-0042: Fact-compiled briefs, following the annotations_docs pattern

**Status:** Proposed 2026-10-04 - design only, nothing built.
**Date:** 2026-10-04

## Context

Liao, "Agents don't need memory, they need documentation"
(liao.gg/blog/agents-dont-need-memory, 2026-10-04; read via a summarizing
fetch, no benchmarks in it, one author's year of anecdote). Its critique of
vector memory: similarity is not relevance, snippets lose context, an agent
cannot search for what it does not know exists, and thousands of embeddings
are unauditable. Its remedy is a human-readable Markdown "brain" consulted
before work and updated after.

Most of that does not describe `aim`: consolidation supersedes stale facts
non-destructively, facts are entities with provenance and a trusted flag,
and multi-user scoping cannot live in one shared Markdown file. Two
complaints do land: top-k recall cannot surface unknown unknowns, and a
pile of atomic facts is hard for a human to review as a whole.

The Annotations suite already solved the analogous problem.
`annotations_docs` generates an AI draft per target from annotation
content, stores it as an unpublished node, and supports human edit,
lock-against-regenerate, hand-edit warnings, per-audience (role) and
per-language output, and a manual publish step.

## Decision (proposed)

A readable **brief** per scope subject (a user, a case, an entity target)
is compiled from that subject's live, trusted, unsuperseded facts, and
injected whole into an agent's context instead of (or ahead of) top-k
recall.

`aim` builds its own, following `annotations_docs` as a pattern, not
using it: the input is facts rather than annotations, and `aim` should not
depend on the Annotations suite. Copy the shape: an AI draft per subject
stored unpublished, optional per-audience (role) filtering, a manual
publish step, and a config-entity system prompt. Deliberately not copied:
direct human editing of the brief body, and with it lock-against-regenerate
and hand-edit warnings, since an edit that bypasses the facts forks them. Where
[ADR-0024](0024-annotations-integration-target-scoped-promotion.md)'s
bridge later connects the two, a brief for an entity-scope subject can
surface beside that target's annotation documents, but neither requires
the other.

- Facts stay the source of truth. A brief is a derived view, regenerated
  when its facts change; the brief body is read-only.
- Correction is by feedback, not edit: a reviewer marks up a sandbox copy
  of the brief (or comments on it), and that feedback goes to an agent that
  turns it into fact changes (new, superseded or retired facts, through the
  normal Guardrails and trust path). The brief then regenerates from the
  corrected facts. Direct editing is reconsidered only if this loop proves
  very successful.
- Provenance: each brief records the fact IDs it was compiled from, so it
  is auditable back to rows.
- Trust: only `trusted` facts compile in; this reuses
  [ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s gate and
  does not need the Content Moderation work to start.
- Injection is size-bounded; a subject whose brief exceeds the budget falls
  back to recall.

## Open questions

- Feedback loop shape: what the sandbox copy is (a draft node, a diff
  view, plain comments), and whether the agent's resulting fact changes
  pass the grounded check
  ([ADR-0037](0037-transient-source-passages-for-grounding.md)) or wait
  for review.
- Regeneration trigger: on consolidation, on queue, or on demand?
- Storage: a node type like `annotations_document`, or an `aim`-owned
  entity (no node dependency in core `aim`).
- Not for per-visitor scopes at scale: one brief per user is an LLM call
  each. Likely only worth it for case, entity and site subjects.

## Consequences

Answers the unknown-unknowns and human-review complaints without dropping
the structured store. Costs one generation call per regenerated brief and a
new entity or node type to maintain. Needs no Annotations dependency; only
the optional bridge to ADR-0024 waits on its unbuilt blockers.
