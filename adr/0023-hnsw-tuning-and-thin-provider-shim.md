# ADR-0023: HNSW tuning (M=16, ef_search=100), and keeping the provider shim thin

**Status:** Accepted - built and verified 2026-09-26
**Date:** 2026-09-26

## Context

[ADR-0018](0018-index-subject-uid-with-btree.md)'s scale test found that a
user holding a large share of the table is served by the HNSW index and
gets 82-92% of the true nearest facts. The question was whether that is
inherent or tunable, and whether aiming for 100% is realistic.

MariaDB's HNSW defaults are `M=6` (graph connectivity, fixed when the index
is built) and `mhnsw_ef_search=20` (candidates examined per query). The
unreleased 1.0.x head of `ai_vdb_provider_mariadb` (issue #3605665) makes
both configurable and says of the default M that it "under-connects large
corpora and can silently miss relevant results", recommending 16-32.

## Measurements

Scratch copy of `aim_facts` (5,067 rows: synthetic templated facts, plus
the site's real ones), 30 probe vectors, top-5 and top-20, `recall()`'s 5x
over-fetch included. Recall against the exact top-k, for the 1,000-fact
user (20% of rows), the 3,900-fact user (77%), and no user filter:

| Setup | Recall | Latency per query |
| --- | --- | --- |
| M=6, ef_search=20 (defaults) | 0.88 - 0.97 | 0.3 - 3 ms |
| M=6, ef_search=200 | top-5 1.00, top-20 0.99 | 0.7 - 4 ms |
| M=16, ef_search=20 | 0.99 - 1.00 | 0.4 - 5 ms |
| **M=16, ef_search=100** | **1.00 everywhere** | **0.7 - 6 ms** |
| M=32, any ef_search | 0.98 - 1.00, noisier | 3 - 30 ms |

Index rebuild at M=16 took 5 seconds for 5,067 rows (15 seconds at M=32).

Exact search (no HNSW), brute force, median top-5 latency:

| Rows | Unfiltered | One user with 20% of rows | One user with 0.2% (BTREE) |
| --- | --- | --- | --- |
| 5,067 | 18 ms | 13 ms | 0.3 ms |
| 50,670 | 181 ms | 125 ms | 0.5 ms |
| 152,010 | 563 ms | 394 ms | 1.1 ms |

## Decision

- **M=16 for new collections, ef_search=100 on every query.** The same
  numbers upstream's 1.0.x head defaults to and recommends.
- **Not M=32**: no better, slower, noisier.
- **Not exact search everywhere**: it scales linearly and is already slow at
  50,000 rows. It stays what a small user gets anyway, through the BTREE
  index on `subject_uid` (ADR-0018).
- `recall()`'s 5x over-fetch for a uid filter stays. It was in every
  measurement above and costs almost nothing.

`mhnsw_ef_search: 100` ships in `search_api.server.aim_vector`'s
`database_settings`, under the key the provider's 1.0.x head uses, so it
carries over unchanged when upstream releases. M is build-time and is a
class constant (`AimMariaDBProvider::HNSW_M`). From upstream's diff (not
tested), its index-update hook creates the provider without the server's
settings, so a configured M would not reach `createCollection()` there
either, which falls back to 16. `AimHooks::configSchemaInfoAlter()` declares the key so config
validation accepts it on provider 1.0.1.

Both are applied by `AimMariaDBProvider`, which opens its own connection in
`createCollection()` and `vectorSearch()` (the provider opens a fresh
connection per call, so a session setting only counts on the connection
that runs the statement). Verified with MariaDB's general log:
`SET SESSION mhnsw_ef_search = 100` and the `VEC_DISTANCE_COSINE` query ran
on the same thread. A table created by the shim carries `M=16`.

An existing table keeps its M until its vector index is rebuilt once
(DEVELOPING.md). Done on this site 2026-09-26.

## Keeping the shim thin

`AimMariaDBProvider` had grown from one bug fix to several overrides plus a
helper, which prompted the question of what belongs in aim and what in the
provider it extends. The rule:

- **A workaround stays only with an upstream issue in TODO.md**, and is
  deleted when the provider releases the fix. Generic features go upstream
  first, whether or not aim carries a stopgap.
- **aim never invents its own config for something upstream defines.** It
  ships values under upstream's key names and declares the schema for them,
  so removing the shim changes no config.
- **What is genuinely aim's stays in aim** whatever upstream does: the
  `aim_exclude_retired` processor, which columns are indexed and given a
  BTREE index, and the tuned values themselves.

| In `AimMariaDBProvider` | Generic or aim-specific | Upstream |
| --- | --- | --- |
| `createCollection()` swallows "table exists" | Generic bug fix | Reported as #3609961 (RTBC), but its MR 8 makes `createCollection()` drop the collection first, and the index-update hook calls it on every save, so it would wipe the vectors on every index save. Comment there: `CREATE TABLE IF NOT EXISTS`, or tolerate error 1050, is the safe fix. Do not upgrade to a release with MR 8 as written |
| `getVdbIds()` pages instead of `LIMIT 10` | Generic bug fix | Unfixed in 1.0.x head; no issue found (the project's 6 issues checked 2026-09-27), to file |
| Empty numeric value inserted as `NULL` | Generic bug fix | Unfixed; no issue found, to file |
| M applied to new collections | Generic feature | In 1.0.x head, unreleased |
| `ef_search` applied per query | Generic feature | In 1.0.x head, unreleased |
| String attributes written raw, not Markdown-escaped (`content_editor` was stored as `content\_editor`, so `subject` filters missed) | Generic bug fix, in `drupal/ai`'s `ai_search` rather than the provider | Already fixed upstream by #3572801 (ai_search 1.3.0-alpha5, 2.0.0-alpha2, drupal/ai 1.x head); the bundled code in drupal/ai 1.4.9 and 1.5.0 predates it. Do not file; remove when the site has the fix |
| `ensureColumnIndex()` BTREE on a column | Generic feature, aim picks the column | Feature request to file |

Alternatives considered:

- **Require the 1.0.x-dev provider now.** Gets M and ef_search as native
  settings, but it is unreleased, changes the site's `composer.json`
  (`minimum-stability: stable`). It is one commit past 1.0.1 (the tuning
  change itself), so the case for waiting is stability policy, not risk.
  Revisit at the next release, which would let the two tuning overrides go.
- **aim-specific settings** (say `aim.settings:hnsw_ef_search`). A second
  home for a setting upstream already has, which is the padding this
  section exists to avoid.

## Consequences

- Recheck the settings when the table grows about tenfold, when the
  embeddings model or dimensions change, or when the provider is upgraded.
  Method in DEVELOPING.md.
- The measurements used synthetic templated text, one corpus size, and 30
  probes, and an approximate index cannot be guaranteed to reach 100%.
  MariaDB caps the HNSW graph cache at `mhnsw_max_cache_size` (16 MB by
  default), which may start to matter at larger sizes; not tested.
- ADR-0018 recorded that `mhnsw_ef_search = 200` "made no difference".
  That was for a sparse-filter case (one row ranked 88th). For dense users
  it matters a lot, and M mattered as much. ADR-0018 is amended.
