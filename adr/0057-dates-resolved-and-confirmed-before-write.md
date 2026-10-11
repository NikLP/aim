# ADR-0057: Dates resolved and confirmed before a fact is written

**Status:** Proposed 2026-10-11 - points 3 (the `expires` input on `remember()`, `drush aim:remember`, batch/ingest entries and both remember tools) and the expiry sweep (`drush aim:expire`, hourly crontab) built and live-tested 2026-10-11; points 1, 2, 4-7 not built.
**Date:** 2026-10-11

## Context

Facts with a relative date ("until Friday", "from now on", "for two months
from now") go wrong over time, and nothing in aim catches it:

- [ADR-0036](0036-memory-algorithm-appraisal.md) (2026-10-02): an UPDATE
  merge of "opens an hour earlier on Tuesdays for two months from when this
  was noted" into the standing hours kept the phrase and lost its anchor,
  so the merged fact is wrong once the two months pass. Three options were
  listed and none chosen.
- The grounded check ([ADR-0037](0037-transient-source-passages-for-grounding.md))
  correctly passes "the reading room is closed for painting until Friday":
  it is what was said. It cannot tell that the fact becomes ambiguous next
  week. Faithful and durable are different checks.
- `expires` exists on `aim_fact` but nothing sets it, `remember()` has no
  parameter for it, and the sweep that retires past `expires` is unbuilt.
- The chat assistant is not given today's date, so it cannot resolve
  "Friday" even if asked to.

## Stance

Store the date, show the date, do not reason about time. Every fact
records when it was told and, if it has one, when it ends; facts past their
end retire themselves; everything else is shown with its age and left to
the reader to judge. Time logic in aim is limited to the points below.

Not adopting: bi-temporal records (Zep/Graphiti), recency or decay ranking
(Generative Agents, MemoryBank), a temporal graph, model-detected
staleness. Most memory systems ignore time beyond a timestamp; the
benchmarks (LongMemEval, LoCoMo) show temporal reasoning is the weak spot
of the whole field, and the machinery that targets it is not worth its
complexity here. Silent staleness (the world changed and nobody said so)
is not solvable by any memory system; the answer is to surface age (point
7) and, later, ADR-0043's drift audit.

## Decision (proposed)

Turn a relative date into a calendar date before the fact is written, and
have a person confirm it where one is present.

1. **Chat: confirm the resolved date.** The assistant gets today's date in
   its context. Before saving a fact with a date in it, its confirmation
   question states the calendar date ("Shall I note that the reading room
   is closed for painting until Friday 16 October?"). The persona already
   waits for confirmation before saving; this makes the date part of what
   is confirmed. The confirmed turn is in the grounded check's source text,
   so the dated fact is checked against what the visitor agreed to.
2. **The fact text carries the date.** "Closed for painting until Friday
   16 October 2026", not "until Friday". A merge then has nothing to lose:
   this replaces ADR-0036's "resolve at merge time" option.
3. **An end date sets `expires`.** `remember()` and both remember tools
   gain an optional `expires`; a fact with a stated end gets it. The sweep
   that retires facts past `expires` (TODO, ADR-0032) is what makes this
   self-maintaining, so it is part of this work, not a follow-up.
4. **No person present: resolve, do not ask.** Extraction (`aim:extract`,
   the ingest form's prose mode) resolves against the document's own date
   when it states one, else the ingest date, and says which in the fact
   ("as of 11 October 2026"). Review is the trust gate
   ([ADR-0002](0002-governance-deferred-guardrails-mandatory.md)), not a
   new step.
5. **MCP: the caller's job.** The `aim_remember` tool description asks for
   calendar dates; aim cannot confirm on another agent's behalf (the same
   limit as `source_text`, ADR-0037).
6. **Consolidation:** a fact with `expires` set is never UPDATE-merged into
   one without (time-limited into permanent); it is kept alongside (ADD).
   ADR-0036's first option, made checkable by point 3.

7. **Show the age.** Recall already carries created/asserted dates
   (ADR-0036); the assistant persona words older facts as "as of March
   2026" rather than as current truth. No staleness judgment by a model.

## Consequences

- Closes ADR-0036's time-limited-merge finding and gives `expires` its
  first caller.
- One more model judgment in the chat (spotting a date and resolving it),
  checked by the visitor rather than by a model.
- Eval: add dated chat items to `decision-eval-grounding.json` (resolved
  date confirmed: supported; relative date saved unresolved after the
  assistant stated a calendar date: unsupported).
- Out of scope: what to do when the fact contradicts published content
  (the reading room page still says open). That is
  [ADR-0043](0043-content-truth-drift-audit.md)'s drift audit: report to a
  person, never edit; parked, needs a content index.

## Open questions

- How today's date reaches the assistant: a token in the persona, or the
  recall tool's output (it already carries dates, ADR-0036). Unverified
  which `ai_assistant_api` supports.
- Timezone: the site's default, presumably; check `system.date`.
- Recurring or vague periods ("during the summer holidays", "most
  Thursdays") are not dates to resolve; keep them as words. The grounded
  check already marks dropping them down (eval item G8).
