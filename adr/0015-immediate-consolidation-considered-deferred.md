# ADR-0015: Immediate post-write consolidation via kernel.terminate - considered, deferred

**Status:** Proposed - designed and prototyped 2026-09-14, then deliberately
reverted the same session. Not built. The design below is complete enough
to build from directly if this gets picked back up; the point of this ADR
is to not re-derive it, and to not re-litigate why it was shelved.
**Date:** 2026-09-14

## Context

Started from a real usability question: `remember()`/`createFactsFromCandidates()`
already enqueue every new fact onto `aim_consolidate`
([ADR-0003](0003-async-processing-dedicated-crontab.md)), but that queue is
only drained by the dedicated crontab (`* * * * * ddev exec drush
queue:run aim_consolidate`, CLAUDE.md's "Consolidation" section) or a
manual `queue_ui` click. A fact is not searchable via `recall()` until
that drain runs (`index_directly` is off - CLAUDE.md's "Vector search"
section). So: a chat session tells `aim_chatbot` ten things, then asks
about one of them two messages later, in the same conversation - if that
lands right after cron just ran, the answer is up to ~60 seconds away
(average ~30s), not "right now."

Two mechanisms were considered and rejected before landing on the design
below:

- **Flip `index_directly` on** (index-wide config, CLAUDE.md's "Vector
  search"). Rejected once batching entered the picture (see next section):
  it makes every single write block on its own embedding-API round trip,
  and a batch of several facts submitted in one request would serialize
  that cost directly into the response the visitor is waiting on -
  exactly the "10-20s of dead air" scenario this ADR exists to avoid, not
  fix.
- **Force the existing `aim_consolidate` queue worker to run on demand**
  (a new drush option, or an MCP-exposed "sync now" tool). Rejected as
  not actually solving the stated problem: `AimConsolidateQueueWorker::
  processItem()` runs `reindex()` and `consolidateFact()` back to back, so
  triggering it doesn't separate "make it searchable" from "run the
  LLM-mediated merge/dedup step" - it was the same conflation the
  conversation started from, just given a button.

Also relevant, decided in the same conversation: `aim_chatbot` should
batch-write per settled conversational point rather than reacting to every
statement (CLAUDE.md's Ideas section, "Pre-extraction summarization as a
dedup lever," and the `aim-discovery` skill's existing per-topic pattern)
- this was built (`ai_agents.ai_agent.aim_chatbot.yml`'s `system_prompt`),
independent of this ADR, and is not reverted. It's mentioned here because
it changes the shape of the problem this ADR addresses: a chat session
now writes in small bursts at natural checkpoints, not one fact at a time
and not one giant dump at the end.

## Design considered

A `kernel.terminate` event subscriber that drains `aim_consolidate`
immediately after the HTTP response has already been sent to the client -
not before, not blocking it.

- **Why post-response is real, not a hack.** Under this stack (DDEV's
  `nginx-fpm`, confirmed via `ddev describe`), Symfony's `Response::send()`
  calls `fastcgi_finish_request()` when running under FPM, which flushes
  the response and closes the client connection while the PHP worker keeps
  executing. `kernel.terminate` fires its listeners after exactly that
  point - this is the textbook use of the event, not an assumption specific
  to this project.
- **Scoping, so it doesn't drain on every page load.** `AimMemoryManager`
  gains a request-scoped `protected bool $hasPendingConsolidation = FALSE`
  flag, set `TRUE` inside `enqueueForConsolidation()` (the single choke
  point `remember()`, `createFactsFromCandidates()`, and `aim_eca`'s
  `FactWrite` all already go through) and read by a
  `hasPendingConsolidation()` getter. The subscriber checks that getter
  first and returns immediately if nothing was enqueued this request - it
  should never touch the queue table on a request that wrote nothing.
- **The drain itself reuses, not duplicates, existing logic.** Loads
  `aim_consolidate`'s worker via `plugin.manager.queue_worker`, then runs a
  manual `claimItem()`/`processItem()`/`deleteItem()` loop against
  `queue.aim_consolidate` (matching what `drush queue:run` itself does
  internally) until the queue is empty. `processItem()` is
  `AimConsolidateQueueWorker::processItem()` unchanged - still `reindex()`
  then `consolidateFact()` together, same as the crontab path. On a caught
  exception, `releaseItem()` (not delete) so the crontab can still retry
  it later - same failure posture the crontab path already has.
- **Registration.** New service `logger.channel.aim` (`parent:
  logger.channel_base`, standard Drupal pattern, didn't exist yet) plus
  `aim.immediate_consolidation_subscriber`, tagged `event_subscriber`, in
  `aim.services.yml`. New class
  `Drupal\aim\EventSubscriber\ImmediateConsolidationSubscriber`.

This closes the gap for exactly one write path: a fact written over an
actual HTTP request (the chatbot, an MCP call over `/mcp`). It does
nothing for `drush aim:remember`/`aim:extract` or any other CLI-triggered
write - those have no HTTP response to hook a `kernel.terminate` off of,
and stay on the crontab exactly as before, same as they always have.

## Why deferred

- **Cron is already at the floor, not somewhere short of it.** Standard
  crontab has no finer grain than once a minute, and this project's
  crontab is already `* * * * *`. There was no cheaper "just run cron more
  often" option sitting on the table - the real choice was live with
  ~0-60s latency, or add a genuinely new mechanism. That's a bigger step
  than it first sounded like.
- **New failure surface with no precedent in this codebase.** Every other
  async path here (ADR-0003's whole point) runs through Queue API +
  crontab, deliberately never mixed with the live request path. This
  design reintroduces request-path involvement, via a mechanism
  (`kernel.terminate`, manual claim/process/delete looping) this codebase
  has never used or tested before - real, but unproven risk next to
  infrastructure (the crontab) that's already trusted.
- **Doesn't touch the majority of write paths.** `drush aim:remember`,
  `aim:extract`, and any ECA-triggered write all stay on the 60s crontab
  regardless - this only speeds up the chatbot/MCP-over-HTTP path, a
  narrower win than it first sounds.
- **PoC status makes the urgency lower than it feels in the moment.**
  Nothing written today is trusted data yet - [ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s
  draft-to-trusted gate is still deferred, every fact is live the moment
  it's saved regardless of how fast it's indexed. Shaving 30-60s off
  searchability doesn't change that risk picture either way (see the
  2026-09-14 conversation this ADR is drawn from: this was checked
  explicitly - indexing speed and the poisoning/trust question are
  orthogonal).
- **No demonstrated problem yet, only a plausible one.** The stale-recall
  scenario (ask about something you just told it, get "I don't know")
  is real and easy to imagine, but hasn't actually been hit in a live
  demo. Building the more complex mechanism ahead of that evidence was
  judged disproportionate for where this project is now.

## Consequences / when to revisit

- If a real demo or session hits the stale-recall problem in practice
  (not hypothetically), this design is ready to build as specified above
  - no re-derivation needed, just implement it.
- If picked back up, add a real test for the `kernel.terminate` path
  specifically (this stack's post-response continuation was confirmed by
  reading how `Response::send()`/FPM behave, not by an actual live
  request/response timing test) before relying on it.
- Worth reconsidering together with [ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s
  draft-to-trusted gate whenever that gets built: once facts are no longer
  live the instant they're saved, "how fast does it become searchable"
  and "how fast is it reviewed" become two different clocks worth
  designing together rather than solving indexing latency in isolation
  again.
