# ADR-0001: Storage and scope model

**Status:** Accepted; scope model has a documented, temporary PoC deviation.
**Date:** 2026-09-08 (storage), ongoing (scope model deviation)

## Context

Agent-memory frameworks (Mem0, Zep) build bespoke infrastructure - graph
stores, hierarchical extraction pipelines, hot/warm/cold tiering, ABAC -
for things Drupal already ships as entities, fields, roles, and Search
API. The open question was whether Drupal's own primitives, plus a real
vector index, are enough to build competitive agent memory without
reinventing that infrastructure, and whether facts need to be partitioned
by *who they're about* at the schema level (bundles) or can start as one
flat entity type.

## Decision

**Storage:** `aim_fact` is a plain content entity. Vectors do not live on
the entity via a Field API field - `ai_vdb_provider_mariadb` has no
`FieldType` plugin for attaching a `VECTOR` column directly. Instead, a
Search API index
(`aim_vector_index`, backend `search_api_ai_search`) keyed by entity ID
populates a separate collection table (`aim_facts`) that the provider
manages, backed by MariaDB 11.7+'s native `VECTOR` column and its
automatic HNSW `VECTOR INDEX`. In-database mode only, not
`ai_vdb_provider_mariadb`'s external-DB option - that would break
Drupal's transaction guarantees, which is the whole point of doing this
inside Drupal rather than bolting on a dedicated vector service.

**Scope model (as designed):** four bundles - user, role, site, case
(support tracking) - each with its own retention/visibility rules,
composable at retrieval the way Mem0 composes `user_id`/`agent_id`/
`run_id`.

**Scope model (PoC deviation, deliberate and temporary):** `aim_fact`
ships with one flat `scope` list field (user/role/site/case) instead of
four bundles, to keep the first slice small. This is a cheap-to-reverse
choice, not a redesign: no update hooks exist yet (see ADR-0002 and
CLAUDE.md's "Schema/config changes during early development"), so
reinstalling to bundle-per-scope is inexpensive *only* until real data
exists worth preserving - at which point it becomes a real migration, not
a reinstall.

## Consequences

- Two-part shape (structured entity + separate vector index keyed by
  entity ID) is the standard RAG/vector-search shape in production
  (Postgres+pgvector, Elasticsearch `dense_vector`, Pinecone/Weaviate/
  Qdrant) - not a novel architecture, just applied inside Drupal's own
  database instead of a dedicated vector-store service. That in-database
  placement is the one non-mainstream choice, and it's what buys real
  ACID/transaction guarantees the dual-write consistency problem most RAG
  stacks manage by hand doesn't have.
- Real gotcha inherited from this shape: the collection table only gets
  created/ALTERed on Search API index *update*, not *create* - a freshly
  created index entity needs a second save to get its attribute columns.
  `aim.install`'s `hook_install()` exists purely to trigger this
  automatically on module enable. `createCollection()` is also not
  actually idempotent despite its docstring - re-running it against an
  existing table throws instead of no-op'ing; the fix is `DROP TABLE
  aim_facts` and re-save, not fighting the exception.
- At PoC scale (9-16 facts) `aim_facts` is roughly 2.5x the size of
  `aim_fact` per row (the `VECTOR(1024)` column plus HNSW
  graph overhead). Trivial today; worth re-checking once fact count
  reaches the tens of thousands, where InnoDB buffer-pool pressure -
  competing with a live site's own working set for memory-resident index
  space - is the more realistic future pressure point than raw disk.
- The flat-`scope`-field deviation means today's `aim_fact` cannot express
  per-scope retention/visibility rules independently; every scope shares
  one set of field definitions and one access-control surface. Revisit
  before any non-PoC data goes in.
