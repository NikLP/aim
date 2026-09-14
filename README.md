# aim - Drupal AI memory infrastructure

This module is currently under heavy development. Your feedback at any level is
appreciated and welcomed.

As such, some claims in the file you're reading may be out of date or quite
possibly incorrect.

The config that ships here is almost certain not to work on your setup. The
details below outline that and how to replicate somewhat, but YMMV.

**Status: PoC working end to end.** A real `aim_fact` entity, indexed through
Search API's AI Search backend into a MariaDB `VECTOR` column, with real
embeddings generated locally via Ollama and a real semantic query returning
the right result. A working extraction prototype (`drush aim:extract`) turns raw
text into classified, scoped facts via a real chat provider call, and a
direct `aim:remember`/`aim:recall` pair lets an agent that has
already done its own reasoning write and query facts with no extra chat
call.
See [CLAUDE.md](CLAUDE.md) for the current build state and
[adr/](adr/0000-index.md) for the concrete decisions made while building
it, including
[ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md), the original,
deeper rationale (market comparison, risk analysis) this repo's other ADRs
are downstream of - folded into this directory 2026-09-10, previously a
standalone root-level document.

## The premise

Businesses are going to want in-house agentic memory as model costs fall, the
same way they wanted in-house CMSs once web publishing got cheap. This project
asks whether that's buildable in Drupal now, cheaply enough to be worth having
ready, rather than waiting for the market and buying in later.

It is assumed that you won't use this module for managing classified
information - *using an AI agent that is not sovereign (local) can expose
your data*. This module is a PoC with the view that it will be used with a local
reasoning model in future.

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

## Setting up vector search (fresh install, or after a provider change)

`config/install` ships the *structure* of the vector search server/index -
field mappings, the AI Search backend wiring, the collection-table schema -
but **not a working AI provider**. The `chat_model`/`embeddings_engine`
plugin IDs it ships with are this site's current working choice at the time
they were last exported, not a portable default: they reference a specific
provider, model, and API key a fresh site won't have, and - proven twice in
one afternoon building this - can go stale on an *already-working* site too,
the moment the account's available models change underneath it. Don't expect
`drush en aim` alone to leave vector search actually working; that isn't a
bug, it's expected, and always needed this manual step, just undocumented
until now.

Run this after enabling `aim` on a fresh site, or whenever `drush aim:recall`
starts erroring on the embeddings call:

1. **Enable and configure a real AI provider module** for chat and
   embeddings - `ai_provider_anthropic`, `ai_provider_amazeeio`, or
   `ai_provider_ollama` (decision 5 in CLAUDE.md: Ollama is the only local/
   sovereign option). Anthropic has no embeddings API at all - don't try to
   point `embeddings_engine` at it.
2. **Store the provider's API key as a `key` entity**
   (Configuration > System > Keys, or `drush key:`) - referenced from the
   search server's `backend_config`, same as any `drupal/ai` consumer.
3. **Check what models the key actually has access to through Drupal's own
   provider service**, not by calling the third-party API directly - the
   direct-API path can give a misleading answer (see CLAUDE.md's
   "amazee.ai embeddings bug" note for a real example of this happening):

   ```bash
   drush php:eval "print_r(\Drupal::service('ai.provider')->createInstance('<provider_id>')->getConfiguredModels('chat'));"
   ```

   Swap `'chat'` for `'embeddings'` to check the embeddings side. Model
   lineups on a hosted account can and do change without warning.
4. **Point `search_api.server.aim_vector`'s `backend_config` at the real
   provider/model IDs**, each in `<provider_id>__<model_id>` form:

   ```bash
   drush config:set search_api.server.aim_vector backend_config.chat_model '<provider>__<model>'
   drush config:set search_api.server.aim_vector backend_config.embeddings_engine '<provider>__<model>'
   ```

5. **If the new embeddings model's output dimension differs from 1024**,
   verify first with a real call (`$provider->embeddings(new
   EmbeddingsInput('test'), '<model>', [])`, count the returned array),
   then update `embeddings_engine_configuration.dimensions` to match, and
   drop and recreate the collection table (`DROP TABLE aim_facts` before
   step 6) - `VECTOR` columns are fixed-width, can't be altered in place.
