# ADR-0018: Index `subject_uid` as a Search API attribute, with a BTREE index on its column

**Status:** Proposed - analyzed 2026-09-19, not built. One open decision
(empty-value handling, below); everything else is settled by evidence.
**Date:** 2026-09-20

## Context

`recall()` (with `--subject-uid`) and `findNearestNeighbor()` (scope=user)
cannot narrow by `subject_uid` in the query, because it is not an indexed
Search API attribute. [ADR-0007](0007-user-scope-requires-real-account.md)
shipped an over-fetch (`recall()` asks for 5x the limit, user-scope
neighbor search asks for 20 instead of 5) plus a PHP-side equality check
as the workaround. TODO.md carried "index `subject_uid` as a search_api
attribute" as the fix.

The over-fetch is a fixed-size guess. Once several users' facts share the
index, the wanted user's nearest neighbors can fall outside the pool:
`recall --subject-uid` returns fewer rows than exist, and consolidation
silently misses duplicate pairs - the same failure ADR-0007 was written to
close, in a different guise. It is latent today: at analysis time all 77
user-scope facts belonged to uid 1 (most tagged `benchmark-corpus`) and
only 3 accounts exist.

Analyzed against Drupal 11.4.7, MariaDB 11.8.9, `drupal/ai` 1.4.9,
`ai_vdb_provider_mariadb` 1.0.1. The backlog item as written turned out to
be insufficient and, done naively, harmful. Findings first, then the
decision.

## Findings

All verified on the live site 2026-09-19 unless marked otherwise.

1. **Field shape.** Property path `subject_uid`, type `integer`, extracts
   the uid correctly (`FieldItemDataDefinition`, cardinality 1, so the
   provider treats it as single-valued). Path `subject_uid:target_id`
   resolves to a `DataReferenceTargetDefinition`, which has no
   `getFieldDefinition()`; `ai_vdb_provider_mariadb_is_field_multiple()`
   calls that unguarded during `indexItems()` (`updateFields()` guards it
   with `method_exists()`, `indexItems()` does not), so that path would
   fatal at index time (by reading the code, not by running an index).

2. **Non-user facts would stop indexing.** Site/role/case facts have an
   empty `subject_uid`. ai_search's `EmbeddingBase::getValue()` returns
   `''` for an empty value, and the provider creates attribute columns of
   type `INT` (`integer` maps to `INT`) and binds every value as a string.
   `''` into an `INT` column fails under MariaDB's strict SQL mode
   (`ERROR 1366 Incorrect integer value: ''`, reproduced on a temp table;
   the server runs `STRICT_TRANS_TABLES`). `NULL`, `0` and `'7'` all
   insert fine. So the attribute cannot be added as-is: every scope other
   than user would fail to index.

3. **MariaDB's HNSW index post-filters.** `EXPLAIN` on the provider's query
   shape uses the vector index (`key: embedding`, `Using where`) even with
   `WHERE` conditions. The index returns the top-`LIMIT` nearest rows over
   the whole table and `WHERE` is applied afterward, so a selective filter
   returns short or empty results:

   ```sql
   SET @v = (SELECT embedding FROM aim_facts WHERE scope = 'site' LIMIT 1);
   SELECT id FROM aim_facts WHERE index_id = 'aim_vector_index'
     AND scope = 'role' ORDER BY VEC_DISTANCE_COSINE(embedding, @v) LIMIT 5;
   ```

   returned 0 rows through the index and 1 row with `IGNORE INDEX
   (embedding)`. (The matching row was one of the stale rows in finding 5,
   ranked 88th of 115 by distance; the mechanism does not depend on that.)
   Session `mhnsw_ef_search = 200` made no difference, so it is not a
   tunable escape hatch. Consequences: (a) an attribute condition alone
   reproduces today's false negatives, just filtered in SQL instead of PHP,
   and (b) the current `range()` value is the ANN candidate pool size, not
   merely a result count, so shrinking it to the result count when the PHP
   filter is removed would make recall worse.

