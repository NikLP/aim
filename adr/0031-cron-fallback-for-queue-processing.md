# ADR-0031: Opt-in `hook_cron` fallback for queue processing, alongside the dedicated crontab

**Status:** Proposed 2026-09-30 - design only, nothing built. Amends
[ADR-0003](resolved/0003-async-processing-dedicated-crontab.md)'s "never
`hook_cron`" rule; does not replace its dedicated-crontab recommendation.
**Date:** 2026-09-30

## Context

[ADR-0003](resolved/0003-async-processing-dedicated-crontab.md) keeps
`AimConsolidateQueueWorker` off Drupal's cron by omitting the `cron` key
from its `#[QueueWorker]` attribute, so only an external
`drush queue:run aim_consolidate` entry drains it. The reason given was
that long LLM calls could starve unrelated cron tasks on a shared site.

Two things weaken that as an absolute rule:

- **A site without the crontab entry never consolidates, silently.** The
  queue fills, nothing errors, and duplicate or contradicting facts
  accumulate. The README promises unattended processing; a site that
  only runs `drush cron` (the default for most Drupal hosting) does not
  get it.
- **The starvation worry is bounded by core.** `Cron::processQueues()`
  gives each queue a time limit (the `cron` key's `time`, default 15
  seconds) and moves on. A worker declaring `cron: ['time' => 30]`
  cannot hold up other cron work for longer than that per run.

The real risk is narrower than ADR-0003 stated: on a site using
automated cron (poor-man's cron, run at the end of a visitor's request),
LLM calls would add latency to that visitor's page load.

## Decision

Add an opt-in cron path, keeping the dedicated crontab as the
recommended one.

- **Setting:** `aim.settings:consolidate_on_cron` (boolean). Proposed
  default `FALSE`, so existing behavior is unchanged until a site turns
  it on; revisit the default once the automated-cron risk is handled.
- **Mechanism:** `hook_queue_info_alter()` in `AimHooks`
  (`#[Hook('queue_info_alter')]`) adds
  `cron: ['time' => <aim.settings:cron_time>]` to the `aim_consolidate`
  definition when the setting is on. The `#[QueueWorker]` attribute
  itself stays without a `cron` key, so the default is still "core cron
  never touches it".
- **Time limit:** `aim.settings:cron_time`, default 30 seconds.
- **Automated cron guard:** when the `automated_cron` module is enabled,
  `aim:status` warns that the fallback would run LLM calls inside a
  visitor request, and the settings form says so next to the checkbox.
  No hard block - a low-traffic PoC site may accept it.
- **Both paths together are safe.** Queue items are claimed under a
  lease, so the dedicated crontab and core cron never process the same
  item twice; a worker that loses the race just sees an empty queue.

Only `aim_consolidate` exists as a queue today. Any future aim queue
(for example extraction) follows the same setting-driven pattern rather
than deciding cron participation in its attribute.

## Consequences

- A site on plain `drush cron` gets consolidation without touching
  crontab, at the cost of up to `cron_time` seconds per cron run.
- ADR-0003's separation is now a recommendation with an escape hatch, not
  an invariant. CLAUDE.md decision 4, the README's Processing bullet and
  DEVELOPING.md's "never touches it" wording need amending when this is
  built.
- `SuspendQueueException` when no default chat provider is configured
  (ADR-0003 addendum) already stops the queue cleanly under cron, so an
  unconfigured site does not spin.
- Interacts with
  [ADR-0015](0015-immediate-consolidation-considered-deferred.md): that
  ADR's immediate path is a separate, still-deferred latency option, not
  a substitute for a cron fallback.

## Open questions

- Default for `consolidate_on_cron`: `FALSE` is the conservative choice
  but leaves the silent-no-consolidation trap for new installs. An
  install-time prompt, or a default of `TRUE` when `automated_cron` is
  not enabled, are the alternatives.
- Whether `aim:status` should also flag a non-empty, not-draining
  `aim_consolidate` queue (neither cron path configured), which is the
  actual failure this ADR exists to surface.