6. **Re-save the index entity** to force the collection table's attribute
   columns to exist - a known `ai_vdb_provider_mariadb` gotcha (CLAUDE.md,
   "Vector search is working end to end"):

   ```bash
   drush php:eval "\Drupal::entityTypeManager()->getStorage('search_api_index')->load('aim_vector_index')->save();"
   ```

   If this throws "Table already exists", the table is mid-recreate from a
   previous attempt - `DROP TABLE aim_facts` and re-run this step.
7. **Reindex every fact, not incrementally** - a provider/model change
   means every existing vector is in the old model's embedding space, not
   comparable to new queries even at the same dimension. If step 5 dropped
   and rebuilt `aim_facts` (a dimension change), the table is already
   empty - reindex directly and **skip `search-api:clear`**: it reprovisions
   `aim_facts` down to just the base columns, silently dropping `scope`/
   `source`/`subject`/`text` again, and the next `search-api:index` fails
   `Unknown column 'scope'` (fix: re-run step 6's `->save()` to restore
   them, then index). If no dimension change happened (dimension unchanged
   from step 5), `search-api:clear` is safe to run first:

   ```bash
   drush search-api:index aim_vector_index
   ```

8. **Verify with a real query**: `drush aim:recall "<something you know is
   in there>"` should return sane, correctly-ranked results.

None of this touches `aim_fact` itself - the real facts are plain Drupal
content entities, never at risk from a provider change. Steps 5-7 cost real
embedding-API credit (one call per fact, or per chunk under the
`contextual_chunks` strategy) and scale with fact count - trivial at PoC
scale, a real line item once volume grows (see CLAUDE.md's AI dependency
map).

## Build order

1. **PoC.** Prove the schema and vector search work end to end. Done -
   entity, index, and a real embedding provider are wired up and returning
   correct semantic search results. The zero-API-key path (local Ollama for
   embeddings) is what's configured now - switched from amazee.ai
   2026-09-10, confirmed ~15x faster recall as a side effect (see
   CLAUDE.md's "Benchmarking" section).
2. Extraction: turn a real interaction into candidate facts automatically,
   instead of writing them by hand. Prototype working - `drush aim:extract
   <file>` sends text to a real chat provider and creates classified,
   scoped facts from it. See CLAUDE.md's "Extraction" section for the
   details and what's still manual (invocation, not the source itself).
   `drush aim:remember`/`drush aim:recall` (CLAUDE.md's "CLI agent
   adapter" section) cover the other case: a caller, like a Claude Code
   session, that has already decided what's worth remembering and just
   needs a direct write/query path with no extra chat call. For a human
   admin rather than an agent or a terminal: `/admin/content/aim-facts`
   lists every remembered fact, and `drupal/queue_ui`
   (`/admin/config/system/queue-ui`) gives a manual "Run" button for the
   consolidation queue - see CLAUDE.md's "Admin UI" section.
3. Wire an unattended `drupal/ai` provider for autonomous, cron-driven
   operation, plus the rest of the governance layer (Content Moderation,
   draft-to-trusted) that the PoC is deliberately skipping for now.
   Guardrails' cheap, no-LLM-call path (`RegexpGuardrail` +
   `InputLengthLimit`) is already wired into every write - see CLAUDE.md's
   "Guardrails cheap-path initial offering" section. Consolidation's own
   unattended path is also built: a Queue API worker enqueued on every
   fact write, drained only by a dedicated crontab entry (never
   `hook_cron`) - see CLAUDE.md's "Consolidation phase 2" section for the
   mechanism, and a real threshold-miscalibration bug it caught and fixed
   along the way.

See [adr/](adr/0000-index.md) for the concrete decisions made while building
this (storage/scope, governance, consolidation, the chatbot mechanism, and
more), including
[ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md) for the deeper
original context (market comparison, widened 2026-09-10 to seven systems -
Mem0, Zep, Letta/MemGPT, OpenAI, LangGraph/LangMem, Cognee, Supermemory -
with concrete parity targets instead of architectural bullet points;
memory-poisoning risk analysis, write-concurrency mitigations, and the open
questions still blocking a build commitment).

## Open questions

The ADR lists eleven; the ones that block starting real (non-PoC) work:

1. What's the first concrete, sellable feature this unlocks?
2. Consolidation conflict-resolution policy - threshold heuristics vs.
   LLM-mediated merge decisions.
3. Local model choice and hardware sizing for the sovereign tier.
4. Relationship to `ai_agents`/`ai_search` - compose with them, or standalone?

Full list: [ADR-0010 § Open questions](adr/0010-drupal-native-agent-memory-rationale.md#open-questions).
