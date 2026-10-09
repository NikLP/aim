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
[ADR-0010](adr/resolved/0010-drupal-native-agent-memory-rationale.md), the
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
- **Entity memory** - a fact about one specific piece of site content
  (a node, a media item, anything with its own Drupal access model),
  visible to whoever can already see that content rather than through a
  flat permission. Added later than the original four
  ([ADR-0027](adr/resolved/0027-entity-scope.md)), as the bridge point for a future
  integration with Annotations (human-curated site knowledge).

## How it works

- **Storage:** entities with `VECTOR` fields via `ai_vdb_provider_mariadb`,
  in-database, full transaction support.
- **Processing:** Queue API plus a dedicated crontab entry, separate from
  `hook_cron` - extraction, consolidation, and decay run unattended, not
  on the live request path.
- **Governance:** every candidate fact passes through `drupal/ai`'s
  Guardrails (prompt-injection/PII filtering) before it's written. A
  lightweight `trusted` flag is the draft-to-trusted human-review gate
  ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
  addendum) - `recall()` excludes untrusted facts by default; full
  Content Moderation/revisioning is deferred for the PoC - see
  [CLAUDE.md](CLAUDE.md). One deliberate
  exception either way: an entity flagged as synchronizing
  (`setSyncing(TRUE)`, which core's Migrate destinations set on
  everything they save) skips both Guardrails and the consolidation
  queue - a migrated corpus is stored exactly as given.
- **Exact values:** `literals` holds values that must be exact (a phone
  number, a URL). A fact can point at one with `[literal:key]`, and the
  assistant has a lookup tool for them. The value is read live as the person
  asking, so it is never stale or copied, and a fact naming a value they may
  not see is withheld. See [DEVELOPING.md](DEVELOPING.md)'s "Exact values
  from `literals`".
- **Non-destructive consolidation:** merging or discarding a fact never
  deletes it. It is retired (kept for audit, excluded from recall) with a
  recorded reason, and a merge creates a new fact rather than overwriting
  one, so any decision can be reversed. A proposed merge must also pass a
  faithfulness check by a second model, else both facts are kept.
