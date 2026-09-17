# AIM - Drupal AI memory infrastructure

This module is under heavy development. Feedback at any level is
appreciated. Some claims in this file may be out of date. The config that
ships here is almost certain not to work on your setup unmodified - see
[DEVELOPING.md](DEVELOPING.md)'s setup runbooks for what to change and
why.

**Status: PoC working end to end.** A real `aim_fact` entity, indexed
through Search API's AI Search backend into a MariaDB `VECTOR` column,
with real embeddings generated locally via Ollama and real semantic
queries returning correct results. `drush aim:extract` turns raw text
into classified, scoped facts via a chat provider call; `drush
aim:remember`/`aim:recall` let an agent that has already done its own
reasoning write and query facts with no extra chat call. See
[CLAUDE.md](CLAUDE.md) for current build state and
[adr/](adr/0000-index.md) for the decisions made building it, including
[ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md), the
original deeper rationale (market comparison, risk analysis) this repo's
other ADRs are downstream of.

## The premise

Businesses are going to want in-house agentic memory as model costs fall,
the same way they wanted in-house CMSs once web publishing got cheap.
This project asks whether that's buildable in Drupal now, cheaply enough
to be worth having ready, rather than waiting for the market and buying
in later.

It is assumed you won't use this module for classified information - an
AI agent that isn't sovereign (local) can expose your data. This is a
PoC with the view that it will be used with a local reasoning model in
future.

## Why Drupal, why SQL

Agent-memory frameworks (Mem0, Zep) build custom infrastructure - graph
stores, hierarchical extraction pipelines, hot/warm/cold tiering, ABAC -
for things Drupal already ships:

| Concept (Mem0/Zep) | Drupal equivalent |
| --- | --- |
| Graph of context objects + relationships | Entity Reference fields |
| Vector embedding per object | `VECTOR` field via `ai_vdb_provider_mariadb`, on the same entity |
| `user_id`/`agent_id`/`run_id`/`org_id` scoping | User entity, Role, a case/session entity, a site-global bundle |
| Provenance (fact -> source episode) | Entity reference to the source content/interaction |
| Audit trail | Revisioning / Content Moderation |
| Governed at the data layer | Permissions/roles, plus a draft-to-trusted human-review workflow state |
| Policy versioning | Config export/sync |

MariaDB 11.7+'s native `VECTOR` type and HNSW `VECTOR INDEX`, wrapped by
`drupal/ai_vdb_provider_mariadb` as a provider for `drupal/ai` and Search
API, closed the last technical gap: vectors now live in the same
transactional database as everything else Drupal stores, instead of a
bolted-on vector service.

## Scope model

Four memory categories, composable at retrieval the way Mem0 composes
`user_id`/`agent_id`/`run_id`:

- **User/developer memory** - per-account, permission-gated.
- **Role memory** - shared across everyone holding a role ("what Editors
  collectively know"). No off-the-shelf competitor (Mem0, Zep) does this
  natively - the candidate differentiator.
- **Site memory** - one global/org-scoped graph of facts about the site
  itself.
- **Support tracking** - session-scoped episodic memory, modeled as a real
  entity with workflow states (open -> assigned -> resolved), not a bare
  session-ID string.

## How it works

- **Storage:** entities with `VECTOR` fields via `ai_vdb_provider_mariadb`,
  in-database, full transaction support.
- **Processing:** Queue API plus a dedicated crontab entry, separate from
  `hook_cron` - extraction, consolidation, and decay run unattended, not
  on the live request path.
- **Governance:** every candidate fact passes through `drupal/ai`'s
  Guardrails (prompt-injection/PII filtering) before it's written. A
  draft-to-trusted human-review gate is designed
  ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)) but
  deferred for the PoC - see [CLAUDE.md](CLAUDE.md). One deliberate
  exception either way: an entity flagged as synchronizing
  (`setSyncing(TRUE)`, which core's Migrate destinations set on
  everything they save) skips both Guardrails and the consolidation
  queue - a migrated corpus is stored exactly as given.
- **Sovereignty:** extraction/consolidation reasoning can run against a
  local model (Ollama, via `drupal/ai`'s provider support) for
  deployments where data can't leave the customer's infrastructure.
  Embedding generation is cheap enough to self-host regardless.

## A second surface: generative/planning use

The same extraction/consolidation pipeline, run *before* a site exists,
could turn a discovery conversation into a **Drupal Recipe** (spec ->
buildable site) - content types, fields, taxonomy, seed content,
scaffolded directly from the conversation. Higher-stakes than
retrospective memory: an AI-generated Recipe mutates live site structure,
so it requires a dry-run/human-review gate before `drush recipe apply`,
same principle as the human-review gate on memory facts. Design only, not
yet built - see [ADR-0009](adr/0009-recipe-apply-safety-gate.md).

## Requirements

- Drupal 11.4+
- MariaDB 11.8 LTS (via `drupal/ai_vdb_provider_mariadb`; plain MySQL's
  `VECTOR` type has no self-hostable indexed ANN search)
- `drupal/ai` (provider abstraction, Guardrails submodule)
- Local Ollama for zero-API-key development

Each submodule has its own requirements and setup:
[aim_chatbot](modules/aim_chatbot/README.md),
[aim_tool](modules/aim_tool/README.md),
[aim_tool_oauth](modules/aim_tool_oauth/README.md).

## Getting started

1. Enable `aim` (and `aim_chatbot`/`aim_tool` as needed).
2. Configure a real AI provider and point vector search at it - see
   [DEVELOPING.md](DEVELOPING.md)'s "Setting up vector search" runbook.
   `config/install` ships the search server/index *structure*, not a
   working provider - this step is required on every fresh install.
3. Try it: `drush aim:remember "some fact" --scope=site`, then
   `drush aim:recall "some fact"`. For an unattended pipeline,
   `drush aim:extract <file>` classifies and stores facts from raw text.
4. For a remote MCP client (Claude.ai/Claude Desktop, no Drupal session),
   enable `aim_tool_oauth` - see its own
   [README.md](modules/aim_tool_oauth/README.md)/
   [DEVELOPING.md](modules/aim_tool_oauth/DEVELOPING.md) for the setup
   runbook.

Full command reference and the developer API are in
[DEVELOPING.md](DEVELOPING.md).

## Status and open questions

See [TODO.md](TODO.md) for the living backlog. The questions that block
starting real (non-PoC) work, per
[ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md):

1. What's the first concrete, sellable feature this unlocks?
2. Consolidation conflict-resolution policy - threshold heuristics vs.
   LLM-mediated merge decisions.
3. Local model choice and hardware sizing for the sovereign tier.
4. Relationship to `ai_agents`/`ai_search` - compose with them, or
   standalone?

Full list: [ADR-0010 § Open questions](adr/0010-drupal-native-agent-memory-rationale.md#open-questions).
