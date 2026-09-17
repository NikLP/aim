# AIM Chatbot - Developer Reference

Mechanism detail and gotchas for `aim_chatbot`. Pitch/requirements in
[README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md), root
module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

---

## Mechanism

Uses `ai_agents` - see
[ADR-0008](../../adr/0008-chatbot-integration-mechanism.md) for the
mechanism and alternatives considered.

- Two `#[FunctionCall]` tools (`aim_chatbot:remember`/`aim_chatbot:recall`)
  and the `ai_agents.ai_agent.aim_chatbot` config entity
  (`guardrail_set: aim_write_guardrails`). Both tools are hardcoded
  `scope: site` - a chat visitor isn't resolved to a real account, and
  unlocking `recall` too would let a broad question surface a real
  `scope: user` fact.
- `ai_assistant_api.ai_assistant.aim_demo_assistant` and
  `block.block.olivero_aimdemochat` ship in this module's
  `config/install`/`config/optional`. The assistant's `ai_agent` field
  points at `aim_chatbot`, so `AiAssistantApiRunner::process()`
  short-circuits to `AgentRunner::runAsAgent()` - the assistant's own
  `system_prompt` is **not** used, only the `ai_agent` entity's is. Block
  placement is `bottom-right` (`placement: toolbar` doesn't render on
  this theme).

## Gotchas

- CSRF for `/api/deepchat` is a `token` **query parameter**, not a
  header - `POST /api/deepchat/session` returns it, append as
  `?token=...`. Anonymous also needs `access deepchat api`.
- `drush php:eval` can't exercise the chat endpoint -
  `AssistantMessageBuilder` resolves the current route, which is null
  outside a real HTTP request. Use `curl` against the live endpoint.
- **`aim_chatbot:recall` has no similarity-score threshold** - any
  non-empty result set gets formatted as "Relevant facts:" and handed to
  the model, even when the best match is poor; only zero rows gets a
  special "No relevant facts found." Tested against a 50-fact benchmark
  and the model's own judgment covered the gap that time, but this isn't
  proof it's safe at scale or across models - a minimum-score cutoff is
  the structural fix, not yet built.

## CCC (`ai_context`), not enabled here

If/when it is, the integration shape is `aim_chatbot` exposing tools
(done) and CCC holding curated policy about when to call them - not `aim`
becoming a CCC content source. See
[ADR-0008](../../adr/0008-chatbot-integration-mechanism.md).
