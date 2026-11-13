# ADR-0057: Dates resolved and confirmed before a fact is written

**Status:** Proposed 2026-10-11, partly built. Built: point 3 (the
`expires` input on `remember()`, `drush aim:remember`, batch/ingest entries
and the remember tool) and the expiry sweep (`drush aim:expire`, hourly
crontab, `hook_cron` as backup), 2026-10-11; point 5 (`aim_remember`'s
description asks for calendar dates, never relative ones); point 6 (facts
with different `expires` are never compared, 2026-10-27, ADR-0036
addendum); half of point 7 (`aim_recall` returns each fact's date and
which kind it is). Not built: point 1 (the chat persona has no date),
point 2 for chat, point 4 (extraction), point 7's persona wording. The
2026-11-12 addendum (time check at write time) supersedes the stance line
"do not reason about time" and point 5; built, default off.
**Date:** 2026-10-11

## Context

Facts with a relative date ("until Friday", "from now on", "for two months
from now") go wrong over time, and nothing in aim catches it:

- [ADR-0036](resolved/0036-memory-algorithm-appraisal.md) (2026-10-02): an UPDATE
  merge of "opens an hour earlier on Tuesdays for two months from when this
  was noted" into the standing hours kept the phrase and lost its anchor,
  so the merged fact is wrong once the two months pass. Three options were
  listed and none chosen.
- The grounded check ([ADR-0037](resolved/0037-transient-source-passages-for-grounding.md))
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
   ([ADR-0002](resolved/0002-governance-deferred-guardrails-mandatory.md)), not a
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

## Addendum (2026-11-12): a time check at write time

A chat test saved "peanut snack on 11 November" with the date repeated in
the text, and time-bound facts ("closed until 16 October") carried no
`expires`, so they went stale. This replaces the stance line "do not
reason about time" and point 5, "MCP: the caller's job": a model now judges
one narrow thing at write time, and the caller is asked for the date
instead of trusted to give it. Recall still does no time reasoning.

- **The check.** One yes/no decision question per candidate: "Does
  candidate_N state or imply a date after which it stops being true?"
  ("fact" is not used: Jev does not know the term). It runs on the grounded
  check's decision model, in the same batched call when grounding is on, and
  has its own setting, `time_check_mode` (`off` default, `flag`, `reject`),
  so it does not vanish when grounding is off. Cutoff:
  `activities.grounding.time_threshold`, 0.3.
- **On a yes with no `expires`.** `aim_remember` (chat and MCP) refuses the
  entry with a retry error asking for `expires` as `YYYY-MM-DD`, or the
  explicit `"open-ended"`. Where no caller can be asked (the ingest form,
  `aim:extract`, Drush): `flag` saves the fact untrusted for review, `reject`
  refuses it. A failed check changes nothing (it never loops a caller).
  `"open-ended"` skips the check.
- **`expires` defined.** The last day the fact is true, inclusive, in the
  site timezone; `aim:expire` retires it once that day has ended. (It was
  UTC end of day.)
- **Text.** `aim_remember`'s `text` input stops asking for dates in the text
  as a rule: a date goes in only when it differs from the save date or is the
  point of the fact.
- **Evidence.** A 12-fact run on `subq-snap-1.2` (24 questions, 0.8s): the
  "ends after a date" question separated cleanly while a four-way "kind"
  question scored 11/12. The gold set (`decision-eval-time.json`, 23 facts:
  explicit and relative ends, periods, future events, standing facts, past
  events, start-only dates; labelled by whether an `expires` is right, so
  past events and start-only dates are "open"; `decision-eval.php -- time`)
  compared the wordings. The first candidate, "Is candidate_N only true or
  relevant for a particular date or period?", had AUC 0.99 but flagged
  every past event and start-only date (0.68 to 0.99; 5 of 13 open facts),
  which would have sent "peanut snack on 11 November" back for an `expires`
  it cannot have. "Does candidate_N state or imply a date after which it
  stops being true?" had AUC 1.00, no false asks and no misses: bound facts
  0.45 to 1.00 (future events lowest), open facts 0.00 to 0.11, across two
  runs (scores move between runs). Cutoff 0.3 sits in the gap. Small set, authored by Claude and not blind, so recheck
  on real traffic in `flag` mode before relying on `reject`.
- **Later.** An `asserted` ("true from") input on `aim_remember`, for
  start-only dates.
