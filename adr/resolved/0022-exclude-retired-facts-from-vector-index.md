# ADR-0022: Retired facts stay out of the vector index

**Status:** Accepted - built and rolled out on the live site 2026-09-26
**Date:** 2026-09-26

## Context

Consolidation retires a superseded fact by setting `expires`, never by
deleting it, to keep an audit trail ([ADR-0005](../0005-consolidation-algorithm.md)).
The vector index does not know `expires`, so retired facts stayed in it and
`recall()` filtered them out in PHP after the index had already applied
`range(0, $limit)`. They took result slots: 57 of the 115 rows in the
vector table were retired, and `recall()` at limit 5 returned 2-3 live
rows ([ADR-0019](../0019-recall-abstention-distance-cutoff.md), "Related
finding"). The retired share only grows, and every retired row also
inflates the HNSW index and every neighbor search consolidation runs.

ADR-0019 recommended over-fetching (`$limit * 5`) and argued against an
indexed `retired` flag: a new column, a full reindex, a PHP post-filter
still needed, and MariaDB's lossy HNSW filtering. A separate
`aim_fact_retired` table was also raised.

## Decision

A Search API processor, `aim_exclude_retired`
(`src/Plugin/search_api/processor/ExcludeRetired.php`), runs in the
`alter_items` stage and rejects any `aim_fact` whose `expires` is set. It
ships enabled in `config/install/search_api.index.aim_vector_index.yml`.

Search API deletes an item a processor rejects from the server. So
retiring a fact (a plain `save()`, which re-tracks it) removes its vector
row on the next index run, and clearing `expires` puts it back. The fact
stays a full `aim_fact` entity: audit trail, `related` edges, admin views
and access control are unchanged.

The PHP `expires` checks in `recall()` and `findNearestNeighbor()` stay.
They now only cover the gap between a retirement and the next index run
(`index_directly` is off).

"Retired" means any non-empty `expires`, the same test `recall()` and
`consolidate()` already use. It is not a comparison against the current
time.

### Why this avoids ADR-0019's and ADR-0018's objections

It is not an indexed flag, so none of those apply.

| Objection to an indexed `retired` flag | Here |
| --- | --- |
| New column | None |
| Full reindex | Enabling the processor does not trigger one (it has no `preprocess_index` stage). Only the retired facts need re-tracking. |
| HNSW post-filter loses rows | There is no query condition. The row is absent, not filtered. |
| Provider cannot express `IS NULL` | No query condition. |
| Processor-computed property fatals ([ADR-0018](0018-index-subject-uid-with-btree.md), finding 1) | It adds no field. |
| PHP post-filter still needed for reindex lag | True here too, and kept. |

## Verification (2026-09-26)

Against throwaway facts, with the processor toggled on the live index:

- Control, processor off: a retired fact's row stays after reindex.
- Processor on: re-tracked retired fact's row is deleted, a live fact's row
  stays, and the tracker reports 100% (rejected items do not stay pending).
- Retire by plain `save()`, no manual tracking: the next `sapi-i` removes
  the row. Clear `expires` and save: the row returns and `aim:recall`
  finds the fact again.
- Rollout on the live site: 57 retired facts re-tracked and reindexed. The
  vector table went from 115 rows to 58 (56 live, 2 stale orphans, 0
  retired). `aim:recall "Who founded NeuralPulse Systems?" --limit=5` went
  from 2-3 rows to 5.

## Two upstream bugs found and worked around

Both are in `ai_vdb_provider_mariadb`, present in 1.0.1 and the 1.0.x head
(checked 2026-09-26). Both are fixed by `Drupal\aim\Vdb\AimMariaDBProvider`,
a subclass swapped in through `hook_ai_vdb_provider_info_alter()`
(`AimHooks::vdbProviderInfoAlter()`). Remove the class and the hook once
the provider fixes them.

1. **Saving the index throws.** `searchApiIndexUpdate()` calls
   `createCollection()` on every index save so the table exists before it
   ALTERs in attribute columns. The provider expects a failure to arrive as
   a `CreateCollectionException` it logs, but its connection runs with
   `MYSQLI_REPORT_STRICT`, so an existing table throws a bare
   `mysqli_sql_exception` out of `Index::save()`, after the config is
   written and before `updateFields()` runs. Every index save threw on this
   site, including the one [ADR-0018](0018-index-subject-uid-with-btree.md)
   plans, which would have thrown before the provider's ALTER for the new
   attribute column ran.
2. **Deletes stop at 10 rows.** `getVdbIds()` resolves Drupal item IDs to
   row IDs through `querySearch()` with its default `limit = 10`, so
   `deleteItems()` and `deleteIndexItems()` remove at most 10 rows per call
   and silently leave the rest. The first rollout attempt removed 10 + 7 =
   17 of 57 rows, one call per batch. Without the fix the processor would
   only work for small batches, and a consolidation sweep that retires more
   than 10 facts between index runs would leave rows behind that nothing
   revisits.

A third override (empty numeric values written as `NULL`) was added later
by [ADR-0018](0018-index-subject-uid-with-btree.md).

## Rollout on an existing site

`config/install` does not re-run on an installed module, and no
`hook_update_N()` exists (CLAUDE.md). Enable the processor on the index
(the admin UI at `/admin/config/search/search-api/index/aim_vector_index/processors`,
or config import), then purge the retired rows already indexed:

```bash
ddev drush php:eval '$ids = \Drupal::entityQuery("aim_fact")->accessCheck(FALSE)->exists("expires")->execute(); \Drupal\search_api\Entity\Index::load("aim_vector_index")->trackItemsUpdated("entity:aim_fact", array_map(fn($id) => "$id:en", array_values($ids)));'
ddev drush sapi-i aim_vector_index
```

## Alternatives considered

- **A separate `aim_fact_retired` table or entity.** Retirement is a state
  of a fact, not a different kind of thing. Moving a row gives it a new id,
  which breaks `related` edges pointing at it (the dangling-edge problem in
  [ADR-0012](../0012-fact-relation-graph.md)), duplicates the entity type,
  views and access handler, complicates consolidation's "lower id is kept"
  rule, and turns un-retiring into a copy. The one thing it buys, removing
  the vector row, this decision gives for free.
- **Over-fetch only** (ADR-0019's recommendation). Treats the symptom: the
  multiplier has to keep growing with the retired share, and every retired
  row still costs index size and search time.
- **An indexed `retired` flag.** See the table above and ADR-0019/0018.
- **Hard-deleting retired facts.** Loses the audit trail ADR-0005 keeps on
  purpose, except for the DELETE decision, which already exists for facts
  that should never have been memories.

## Consequences

- Retired facts are no longer vector-searchable, so "what did we used to
  believe" queries are not possible. Nothing needs them today. A second
  index over retired facts would add it later.
- `expires` is treated as a retired marker, not a TTL. A future-dated
  expiry would remove a fact from the index early, so a real TTL needs its
  own field.
- Retention and erasure are a separate question this does not answer:
  retired facts about a person are still personal data in `aim_fact`. If a
  prune, archive or right-to-erasure policy is needed, that is the trigger
  for moving old retired rows out of `aim_fact`, in its own ADR. Not built.
- The vector table is roughly half its earlier size on this site, which
  also shrinks the candidate pool for every neighbor search.
- The two-row stale-orphan finding in ADR-0018 is unaffected and still
  self-heals.
- Two upstream issues to file against `ai_vdb_provider_mariadb` (TODO.md).
