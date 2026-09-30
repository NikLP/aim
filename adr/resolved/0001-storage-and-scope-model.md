# ADR-0001: Storage and scope model

**Status:** Accepted. Scope model's PoC deviation was resolved 2026-09-15
(real bundles now exist, see the addendum at the end).
**Date:** 2026-09-08 (storage), 2026-09-15 (scope model deviation resolved)

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

**Scope model (PoC deviation, resolved 2026-09-15):** `aim_fact` shipped
with one flat `scope` list field (user/role/site/case) instead of four
bundles, to keep the first slice small. This paragraph is now historical;
see the addendum at the end for the actual conversion, done while it was
still cheap (no update hooks existed yet, so the reinstall this paragraph
anticipated stayed a reinstall, never became a migration).

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
- Resolved by the 2026-09-15 bundle conversion (see addendum): scopes are
  now real `aim_scope` bundles with their own per-bundle `view`/`create`
  permissions (`BundlePermissionHandlerTrait`), not one shared
  access-control surface. Per-scope *field* definitions remain
  deliberately unbuilt - see DEVELOPING.md's "Scope/bundle model" for why
  `subject`/`subject_uid` as per-bundle fields was tried and reverted.

## Addendum (2026-09-15): scope model deviation resolved, real bundles built

Built in two steps, not one. `aim_fact`'s `scope` field was first
converted to bundles via `hook_entity_bundle_info()` (2026-09-14), then
the bundle source itself was converted a second time to a real
`aim_scope` config entity (`bundle_entity_type` on `AimFact`,
2026-09-15) - `id`/`label` only, no `field_ui_base_route`. Base fields
(`scope`, `subject`, `subject_uid`, `text`, etc.) don't duplicate storage
per bundle, so the conversion cost was a wide-but-mechanical refactor
(every `->get('scope')->value` read became `->bundle()`) rather than a
schema migration - the "cheap-to-reverse choice" framing above held.

Real payoff: `AimPermissions` now generates `view {scope} aim facts`/
`create {scope} aim facts` per bundle via
`BundlePermissionHandlerTrait`, replacing a hand-rolled permission loop.
Deleting a scope now cleans up its own permission grants automatically
via config-dependency tracking. A site or contrib module can still add a
fifth scope with zero PHP (`config/install/aim.aim_scope.<id>.yml`), or
now also through the admin UI - the conversion only added the second
option. Full mechanism and gotchas (the reverted per-bundle-field
attempt, the `field_ui_base_route` guardrail) in DEVELOPING.md's
"Scope/bundle model".
