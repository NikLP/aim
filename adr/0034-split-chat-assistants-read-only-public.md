# ADR-0034: Split chat assistants - a read-only public bot and a staff bot that writes

**Status:** Proposed (2026-10-01) - design only, not built. Builds on
[ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s untrusted-write
posture and [ADR-0033](0033-plausibility-gate-processing-modes.md)'s gate.
**Date:** 2026-10-01

## Context

`aim_chatbot` exposes two tools to one assistant: `aim_chatbot:recall` and
`aim_chatbot:remember`, the latter hardcoded to `scope: site`. On the demo
site that single assistant answers public visitors and also writes facts
into site memory. A customer-facing bot writing site-wide facts is a
poisoning vector: there is no draft-to-trusted gate in the PoC, so a
visitor's claim is live the moment it is saved and is recalled to the next
visitor as fact.

The demo also leans on the write path: the persona, welcome text and front
page invite visitors to "tell it something to remember", and the session
history deviation (`allow_history: session`) exists only to support
confirm-before-save.

## Decision

Run two assistants over the same memory, with different powers:

- **Public assistant** - tool `aim_chatbot:recall` only. Persona has no
  save wording. This is the front-page Q&A bot.
- **Staff assistant** - tools `aim_chatbot:recall` and
  `aim_chatbot:remember`. Persona is "record what staff tell you". Its chat
  block is visible only to a staff role (e.g. `librarian`).

Each is its own `ai_agent`, `ai_assistant` and chat block.

**Block visibility is not the gate.** `access deepchat api` is global, so a
logged-in user who can see no staff block may still be able to call the
staff assistant's endpoint directly (to be verified: how the endpoint
selects the assistant). The real gate is the tool: `AimRemember` must run
as the current user and require `create site aim facts`, granted only to
the staff role.

## Consequences

- `AimRemember::execute()` (aim_chatbot) currently calls
  `AimMemoryManager::remember()` with no permission check. Adding one is a
  prerequisite, and fixes the hole for any assistant, not just the demo.
- The demo's write story moves to the staff assistant and to MCP
  (`aim:remember` via OAuth), making the point that one memory serves
  agents with different authority.
- `allow_history: session` can be dropped from the public assistant; it is
  only needed where confirm-before-save applies.
- Needs `demo/seed.php` and `seed-facts.json` changes: two agents and
  assistants, a staff role and demo user, trimmed public persona, welcome
  text and front page.
- Facts the staff bot writes still pass Guardrails, and will pass
  ADR-0033's plausibility gate once built.

## Open questions

- Does the deepchat endpoint let a caller pick any assistant by ID? If so,
  is a per-assistant access check worth adding upstream or in `aim_chatbot`?
- Should `remember` stay hardcoded to `scope: site` for the staff bot, or
  take a scope the way `aim_tool` does?
