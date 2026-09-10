# ADR-0006: Agent-native write path bypassing extraction's LLM call

**Status:** Accepted
**Date:** 2026-09-09

## Context

`drush aim:extract` is the right tool when the caller hands over raw text
and wants `aim` to decide what's worth remembering - but that's the wrong
tool for a calling agent (a Claude Code session, or any agent already
doing its own reasoning as part of a conversation) that has *already*
decided a specific statement is worth remembering. Routing that through
extraction would make a second, redundant LLM call just to re-derive a
decision the caller already made.

A precedent was checked before designing this: **Hermes Agent** (Nous
Research) exposes a single `memory` tool with an `action` enum (`add`/
`replace`/`remove`) - one unified entry point beats several separate tool
definitions for agent ergonomics. But Hermes has no read/search tool for
its core memory at all; `MEMORY.md`/`USER.md` are hard-capped small files
injected wholesale into the system prompt every session, which only works
because the store is deliberately tiny.

## Decision

**`drush aim:remember`** and **`drush aim:recall`** are a direct pair, no
LLM round-trip: `remember` validates scope and creates an `aim_fact`
directly (same shape `extract()`'s loop body already produces, minus the
LLM call around it); `recall` runs a real semantic query against
`aim_vector_index` with optional scope/subject filters. Both live on the
same underlying service (`AimMemoryManager`) that every other consumer
(CLI, ECA, the chatbot) also calls, so this isn't a parallel
implementation of memory access - it's the same write/read path with the
LLM-mediated decision step removed.

What transfers from the Hermes precedent: framing this as one memory
*capability* with two directions (the `.claude/skills/aim-memory/`
Skill), not N narrow tool-shaped instructions to reach for separately.
What deliberately doesn't transfer: Hermes's substring-matched `replace`/
`remove` against a tiny prompt-injected store. `aim_fact` is ID/UUID and
scope+subject addressed and meant to scale past what fits in a prompt -
`recall` (a real semantic query) is the correct divergence, and Hermes's
own `replace` maps onto aim's already-planned similarity-threshold
consolidation (ADR-0005) done via vector distance instead of substring
matching, not something to import.

## Consequences

- No new schema, no new dependencies - both commands live on the existing
  `AimCommands` class alongside `extract()`, sharing its DI.
- A real gotcha specific to this path: drush runs as the anonymous user
  by default, and `ai_search`'s backend applies a real per-match entity
  access check - a match anonymous can't view is silently dropped, not
  reported as an error. `recall()` account-switches to uid 1 for the
  query duration rather than bypassing access entirely
  (`search_api_bypass_access`), which was tried first and rejected on
  review as a bad default to leave in committed code. This is a genuine
  improvement over a blanket bypass, not a fully solved problem: it
  trades "skip the check" for "uid 1 holds an `is_admin` role" - the
  actually correct fix is ADR-0002's deferred governance layer (a real
  `aim_fact`-specific permission plus a dedicated non-superuser service
  account for CLI tooling).
- This same account-switch gotcha and fix generalizes to any `search_api`
  query against an access-controlled entity run from drush/cron context,
  not just this command.