- **Sovereignty:** extraction/consolidation reasoning can run against a
  local model (Ollama, via `drupal/ai`'s provider support) for
  deployments where data can't leave the customer's infrastructure.
  Embedding generation is cheap enough to self-host regardless.

## How accurate is the search, and what can you tune?

Finding the facts closest in meaning to a question would mean comparing
the question against every stored fact. That gets slow as memory grows, so
MariaDB builds an index: a map that lets it jump straight to the right
neighborhood instead of checking everything. The catch is that the map is
approximate. Most of the time it returns exactly what an exhaustive search
would. Occasionally it misses one of the closest few.

We measured this rather than assuming it. On a test memory of about 5,000
facts, MariaDB's out-of-the-box settings missed roughly 3 to 12 of every
100 of the truly closest facts. Two dials fix that, and both ship set:

- **How well the map is connected (called `M`).** More routes between
  neighborhoods means fewer dead ends, at the cost of a slightly bigger
  index. We use 16. MariaDB's own default is 6, which is too low. This is
  fixed when the index is created, so changing it later means rebuilding
  the index once (seconds, for thousands of facts).
- **How many places it checks per question (called `ef_search`).** More
  means it looks at more candidates before answering, at the cost of a
  slightly slower search. We use 100. MariaDB's default is 20. You can
  change it any time in the search server's settings.

With those two, every test question matched the exact answer, in 1 to 6
milliseconds per search. Raising `M` further did not help and made
searches slower.

Two more things help. Facts about one person are looked up through a
sorted list of who owns what, so searching one user's memory is exact
rather than approximate, unless that user holds a large share of
everything. And a small memory does not strictly need the map: an
exhaustive search takes about 18 ms at 5,000 facts and 180 ms at 50,000,
then gets slow.

What this does not promise: an approximate index cannot guarantee 100%,
the test used made-up facts, and it was one size. Re-check when your
memory grows about tenfold, when you change the embeddings model, or when
you upgrade the vector provider. How, and where these settings live, is in
[DEVELOPING.md](DEVELOPING.md); the measurements are in
[ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md).

## A second surface: generative/planning use

The same extraction/consolidation pipeline, run *before* a site exists,
could turn a discovery conversation into a **Drupal Recipe** (spec ->
buildable site) - content types, fields, taxonomy, seed content,
scaffolded directly from the conversation. Higher-stakes than
retrospective memory: an AI-generated Recipe mutates live site structure,
so it requires a dry-run/human-review gate before `drush recipe apply`,
same principle as the human-review gate on memory facts. Design only, not
yet built - see [ADR-0035](adr/0035-standing-constraints-action-gate.md).

## Requirements

- Drupal 11.4+
- MariaDB 11.8 LTS (via `drupal/ai_vdb_provider_mariadb`; plain MySQL's
  `VECTOR` type has no self-hostable indexed ANN search)
- `drupal/ai` (provider abstraction, Guardrails submodule)
- Local Ollama for zero-API-key development

`aim` itself ships zero scopes (ADR-0026) - `user`/`role`/`site`/`case`/
`entity` each come from their own submodule, so a site installs only the
ones it wants. Each submodule has its own requirements and setup:
[aim_scope_user](modules/aim_scope_user/README.md),
[aim_scope_role](modules/aim_scope_role/README.md),
[aim_scope_site](modules/aim_scope_site/README.md),
[aim_scope_case](modules/aim_scope_case/README.md),
[aim_scope_entity](modules/aim_scope_entity/README.md),
[aim_chatbot](modules/aim_chatbot/README.md),
[aim_tool](modules/aim_tool/README.md),
[aim_tool_oauth](modules/aim_tool_oauth/README.md),
[aim_benchmark](modules/aim_benchmark/README.md) (dev tool, optional).

## Getting started

1. Enable `aim` plus whichever scopes you want (`aim_scope_user`/
   `aim_scope_role`/`aim_scope_site`/`aim_scope_case`), and
   `aim_chatbot`/`aim_tool` as needed. `aim_chatbot`'s tools are
   hardcoded to `scope: site`, so it needs `aim_scope_site` installed
   too.
2. Configure a real AI provider and point vector search at it - see
   [DEVELOPING.md](DEVELOPING.md)'s "Setting up vector search" runbook.
   `config/install` ships the search server/index *structure*, not a
   working provider - this step is required on every fresh install.
3. Try it: `drush aim:remember "some fact" --scope=site`, then
   `drush aim:recall "some fact"`. For an unattended pipeline,
   `drush aim:extract <file>` classifies and stores facts from raw text; the
   Content > AIM facts > Ingest form (`ingest aim memory`) does the same, plus
   one-fact-per-line and JSON imports without a model.
4. For a remote MCP client (Claude.ai/Claude Desktop, no Drupal session),
   enable `aim_tool_oauth` - see its own
   [README.md](modules/aim_tool_oauth/README.md)/
   [DEVELOPING.md](modules/aim_tool_oauth/DEVELOPING.md) for the setup
   runbook.

5. Optional logging, at `/admin/config/aim/settings`: warnings and errors
   (rejected writes, blocked merges, a suspended queue) always go to the
   `aim` log channel. `log_audit` adds writes, consolidation decisions and
   trust/retire actions; `log_verbose` adds recall, embedding cache and
   extraction detail. Both default off. Fact text is never logged. Recall
   query text is logged only if you also tick `log_query_text` (personal
   data, for short investigations).

To see it working with a story already loaded, apply the
[community library demo recipe](recipes/aim_demo_library/README.md) to a
fresh site (untested so far, see its README).

Full command reference and the developer API are in
[DEVELOPING.md](DEVELOPING.md).
