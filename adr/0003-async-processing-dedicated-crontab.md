# ADR-0003: Async processing via Queue API and a dedicated crontab, never `hook_cron`

**Status:** Accepted
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
`createFactsFromCandidates()` call `enqueueForConsolidation($factId)`
right after `save()`, so each fact is evaluated once, at creation, rather
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
