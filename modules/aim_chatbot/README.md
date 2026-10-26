# AIM Chatbot

A chat assistant on AIM memory: an `ai_agents` agent that saves and
recalls facts during a conversation with `aim_tool`'s shared tools. Part of [AIM](../../README.md) - see the root module for
the overall pitch.

## What it ships

- The `ai_agents.ai_agent.aim_chatbot` agent
  (`guardrail_set: aim_write_guardrails`), using `aim_tool`'s
  `aim_recall` (5 results) and `aim_remember` through
  `tool_ai_connector` - the same tools MCP callers use.
- An assistant (`ai_assistant_api.ai_assistant.aim_assistant`) for a
  chat block to use. No block ships here: place an AI DeepChat block and
  pick this assistant (the `aim_demo_library` recipe places one).

What chat can read and write is set by the visitor's permissions: give a
chat-facing role `recall aim facts`, `remember aim facts` and the
`view`/`create` permissions of the scopes it should reach (the demo: `site`
only). A visitor's fact waits for staff review (the `chat` trust channel
ships as `hold`). See
[ADR-0062](../../adr/0062-chat-on-the-shared-tools.md).

## Requirements

- `aim` (this module's parent, with its `site` scope enabled), `aim_tool`
- `drupal/ai`'s `ai_agents`, `ai_assistant_api`, `ai_chatbot` submodules
- `drupal/tool`'s `tool_ai_connector`

## Getting started

1. Enable `aim` and `aim_chatbot`.
2. Configure a default AI provider (see [aim's DEVELOPING.md](../../DEVELOPING.md)'s
   "Setting up vector search" and "AI provider configuration" sections).
3. Visit the site with the demo chat block placed, or build your own
   `ai_agents` agent using `tool:aim_recall`/`tool:aim_remember`.

Full mechanism and gotchas: [DEVELOPING.md](DEVELOPING.md). Decision
record: [ADR-0008](../../adr/0008-chatbot-integration-mechanism.md).
