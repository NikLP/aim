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

## Addendum (2026-09-28): draft-to-trusted resolved as a lightweight flag, not Content Moderation

Re-examined while unblocking [ADR-0024](0024-annotations-integration-target-scoped-promotion.md).
Content Moderation was the obvious mechanism (this ADR's own Context
section named it), but doesn't earn its cost here:

- It requires making `aim_fact` revisionable - the schema lift this ADR
  already flagged as the actual cost, not a small one, on a table that's
  already 14 columns wide before this.
- Keeping `recall()`/the vector index clean would need either a
  `moderation_state`-aware Search API processor (the same shape ADR-0022
  already built once for `expires`, not reusable as-is) or adopting
  `EntityPublishedInterface` purely to get Content Moderation's
  publish-state sync - both are more moving parts than the alternative.
- Two new hard module dependencies (`content_moderation`, `workflows`) on
  a module headed to drupal.org that's deliberately trying to stay thin.
- Trust is a retrieval-quality decision ("should this be surfaced as
  authoritative"), not an identity-based access rule - it's orthogonal to
  [ADR-0025](0025-scope-access-plugin-type.md)'s scope-access plugin work
  and shouldn't be folded into it.

**Decision: one new base field, `trusted` (boolean)**, indexed as a
Search API attribute from the start (the same treatment `subject_uid`
already gets, ADR-0018) - filtered at query time, not post-filtered in
PHP, avoiding the result-slot-eating mistake ADR-0005's addendum already
paid for once with `expires`. `recall()` defaults to `trusted = 1`; an
explicit param lets the review queue itself see untrusted rows too.

**No new command or form needed to set it.** `aim_fact` already declares
real form handlers and routes (`ContentEntityForm`, `edit-form` at
`/admin/content/aim-facts/{aim_fact}/edit`, gated on `administer aim
memory` for update per `AimFactAccessControlHandler`'s own docblock) -
it just has no `entity_form_display` config, so it renders with
Drupal's default fallback widgets rather than a curated layout. Making
`trusted` `setDisplayConfigurable('form', TRUE)`, the same treatment
`category`/`source`/`asserted` already get, puts a checkbox on that
existing form for free. `AimFactListBuilder`'s existing admin listing at
`/admin/content/aim-facts` is the review queue, also for free. No Drush
command, no bespoke review UI.

**Default value resolved as a config setting, not a hardcoded
per-write-path decision.** `aim.settings:default_trusted` (boolean),
alongside the existing tunables there (`auto_threshold`,
`recall_max_distance`, etc.), editable via the existing
`AimSettingsForm` at `/admin/config/aim/settings`. Read uniformly
wherever a new `aim_fact` gets created - one site-wide policy, not
branching logic per caller. A cautious site ships `FALSE`; a site that
trusts its own extraction pipeline sets `TRUE`. This resolves the "still
open" item below about per-write-path defaults - it's a site's choice to
configure, not a hardcoded rule `aim` itself imposes.

**`trusted_by` (who approved it) is deliberately skipped for now, not
forgotten.** It isn't safely inferable from the existing `uid` field -
`uid` means "who/what authored the fact" (often a service account for
LLM-extracted or consolidation-authored facts), and the entire point of
a review gate is that the reviewer can be a different person from the
author; collapsing the two would either destroy existing authorship
provenance or make review unattributable for exactly the facts worth
reviewing. The one legitimate simplification - defaulting `trusted_by`
to `uid` automatically for self-trusted writes (a human's own verbatim
`aim:remember`, no real review happening) - doesn't remove the need for
a real, distinct value on the reviewed path, so this is a genuine scope
cut (losing "who approved this"), not a free technical win. Revisit only
if the audit-of-approver claim becomes load-bearing (e.g. for the
public-sector/audit-trail pitch raised in an earlier discovery
conversation), not preemptively.

**Per-scope trust permission, BUILT 2026-09-30:** `trust {scope} aim
facts` is generated via `BundlePermissionHandlerTrait` and checked by a
custom `trust` operation in `AimFactAccessControlHandler`, used by the
Trust/Untrust bulk actions. Motivating case: site editors approving
site-scope facts but not user-scope ones, without update access to the
fact text. `administer aim memory` still passes. Still open: the
`trusted` override on `remember()` bypasses it.

Promotion into Annotations ([ADR-0024](0024-annotations-integration-target-scoped-promotion.md))
doesn't depend on any of this either way - `annotation` entities are
already revisioned on Annotations' own side, and promotion is fully
custom/programmatic code, not bound to whatever mechanism `aim_fact`
uses for its own trust gate.
