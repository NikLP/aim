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
  reported as an error. Fixed: for an anonymous caller, `recall()` sets
  `search_api_bypass_access` (see the 2026-09-18 addendum below, which
  reversed an earlier uid-1 account-switch). Documented in DEVELOPING.md's
  "`checkViewAccess()`/anonymous drush callers".
- This same gotcha generalizes to any `search_api`
  query against an access-controlled entity run from drush/cron context,
  not just this command.

## Addendum (2026-09-18): the uid-1 account-switch was itself wrong, reversed to `search_api_bypass_access`

The Consequences section above rejected `search_api_bypass_access` in
favor of account-switching to uid 1, on the reasoning that it was "a
genuine improvement over a blanket bypass." Reassessed and reversed:
that framing was wrong, not just imperfect, for exactly this call site.

**Uid 1 was never a real guarantee, only a coincidence.** Confirmed by
reading Drupal core directly (not assumed): `\Drupal\Core\Session\
PermissionChecker::hasPermission()` has no special-cased uid-1 bypass -
it evaluates uid 1's roles/permissions exactly like any other account, so
whether uid 1 is actually privileged depends entirely on which role a
given site happened to assign it (typically `administrator` on a
standard/minimal install, but nothing in core requires this). Separately,
core's `\Drupal\user\Entity\User` carries no storage-layer protection
against uid 1 being deleted - the only guard is a UI form check in the
account cancellation form, which a direct `$user->delete()` or
`drush user:cancel --delete 1` bypasses outright. The code this addendum
replaces (`AimMemoryManager::executeAsAdmin()`, since renamed
`executeSearchQuery()`) already tacitly admitted this by throwing
`\RuntimeException('User 1 does not exist, no account to run this query
as.')` - a defensive check for a failure mode the design could not
actually prevent, not a hardening of it.

**The "bad default to leave in committed code" framing didn't
distinguish two different situations.** `search_api_bypass_access` is a
real problem left carelessly in a request path a real, identifiable user
is viewing - that's the shape the original rejection had in mind. It is
not the shape of this call site: `recall()`/`findNearestNeighbor()`
invoked from `drush`/cron have no real "viewer" to check access on
behalf of at all, and the caller already holds raw database credentials
(drush connects with full DB access) - gating `search_api`'s result set
behind `$entity->access('view', $account)` in that context is not a real
security boundary to begin with, since the same actor can trivially route
around it with `drush sql:query`. Uid-1 impersonation didn't add real
protection over a bypass here; it added a false sense of one, plus a
fragility (a `RuntimeException`, or silently wrong results if uid 1
lacked the assumed permissions) that a bypass doesn't have.

**Fix:** `executeSearchQuery()` now sets `search_api_bypass_access` on
the query directly for an anonymous caller, and does nothing extra for an
authenticated one (already run as the real caller, unrelated to this
addendum - see CLAUDE.md's "Code review, 2026-09-17" item 2). No account
switching, no uid 1 dependency, no `AccountSwitcherInterface` left
injected into `AimMemoryManager`. ADR-0002's deferred governance layer
(a real `aim_fact`-specific permission plus a dedicated non-superuser
service account for CLI tooling) remains the correct long-term answer for
a *human operator* deliberately running `drush aim:recall` and wanting
"see everything" to mean something more accountable than "whatever uid 1
happens to be allowed" - that part of the original Consequences entry
still holds. What's reversed is narrower: for the *system-process* half
of this call site (no real viewer at all), bypass was the correct choice
from the start, not the "bad default" it was rejected as.

Verified live: an anonymous `drush aim:recall` invocation still returns
real `scope=user` results (impossible under real per-scope access
checking, confirming bypass is genuinely active); a real authenticated
non-admin account with no `view user aim facts` permission still gets
zero `scope=user` rows back (confirming the authenticated branch is
unaffected and still access-checked, not bypassed). See CLAUDE.md's
"CLI agent adapter" and "Consolidation" sections for the corresponding
gotcha-text updates.

## Addendum (2026-09-26): what "verbatim" does and does not guarantee

`remember()` is already the verbatim write path: it stores its text as
given, with no extraction call, and every human-facing entry point (Drush,
`aim_tool`'s `aim_remember`, the chatbot's `AimRemember`, the admin add
form) goes through it. Three limits on the word, none of them new
behavior:

- Over MCP and the chatbot the calling model composes the text, so the
  fact is verbatim relative to the tool call, not to what the human said.
  Only `drush aim:remember` and the admin form take a human's literal
  text.
- Guardrails still run (ADR-0002). Today they only reject, never rewrite.
- Consolidation still runs on every inserted fact (ADR-0003, ADR-0005)
  and can retire, delete, or LLM-rewrite a "verbatim" fact.

The third is a real gap, not just a caveat. Proposed fix (a `verbatim`
opt-out flag, not built) in
[ADR-0020](../0020-verbatim-facts-consolidation-opt-out.md).

## Addendum (2026-10-10): the trigger is an explicit `accessCheck` argument, not "anonymous"

Keying the bypass on `isAnonymous()` failed open: any anonymous entry
point (the chat widget reopened for anonymous, an MCP transport running
without a session) recalled every fact in every scope. `executeSearchQuery()`
and `recall()` now take `bool $accessCheck = TRUE` (core's entity query
`accessCheck()` idiom); only Drush commands (`aim:recall`,
`aim:benchmark`) and consolidation's neighbor search pass FALSE. Every other caller, anonymous included, runs the query as the
current account. Still no account switching.
