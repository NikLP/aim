# ADR-0033: Plausibility gate on new facts - gate semantics, and queued vs inline processing

**Status:** Proposed (2026-10-01) - design only, not built. Amends
nothing; builds on [ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s
`trusted` flag and [ADR-0021](0021-jev-typed-decision-provider.md)'s Laya
addendum.
**Date:** 2026-10-01

## Context

The goal is protection against memory pollution, as strong as a
single-module PoC can offer. Speed is secondary: a fact becoming
recallable a little later is acceptable, a bad fact becoming recallable
at all is not.

Today every fact is live at save: `aim.settings:default_trusted` is
`true`, and `recall()` only excludes untrusted facts. The `trusted` flag
and the per-caller override on `remember()` are built. A local
plausibility scorer is now available: Ollaya (`laya:en`) answers Noul
(yes/no probability) questions over `/v1/systemone`, on this site at
`127.0.0.1:11435`.

What is being decided is two separate things that are easy to conflate:
**what makes a fact recallable** (the gate), and **when the scoring runs**
(the processing mode). Pollution protection comes entirely from the
first. The second only moves latency around.

## Measurements

Taken 2026-09-30 on the dev laptop (14 GB RAM, no GPU), `laya:en`:

- Working set about 3.8 GB resident. Cold load 7-36 s depending on the
  memory cap, then 0.4-0.7 s per warm call. Caps in use: `MemoryHigh`
  4500M, `MemoryMax` 5G (an earlier 2-2.5G cap throttled the load).
- CPU-bound: 16 parallel requests finished no faster than the same
  requests serially. A GPU (vendor figure 89 ms for five questions on an
  RTX 4090) removes this constraint; none of the numbers below are a
  property of the design.
- Real fact text is short: 63 facts, 92 characters average, 214 maximum.
  Input beyond the model's context window is rejected with
  `STATE_TRUNCATED`, not scored.

## Decision

**1. The gate.** New facts are saved with `trusted=false` and become
recallable only when scoring sets `trusted=true`. This is
`default_trusted: false` plus a scorer that flips the flag; `recall()`
needs no change. Facts that fail the threshold stay untrusted for human
review (the draft-to-trusted review queue in ADR-0002).

**2. Fail closed.** If the scorer is unreachable, errors, or returns
`STATE_TRUNCATED`, the fact stays untrusted. The failure mode is silence
(a saved fact that is never recalled), not pollution. The runtime must
therefore make silence visible: queue depth and untrusted-fact count
belong on `aim:status`.

**3. Bound the input.** A maximum length on `text` (proposed 500
characters; the longest real fact is 214), enforced by a field
constraint on write, plus a length hint in the extractor prompt. The
prompt is a soft control, the constraint is the hard one, and its error
message should tell a caller to split the fact. `text` is currently an
unbounded `longtext`.

**4. Processing mode is a setting, not a fork.** The scoring work is one
service; how it is triggered is configurable. The options, in order of
preference:

| Mode | How | Fact recallable after | Cost |
| --- | --- | --- | --- |
| Queued (default) | Save enqueues a job; a worker scores it. Drained by the dedicated crontab ([ADR-0003](resolved/0003-async-processing-dedicated-crontab.md)), optionally the `hook_cron` fallback ([ADR-0031](0031-cron-fallback-for-queue-processing.md)) | Next worker run (up to a minute on a one-minute cron) | Nothing in the request path. Needs a running worker, which is not installed on this site today |
| Inline | Score synchronously inside `remember()` before the write commits | Immediately | Blocks the save for the scoring time (about 0.5 s warm here, several seconds for an extraction batch) |
| Post-response | Score in `kernel.terminate` after the response is sent, the mechanism prototyped in [ADR-0015](resolved/0015-immediate-consolidation-considered-deferred.md) | Seconds after the save | Holds a PHP worker after the response; fragile if the process dies before scoring |

Queued is the default because it matches the existing architecture and
keeps scoring out of the request path. Inline is the right choice for a
single `remember()` from a tool or a demo where the worker is not
running. Post-response inherits ADR-0015's caveats.

**5. Demo operation.** With no crontab installed, run the worker by hand
(`drush queue:run <queue>`) or in a loop
(`while true; do ddev drush queue:run <queue>; sleep 5; done`), or
switch to inline mode for the session.

## Alternatives considered

- **Optimistic (save as trusted, score afterward, demote on failure).**
  Instant availability, but a bad fact is recallable until the score
  lands. Rejected: it reintroduces the pollution window this ADR exists
  to close. It stays a valid option for a low-stakes site, and the same
  scorer serves it.
- **Same-session visibility (recall accepts an "include this session's
  untrusted facts" flag).** Fixes the "I just told it, why doesn't it
  know" gap without widening the gate. Deferred: it needs a session or
  batch identity on the fact, which [ADR-0029](0029-context-carrying-turns.md)'s
  `batch_id` would supply.
- **No length cap, rely on truncation.** Rejected: truncation is an
  error, not a degraded score, and it spends the model's full context
  window of memory on input that cannot be scored.

## Addendum (2026-10-02): tev1 on Ollama, and what it changes

Ollama 0.35 now serves decision models, so Ollaya is not required to
host `/v1/systemone`. [ADR-0021](0021-jev-typed-decision-provider.md)'s
2026-10-02 addendum has the measurements. For this ADR:

- **`tev1:4b` is the candidate scorer, not `tev1:0.8b`.** On a small set
  the 4B passed 31/31 true facts and rejected 6/6 blatant and 5/8
  subtle falsehoods at 0.5; the 0.8B rejected 2/6 and 2/8.
- **Inline mode is not viable on this laptop.** About 6.5-7 s per new
  fact on CPU (the 0.8B is 0.9 s but cannot gate). Queued stays the
  default; post-response is the only other realistic mode.
- **General plausibility is not site agreement.** The scorer misses
  site-specific contradictions because it sees only the fact. Sending
  recalled facts as context would help but spends the roughly
  2,050-token input limit; untested.
- **A guardrail is the wrong home for the gate itself.** A guardrail
  rejects the write; this ADR quarantines it. A guardrail can still
  reject the clearly-bad tier (for example below 0.2).
- **Wiring** goes through the core Decision API and the provider
  manager, not the direct HTTP client suggested under Open questions.
  `ai_provider_typesafeai` 1.1.0-beta1 is installed but not enabled.

## Open questions

- **Threshold and questions.** What Noul questions to ask and where to
  set the pass mark are unknown. Run the scorer in shadow (compute and
  record the score, do not flip `trusted`) against human-reviewed labels
  before it is allowed to gate anything. Wire compatibility with Jev is
  not decision-quality compatibility ([ADR-0021](0021-jev-typed-decision-provider.md)).
- **Where the score lives.** Whether to store the score and question
  answers on the fact (for review and re-scoring) or only the resulting
  flag.
- **Provider wiring.** `ai_provider_typesafeai` and `ai_decision` are not
  installed on this site, and `ai_decision` depends on an unmerged core
  patch. A direct HTTP client to `/v1/systemone` is the lower-risk spike.
