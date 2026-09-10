# ADR-0004: Sovereignty option and zero-API-key PoC build order

**Status:** Accepted
**Date:** 2026-09-08, ongoing

## Context

Two related questions: whether a deployment that can't send data to a
third party (a genuinely sovereign/self-hosted requirement) is a supported
target, not just a hosted-API product; and how to prove the schema and
pipeline logic cheaply, before committing to any recurring API cost or
unattended provider wiring.

## Decision

**Sovereignty:** extraction/consolidation reasoning must support a local
model via `drupal/ai`'s Ollama-class provider. Embeddings may use a
smaller local model regardless of sovereignty tier, purely on cost
grounds - embedding generation is cheap enough to self-host either way.
`drupal/ai_provider_amazeeio` (amazee.ai's hosted "Private AI") and
`ai_provider_anthropic` are both installed as non-local reasoning/
embedding options - convenient, but neither satisfies the sovereignty
requirement for a genuinely local deployment. Don't drop Ollama from the
plan on account of having hosted options; support both.

**PoC build order:** prove schema and consolidation logic with an
interactive Claude Code session doing the reasoning and a local model
doing embeddings, before wiring any unattended `drupal/ai` provider. Zero
API keys, zero recurring cost, for the schema/logic-proving phase.

## Consequences

- A Claude Pro/Max subscription cannot power the unattended `drupal/ai`
  provider the shipped product eventually needs - Anthropic prohibits
  subscription OAuth for third-party integrations. That path needs a real
  Anthropic Console API key, or stays on local Ollama. Subscription quota
  only covers the interactive Claude Code reasoning step of this ADR, not
  cron-driven extraction.
- In practice the PoC ended up exercising **three** chat/reasoning
  providers, not just the two this decision names, because real
  environment issues forced it: amazee.ai (originally default), then a
  real Anthropic Console key (added once credit was purchased - initially
  rejected outright with `AiQuotaException`, confirmed a real pre-flight
  quota check, not a wiring bug), which became the site-wide default once
  usable. None of this changes the decision - Ollama is still the only
  *local* option among the three - but it's real evidence the "provider
  is a site choice, not a mandate" framing paid off: swapping providers
  mid-build didn't require redesigning anything, just updating config
  (twice, by hand, which is what motivated `AimMemoryManager::
  getDefaultChatProvider()` resolving the site-wide default automatically
  instead of a hardcoded PHP default - see CLAUDE.md's "AI dependency
  map").
- Migrating providers surfaced a real, non-obvious portability gap:
  Anthropic's structured-output mode requires `additionalProperties:
  false` on *every* object level of a JSON schema, stricter than
  amazee.ai's Bedrock-backed mode. Fixed as a pure schema addition (still
  valid JSON Schema under the previous provider), not a provider-
  conditional branch - but proof that "swap the provider ID" is not
  always the whole cost of a provider change.
- Embeddings are not swap-safe the same way chat is: a provider/model
  change invalidates every existing vector (different embedding space,
  and possibly a different dimension), requiring a full reindex, not an
  incremental one. Hit for real when amazee.ai's account lineup changed
  underneath the project mid-build (`titan-embed-text-v2:0` removed,
  `mistral-embed` the only replacement) - see README.md's "Setting up
  vector search" runbook, written directly from this incident.
