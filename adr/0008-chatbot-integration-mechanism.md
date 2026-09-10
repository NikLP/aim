# ADR-0008: Chatbot integration - `ai_agents` tools, not a CCC content source

**Status:** Accepted; design for the CCC half not yet built (CCC not enabled)
**Date:** 2026-09-09 (AI Assistant API), 2026-09-10 (migrated to `ai_agents`)

## Context

`aim` needed a real, visitor-facing front end beyond drush - the "average
Joe" access path. Two mechanism questions followed on from that: which
`drupal/ai` integration surface to build the demo chatbot on, and (once
`drupal/ai_context`, "CCC", entered the picture via a sibling project's
composer.json) whether `aim`'s memory should become a content source
inside CCC's system, or something else.

## Decision

**Chatbot mechanism: `drupal/ai`'s AI Assistant API
(`ai_assistant_api`/`ai_chatbot`), then migrated to `ai_agents`.**
Originally built on `#[AiAssistantAction]` (`AimMemoryAction`, exposing
`aim_remember`/`aim_recall`) because `ai_agents` wasn't composer-present
in this project yet. Migrated 2026-09-10, once a sibling project's
`ai_context` dependency pulled `ai_agents` in for free, because the old
mechanism has a real, dated removal in `ai:2.0.0` (flagged directly on
the admin UI's own deprecation warning), and because the CCC integration
shape below needs tools in the exact form `ai_agents` expects anyway.
`AimMemoryAction`'s two actions became two separate
`#[FunctionCall]` plugins (`aim_chatbot:remember`/`aim_chatbot:recall`)
in a new `aim_chatbot` submodule, wired to the demo assistant via its
`ai_agent` field - the built-in seam `AiAssistantApiRunner::process()`
already provides for exactly this transition: when `ai_agent` is set, it
short-circuits entirely to
`AgentRunner::runAsAgent()`, so the DeepChat block and `/api/deepchat`
endpoint keep working completely unchanged.

**Scope: locked to `scope: site` on both directions, hardcoded in the
plugin, not a parameter the LLM can set.** Two independent reasons: a
chat visitor isn't resolved to a real Drupal account, and `scope: user`
facts require one (ADR-0007); and privacy - without locking `recall` too,
not just `remember`, an anonymous visitor's broad question could surface
a real `scope: user` fact (e.g. a contact preference) that isn't theirs
to see. Caught before building, not found as a bug afterward.

**CCC integration: aim exposes tools; CCC (if enabled) holds curated
policy about when to use them - not aim becoming a CCC content source.**
Checked CCC's actual shipped RAG guidance
(`ai_context/docs/developers/rag.md`) rather than assuming: the formal
external-provider plugin type this integration might have used
(drupal.org #3586289) doesn't exist in the installed 1.0.0-beta5 source.
CCC's own documented pattern for this is "Pattern A - agentic RAG via
tools": give the agent a retrieval tool, let CCC hold policy about when
to reach for it. That's a direct match for aim's shape - dynamic,
LLM-extracted facts, not curated editorial content - and requires zero
code, zero dependency from `aim`/`aim_chatbot` on `ai_context` either
direction. Not built: CCC itself isn't enabled on this site (still
beta5, and enabling it pulls in Content Moderation/Workflows/Scheduler/
taxonomy config that's a separate decision from the chatbot migration).

## Consequences

- A related suite (`annopm`/Annotations) evaluated the identical CCC
  question in mid-2026 (ADR-010 there) and reached the same "Tool API
  primary" call for structural reasons, before CCC's own guidance
  confirmed it - this decision and that one are independent but
  converge, which is corroborating evidence, not a coincidence to ignore.
- `AiAgent` config entities carry their own `guardrail_set` property - the
  migration let the demo agent adopt aim's existing `aim_write_guardrails`
  set (ADR-0002) at zero extra cost, protecting the chat conversation
  itself, not just what gets persisted as a fact. The old
  `AiAssistantAction` mechanism had no equivalent hook.
- One real behavioral consequence of the migration: `runAsAgent()` does
  not pass the assistant's own `system_prompt`/`instructions` through at
  all once `ai_agent` is set - a live prompt fix (redirecting broad
  "what do you know" questions instead of attempting an overbroad recall)
  had to be copied verbatim into the new agent's own `system_prompt`, or
  it would have silently regressed.
- A real config-installer gotcha, worth knowing before moving config
  between modules again: `ConfigInstaller::findPreExistingConfiguration()`
  throws a hard exception if a module's `config/install` names a config
  object that already exists in active storage. Moving the assistant/
  block config into the new `aim_chatbot` submodule required editing the
  live entities directly first, then relocating the already-existing
  YAML files in the repo (not reinstalling them) - not simply copying
  file names into the new module's `config/install`.
- Not urgent yet, but a real gap once either the chatbot's recall trigger
  loosens or a second, less-curated consumer of `scope: site` facts shows
  up: nothing partitions *within* `scope: site` by audience/sensitivity
  today (see CLAUDE.md's "Ideas raised, not designed",
  sub-scope visibility control).
