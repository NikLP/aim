# ADR-0018: Index `subject_uid` as a Search API attribute, with a BTREE index on its column

**Status:** Accepted - analyzed 2026-09-19, built and verified 2026-09-26
**Date:** 2026-09-20

## Context

`recall()` (with `--subject-uid`) and `findNearestNeighbor()` (scope=user)
cannot narrow by `subject_uid` in the query, because it is not an indexed
Search API attribute. [ADR-0007](../0007-user-scope-requires-real-account.md)
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
   Session `mhnsw_ef_search = 200` made no difference to this sparse-filter
   case (the matching row ranked 88th). For users holding a large share of
   the table it does matter, as does M; see
   [ADR-0023](0023-hnsw-tuning-and-thin-provider-shim.md). Consequences: (a) an attribute condition alone
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

### Decided 2026-09-26: empty values (finding 2)

**Option C: coerce `''` to `NULL` in `AimMariaDBProvider`**, the aim-local
provider subclass that [ADR-0022](0022-exclude-retired-facts-from-vector-index.md)
already swaps in for two other provider bugs. It overrides `indexItems()`
(to remember the index being written) and `insertIntoCollection()` (to
set `''` to `NULL` for a non-multiple field whose Search API type is
`integer`, `decimal`, `date` or `boolean`, the types the provider maps to
numeric columns) before delegating to the parent. String and text fields
are left alone, so existing `''` values in `source`/`subject` and the
filters that compare them do not change. `bind_param` sends `NULL`
correctly.

Chosen over the two options considered earlier:

- **A: a root `composer.json` patch to `MariaDBProvider::indexItems()`**
  (plus the same change upstream). Same semantics, but this site has no
  `cweagans/composer-patches`, aim's own `composer.json` cannot carry
  patches, and the subclass already exists for the same reason.
- **B: a `contextual_chunks` strategy subclass returning `0`** for empty
  integers. Couples aim to ai_search internals and stores a sentinel, where
  `NULL` is the correct value for "no uid".

Upstream: file this with the other two `ai_vdb_provider_mariadb` issues
(TODO.md). The subclass can lose all three overrides once released.

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
- **Raise `mhnsw_ef_search`** to fix the sparse-filter case. Tested at 200,
  no effect there. It does help dense users, see
  [ADR-0023](0023-hnsw-tuning-and-thin-provider-shim.md).
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

## Outcome (built and verified 2026-09-26)

Built as decided, with two departures:

- **Step 3 adds the index only.** `AimHooks::vectorIndexUpdate()` (a
  `search_api_index_update` implementation, `Order::Last`) calls
  `AimMariaDBProvider::ensureColumnIndex()`, which checks the column
  exists and runs `ADD INDEX IF NOT EXISTS`. The provider's own hook
  already creates the column, so `ADD COLUMN` is not repeated.
- **`recall()` keeps its 5x over-fetch when filtering by uid.** The plan
  was to drop it. The measurements below show why it stays, now as
  candidate headroom for the HNSW path rather than as a PHP filter.
  `findNearestNeighbor()` keeps `range(0, 20)` for user scope, headroom
  for itself and handled ids, and returns `NULL` early for a user fact
  with no account. Both PHP-side uid comparisons are gone.

The live site was reindexed (`sapi-r`, `sapi-i`): every fact indexed with
no `1366` error, user rows carry their uid, every other scope carries
`NULL`. The shipped `config/install` YAML for both indexes matches the
live saved config.

## Verification (run 2026-09-26)

Corpus: 5,069 vector rows. Five throwaway accounts holding 1, 10, 100,
1,000 and 3,900 synthetic user-scope facts (`generateBenchmarkFacts()`,
tagged `zzpar`, removed afterward) plus the site's real facts (uid 1
holds 38). 30 probe vectors (stored embeddings of random rows) per user,
k = 5 and 20. Recall against the exact top-k (`IGNORE INDEX (embedding)`),
reported as k=5 / k=20:

