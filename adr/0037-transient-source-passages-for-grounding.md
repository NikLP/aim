# ADR-0037: Transient source passages, so a decision model can check a candidate fact against what was actually said

**Status:** Built 2026-10-11 as a write-time check: decisions 1-4 (side
table, queue step, retention sweep) replaced by checking while the source
text is in memory and storing none of it (see "Built" below; first proposed
as [ADR-0056](0056-personal-data-write-gate.md) decision 7). Shadow mode on
this site. Proposed 2026-10-02.
**Date:** 2026-10-02

## Context

[ADR-0033](0033-plausibility-gate-processing-modes.md) proposed scoring a
bare fact for plausibility. Measured 2026-10-02, that does not work with
the small decision models available locally: `laya:en` scored true and
false library facts almost identically (AUC 0.61 for the generic
question, 0.77 at best for a reworded one), and `tev1:4b` only did well
on blatant falsehoods. These models judge the state they are given, not
the world. A fact on its own gives them nothing to judge.

The real pollution risk in aim is also not "a false fact about the
world". It is that `extractFacts()` (a chat model) turns source text into
candidate facts and can hallucinate or misattribute ("the partner is
vegetarian" from a text about the user being vegetarian). The question
that matters is "does the source text support this candidate?", and that
is a grounded question a decision or NLI model can answer, because the
evidence is in the state.

Today the source text is not kept. `extractFacts()` receives it, returns
facts, and drops it; `aim_fact.source` is a short label string. A
grounded check needs the text to still exist when the check runs, which
is later, in the queue ([ADR-0003](resolved/0003-async-processing-dedicated-crontab.md)).

The earlier "no conversation recording" constraint was a misconstrual
(it meant audio only) and no longer applies, so keeping text for
processing is a design choice on its merits: value against exposure.

## Decision

**1. A side table for source passages, with a pointer on the fact.**
`aim_fact_source` (`id`, `hash` unique, `text` longtext nullable,
`created`) is a plain
database table, not an entity: no views data, no Search API index, no
Tool API or MCP exposure, never returned by `recall()`. `aim_fact` gains
a nullable integer `source_ref` pointing at it. The text never lands on
the fact entity, where list builders, views, exports and recall could
expose it. One passage serves every fact extracted from it.

**2. The write path stores the passage.** `extractFacts()` callers
(Drush, the ingestion form, the chat tools) write one passage row per
extraction and set `source_ref` on each resulting fact. `remember()`
and the Tool API/MCP `store` tool gain an optional `source_text`
parameter for callers that have the evidence; without it `source_ref`
stays null.

**3. The grounded check runs in the queue worker, then the text goes.**
A new worker (or a step in `AimConsolidateQueueWorker`) asks a decision
model one Noul question per fact with state = passage plus candidate
("The text states the candidate fact."). The verdict sets `trusted`
(ADR-0033 decisions 1 and 2: fail closed, stay untrusted on error or
truncation). When every fact pointing at a passage has a verdict, the
passage `text` is set to NULL. The row stays, so the fact's `source_ref`
and the passage `hash` remain as an audit trail ("checked against
passage X") without keeping the personal data. A passage with a known
`hash` is reused rather than stored twice, and a repeat ingest of the
same file can skip re-extraction.

**4. A short retention backstop.** A sweep (the dedicated crontab,
[ADR-0003](resolved/0003-async-processing-dedicated-crontab.md)) clears the
text of passages older than a setting (default 7 days) whatever their state, so
a stuck queue cannot hold personal data indefinitely. Facts whose
passage expired unchecked stay untrusted.

**5. No consolidation table, no failed table.** The queue is the work
list, and a fact row with `trusted=false` is both "pending" and "failed
the check" (ADR-0033 decision 1). Neither needs a table. The agreed
`aim_rejection` quarantine (TODO.md "Lossy rejection") is a different
thing: candidates that never became a fact row. A fact that fails the
grounded check is a fact row and appears in the review queue as such.

**6. Instant indexing is unaffected.** `index_directly` indexes a fact
when it is saved, trusted or not; `recall()` filters on `trusted`.
Only the flag flips later, so the vector index never waits on the gate.

**7. Privacy controls.**
- A permission, `view aim fact source` (`restrict access`), is
  held by nobody by default; no UI lists passages in the first build.
- Passage text is never logged (IDs only, the existing rule in
  CLAUDE.md "Logging").
- Sending passage text to a model is the real exposure, since a hosted
  provider sees the raw conversation, which can contain more than the
  extracted fact. A setting `source_check_allow_remote` defaults to
  false: the grounded check refuses a non-local provider unless a site
  opts in. The decision backend on a local Ollama keeps the text on the
  machine.

## Alternatives considered

- **Source text as a field on `aim_fact`.** Simplest, but it puts raw
  conversation on an entity that views, the admin list, exports and any
  future recall change can expose, and it bloats the table that holds the
  vector-adjacent hot path. Rejected.
- **Passage in the queue item payload.** No new table, and the item is
  deleted on success. Rejected: `queue_ui` (enabled) shows item data to
  administrators, an unclaimed or stuck item has no retention limit of
  its own, and extraction yields several facts per passage, so the text
  would be copied once per item.
- **A consolidation table / a failed-facts table.** Rejected: see
  decision 5.
- **Plausibility on the bare fact** (ADR-0033 as first written). Measured
  weak above; kept only as the optional reject-the-obvious tier.

## Open questions

- **Passage length.** Parked (2026-10-03): no chunking for now. A site
  setting `ingest_max_chars` (default about 100 KB, about 25k tokens)
  caps what `aim:extract` and the ingest form accept, with a "split this
  file" error. A passage longer than the check model's window fails
  closed (facts stay untrusted, ADR-0033). Revisit with chunking.
- **Direct writes.** Decided (2026-10-03): `remember()` without
  `source_text` cannot be grounded and stays on the per-caller `trusted`
  override. Worth trying: the chat tools pass the visitor's message as
  `source_text`, so chat writes become groundable.
- **Consolidation reuse.** Deferred: the pair check does not see the
  passage (real pairs already score 25/25 on Jev).
- **Audit.** Decided (2026-10-03): the `hash` lives on `aim_fact_source`,
  not on the fact, and the row outlives the text. Open edge: whether a
  known hash with cleared text re-runs the check (leaning no).
- **Model.** Decided (2026-10-03): `nli` is no longer available, so hosted
  Jev is the only candidate; the groundedness eval set decides it.

## Addendum (2026-10-10): groundedness eval, hosted Jev

`aim_benchmark/scripts/decision-eval-grounding.json`: 44 hand-written
items (21 supported, 16 unsupported, 7 misattributed) over the three
`demo/ingest-test` prose files plus six one-line chat messages. Labels
human-verified by Nik 2026-10-10, no changes. `jev-latest`, three runs, scores stable to about 0.05:

| Wording | AUC | Wrongly trusted at 0.50 | Wrongly held at 0.50 | Supported min |
| --- | --- | --- | --- | --- |
| plain ("Does the text state the candidate fact?") | 0.99 | 3-4 of 23 | 0 of 21 | 0.92 |
| strict (names the failure kinds) | 0.99 | 2 of 23 | 0 of 21 | 0.73 |

0.35 s median per call, both wordings in one call.

- The strict wording catches proposals and wishes stated as fact ("Alex
  wants to add" read as "runs") that the plain one lets through at 0.6-0.7.
- One miss in every run and both wordings: a preference moved between the
  two parties in a meeting ("Northlight wants" for "the library wants"),
  0.85-0.98. Misattribution between people in the same passage is the
  known weak spot.
- The chat item whose passage is only "Yes, that's right." was held, so
  chat writes must pass the prior turn in `source_text` or confirmed facts
  stay untrusted.
- Batched (`--batch`, 2026-10-11): one call per passage with every
  candidate in the state (up to 14 candidates, 28 questions) takes the same
  0.35-0.41 s as a single-fact call, and scores hold: strict AUC 1.00, 1 of
  23 wrongly trusted at 0.50, supported min 0.76, two runs. So the check
  costs one call per write operation, not per fact.
- Next: decide wording and cutoff (strict at
  a cutoff of about 0.7 holds every bad item but the misattribution on this
  set, tuned on the set so optimistic).

## Built (2026-10-11): checked at write time, nothing stored

Hosted Jev answers a batched call in 0.35 s, so the check runs when the
fact is written, not later in the queue. That drops decisions 1-4: no
`aim_fact_source` table, `source_ref`, retention sweep or `view aim fact
source` permission, and no raw text in the database. Lost: re-checking a
fact later against its passage.

- `AimMemoryManager::checkGrounding()`: every candidate in one Decision
  call, the strict wording from the eval (`GROUNDING_QUESTION`), a request
  cache so a batch is checked once. Activity `grounding` (decision only,
  tag `aim_ground`), `activities.grounding.threshold` (default 0.7).
- `grounding_score` on `aim_fact`; `grounding_mode` off, shadow or enforce
  (decision 6 of ADR-0033 kept: enforce fails closed). This site: shadow,
  `jev-latest`, 0.7.
- Source text from extraction (whole document), `remember()`'s
  `sourceText`, the MCP tool's `source_text`, and the chat assistant's
  remember tool via `aim_chatbot`'s subscriber (visitor's last message plus
  the assistant turn before it).
- Checks: the facts list's Grounding column and "below" filter, an audit
  line per verdict, `aim_activity_metrics`, the eval set; texts only under
  the opt-in `log_query_text`. Kernel test `AimGroundingTest`.

Found while building:

- The demo assistant had lost its session history (aim_chatbot ships
  `allow_history: none` and the demo recipe did not override it), so the
  persona's confirm-before-save could not work and the chat source text had
  no previous turn. The recipe now sets `private_tempstore_pool`.
- The strict question marks a faithful clean-up down: the preflight canary
  ("Please remember that the library's ZZPREFLIGHT story-time mascot is a
  fox named Rusty", saved without the token) scored 0.52-0.62. Before
  enforcing, test a narrower clause ("drops a detail that changes its
  meaning") with clean-up items added to the eval set; the preflight's
  recall-after-save check would fail under enforce as things stand.


## Addendum (2026-10-11): narrower wording tested, not adopted

Eight items added to the eval set (six faithful clean-ups, two drops that
change the meaning; labels not yet verified by Nik), 52 items. Run through
Drupal's own provider (`aim_benchmark/scripts/decision-eval.php`,
`typesafeai`, `jev-latest`, batched as live), two runs, cutoff 0.7:

| Wording | AUC | Wrongly trusted | Wrongly held | Canary | Members-only drop |
| --- | --- | --- | --- | --- | --- |
| strict (live) | 0.99 | 1 of 25 | 2 of 27 | 0.52-0.56 | 0.63-0.66 |
| narrow ("drops a detail that changes its meaning") | 0.99 | 1 of 25 | 2 of 27 | 0.58-0.62 | 0.64-0.66 |
| narrow, plus "leaving out greetings, filler, typos or a request to remember is fine" | 0.99 | 2 of 25 | 1 of 27 | 0.71 | 0.79-0.82 |

- The narrow clause moves scores by about 0.05: no item changes side.
- The explicit allowance passes the canary, but only just, and it also
  passes the computers item with its members-only condition dropped. That
  is the error the check exists to catch, so it is not adopted.
- Four of the six clean-ups score 0.81-0.97 on every wording. The two
  held ones lose more than filler: the canary loses its `ZZPREFLIGHT`
  token, and the Saturday item loses "from now on".
- So `GROUNDING_QUESTION` stays strict. Wording alone does not fix the
  preflight under enforce; the canary's junk token is the problem (a
  test-only token that a faithful clean-up drops). Fixed in the preflight:
  the canary is now "the library's story-time mascot is a fox named
  Fizzwick", no token, and scored 0.84 live (shadow, cutoff 0.7; the old
  canary 0.62). All 13 preflight checks pass.
