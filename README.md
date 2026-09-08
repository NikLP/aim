# aim - Drupal-native AI agent memory infrastructure

**Status: PoC working end to end.** A real `aim_fact` entity, indexed through
Search API's AI Search backend into a MariaDB `VECTOR` column, with real
embeddings generated via amazee.ai and a real semantic query returning the
right result. A working extraction prototype (`drush aim:extract`) turns raw
text into classified, scoped facts via a real chat provider call.
See [CLAUDE.md](CLAUDE.md) for the current build state and
[ADR-003-drupal-native-agent-memory.md](../../../../ADR-003-drupal-native-agent-memory.md)
for the full rationale and history.

## The premise

Businesses are going to want in-house agentic memory as model costs fall, the
same way they wanted in-house CMSs once web publishing got cheap. This project
asks whether that's buildable in Drupal now, cheaply enough to be worth having
ready, rather than waiting for the market and buying in later.

This is a **distinct venture**, not a feature of any other suite. It shares an
architectural idea with sibling projects (structured, permission-gated,
per-content or per-user context) but is a different product for a different
buyer.

## Why Drupal, why SQL

Agent-memory frameworks (Mem0, Zep) build custom infrastructure - graph
stores, hierarchical extraction pipelines, hot/warm/cold tiering, ABAC - for
things Drupal already ships:

| Concept (Mem0/Zep) | Drupal equivalent |
| --- | --- |
| Graph of context objects + relationships | Entity Reference fields |
| Vector embedding per object | `VECTOR` field via `ai_vdb_provider_mariadb`, on the same entity |
| `user_id`/`agent_id`/`run_id`/`org_id` scoping | User entity, Role, a case/session entity, a site-global bundle |
| Provenance (fact → source episode) | Entity reference to the source content/interaction |
| Audit trail | Revisioning / Content Moderation |
| Governed at the data layer | Permissions/roles, plus a draft-to-trusted human-review workflow state |
| Policy versioning | Config export/sync |

MariaDB 11.7+'s native `VECTOR` type and HNSW `VECTOR INDEX`, wrapped by
`drupal/ai_vdb_provider_mariadb` as a provider for `drupal/ai` and Search API,
closed the last technical gap: vectors now live in the same transactional
database as everything else Drupal stores, instead of a bolted-on vector
service.

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
  entity with workflow states (open → assigned → resolved), not a bare
  session-ID string.

## How it works

- **Storage:** entities with `VECTOR` fields via `ai_vdb_provider_mariadb`,
  in-database, full transaction support.
- **Processing:** Queue API plus a dedicated crontab entry, separate from
  `hook_cron` - extraction, consolidation, and decay run unattended, not on
  the live request path.
- **Governance:** every candidate fact passes through `drupal/ai`'s
  Guardrails (prompt-injection/PII filtering) and then sits in a
  draft-to-trusted human-review state before it's retrievable. Nothing
  extracted by an LLM is trusted on write.
- **Sovereignty:** extraction/consolidation reasoning can run against a local
  model (Ollama, via `drupal/ai`'s existing provider support) for deployments
  where data can't leave the customer's infrastructure. Embedding generation
  is cheap enough to self-host regardless.

## A second surface: generative/planning use

The same extraction/consolidation pipeline, run *before* a site exists, turns
a discovery conversation into a **Drupal Recipe** (spec → buildable site) -
content types, fields, taxonomy, seed content, scaffolded directly from the
conversation. Higher-stakes than retrospective memory: an AI-generated Recipe
mutates live site structure, so it requires a dry-run/human-review gate before
`drush recipe apply`, same principle as the human-review gate on memory facts.

## Requirements

- Drupal 11.4+
- MariaDB 11.8 LTS (via `drupal/ai_vdb_provider_mariadb`; plain MySQL's
  `VECTOR` type has no self-hostable indexed ANN search)
- `drupal/ai` (provider abstraction, Guardrails submodule)
- Local Ollama container for zero-API-key PoC development

## Build order

1. **PoC.** Prove the schema and vector search work end to end. Done -
   entity, index, and a real embedding provider (amazee.ai) are wired up and
   returning correct semantic search results. The zero-API-key path (local
   Ollama for embeddings) is still the plan for a fully self-hosted/sovereign
   setup; amazee is what's configured right now.
2. Extraction: turn a real interaction into candidate facts automatically,
   instead of writing them by hand. Prototype working - `drush aim:extract
   <file>` sends text to a real chat provider and creates classified,
   scoped facts from it. See CLAUDE.md's "Extraction" section for the
   details and what's still manual (invocation, not the source itself).
3. Wire an unattended `drupal/ai` provider for autonomous, cron-driven
   operation, plus the governance layer (Guardrails, draft-to-trusted) that
   the PoC is deliberately skipping for now.

See [ADR-003](../../../../ADR-003-drupal-native-agent-memory.md) for the full
context (market comparison against Mem0/Zep/Kenkeep, memory-poisoning risk
analysis, write-concurrency mitigations, and the open questions still
blocking a build commitment).

## Open questions

The ADR lists eleven; the ones that block starting real (non-PoC) work:

1. What's the first concrete, sellable feature this unlocks?
2. Consolidation conflict-resolution policy - threshold heuristics vs.
   LLM-mediated merge decisions.
3. Local model choice and hardware sizing for the sovereign tier.
4. Relationship to `ai_agents`/`ai_search` - compose with them, or standalone?

Full list: [ADR-003 § Open questions](../../../../ADR-003-drupal-native-agent-memory.md#open-questions).
