# CLAUDE.md — AIM Chatbot

A chat assistant on AIM memory: an `ai_agents` agent using `aim_tool`'s
`aim_recall`/`aim_remember` (and `aim_literal`) through `tool_ai_connector`.
Part of the [aim](../../CLAUDE.md) project - see the root module's
CLAUDE.md for environment, architecture decisions, and the code-style/
git rules shared across all of `aim`'s submodules; this file only covers
what is specific to `aim_chatbot`.

## Scope

**Set by the visitor's permissions, not by code**
([ADR-0062](../../adr/resolved/0062-chat-on-the-shared-tools.md)). The tools run as
the visitor: recall returns only facts they may view, remember checks
`create {scope} aim facts` per fact. A chat-facing role needs `recall aim
facts`/`remember aim facts` plus the per-scope permissions; whatever scopes
it holds, chat reaches. The demo recipe gives members `site` only. Chat
writes go on the `chat` trust channel (shipped `hold`) because
`aim_remember` picks the channel from its Tool API invoker
(`tool_ai_connector`), not because of anything here. The one hardcoded
`site` left is `AimChatbotRecallContextSubscriber`'s; `site` ships with
`aim`.

## What lives here

- No tools of its own: the agent lists `tool:aim_recall`,
  `tool:aim_remember` and (in the demo recipe) `tool:aim_literal`. Recall
  is limited to 5 results by the agent's `tool_usage_limits` (forced,
  hidden from the model). The grounded check's evidence is the
  `source_text` the model passes, as over MCP; reading the chat history
  instead was dropped with the old tools (ADR-0062 decision 3 says when
  it comes back).
- `src/Plugin/ChatProcessor/AimGateProcessor.php` (`aim_chatbot_gate`,
  extends the AI Assistant API processor) - the finder-first gate. Asks the
  literals finder about the last message with its own stricter thresholds
  (`gate_match_threshold` 0.8, `gate_choice_margin` 0.4, calibrated on the
  74-item gold sets: 30 hits, 1 arguable wrong match, against 5 at the finder
  defaults); a clear match is answered from a fixed template (label, value,
  the gist so a wrong match shows), no chat model; anything else, or any
  finder error, goes to the assistant as before. The block's Chat Executor
  is the switch: changing `chat_processor_plugin` back to
  `ai_assistant_api_processor` turns the gate off. Audit lines carry IDs
  only. Needs `literals_finder` to do anything; without it, a pass-through.
- Recall is not forced: `require_usage` is off on `tool:aim_recall`
  (shipped and in the `aim_demo_library` recipe, which also sets
  `max_loops` 7). Forcing it made `ai_agents` discard a correct answer
  whenever a turn ended without a recall call (a second question in a
  conversation, an off-topic refusal) and loop to `max_loops`. Measured
  2026-10-22 with `drush aim:chatbot-converse` (site's
  `demo/chat-eval/`): forced 32/36 turns, 3 out of loops, 6.3 s a turn;
  off 36/36, 3.6 s. The older "wifi 0 of 5 without it" did not reproduce
  on that model.
- `src/EventSubscriber/AimChatbotRecallContextSubscriber.php` - optional
  recall in code: with the agent's third-party setting
  `aim_chatbot.recall_context: true`, each visitor message (and it with the
  previous one, for follow-ups) is recalled before the model runs and the
  facts are added to that turn's system prompt only, never to stored
  history. The recall tool stays available. Off by default; measured
  35/36 at 2.3 s a turn, the one miss a literal choice. On the shared
  tools (2026-10-25, `free`): 36/36 at 2.8 s a turn as uid 1, 36/36 at
  3.0 s as a plain member.
- `config/install/ai_agents.ai_agent.aim_chatbot.yml` - the agent config,
  `guardrail_set: aim_write_guardrails`.
- `config/install/ai_assistant_api.ai_assistant.aim_assistant.yml` - the
  assistant a chat block uses. The demo block
  (`block.block.olivero_aimdemochat`) is the `aim_demo_library` recipe's
  config, not this module's.

## Abstention

`aim_recall` drops matches past `aim.settings:recall_max_distance`
and answers "No relevant facts found." when none survive - see
[DEVELOPING.md](DEVELOPING.md)'s "Gotchas" and
[ADR-0019](../../adr/resolved/0019-recall-abstention-distance-cutoff.md). The
cutoff is embeddings-model specific: retune it if the model changes, and
recheck as the site-scope corpus grows or diversifies. The recall-context
subscriber passes the same cutoff.

## CCC (`ai_context`)

Not enabled on this site. If/when it is, the integration shape is
`aim_tool`'s tools (done) and CCC holding curated policy about
when to call them - not `aim` becoming a CCC content source. See
[ADR-0008](../../adr/resolved/0008-chatbot-integration-mechanism.md).
