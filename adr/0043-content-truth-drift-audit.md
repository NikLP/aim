# ADR-0043: Content-versus-truth drift audit: facts as an independent reference for published content

**Status:** Proposed 2026-10-04 - design only, nothing built.
**Date:** 2026-10-04

## Context

Memory is usually pictured as something a chat reads from. This ADR is
about a consumer that is not a conversation: a background process that
uses facts as a **reference to check published content against**.

Staff tell the site things in passing ("the Leeds branch is closed on
14 March", "the lift is out on Tuesday"). Those become dated, trusted facts
([ADR-0032](0032-dated-category-listing-for-quick-notes.md),
[ADR-0002](0002-governance-deferred-guardrails-mandatory.md)). The pages
people actually read were published earlier and nobody edits them. The two
diverge silently, and a CMS has no way to notice: a page is only ever
compared with itself.

Facts are the right reference because they are the one source that is
newer than the content, scoped, dated, and revisable without an editor
workflow. Annotations and literals describe how the site is built;
neither records what was said last Monday.

Site-building facts are scaffolding: once promoted into annotations and
literals they are redundant. Facts that stay worth keeping are temporal,
per-case or about rationale. This ADR targets the temporal kind.

## Decision (proposed)

A **drift audit** compares live facts with published content and reports
disagreements to a human. It never edits content.

1. **Inputs.** Trusted, unsuperseded, unexpired facts in the scopes whose
   subject is public or staff-visible: `site`, `role`, and `entity` (the
   target is already known, so the candidate page is exact). `user` facts
   are excluded: a private fact must never be compared against, or
   mentioned next to, public content.
2. **Candidate pages.** `entity` facts name their target. For `site` and
   `role` facts, a shortlist comes from a Search API index over published
   content (a second index, separate from `aim_vector_index`; the content
   index is a prerequisite and is not built).
3. **Verdict by a typed decision.** For each (fact, passage) pair a
   decision model ([ADR-0021](0021-jev-typed-decision-provider.md))
   returns one of `supports`, `contradicts`, `unrelated`. A closed set of
   options is the easy case for a classifier, and it cannot invent text.
   Only `contradicts` becomes a finding.
4. **Finding, not fix.** A finding records fact ID, entity ID and revision,
   passage offsets, the verdict and the model ID. It carries no copy of the
   fact or page text (CLAUDE.md's logging rule). It is shown to anyone who
   can both see the fact and edit the entity. Resolution is human: edit
   the page, retire the fact, or dismiss as a false positive.
5. **Triggers.** Queued, never inline
   ([ADR-0003](resolved/0003-async-processing-dedicated-crontab.md)): on a
   fact becoming trusted or superseded, and on a content save (the other
   direction: new page text checked against the live facts it touches).
6. **Self-healing.** A finding closes automatically when its fact expires
   or is retired, or when the page revision changes and re-check returns
   `supports` or `unrelated`. A dated "closed 14 March" thus produces a
   finding only while it is still true.

## Consequences

- Gives memory a job that is not "a person chats and gets details back":
  it becomes an independent source of truth for an editorial audit, and for
  any other automated process that wants a reference (the same facts back
  [ADR-0035](0035-standing-constraints-action-gate.md)'s action gate).
- **Precision decides usability.** A noisy audit gets switched off. The
  `unrelated` option and a human-only resolution path are the mitigation;
  the false-positive rate must be measured on a labelled set before this
  ships, in the same way as the grounded evaluation in
  [ADR-0037](0037-transient-source-passages-for-grounding.md).
- **Garbage in, flagged out.** A wrong but trusted fact produces false
  findings against correct pages. The trust gate and the plausibility gate
  ([ADR-0033](0033-plausibility-gate-processing-modes.md)) bear more weight
  here than in chat, where a wrong fact only affects one answer.
- **Cost.** One classifier call per (fact, passage) pair, bounded by the
  shortlist size and by queueing. Decision models are small and typed, so
  this suits a local model once one is measured acceptable
  ([ADR-0038](0038-local-decision-models-parked.md)).
- **Content index.** A new dependency: a Search API index over published
  content, kept in sync. `ai_search` may already provide one on a given
  site; core `aim` should not require it.
- **Where findings surface.** Open. Candidates: a Views-backed report, a
  queue-style admin list, or (via [ADR-0024](0024-annotations-integration-target-scoped-promotion.md)'s
  bridge, if built) an annotation on the offending target.

## Open questions

- Pair granularity: whole page versus paragraph passages; passage splitting
  affects both recall of the contradiction and cost.
- Whether a `contradicts` verdict needs the grounding check (does the
  passage really say that) before a finding is raised.
- Time-aware verdicts: "closed 14 March" does not contradict "open Monday
  to Friday" on 13 March. The decision input must carry the fact's
  `asserted`/`expires` window and the evaluation date.
- Entity-scope target resolution when the target is a paragraph or other
  embedded entity rather than the node a human edits.
