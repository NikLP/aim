# AIM Chatbot

Exposes AIM memory to an `ai_agents` chat assistant as function-call
tools, so a site's chatbot can save and recall facts during a
conversation. Part of [AIM](../../README.md) - see the root module for
the overall pitch.

## What it ships

- Two `#[FunctionCall]` tools, `aim_chatbot:remember`/`aim_chatbot:recall`,
  wired to the `ai_agents.ai_agent.aim_chatbot` agent
  (`guardrail_set: aim_write_guardrails`).
- A demo assistant (`ai_assistant_api.ai_assistant.aim_demo_assistant`)
  and chat block (`block.block.olivero_aimdemochat`) in `config/optional`,
  so the capability is visible out of the box.

Both tools are hardcoded to `scope: site` - a chat visitor isn't resolved
to a real Drupal account, and `scope: user` facts require one
([ADR-0007](../../adr/resolved/0007-user-scope-requires-real-account.md)).

## Requirements

- `aim` (this module's parent)
- `drupal/ai`'s `ai_agents`, `ai_assistant_api`, `ai_chatbot` submodules

## Getting started

1. Enable `aim` and `aim_chatbot`.
2. Configure a default AI provider (see [aim's DEVELOPING.md](../../DEVELOPING.md)'s
   "Setting up vector search" and "AI provider configuration" sections).
3. Visit the site with the demo chat block placed, or build your own
   `ai_agents` agent pointing at `aim_chatbot`'s tools.

Full mechanism and gotchas: [DEVELOPING.md](DEVELOPING.md). Decision
record: [ADR-0008](../../adr/0008-chatbot-integration-mechanism.md).
