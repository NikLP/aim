# CLAUDE.md — AIM Chatbot

`ai_agents` FunctionCall tools exposing AIM memory to a chat assistant.
Part of the [aim](../../CLAUDE.md) project - see the root module's
CLAUDE.md for environment, architecture decisions, and the code-style/
git rules shared across all of `aim`'s submodules; this file only covers
what is specific to `aim_chatbot`.

## Scope

**Hardcoded to `scope: site`, deliberately.** A chat visitor isn't
resolved to a real Drupal account, so `scope: user` writes/reads aren't
offered here - see
[ADR-0007](../../adr/resolved/0007-user-scope-requires-real-account.md). Don't
widen this without a real per-visitor identity to scope to.
`aim_chatbot.info.yml` depends on `aim_scope_site` (ADR-0026 piece 5) so
the `site` bundle this hardcode relies on is guaranteed installed.

## What lives here

- `src/Plugin/AiFunctionCall/AimRemember.php` / `AimRecall.php` - the two
  tools, both backed by `aim.memory_manager` directly (no extraction LLM
  call - same write path as `drush aim:remember`/`aim:recall`).
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
- Recall is not forced: `require_usage` is off on `aim_chatbot:recall`
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
  35/36 at 2.3 s a turn, the one miss a literal choice.
- `src/EventSubscriber/AimChatbotSourceTextSubscriber.php` - before the
  remember tool runs, passes it the visitor's last message plus the
  assistant turn before it, for the grounded check
  ([ADR-0037](../../adr/resolved/0037-transient-source-passages-for-grounding.md)).
  The previous turn is only there when the assistant keeps history
  (`allow_history`); this module ships `none`, the demo recipe sets
  `private_tempstore_pool`.
- `config/install/ai_agents.ai_agent.aim_chatbot.yml` - the agent config,
  `guardrail_set: aim_write_guardrails`.
- `config/install/ai_assistant_api.ai_assistant.aim_demo_assistant.yml` +
  `config/optional/block.block.olivero_aimdemochat.yml` - demo assistant
  and block, both shipped so the capability is visible without extra
  config.

## Abstention

`aim_chatbot:recall` drops matches past `aim.settings:recall_max_distance`
and answers "No relevant facts found." when none survive - see
[DEVELOPING.md](DEVELOPING.md)'s "Gotchas" and
[ADR-0019](../../adr/resolved/0019-recall-abstention-distance-cutoff.md). The
cutoff is embeddings-model specific: retune it if the model changes, and
recheck as the site-scope corpus grows or diversifies. The same cutoff is
passed to `AimMemoryManager::recall()` as `$maxDistance`, and `aim_tool`'s
MCP `aim_recall` applies it by default too.

## CCC (`ai_context`)

Not enabled on this site. If/when it is, the integration shape is
`aim_chatbot` exposing tools (done) and CCC holding curated policy about
when to call them - not `aim` becoming a CCC content source. See
[ADR-0008](../../adr/0008-chatbot-integration-mechanism.md).