| Facts for the user | Optimizer | Current | Attribute only, no BTREE | Current + 5x over-fetch |
| --- | --- | --- | --- | --- |
| 1 | BTREE `ref` | 1.00 / 1.00 | 0.87 / 1.07* | 1.00 / 1.00 |
| 10 | BTREE `ref` | 1.00 / 1.00 | 0.67 / 0.73 | 1.00 / 1.00 |
| 38 (uid 1) | BTREE `ref` | 1.00 / 1.00 | 0.05 / 0.10 | 1.00 / 1.00 |
| 100 | BTREE `ref` | 1.00 / 1.00 | 0.63 / 0.73 | 1.00 / 1.00 |
| 1,000 (20% of rows) | HNSW | 0.82 / 0.82 | 0.82 / 0.82 | 0.88 / 0.91 |
| 3,900 (77% of rows) | HNSW | 0.92 / 0.89 | 0.92 / 0.89 | 0.93 / 0.97 |

\* Above 1.0 because of repeated rows, see 3.

1. **The BTREE index is decisive for selective users.** With it the
   result is exact (0 of 30 probes missed anything) at every size up to
   100 facts. Without it the same attribute returns 5 to 87 percent of the
   right rows. Finding 4 holds at scale.
2. **Large users go through HNSW and stay approximate.** From some share
   of the table upward the optimizer stops choosing the BTREE index and
   post-filters HNSW candidates, so recall is 82 to 92 percent on
   MariaDB's default HNSW settings. The 5x over-fetch lifts that to 88 to
   97 percent. Tuning M and ef_search closes the rest, to about 100 percent
   on this test ([ADR-0023](0023-hnsw-tuning-and-thin-provider-shim.md)). The crossover lies between 100 and 1,000 facts
   (2 to 20 percent of this table) and was not located. In practice a
   consolidation sweep can miss a duplicate pair for a user who owns a
   large fraction of all facts.
3. **The forced HNSW post-filter path returned repeated rows.** With the
   BTREE index ignored, 2 to 4 of 30 probes returned the same row id twice
   in one result (max repeat 2) for the 1, 10 and 100-fact users, never
   with the index in use. A MariaDB 11.8.9 quirk, not investigated. It is
   another reason the attribute alone is not acceptable, and `recall()`
   does not de-duplicate.
4. **`ANALYZE TABLE` changed nothing.** Optimizer choices and recall were
   identical before and after, so no statistics step is needed.
5. **End to end through `recall()`** (limit 5): correct row counts for
   every account and no row from another user.
6. **Regression:** `aim:consolidate --scope=user --dry-run` is identical
   to its pre-change output (18 decisions), and site scope is unchanged.

Limits: the corpus is synthetic templated text, which clusters tightly in
embedding space and is likely harsher than real facts; the probes are
stored embeddings; one corpus size.

## Consequences

- aim depends on `ai_vdb_provider_mariadb` internals through
  `AimMariaDBProvider` (`indexItems()`, `insertIntoCollection()`, the
  provider's `getClient()`). File upstream against the provider: `''` to
  `NULL` for numeric columns; guard `is_field_multiple` like
  `updateFields()` does; ideally create a BTREE index for filterable
  attribute columns, which would make `ensureColumnIndex()` unnecessary.
- **Dense filters cannot be pre-filtered**, so an over-fetch factor is
  intrinsic to any low-selectivity condition. This bears on TODO.md's
  retired-facts item: its "durable fix" (an indexed `retired` flag) would
  still be post-filtered when most rows are live, so it would not remove
  the over-fetch, and the flag would have to be a real entity field (a
  processor property fails finding 1; the provider also cannot express
  `IS NULL`, it emits `(expires = ())`).
- The retired-facts item above is now resolved by excluding retired facts
  from the index ([ADR-0022](0022-exclude-retired-facts-from-vector-index.md)),
  not by a flag. That work also found that every index save threw
  (`ai_vdb_provider_mariadb` bug, fixed by `AimMariaDBProvider`), and the
  throw came before the provider's `updateFields()` ALTER, so step 2's
  config save would have thrown before the column was added by that path.
  It no longer throws.
- The `subject` filter for role/case scope has the same post-filter
  weakness and could get a BTREE index from the same hook. Out of scope
  here; worth doing with it.
