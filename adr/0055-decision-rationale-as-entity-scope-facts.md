# ADR-0055: Decision rationale as entity-scope facts - validate before building a `decision` entity

**Status:** Proposed - concept and validation test only, nothing built.
**Date:** 2026-10-08

## Context

The sibling `annopm` project's ADR-022 (decision provenance) proposes a
`decision` content entity: problem, options considered and why each was
rejected, chosen option, decision-makers, date, a draft -> proposed ->
accepted -> superseded workflow, `supersedes`/`superseded_by` edges so
stale decisions are marked rather than deleted, and a polymorphic target
reference to whatever the decision governs. Its own validation gate: hand-
model 5-10 ADRs and check the chain and target beat grepping the ADR
files. If not, don't build it.

Most of that shape already exists in `aim_fact`:

| ADR-022 needs | aim has |
| --- | --- |
| Non-destructive supersession | `superseded_by`, `expires`, `superseded_by_reason` (ADR-0005) |
| Polymorphic target | `scope=entity`, `target_type`/`target_id` (ADR-0027) |
| Date, author | `asserted` (valid time), `uid` |
| Accepted vs draft | `trusted` (boolean) |
| Finding by meaning, not keyword | vector `recall()` |

Semantic recall is the one thing grep cannot do, so it is the strongest
reason to run the test on aim.

## Origin (carried over from annopm ADR-022)

ADR-022 began as a deliberate search for a "codifying knowledge"
experiment that is *not* Annotations, meaning not another layer of
commentary attached to existing content. It landed on capturing decision
rationale: why something exists in its current form, what was not done,
and what would have to change to revisit it. Three kinds of codified
knowledge are distinct and should stay so in any write-up:

- **Descriptive commentary** - Annotations' job: a note about existing
  content.
- **Tacit judgment** - how experienced staff handle exceptions and edge
  cases, elicited via panel discussion (annopm's
  `pitch/judgment-capture-angle.md`, a `judgment_case` annotation
  bundle). Adjacent in spirit, but about staff exception-handling and
  tied to Annotations' entity model.
- **Decision rationale** - this ADR: architectural and product decisions,
  independent of Annotations' entity model.

ADR-022 checked for overlap with the first two before being written, and
found none beyond the shared theme.

## Decision

Do not build a `decision` entity in aim. Run the validation test first,
using facts that already work:

1. Take 5-10 of this module's own ADRs, including at least one
   superseded chain (for example 0039 -> 0040, or 0009 retired by 0035).
2. Store each decision as one or more atomic `scope=site` or
   `scope=entity` facts under a dedicated `aim_category` term (working
   name `decision`), via `drush aim:remember` or the ingest form
   (ADR-0016). For superseded decisions, retire the old fact with a
   `superseded_by_reason` naming the new one.
3. Ask a fixed set of "why did we decide X / what did we reject" questions
   phrased differently from the ADR wording, answer each by `aim:recall`
   and by grep over `adr/`, and record which found the right ADR.

Pass bar: recall finds the governing decision, and its superseded
predecessor stays out of default results, where grep returns both with
no hint which is current. If recall does not clearly beat grep on
differently-worded questions, stop; a `decision` entity would not either.

## Known gaps the test should probe

- **Structure.** Options-considered and why-rejected have no field. They
  would live in the fact text (one fact per option) or in
  `superseded_by_reason`-style JSON. Test whether atomic facts per option
  recall well or fragment the decision.
- **Workflow states.** `trusted` is boolean; the proposed/accepted/
  superseded lifecycle has no equivalent while Content Moderation is
  deferred (ADR-0002). Superseded is covered by `expires`; proposed vs
  accepted would need `trusted` repurposed.
- **Consolidation.** It merges or retires near-duplicate neighbors and
  could collapse two related but distinct decisions. Decision facts need
  the opt-out analyzed in ADR-0020 (not built), or the test must run with
  consolidation off for the category.
- **Name clash.** Annopm's ADR-008 already uses "provenance" for a
  `provider` field; aim has `superseded_by_reason` provenance too. Prefer
  "decision rationale" here.

## Consequences

- No code. The test is a manual exercise; record the outcome as an
  addendum here and update annopm's ADR-022 status either way.
- If it passes, the follow-ups are an ADR-0020-style category opt-out and,
  if proposed/accepted matters, a real status field. Both are separate
  ADRs.
- Feeds ADR-0024's annotations bridge: decision facts targeting an
  annotated entity would be its first concrete content, but that bridge
  stays blocked on the ADR-0002 gate and an Annotations write path.
- Distinct from ADR-0043 (content-truth drift audit): that checks content
  against facts; this stores why a decision was made.