4. **A BTREE index on the column makes MariaDB pre-filter.** On an
   isolated scratch copy of `aim_facts` (`CREATE TABLE ... LIKE`, extra
   `uid INT` column, 4 rows given uid 2, probe vector taken from a
   uid-1 row): without an index on `uid` the filtered `LIMIT 5` query
   returned 3 of the 4 matching rows; after `ADD INDEX idx_uid (uid)` and
   `ANALYZE TABLE`, `EXPLAIN` showed `type: ref, key: idx_uid, Using where;
   Using filesort` (exact search over the user's own rows) and all 4
   returned. The choice is cost-based: a selective filter (few rows for this
   user) goes exact, a dense one (a user with thousands of facts) stays on
   HNSW where post-filtering rejects little. The provider adds plain
   columns (`ADD COLUMN IF NOT EXISTS`) and never a secondary index, so
   this index must come from aim.

5. **Side finding, not investigated.** `aim_facts` held two stale rows
   (`entity:aim_fact/2` role, `/3` case) whose entities no longer exist,
   and about 7 site facts were not indexed yet. `recall()` already skips
   stale rows (`SearchApiException`); `search-api:clear` purges them.

## Decision

Index `subject_uid` as an `integer` attribute **and** maintain a BTREE
index on its collection column, then switch `recall()` and
`findNearestNeighbor()` to a `subject_uid` query condition and delete the
PHP-side uid comparison. The attribute without the BTREE index is not an
acceptable variant (finding 3).

### Open decision: empty values (finding 2)

- **Option A (recommended): patch the provider.** In
  `MariaDBProvider::indexItems()`, coerce `''` to `NULL` for fields of type
  integer/decimal/date/boolean before `insertIntoCollection()` (about 3
  lines; `bind_param` sends `NULL` correctly, to be confirmed with one
  site-fact write after patching). Apply as a root `composer.json` patch
  now and send the same change upstream. aim's own `composer.json` cannot
  carry patches (only the root package's can), so it documents a minimum
  provider version once a fixed release exists. Correct semantics (no value
  is `NULL`), fixes every integer attribute for everyone.
- **Option B: aim-local override.** Ship a subclass of the `contextual_chunks`
  strategy overriding the protected `getValue()` to return `0` for empty
  integer-typed fields (its return type forbids `NULL`), and point the
  server's `embedding_strategy` at it. Self-contained, but couples aim to
  ai_search internals and uses `0` as a sentinel.

Record the choice by amending this ADR before building.

### Build sequence

Steps 1-4 must land before step 5: existing vector rows have `NULL`
`subject_uid` until reindexed, so a filtered query would return nothing and
consolidation would silently find no neighbors.

1. Empty-value handling per the option above.
2. Config, via a real save, not hand-typed (CLAUDE.md's "Cross-cutting
   conventions"): add field `subject_uid` (`datasource_id: 'entity:aim_fact'`,
   `property_path: subject_uid`, `type: integer`) to
   `search_api.index.aim_vector_index`; add
   `indexing_options.subject_uid` (`field_name: subject_uid`,
   `indexing_option: attributes`) to `ai_search.index.aim_vector_index`;
   re-export `getRawData()` into `config/install/`. Saving the index makes
   the provider's own `search_api_index_update` hook add the column.
3. BTREE index: a `#[Hook('search_api_index_update')]` in `AimHooks` for
   `aim_vector_index` running `ALTER TABLE <collection> ADD COLUMN IF NOT
   EXISTS subject_uid INT NULL, ADD INDEX IF NOT EXISTS <name> (subject_uid)`.
   Collection and database name come from the server's
   `database_settings`, the connection from the `ai.vdb_provider` `mariadb`
   plugin (`getConnection()`, `getClient()->escapeIdentifierForSql()`).
   Both clauses are idempotent, so it does not depend on hook order against
   the provider's own. Fresh installs get it through the index re-save
   already in `aim_install()`; the live site gets it when step 2's config
   is saved.
4. Rebuild: `ddev drush sapi-c aim_vector_index && ddev drush sapi-i
   aim_vector_index`. Check user rows have `subject_uid` populated, other
   scopes `NULL`, and `SHOW INDEX FROM aim_facts` lists the new index.
5. Code in `AimMemoryManager`:
   - `recall()`: when a `subject-uid` account is given, add
     `addCondition('subject_uid', (int) $id)`; remove the
     `$filter_account ? $limit * 5 : $limit` special case and the PHP uid
     check. The remaining `range()` expression belongs to the retired-facts
     item (TODO.md), not to uid filtering.
   - `findNearestNeighbor()`: for user scope, return `NULL` early when the
     fact has no `subject_uid`, otherwise add the same condition; remove the
     PHP uid block. Keep `range(0, 20)` for user scope, now as headroom for
     self/handled/retired candidates rather than for uid filtering.
   - Update both stale "not an indexed attribute" comments.
6. Docs when built (CLAUDE.md "Docs"): rewrite DEVELOPING.md's "Known gap"
   paragraph to current state and add findings 2-4 as gotchas; tick
   TODO.md; amend ADR-0007's over-fetch clause; adjust CLAUDE.md's "Data
   model" vector-search bullet. No `hook_update_N()` (CLAUDE.md
   convention), so the live site needs steps 2-4 applied by hand:
   `config/install/` does not re-run on an installed module.

## Alternatives rejected

- **Attribute only, no BTREE index.** Finding 3: same false negatives as
  today, and a regression if `range()` is shrunk.
- **Raise `mhnsw_ef_search`.** Tested at 200, no effect.
- **`string`-typed `subject_uid`.** `getValue()` converts an entity
  reference to its label for string/fulltext fields (by reading the code),
  so it would store the username, i.e. `User::label()` (display name,
  alterable, not guaranteed unique) and go stale on rename. A wrong-user
  match in consolidation would merge one person's facts into another's.
- **`subject_uid:target_id` path.** Finding 1.
- **Search API processor-computed property (never empty).** Its data
  definition has no `getFieldDefinition()` either, so it is expected to hit
  finding 1's fatal (not tested).
- **Pre-create the column as `VARCHAR` to tolerate `''`.** Comparing a
  string column against an integer literal converts per row and defeats the
  BTREE index; also invisible to config and lost if the collection is
  recreated.
- **A second index restricted to the user bundle** (every row non-empty).
  Doubles query fan-out and result merging for a problem a 3-line
  coercion solves.
- **Bypass Search API with a direct SQL join to `aim_fact`.** Abandons the
  abstraction the whole retrieval path is built on.
- **Do nothing.** Acceptable while one account owns all user facts; the
  cost is silent missed duplicates and short `--subject-uid` results the
  day a second account accumulates facts.

## Verification

No test suite exists in the module, so this is manual and must precede
calling it done:

- `EXPLAIN` of the generated SQL for a selective uid shows `ref` on the new
  index.
- Parity against ground truth: `aim:benchmark` (which spreads user-scope
  facts across real accounts) compared with exact `IGNORE INDEX (embedding)`
  top-k, at several facts-per-user counts (roughly 1, 10, 100, 1000) inside a
  5k+ fact corpus. Create throwaway accounts if 3 is not sparse enough.
  Pass: identical to exact at every selectivity. If mid-range selectivities
  show misses (the optimizer picked HNSW), keep a small over-fetch for that
  case rather than none.
- Regression: `aim:consolidate --scope=user --dry-run` finds the same
  known-duplicate pair; `aim:recall --scope=user --subject-uid=1`; write and
  index one site fact to confirm no `1366` error.
- `aim:benchmark-cleanup <tag>`, then phpcs and phpstan per CLAUDE.md.

## Consequences

- aim gains a dependency on a fixed provider release (Option A) or on
  ai_search's strategy internals (Option B). File upstream against
  `ai_vdb_provider_mariadb`: `''` to `NULL` for non-string columns; guard
  `is_field_multiple` like `updateFields()` does; ideally create a BTREE
  index for filterable attribute columns, which would make step 3
  unnecessary.
- **Dense filters cannot be pre-filtered**, so an over-fetch factor is
  intrinsic to any low-selectivity condition. This bears on TODO.md's
  retired-facts item: its "durable fix" (an indexed `retired` flag) would
  still be post-filtered when most rows are live, so it would not remove
  the over-fetch, and the flag would have to be a real entity field (a
  processor property fails finding 1; the provider also cannot express
  `IS NULL`, it emits `(expires = ())`).
- The `subject` filter for role/case scope has the same post-filter
  weakness and could get a BTREE index from the same hook. Out of scope
  here; worth doing with it.
