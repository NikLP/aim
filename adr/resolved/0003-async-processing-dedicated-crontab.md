# ADR-0003: Async processing via Queue API and a dedicated crontab, never `hook_cron`

**Status:** Accepted; the "never `hook_cron`" rule is amended by [ADR-0031](../0031-cron-fallback-for-queue-processing.md) (proposed)
**Date:** 2026-09-09

## Context

Extraction and consolidation both make LLM calls with real, variable
latency. `aim` runs beside an existing site sharing its DB/CPU, not on
dedicated infrastructure - mixing a long extraction or consolidation run
into general site cron risks starving unrelated housekeeping tasks that
share the same cron cycle.

## Decision

Processing runs through Drupal's Queue API plus a **dedicated crontab
entry, separate from `hook_cron`** - never mixed into the site's general
cron cycle. Consolidation's queue worker
(`AimConsolidateQueueWorker`, plugin ID `aim_consolidate`) deliberately
carries no `cron` key on its `#[QueueWorker]` attribute: `cron` is an
optional, default-`NULL` array on core's `QueueWorker` attribute, and
omitting it means
`Cron::processQueues()` never touches this queue. Draining happens only
via an external crontab entry:

```crontab
* * * * * drush queue:run aim_consolidate
```

Granularity is per-fact, not a periodic full sweep: `remember()` and
`createFactsFromCandidates()` originally called
`enqueueForConsolidation($factId)` right after `save()` (now an insert
hook, see the 2026-09-26 addendum), so each fact is evaluated once, at
creation, rather
than re-compared against the whole corpus on every future sweep (the
inefficiency the original on-demand `drush aim:consolidate` CLI sweep
had, before this queue existed).

A UI-triggerable "run this queue now" convenience (`drupal/queue_ui`'s
manual per-queue Run button) was evaluated and kept separate from this
decision on purpose: it was considered and explicitly *not* implemented
via a `cron` key on the queue worker, since that's exactly the mixing
this ADR rules out. `queue_ui`'s button is triggered only on an explicit
click, so it doesn't have the same conflict, and was added as an
operational convenience instead (see CLAUDE.md's "Admin UI" section).

Only add a dedicated async queue worker with a tighter cadence if a
feature genuinely needs low-latency inline extraction during a live chat
turn - the default is the 1-5 minute crontab cadence above, not
synchronous.

## Consequences

- A real write/index/consolidate race this design didn't originally
  account for: `index_directly` is off by default (ADR-0001's sibling
  decision under vector search), so a fact isn't searchable the instant
  it's saved. Two facts enqueued back to back would each be asked to
  consolidate before the other was indexed, missing each other as
  neighbors. Fixed by having `AimConsolidateQueueWorker::processItem()`
  call `reindex()` before `consolidateFact()` - this is the specific,
  narrow exception to "index_directly stays off" that the original
  storage decision already anticipated: a queue-worker-driven write
  that's already off the request path anyway.
- `drush cron` leaves a pending queue item completely untouched;
  `drush queue:run aim_consolidate` drains it immediately - the intended
  separation.
- Threshold miscalibration (see ADR-0005) had a materially worse blast
  radius once this queue went live and unattended, compared to the old
  on-demand CLI sweep a human could `--dry-run` first - the same
  underlying bug, but automation raised its stakes. Recalibrated the same
  day it was found; see ADR-0005.

## Addendum (2026-09-26): enqueue moved to an insert hook

The decision above (Queue API, no `cron` key, dedicated crontab, per-fact
granularity) is unchanged and still binding. What changed is where the
enqueue happens, and a few pointers had gone stale.

- **Enqueue is now `hook_aim_fact_insert`.** `AimHooks::factInsert()`
  (`#[Hook('aim_fact_insert')]`) calls `enqueueForConsolidation()` for
  every newly inserted fact, so every save path is covered (the admin add
  form, `remember()`, `createFactsFromCandidates()`, any future writer)
  without each one having to remember to call it. `remember()` and
  `createFactsFromCandidates()` no longer call it themselves.
- **Opt-out is core's `setSyncing(TRUE)`.** An entity flagged syncing
  skips the enqueue. `generateBenchmarkFacts()` sets it deliberately
  (synthetic text needs no consolidation), and core's Migrate
  destinations set it on every entity they save, so migrated facts also
  skip consolidation. The flag is not exposed on any write path, so a
  caller cannot use it to keep a real fact out of consolidation - see
  [ADR-0020](../0020-verbatim-facts-consolidation-opt-out.md) for a
  proposed explicit flag.
- **Unchanged, re-checked against the code:** `AimConsolidateQueueWorker`
  still carries no `cron` key; `processItem()` still skips an
  already-retired or deleted fact, calls `reindex()` before
  `consolidateFact()`, and throws `SuspendQueueException` when no default
  chat provider is configured.
- **`queue_ui` pointer.** The manual per-queue Run button is documented in
  DEVELOPING.md's `aim:consolidate` section, not a CLAUDE.md "Admin UI"
  section.
- **Latency.** The "tighter cadence only if a feature needs it" clause
  above was explored as an immediate post-write path and deliberately
  deferred: [ADR-0015](../0015-immediate-consolidation-considered-deferred.md).

Checked by reading the code 2026-09-26, not by running the queue.
