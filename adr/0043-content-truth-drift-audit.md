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
- **Findings can point at literals.** A flagged fact that is a bare value
  (a rate, an email address, a loan limit, a hold period) is a copy of a
  number that lives on an authoritative page, so it will drift again.
  Besides dismiss and update, a finding can offer "convert to literal"
  ([ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md)): store
  a pointer to the exact value instead of the number. The audit then also
  surfaces facts that should never have been facts.
- **Where findings surface.** Open. Candidates: a Views-backed report, a
  queue-style admin list, or (via [ADR-0024](0024-annotations-integration-target-scoped-promotion.md)'s
  bridge, if built) an annotation on the offending target.

## Open questions

- Pair granularity: whole page versus paragraph passages; passage splitting
  affects both recall of the contradiction and cost.
- Whether a `contradicts` verdict needs the grounding check (does the
  passage really say that) before a finding is raised.
- Time windows: **the model never reasons about dates.** Validity
  (`asserted`/`expires`) is chosen by a person with a date picker in the
  console and filtered by code before any pair is built, so an expired or
  not-yet-valid fact never reaches the classifier. Inferring a window from
  prose is deliberately out of scope. This needs `expires` to mean "valid
  until" rather than only "retired" (today any non-empty value retires a
  fact, see TODO.md).
- Detecting literal candidates: a separate typed question, "is this fact a
  single exact value that lives in one authoritative place?", asked per
  fact (no passage needed), so it can run over the whole fact store, not
  only on findings. Value-shaped facts (rates, contact details, limits,
  periods) say yes; policy-shaped ones ("alcohol needs Town Council
  permission") say no. Needs its own labelled set and precision check
  before it is trusted, and is independent of the drift classifier.
- Entity-scope target resolution when the target is a paragraph or other
  embedded entity rather than the node a human edits.

## Prototype result (2026-10-04)

A throwaway eval task, `decision-eval.py drift`, with 48 hand-labelled
synthetic (fact, passage) pairs
(`aim_benchmark/scripts/decision-eval-drift.json`: 32 clear, 16 hard),
hosted `jev-latest`, one typed choice per pair:

- The 32 clear pairs: 32/32 correct.
- Adding the 16 hard pairs (implied contradictions, multi-tier rules,
  different branch or group): 17/18 contradictions found, 2 false alarms in
  30 non-contradicting pairs, about 0.4 s a call.
- Both false alarms are arguably label errors, not model errors: a fact
  "closes at 6pm on 14 March" against a page saying "open until 8pm" is
  exactly the drift this audit exists to catch, and a pilot early-opening
  fact against a page listing 9am is a real discrepancy. That points at the
  main risk: the boundary between "exception" and "contradiction" is a
  human judgment, so findings need a dismiss path.
- Weak evidence: 48 pairs, labels written by the same author as the
  prompt, synthetic data, no real pages. It shows the classifier is
  viable for the check, not that the audit is precise on a real site.
  Next: real page passages and independently labelled pairs before any
  build decision.

## Addendum: real-passage validation (2026-10-04)

Set: `modules/aim_benchmark/scripts/decision-eval-drift-real.json`, 62 pairs
(28 contradicts, 17 supports, 17 unrelated; 24 marked HARD) built from
real public pages (US/UK libraries, a town council hall booking page, a
community centre, charity shop pages). Source URL and quoted passage on
every item; passages are short quotes taken via WebFetch, so they carry
less surrounding context than a full page would. Facts were written by
the prototype author. Run: `decision-eval.py drift jev-latest --drift-set
...decision-eval-drift-real.json`.

Labels: a second model (Opus, separate subagent, shown only fact and
passage, none of the author's intent or the prompt) labelled blind. It
agreed with the author on 62/62. Not human-independent: same model
family as the author, and a unanimous result on 24 deliberately hard
items is itself a reason for suspicion. The labeller flagged 15 items
below full confidence (ids 8, 9, 14, 16, 19, 20, 27, 34, 37, 38, 39, 44,
48, 58, 62), the genuinely borderline being 14 (25 items out is within a
limit of 60), 37 (8-day hold vs "a week"), 39 (a hire rate "starting at"
17.50 vs 25) and 48 (an email address vs a second one). Human
adjudication of those four is still outstanding.

Result (hosted jev-latest, 0.3 s a call):

- Contradictions: recall 28/28, precision 28/28, false alarms 0 of 34
  non-contradicting pairs.
- Three misses, all UNRELATED labelled SUPPORTS (never flagged, so
  harmless to the audit): regular-hirer deposit against a non-regular
  fee rule (Sheringham), non-residents paying vs "residents get a free
  account" (Knox), Newport Pagnell opening time vs a three-branch
  training-day rule (Milton Keynes).
- The model passed every HARD contradiction: implied, tiered, branch,
  exception. It also found all four borderline items (labelled CONTRADICTS), so those
  four are where a human may overrule the label.

Limits of the evidence: author-written facts skew toward crisp numeric
or time contradictions; real staff facts will be vaguer. The set has no
long pages, only quoted passages, so retrieval of the right passage (the
part this ADR leaves to the content index) is untested. A reviewer's
tolerance for false alarms will be set by real data, not this set.

Decision: BUILD, provisional. The suggested bar (at most 1 false alarm in
10 findings) is met with room (0 in 28). Conditions before the content
index work starts: (1) the four borderline labels (ids 14, 37, 39, 48)
were adjudicated 2026-10-04 as worth human review, so they stay
CONTRADICTS; all are value-shaped facts and literal candidates (see
Consequences),
(2) a smaller run on facts written by someone other than the prototype
author, (3) the dismiss path and the date-picker/expiry prerequisite stay
as already specified above.
