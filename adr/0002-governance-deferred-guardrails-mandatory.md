# ADR-0002: Governance deferred for PoC; Guardrails mandatory from day one

**Status:** Accepted
**Date:** 2026-09-08 (deferral), 2026-09-09 (Guardrails cheap-path built)

## Context

Every fact `aim` stores can originate from an LLM (extraction, chatbot
`aim_remember`, ECA's `FactWrite`, consolidation's UPDATE merges) - all
untrusted input by default. Full governance (Drupal permissions/roles for
access control, Content Moderation for provenance/audit and a
draft-to-trusted human-review gate) is architecturally required, but
building it now - Content Moderation requires `aim_fact` to be
revisionable, which it isn't (no revision entity key, no revision table) -
is a real schema lift, not a small one, and would slow down proving the
extraction/consolidation pipeline itself.

Separately: is "governance" one thing, or two things with very different
cost profiles that shouldn't be conflated?

## Decision

**Split governance into two independent pieces, defer one, build the
other immediately:**

1. **Content Moderation / draft-to-trusted (deferred).** No moderation
   state, no revisions, no human-review gate on `aim_fact` in the PoC -
   every fact is live the moment it's saved. This is a temporary
   deviation, not abandonment: re-introduce before any non-PoC data goes
   in.
2. **Guardrails (not deferred - mandatory from day one, decision 7).**
   Every candidate fact runs through `drupal/ai`'s Guardrails submodule
   before it's written anywhere, ahead of (not instead of) the
   draft-to-trusted gate above. Built as `AimMemoryManager::
   runGuardrails()`, called from `remember()`,
   `createFactsFromCandidates()`, `aim_eca`'s `FactWrite`, and
   consolidation's UPDATE path (merged text counts as LLM-proposed
   writing too - settled explicitly, see CLAUDE.md's "Consolidation"
   section).

The Guardrail Set actually shipped (`aim_write_guardrails`,
`config/install`) uses only `RegexpGuardrail` (blocks `<script>`-shaped
markup - facts render as plain text, HTML is out of place defense in
depth) and `InputLengthLimit` (2000 chars - a fact is meant to be one
short atomic statement). This was a deliberate cost choice, not the only
option: `drupal/ai` also ships `RestrictToTopic`, which does cost an LLM
call (topic classification) per candidate. Neither `RegexpGuardrail` nor
`InputLengthLimit` calls `->chat()` or implements
`NonDeterministicGuardrailInterface`, so the mandatory set costs nothing
beyond deterministic pattern/length checks - "mandatory Guardrails"
doesn't imply a mandatory extra LLM call.

## Consequences

- A guardrail stop throws `\InvalidArgumentException` deliberately, so
  every existing caller's error handling (already built to catch that
  exception for scope validation) covers a blocked write with zero
  additional changes.
- `createFactsFromCandidates()`'s batch path catches per-candidate rather
  than aborting the whole batch - one rejected fact doesn't discard the
  rest of an extraction run.
- Consolidation's UPDATE decision downgrades to a synthetic `BLOCKED`
  outcome on guardrail rejection rather than falling back to NOOP - NOOP
  would still retire the candidate fact on the strength of unapproved
  merged text; `BLOCKED` leaves both facts untouched, same as ADD.
- If the guardrail set is ever removed from a site's config, the check is
  silently skipped rather than blocking every write, matching the "the
  provider/config is a site choice" posture used elsewhere (ADR-0004) -
  not a bypass a candidate fact's own content can trigger.
- The deferred half's real cost: the `moderation_state` field/revision-
  table mechanism itself is cheap at
  this scale (the same mechanism `node` uses at far higher volume) - the
  actual schema lift is making `aim_fact` revisionable in the first place,
  a one-time change. Guardrails' own cost is real (network latency, and
  API cost if `RestrictToTopic` is ever added) but is a provider choice
  already covered by ADR-0004, not local-CPU contention on a shared host
  by default.
