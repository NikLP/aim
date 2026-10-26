# AIM Chatbot - Developer Reference

Mechanism detail and gotchas for `aim_chatbot`. Pitch/requirements in
[README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md), root
module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

---

## Mechanism

Uses `ai_agents` - see
[ADR-0008](../../adr/0008-chatbot-integration-mechanism.md) for the
mechanism and alternatives considered.

- The `ai_agents.ai_agent.aim_chatbot` config entity
  (`guardrail_set: aim_write_guardrails`) with `tool:aim_recall` and
  `tool:aim_remember` (`aim_tool`, through `tool_ai_connector`; function
  names `tool__aim_recall`/`tool__aim_remember`). No scope lock: the tools
  run as the visitor, so a role's scope permissions are what chat reaches
  ([ADR-0062](../../adr/0062-chat-on-the-shared-tools.md)). Recall's 5-result
  limit is a `tool_usage_limits` `force_value` with `hide_property`.
  `aim_remember` writes on the `chat` channel with source
  `chat:aim_remember` because its invoker is `tool_ai_connector`.
- `ai_assistant_api.ai_assistant.aim_assistant` ships in this module's
  `config/install`; the demo chat block is the `aim_demo_library`
  recipe's own config. The assistant's `ai_agent` field
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
- **`aim_recall` abstains past a distance cutoff** - rows whose
  cosine distance exceeds `aim.settings:recall_max_distance` (default
  0.48, editable at `/admin/config/aim/settings`, read via
  `AimMemoryManager::getRecallMaxDistance()`) are dropped, and an empty
  remainder returns "No relevant facts found." instead of a result list.
  `AimMemoryManager::recall()` itself does not filter, so `drush
  aim:recall` without `--max-distance` still returns raw results.
  Calibrated against `nomic-embed-text` (question vs. fact): answerable
  questions' best match 0.12-0.29 (full questions and terse keyword
  queries alike), near-topic-but-unanswerable 0.25-0.33, off-topic
  0.51-0.64. The cutoff catches the off-topic case only - an unanswerable
  question about a known topic still gets its nearest facts, and the model
  has to notice they don't answer it. **Retune if the embeddings model
  changes**, and recheck as the site-scope corpus grows or diversifies:
  pairwise distances don't move when facts are added, but more varied
  facts mean more coincidental near matches, so the off-topic floor
  drifts down (same queries against all scopes' 120 facts instead of
  site's 18 matched closer by up to 0.035). Decision, full measurements
  and the retuning checklist: [ADR-0019](../../adr/resolved/0019-recall-abstention-distance-cutoff.md).
  Separately, `recall()` returns fewer than 5 live rows here because
  retired facts take result slots - same ADR.

## CCC (`ai_context`), not enabled here

If/when it is, the integration shape is `aim_chatbot` exposing tools
(done) and CCC holding curated policy about when to call them - not `aim`
becoming a CCC content source. See
[ADR-0008](../../adr/0008-chatbot-integration-mechanism.md).
