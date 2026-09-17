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
[ADR-0007](../../adr/0007-user-scope-requires-real-account.md). Don't
widen this without a real per-visitor identity to scope to.

## What lives here

- `src/Plugin/AiFunctionCall/AimRemember.php` / `AimRecall.php` - the two
  tools, both backed by `aim.memory_manager` directly (no extraction LLM
  call - same write path as `drush aim:remember`/`aim:recall`).
- `config/install/ai_agents.ai_agent.aim_chatbot.yml` - the agent config,
  `guardrail_set: aim_write_guardrails`.
- `config/install/ai_assistant_api.ai_assistant.aim_demo_assistant.yml` +
  `config/optional/block.block.olivero_aimdemochat.yml` - demo assistant
  and block, both shipped so the capability is visible without extra
  config.

## Known gap

`aim_chatbot:recall` has no similarity-score threshold - see
[DEVELOPING.md](DEVELOPING.md)'s "Gotchas". A minimum-score cutoff is the
structural fix, not yet built.

## CCC (`ai_context`)

Not enabled on this site. If/when it is, the integration shape is
`aim_chatbot` exposing tools (done) and CCC holding curated policy about
when to call them - not `aim` becoming a CCC content source. See
[ADR-0008](../../adr/0008-chatbot-integration-mechanism.md).
