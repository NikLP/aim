# AIM - Developer Reference

Developer-focused reference: commands, API, runbooks, and gotchas worth
not rediscovering. For the pitch and requirements see
[README.md](README.md); for architecture decisions and operating rules
see [CLAUDE.md](CLAUDE.md).

This covers the `aim` core module only. Each submodule carries its own
README.md/CLAUDE.md/DEVELOPING.md:
[aim_chatbot](modules/aim_chatbot/DEVELOPING.md),
[aim_tool](modules/aim_tool/DEVELOPING.md),
[aim_tool_oauth](modules/aim_tool_oauth/DEVELOPING.md).

---

## Drush commands

### `aim:remember` - CLI agent adapter

For a caller (Claude Code, or any agent) that already decided what's
worth remembering - no extraction LLM round-trip. See
[ADR-0006](adr/resolved/0006-agent-native-write-path.md) for why this exists
alongside `extract()`.

```bash
drush aim:remember <text> [--scope] [--subject] [--source] [--category] [--asserted] [--target-type] [--target-id]
```

- Validates scope, creates the fact directly, prints its ID.
- `--category` resolves comma-separated term names against the
  `aim_category` vocabulary (admin-curated - a name with no matching term
  is skipped, never auto-created).
- `--asserted` sets when the fact became true in reality if different
  from today (any `strtotime()`-parseable string); empty means "same as
  created".
- `--target-type`/`--target-id` name the referenced entity for
  `scope=entity` (e.g. `--target-type=node --target-id=42`); ignored for
  every other scope. See [ADR-0027](adr/resolved/0027-entity-scope.md).
- `scope=case` resolves `--subject` on save (`AimScopeCase::resolveSubject()`,
  via `AimHooks::factPresave()`, so the add form gets the same): a subject
  some fact of the scope already has is kept as is; a new one is a case
  name, turned into an ID under the scope's `prefix` setting (default
  `case-`, on the scope's edit form: `Site redesign` becomes
  `case-site-redesign`, max 64 chars); none mints one
  (`case-<8 hex chars>`, from Drupal's `uuid` service). The success
  message (and `aim_tool`'s `aim_remember` reply) surfaces the resolved ID
  so later calls can pass it back as `--subject` to append to the same
  case. Two callers naming a case the same join one case, by design; a
  mistyped ID starts a new one.
- `scope=user` requires a real account (`--subject` resolved via
  `resolveAccountByUid()`, uid only - no username fallback on write paths,
  to avoid a typo'd username silently misattributing a fact). See
  [ADR-0007](adr/resolved/0007-user-scope-requires-real-account.md).

### `aim:recall` - semantic query

```bash
drush aim:recall <text> [--scope] [--subject] [--subject-uid] [--limit] [--max-distance] [--include-untrusted] [--format]
```

Real semantic query against `aim_vector_index`. `--format=json` for a
parsing caller. **`score` is a cosine *distance*, not a similarity** -
0.0 for an identical embedding, larger the less similar; lower is a
better match. The table column is labeled `Distance`; the JSON key stays
`score` to match search_api's own naming. Raw by default (the nearest
facts however far, which is what calibrating the cutoff needs);
`--max-distance=0.48` drops matches past that distance, as the chatbot and
the MCP tool do by default with `aim.settings:recall_max_distance`.

Untrusted facts (ADR-0002's addendum) are excluded unless
`--include-untrusted` is given - the way to review what the trust policy
is holding back (also the facts list's Trusted filter; `aim:status` shows the
count). Reviewers hold `review aim facts`.

Both commands live on `AimCommands`, backed by `AimMemoryManager` (also
used by `aim_tool` and the chatbot).

### `aim:extract` - LLM-driven extraction

```bash
drush aim:extract <file> [--provider] [--model] [--source] [--index] [--subject-uid]
```

Sends the file to a chat provider with a structured-JSON-schema request,
creates one `AimFact` per returned item (scope/subject classified by the
model). The source file itself is never stored - only the extracted
facts persist. `source` is a short provenance pointer.

The structured-output schema's `scope` enum is built from
`allowedScopes()` at call time, so a scope added as an `aim_scope` config
entity is extractable with no code change.

`--subject-uid` (uid only) attaches every model-classified `scope=user`
candidate to that one real account; without it every `scope=user`
candidate is skipped (with a warning) - extraction never attempts to
match the model's own freeform subject text against the accounts table.
See [ADR-0011](adr/resolved/0011-extraction-explicit-subject-uid.md) for why the
match can't be model-guessed.

### Ingest form

`/admin/content/aim-facts/ingest` (`AimIngestForm`, permission `ingest aim
facts`) shares one code path with drush: `AimRememberService::ingestText()`
(`aim:extract`), `rememberBatch()` (`aim:remember --file`, and the form's
one-fact-per-line and JSON modes), `parseFactFields()`. The form passes its
user as `$writer`, so each fact needs its scope's `create {scope} aim facts`
(`checkCreateAccess()`): the one-per-line scope list shows only those
scopes, a JSON entry outside them is an error, an extracted candidate
outside them is dropped and counted as `denied`. Drush passes no writer
(it runs as anonymous) and is unchecked. Uploads are read
from the request and never saved as a File entity; capped at 100 KB
(`AimIngestForm::MAX_BYTES`). Submit is synchronous; consolidation stays
queued. Test data: `tests/ingest-test/`.

**Skill:** `skills/aim-discovery/` ("the grill") - a structured
discovery interview that distills each topic into a summary and runs it
through `aim:extract`, mostly `scope: site`.

**Skill:** `skills/aim-memory/` - reach for `aim:remember`/
`aim:recall` continuously through a session, framed as one capability
with two directions.

### `aim:consolidate` - dedup/merge sweep

```bash
drush aim:consolidate [--scope] [--provider] [--model] [--compare-distance] [--dry-run]
```

On-demand sweep. Algorithm, schema, and thresholds in
[ADR-0005](adr/resolved/0005-consolidation-algorithm.md). Pairs within
`compare_distance` (UI: Consolidation > Comparison distance, default
0.45, calibrated against `ollama__nomic-embed-text:latest`) are
compared: identical text (ignoring case, spacing and a final full stop,
`AimFact::hasSameText()`) is retired as a copy with no model call, and
every other pair gets one classification call. There is no automatic
merge by distance: distance cannot tell a correction from a reworded
duplicate (8 of 10 short contradicting pairs scored under 0.09, "borrow
up to 20 items" vs "up to 10" at 0.016, while `subq-snap-1.1` classified
all 18 test pairs right; site's `demo/recall-eval/auto-band.php`,
ADR-0036's 2026-10-27 addendum). The `auto_threshold` setting that did
that was removed. **Retune `compare_distance` if the embeddings model
changes.**

**Nothing is destroyed** (ADR-0005 addendum, 2026-10-01). Every outcome
that removes a fact from recall is a soft retire (`retired` set), so it
is auditable and reversible with the Un-retire action (which also clears
`superseded_by_reason`):

- `NOOP` (and identical text): candidate retired, `superseded_by` = kept.
- `RETIRE`: candidate retired, `superseded_by` left empty.
- `UPDATE`: a **new merged fact** is created and both inputs are retired
  pointing at it. The old wording survives on the retired rows. Metadata
  carried over: scope, subject/user, target and owner from the kept fact;
  `asserted` from the candidate only; `source` from the candidate
  else the kept fact; `category` the union; `trusted` only if both inputs
  were; `expires` (equal on both, see below); `grounding_score` from the
  merge's own grounded check (see "Merge verification"), empty when
  grounding is off. The merged fact is queued for consolidation like any
  new fact.

Facts with different `expires` (one dated, one not, or two different
dates) are never compared, so a temporary fact cannot be merged into a
lasting one and lose its end date (`findNeighbors()`; ADR-0036's
2026-10-27 addendum).
- `superseded_by_reason` (on the retired candidate's row only; JSON,
  never fact text): `decision`, `by` (`auto` or `model`), `score`,
  `provider`, `model`. Installed by `aim_update_10004()`.

Retired facts stay out of the vector index (`aim_exclude_retired`), so
history costs no recall precision or index size. They are still personal
data: no parallel archive exists, the retention policy (TODO) is the later
answer.

**Merge verification** (2026-10-01). Guardrails check policy, not
fidelity, so an UPDATE's merged text must also pass `verifyMerge()`
before it is accepted, and then the grounded check (below); failure
downgrades the pair to **ADD** (keep both) and logs an audit line
(`@check` = `model`, `distance`, `error` or `grounding`, never text).
Dry-run shows such rows as `ADD` with a non-empty "Merged text".
Settings (`aim.settings`, `/admin/config/aim/settings`):

- `merge_verify` (default on): a verifier model gets both inputs and the
  merge (`merge_verify_prompt`) and must confirm every detail survives
  and nothing is invented. Verifier errors count as failure.
- `activities.{extraction,consolidation,verifier}` = `{backend, provider,
  model}` (settings form, AI suite's `ai_provider_configuration`; Drush
  `--provider`/`--model` still override). Empty chat provider = the site
  default chat model (verifier: the classifier's own). `backend: chat`
  (default) runs the prompts above; `backend: decision` (consolidation and
  verifier only, needs an explicit model on a provider implementing
  `DecisionInterface`, today `typesafeai`) goes through `src/Backend/`:
  `DecisionBackend` calls `decision()`, `ChatBackend` is the bridge that
  renders the same `DecisionInput` as a structured-output chat prompt (one-hot
  choice answers, stated noul probability; no score questions). A decision
  classifier writes no text, so on UPDATE the site default chat model
  writes the merge (`merge_prompt`, tag `aim_consolidate_merge`); with no
  default chat model the pair is downgraded to ADD.
  The verifier is a noul `faithful` question, true at probability >= 0.5.
  Set the verifier to a different model so one checks the other. The
  decision backend's confirmation cutoff is `activities.verifier.threshold`
  (empty or 0 means 0.5; this site uses 0.8 for Jev). A local decision
  model is parked, see [ADR-0021](adr/0021-jev-typed-decision-provider.md).
- `merge_max_distance` (default 0 = off): merged embedding must sit within
  this cosine distance of each input. Measured 2026-10-01 on one pair, it
  barely separates a faithful merge (0.02 / 0.11) from a lossy one
  (0.08 / 0.14), so it is a weak tripwire, not a verifier; calibrate
  before enabling.
- Grounded check (`grounding_mode` not `off`): after the verifier passes,
  the merged text goes through `checkGrounding()` with the two inputs as
  the passage (kept fact first, one per line), and the score is stored on
  the merged fact as `grounding_score`, so a merge carries the same
  provenance as any checked write and is checked by a second, separately
  calibrated question.
  In `enforce`, a score below `activities.grounding.threshold`, or a check
  that failed to run, rejects the merge like the verifier does (ADD, both
  facts stay live), rather than merging and marking the result untrusted,
  which would retire two good facts for one doubtful one. An audit line
  per merge (kept and candidate IDs, score, cutoff, mode). Measured
  2026-10-27 on hosted Jev (`subq-snap-1.1`) over 16 real merges (the
  site's `demo/recall-eval/auto-band.php ground`): every correct merge
  scored 0.90 or more although it drops the superseded value, and each
  with one invented sentence added scored 0.07 or less, so the plain
  passage stays and `GROUNDING_QUESTION` is unchanged. The verifier
  (threshold 0.8) also rejected all 16 invented sentences, and 2 of the 16
  correct merges, both of which grounding passed.

Both check faithfulness to the inputs, not real-world truth. The shipped
consolidation prompt also now says to choose ADD when unsure
(`aim_update_10005()` adds that line to an unedited prompt and the new
keys to an existing site).

**Testing gotcha:** `drush aim:consolidate --scope=site` (any scope) acts
on every live fact in that scope, not just test facts you just wrote.
Take a `ddev snapshot` first, or use `--dry-run`; restore the snapshot
afterward (the apply path was live-tested this way 2026-10-01).

`--dry-run` still calls the model and prints the merged text in a
"Merged text" column (also for `BLOCKED`), for reviewing UPDATE fidelity
by hand. Identical text keeps the *older* fact and retires the newer one
with no model check.

**Automated path:** `AimConsolidateQueueWorker` (plugin ID
`aim_consolidate`) - `remember()`/`createFactsFromCandidates()` enqueue
each new fact right after save. Carries no `cron` key, so
`hook_cron`/`drush cron` never touches it - drain via:

```crontab
* * * * * ddev exec drush queue:run aim_consolidate
0 * * * * ddev exec drush aim:expire
```

The second entry is the expiry sweep (ADR-0057): `aim:expire` retires every
live fact whose `expires` date has passed (soft, like consolidation's
retirement). Set `expires` with `drush aim:remember --expires=2026-10-16`,
the `expires` field in a JSON/batch entry, or the `expires` input of the
chat and MCP remember tools (a `YYYY-MM-DD` calendar date; the fact is
retired after that day, and a date already past is refused).
`hook_cron` runs the same sweep as a backup (it is a cheap query with no
model call, so it stays clear of ADR-0003's no-`hook_cron` rule for
LLM-bound queue work); the crontab line just makes it prompt.

`processItem()` reindexes before consolidating (`index_directly` is off
in the shipped config, so a just-written fact isn't searchable yet without
this; see the `index_directly` note under "Vector search").
Throws `SuspendQueueException` if no default chat provider is configured
- the runner releases the item and stops draining rather than failing
every remaining item one by one.

Manual trigger without a terminal: `drupal/queue_ui` at
`/admin/config/system/queue-ui` (per-queue "Run" button - deliberately
not a `cron` key, see [ADR-0003](adr/resolved/0003-async-processing-dedicated-crontab.md)).

**Retired facts are not in the vector index:** the `aim_exclude_retired`
Search API processor (`src/Plugin/search_api/processor/ExcludeRetired.php`)
rejects any fact with `retired` set, and Search API deletes a rejected
item from the server. Retiring a fact is a plain `save()` that re-tracks
it, so its row goes on the next `sapi-i` (`index_directly` is off), and
clearing `retired` brings it back. `recall()`/`findNeighbors()` keep
their PHP `expires` check as a safety net for that gap. Retired facts stay
`aim_fact` entities (audit trail, `superseded_by` edges, admin views) but are not
vector-searchable. Design, verification and rollout in
[ADR-0022](adr/resolved/0022-exclude-retired-facts-from-vector-index.md).

**Enabling the processor on an existing site:** `config/install` does not
re-run on an installed module. Enable it on the index (admin UI, or config
import), then purge the retired rows already indexed:

```bash
ddev drush php:eval '$ids = \Drupal::entityQuery("aim_fact")->accessCheck(FALSE)->exists("expires")->execute(); \Drupal\search_api\Entity\Index::load("aim_vector_index")->trackItemsUpdated("entity:aim_fact", array_map(fn($id) => "$id:en", array_values($ids)));'
ddev drush sapi-i aim_vector_index
```

**`user` is an indexed attribute with a BTREE index** (the field was
named `subject_uid` until 2026-09-28, `aim_update_10001()` - ADR-0018's
title and file name still say `subject_uid`, the field itself does not):
`recall()` (with `--subject-uid`) and user-scope `findNeighbors()`
filter by a `user` query condition, not in PHP. The column's BTREE index
(`idx_user`, added by `AimHooks::vectorIndexUpdate()` through
`AimMariaDBProvider::ensureColumnIndex()` on every index save) is what
makes this exact: MariaDB's HNSW index post-filters, so without it a
selective filter returns short or wrong results. A user holding a large
share of the table (measured at 20% and 77%) is served by HNSW instead and
stays approximate (82 to 97% of the exact top-k), which is why `recall()`
still over-fetches 5x for a uid filter. Measurements and the limits of
the test in [ADR-0018](adr/resolved/0018-index-subject-uid-with-btree.md).

**`ai_vdb_provider_mariadb` is swapped for a thin subclass:**
`Drupal\aim\Vdb\AimMariaDBProvider`, via `AimHooks::vdbProviderInfoAlter()`,
works around three bugs in provider 1.0.1 and the 1.0.x head (checked
2026-09-26/27) and applies HNSW tuning. Upstream status: the table-exists
throw is #3609961 (its RTBC MR drops the whole collection on every index
save, so do not take a provider release with that as written); the
10-row delete cap and the empty numeric value have no issue yet. Saving
the index threw `Table 'aim_fact_vectors' already exists` (an unhandled
`mysqli_sql_exception` from `createCollection()`, before `updateFields()`
ran); `deleteItems()`/`deleteIndexItems()` removed at most 10 rows per
call (`getVdbIds()` used `querySearch()`'s default limit); and an empty
integer/decimal/date/boolean attribute reached the insert as `''`, which
strict mode rejects in a numeric column (`ERROR 1366`), so it is set to
`NULL` there. The class also has `ensureColumnIndex()`, aim's own BTREE
helper (below). **It is a shim:** each override needs an upstream issue in
TODO.md/BACKLOG.md and goes when the provider releases the fix, and aim ships tuned
values under the provider's own config key names rather than inventing
its own (rule and per-override table in
[ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md)). If a symptom
returns, check the alter hook still applies
(`ddev drush php:eval 'echo get_class(\Drupal::service("ai.vdb_provider")->createInstance("mariadb"));'`
should print the aim class; `drush aim:status`'s "Provider shim" line checks
the same). Bug details in
[ADR-0022](adr/resolved/0022-exclude-retired-facts-from-vector-index.md).

**The swap needs the definitions rebuilt at aim's install.** `drupal/ai`
names the VDB manager `ai.vdb_provider` without the
`plugin_manager_cache_clear` tag, and core's `PluginManagerPass` only tags
`plugin.manager.*` services, so a module install never clears its cached
definitions. A provider enabled before aim then kept definitions cached
without the alter, and aim's own `config/install` index save ran the stock
provider (`Table 'aim_fact_vectors' already exists` on the second save,
and silently without the shim's fixes otherwise). `AimServiceProvider`
adds the tag; delete it once `drupal/ai` does.

**A fourth override (string attributes written Markdown-escaped) was
removed 2026-09-27**, after the site moved from `ai_search` as bundled
inside `drupal/ai` 1.4.9 to the standalone `drupal/ai_search:^1.3@alpha`
package (1.3.0-alpha5), which carries the upstream fix (#3572801). See
"Upgrading to standalone `ai_search`" below for that migration; the
removed override is documented for provenance in
[ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md).

### Upgrading to standalone `ai_search`

`ai_search` split out of `drupal/ai` into its own drupal.org project;
the copy still bundled inside `drupal/ai` (`web/modules/contrib/ai/modules/ai_search`)
is deprecated (critical fixes only). The standalone 1.x line stays
compatible with `drupal/ai` 1.x (`requires: drupal/ai ^1.3`, `conflicts:
drupal/ai <1.3`) - no need to move `drupal/ai` itself to 2.x, which the
standalone 2.x line would require instead.

```bash
ddev composer require 'drupal/ai_search:^1.3@alpha'
rm -rf web/modules/contrib/ai/modules/ai_search
ddev drush cr
ddev drush updatedb -y
```

Two gotchas hit doing this 2026-09-27:

- The two `ai_search` copies share the machine name `ai_search`, which
  Composer's `conflicts` can't express (it's a subdirectory of another
  package, not a separate one) - deleting the old bundled copy is a
  manual step, same as the standalone project's own CI does.
- `ai_search` 1.3.0-alpha5 ships four `hook_update_N()`s (chunk-tracking
  columns, RAG access-control config, `max_pager_iterations`, and one
  specifically for "submodule collision" migrations) - `drush updatedb`
  is required, not just a cache rebuild.

If the vector collection table gets rebuilt from scratch after this
(for example, `drush search-api:clear` genuinely drops it), the
attribute columns (`scope`, `user`, etc.) only come back when the
index entity itself is re-saved - a plain reindex does not re-run
`updateFields()`:

```bash
ddev drush php:eval '\Drupal\search_api\Entity\Index::load("aim_vector_index")->save();'
ddev drush sapi-i aim_vector_index
```

Verify with `drush aim:status` and a real `drush aim:recall`/
`drush aim:consolidate --dry-run` afterward.

**Tuning vector search accuracy (HNSW):** MariaDB's defaults (`M=6`,
`mhnsw_ef_search=20`) missed 3-12% of the true nearest facts on a 5,000-row
test. aim ships `M=16` and `ef_search=100`, which matched the exact answer
on every test question at 1-6 ms. Plain-English version in
[README.md](README.md); measurements in
[ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md).

| Setting | Where | Applies |
| --- | --- | --- |
| `mhnsw_ef_search` (100) | `search_api.server.aim_vector`, `backend_config.database_settings.mhnsw_ef_search` (the key the provider's 1.0.x head also uses) | Every query, set with `SET SESSION` on the connection that runs it. Change it any time. |
| `M` (16) | `AimMariaDBProvider::HNSW_M` | A new collection only. Build-time. |

An existing table keeps the M it was built with. Check it, and rebuild
once if it is not 16 (seconds for thousands of rows, longer for millions,
and writes to the table may block meanwhile):

```bash
ddev drush sql:query "SHOW CREATE TABLE aim_fact_vectors" | tr '\\' '\n' | grep "VECTOR KEY"
ddev drush sql:query "ALTER TABLE aim_fact_vectors DROP INDEX embedding, ADD VECTOR INDEX embedding (embedding) M=16 DISTANCE=cosine"
```

To confirm a setting reaches the query connection, turn on MariaDB's
general log (`SET GLOBAL log_output='TABLE'; SET GLOBAL general_log=ON;`),
run `drush aim:recall`, and look in `mysql.general_log` for
`SET SESSION mhnsw_ef_search` and the `VEC_DISTANCE_COSINE` query on the
same `thread_id`. Turn the log off afterward.

**Rechecking accuracy** (when the table grows about tenfold, the embeddings
model or dimensions change, or the provider is upgraded). Build a skewed
test corpus, then compare the index to exact search:

1. Create throwaway accounts and give each a different number of synthetic
   user-scope facts with `aim_benchmark`'s
   `AimBenchmarkGenerator::generateBenchmarkFacts('user', $n, 'zzpar',
   [$uid])` (from `drush php:eval`; see
   [aim_benchmark's DEVELOPING.md](modules/aim_benchmark/DEVELOPING.md)),
   including one user holding 20% or more of all facts. Index them
   (`sapi-i`); synthetic facts skip the consolidation queue.
2. For about 30 stored vectors, set `@v` to the vector and compare, for
   each user and k of 5 and 20, `SELECT drupal_entity_id FROM aim_fact_vectors
   WHERE index_id='aim_vector_index' AND user=<uid> ORDER BY
   VEC_DISTANCE_COSINE(embedding, @v) LIMIT k` against the same query with
   `IGNORE INDEX (embedding)`, which is exact. Recall is the overlap over
   k. Repeat with `SET SESSION mhnsw_ef_search = <n>` for a few values.
3. Do this on a copy (`CREATE TABLE ... LIKE aim_fact_vectors` then `INSERT ...
   SELECT`) so index rebuilds and ef_search experiments never touch live
   data. `aim:benchmark-cleanup zzpar` and deleting the accounts removes
   the corpus.

**Stale vector rows self-heal:** a row in `aim_fact_vectors` whose fact no longer
exists (deleted directly, or a test leftover) logs a one-off "Could not
load the following items on index" warning the first time a query returns
it, then search_api deletes it (`delete_on_fail: TRUE` on the index).
`recall()` already skips such rows. Harmless; not worth a manual cleanup.

### Query-embedding cache

`Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber` (registered in
`aim.services.yml`, tagged `event_subscriber`), keyed on (provider ID,
model ID, provider configuration, query text), in a dedicated
`cache.aim_embeddings` bin (DB-backed, no Redis on this site). Active
only for the duration of `AimMemoryManager::executeSearchQuery()` -
toggled on and off around `$query->execute()` - so index-time embeds are
never read from or written to it; `ai_search` gives query-time and
index-time embeds the same operation type and tag set otherwise, so this
is the only reliable way to tell them apart. Design and alternatives
considered in [ADR-0017](adr/resolved/0017-query-embedding-cache.md),
including why the fact's own already-indexed vector can't be reused
directly instead (blocked on `ai_vdb_provider_mariadb`, see "Upgrading to
standalone `ai_search`" above).

Also logs a hit/miss line and, on a miss, the provider call's own
duration - the embed-time vs. DB-search-time split, previously only
inferred from a benchmark run's one aggregate number. Verified live: two
identical `aim:recall` calls logged a miss then a hit with identical
results; the missed call's embed cost logged as 556ms on this site's
local Ollama, noticeably higher than the ~33-35ms aggregate a benchmark
run reports - worth knowing before treating that aggregate as a per-call
guarantee.

**Retrieval-latency benchmarking** (`aim:benchmark`/
`aim:benchmark-cleanup`, including `--bypass-cache` for this cache) moved
to the `aim_benchmark` submodule - see
[its DEVELOPING.md](modules/aim_benchmark/DEVELOPING.md).

### `aim:status` - database-side health checks

```bash
drush aim:status
```

Checks `config:status` can't do, because they're runtime DB state,
not config: vector index parity (live fact count vs. `aim_fact_vectors` row
count), orphan vector rows, the `mariadb` VDB provider plugin still
resolving to `AimMariaDBProvider`, the collection table's build-time HNSW
`M` and the server's `mhnsw_ef_search`, and `aim.settings:recall_max_distance`
actually being set rather than silently using the in-code fallback, no
fact overdue for the expiry sweep, the `aim_consolidate` queue being drained
(oldest item under two hours; otherwise the crontab line is missing), an
informational count of untrusted facts, and every configured model still existing
with its provider (the AI module's chat, decision and embeddings defaults,
plus any activity that names its own: a retired model is otherwise found by
a failed call), and `index_directly` not being on with a hosted embeddings
provider (each write would wait on it and send it the fact text), and
model-call logging (passes when off, a site's choice; when on, fails if an
aim tag would not be recorded, replies are off or entries are kept under 90
days - see "Model-call logging"). Exits
1 if any check fails, 0 otherwise - safe to use in a preflight script.
Covers the database-side items in "Upgrading an existing site" below, in
place of walking them by hand.

**MariaDB quirk found writing this:** `SHOW CREATE TABLE` drops a VECTOR
KEY's `M=`/`DISTANCE=` suffix under this site's own `sql_mode`
(`ANSI,TRADITIONAL`, Drupal's own mysql driver default) - present under
`ANSI` or `TRADITIONAL` alone, only the combination hides it (checked
2026-09-27). The HNSW check works around it by swapping the session to
`ANSI_QUOTES` alone (keeps the suffix, keeps double-quoted identifiers
so `{aim_fact_vectors}` substitution still parses) for that one query, restoring
the original mode straight after. Not filed upstream yet - MariaDB vector
support is new enough, and this quirk narrow enough (one specific
sql_mode combination, cosmetic only, the index itself still applies its
tuning), that it wasn't worth a TODO.md item on its own; revisit if it
turns out to affect anything beyond this diagnostic's own parsing.

---

## Developer API

### `AimMemoryManager` (`aim.memory_manager`, aliased from its FQCN for
autowiring)

Central service backing every write/read path. Key public methods:

- `remember(string $text, ?string $scope, ?string $subject, ?string
  $source, ...): AimFact` - validated direct write. The
  created fact's `trusted` comes from the trust policy
  (`AimTrustPolicy`, see "Grounded check") via the `channel` argument; the
  entity add form still takes `default_trusted` through
  `AimFact::getDefaultTrusted()`.
- `recall(string $text, ?string $scope, ..., bool $includeUntrusted =
  FALSE): array` - semantic query, restricted to trusted facts unless
  `$includeUntrusted` is TRUE (ADR-0002's addendum).
- `allowedScopes(): array` - reads `entity_type.bundle.info`'s
  `getBundleInfo('aim_fact')`, i.e. installed `aim_scope` entities. Every
  scope-validation call site uses this - a new scope needs zero code
  changes.
- `saveFact(AimFact $entity): void` - `validate()` then `save()`, throws
  `\InvalidArgumentException` on any violation (including a Guardrails
  rejection - Guardrails runs as a real entity validation `Constraint`
  on the `text` field, not a presave hook, so `ContentEntityForm`'s own
  `validateForm()` picks it up as an ordinary field error too).
- `checkCreateAccess(string $scope, AccountInterface $account): bool` -
  wraps the access handler's `createAccess()`; `aim_tool` calls this
  before every Tool API/MCP write.
- `getCompareDistance()`/`getRecallMaxDistance()`
  - live `aim.settings` values, not the class constants (those are only
  the shipped defaults and in-code fallback). `recall_max_distance` is
  applied only when a caller passes it to `recall()` as `$maxDistance`:
  `aim_tool`'s `aim_recall` and `aim_chatbot`'s recall-context subscriber
  do; `drush aim:recall`
  does with `--max-distance` and is raw otherwise.
- `getDefaultChatProvider(): ?array` - resolves the site-wide default via
  `AiProviderPluginManager`; `NULL` if none configured. `AimCommands`'
  `--provider`/`--model` options default to `NULL` and fall through to
  this, so they follow whatever the site default is.

### Exact values from `literals`

`literals` (a hard dependency) is the store for exact values (a phone
number, a URL) that must never be paraphrased. Memory stays aim's job;
literals is the value store behind it. They meet in two small places:

- **Tokens in fact text.** A fact may contain `[literal:key]` (the value) or
  `[literal:key:link]` (a Markdown link where the value has a safe one).
  `recall()` replaces them at read time as the *recalling* account, via
  `LiteralReader::replaceTokens()`: the value is never stored in the fact,
  and a viewer sees only what their own account may see. A fact naming a
  literal the viewer cannot read is withheld whole (a sentence with a hole
  misleads, and its presence would hint a restricted value exists);
  `aim.settings:show_redacted_facts` (Debugging section, default off)
  returns it with `LiteralReader::REDACTED` (`[redacted]`) instead.
  Replacement is one pass, so a resolved value is never scanned for further
  tokens. Use a token for a sentence that says something the literal does
  not; a fact that only points at a literal duplicates the literal's
  description. Not enforced.
- **A lookup tool the assistant calls.** An aim site uses `aim_tool`'s
  `aim_literal` (decided 2026-10-10), exposed to the on-site assistant
  through `tool_ai_connector` and over MCP; `literals_tool`'s
  `literals:lookup` is for sites running `literals` without aim. Both are
  thin wrappers over the one `literals.lookup` service, so they behave
  identically. The demo recipe (`aim_demo_library`) gives its agent
  `tool:aim_literal`. The large
  model decides when it needs an exact value and calls it (by key, search
  words or question); the model is the gate for "is this a one-shot lookup",
  so recall does not call the literals finder and the two paths never
  compete. The finder (`literals_finder`) is optional and only matters for
  the `question` mode; `aim` does not depend on it. The demo persona
  tells the assistant to use the tool for phone numbers and web addresses.

### Recall gap

`aim.settings:recall_gap` (0 = off) keeps only facts within that distance of
the best match, applied by the model-facing recall tools through
`getRecallGap()` and the `$gap` argument of `recall()`, never by
consolidation.

### Guardrails (`aim_write_guardrails` set)

`aim_max_length` (`input_length_limit`, 2000 chars) and `aim_no_markup`
(`regexp_guardrail`, blocks `<script>`-shaped markup), `stop_threshold:
1.0`. Neither calls `->chat()` - zero LLM cost. Wired via
`AimGuardrails` (`src/Plugin/Validation/Constraint/`), added to
`aim_fact`'s `text` field in `baseFieldDefinitions()`. Mirrors `drupal/
ai`'s own `GuardrailsEventSubscriber::applyPreGenerateGuardrails()` -
`PassResult` skipped, `StopResult` scores aggregated against the set's
stop threshold, `RewriteInputResult` replaces the text in place so later
guardrails see the rewrite. There's no public "apply this set to
arbitrary text" API in `drupal/ai`, so this is a deliberate copy of the
subscriber's logic, not an API call - re-check against the subscriber on
every `drupal/ai` update.

A `RewriteInputResult` guardrail's rewrite survives a `saveFact()` caller
(single entity, validate-then-save) but not the entity form
(`ContentEntityForm::submitForm()` rebuilds a fresh entity from raw form
input, independent of what `validateForm()` validated) - moot today since
both shipped guardrails are Stop-only, worth knowing before adding one
that rewrites.

### Grounded check (ADR-0037)

When a fact is written with the text it came from, a decision model is
asked whether that text states the fact, and the answer (a probability, 0
to 1) is stored on the fact as `grounding_score`. The source text is sent
to the model and never stored.

- **Where the source text comes from:** extraction (`aim:extract`, the
  ingest form's prose mode) passes the whole document;
  `remember(..., sourceText: ...)`; the MCP `aim_remember` tool's
  `source_text` input (supplied by the calling agent, so only as verbatim
  as that agent is), which the chat assistant uses too since ADR-0062
  (reading the chat history instead was dropped; that ADR's decision 3
  says when it returns). JSON and one-fact-per-line ingest have no source
  text and stay unchecked (`grounding_score` empty).
- **Consolidation merges** are checked too, with the two inputs as the
  passage (see `aim:consolidate`'s "Merge verification").
- **One call per write, not per fact.** `checkGrounding()` puts the
  candidates in one Decision call (per group, below) (`candidate_1` ... in the state, one
  strict Noul question each, `GROUNDING_QUESTION`). Extraction and an MCP
  batch check all their facts at once; each `remember()` then finds its
  score in the request cache. About 0.35 s on hosted Jev.
- **Chunking.** More candidates than
  `activities.grounding.max_questions` (default 16, subq's cap; raise it
  for a model that takes more) go in groups, one call each; a failed group
  leaves only its own scores empty.
- **Trust policy** (`aim.settings:trust_policy`, `AimTrustPolicy`): each
  write names its entry channel in code (`chat`, `mcp`, `ingest`, `drush`;
  never the caller's `source`). Rule order: an explicit `trusted` (FALSE
  always; TRUE only for a writer who `canApprove()`: `administer aim`,
  `review aim facts` or the scope's trust permission), then `trusted` for
  a writer who `canApprove()`, the channel's rule, the scope's rule,
  `default` (empty follows `default_trusted`). The writer is the current
  user: Drush runs as anonymous (Drush 13 has no `--uid`/`--user` option),
  so Drush writes follow the `drush` channel's rule; an MCP caller is its
  OAuth token's user with permissions narrowed to the token's scopes, so
  `aim_tool_oauth`'s two scopes never make a non-admin a reviewer (an
  admin-role token keeps its bypass). Values: `trusted` (starts trusted, enforce
  demotes below the threshold), `untrusted` (starts untrusted, enforce
  promotes at or above it), `hold` (untrusted, never promoted). Shipped:
  `channels: {chat: hold}`. A fact with no source text keeps its starting
  state. No UI yet: edit via `drush config:set` or config import.
  A writer who `canApprove()` is the source of truth, so their fact starts
  trusted on any channel, by design (decided 2026-10-25, no four-eyes
  rule). The gap is wording, not the person: on chat, MCP and extraction a
  model writes the fact text, so a reviewer's trusted fact can be a
  paraphrase nobody read. That is the grounded check's job: in `enforce`
  it demotes a trusted fact whose text the source doesn't state (this site
  runs `shadow`, so it only scores).
- **Modes** (`aim.settings:grounding_mode`, AIM settings > Models >
  Grounded check): `off`; `shadow` records the score and leaves `trusted`
  alone; `enforce` also moves `trusted` per the policy above (threshold
  `activities.grounding.threshold`, default 0.7), untrusted when the check
  fails or has no model (fail closed), including a reviewer's start. A
  permitted explicit `trusted` wins. Decision backend only (`activities.grounding`).
- **Checking reliability:** the Grounding column (sortable) and the
  "Grounding score below" filter on the facts list; an audit line per
  verdict (fact ID, score, cutoff, mode, no text); call time, tokens and the
  prompt and reply in `ai_log` (tag `aim_ground`); the eval set
  (`aim_benchmark`'s `decision-eval.php -- grounding`) after any model
  or wording change. The candidate and passage text are in the logged prompt
  (`ai_log`, ADR-0060).
- **Known calibration issue:** the strict question penalizes any dropped
  detail, so a clean-up that drops a junk word scores low (the old
  preflight canary's `ZZPREFLIGHT` token, 0.52-0.62). Narrower wordings did
  not fix it without passing real meaning-changing drops (ADR-0037
  addendum), so the preflight canary no longer carries a junk token.

### Scope/bundle model

`aim_fact`'s bundle key field is named `scope`, deliberately not `type` -
core has no single convention here (node uses `type`, media uses
`bundle`, comment uses `comment_type`, taxonomy_term uses `vid`), so
`scope` is a legitimate domain-specific choice like the others. Bundles
are real `aim_scope` config entities (`bundle_entity_type` on `AimFact`'s
`#[ContentEntityType]` attribute) - `getBundleInfo('aim_fact')` derives
automatically, no `hook_entity_bundle_info()` needed.

**Scopes are config on core plugins (ADR-0063)**, the way `literals`
ships resolvers and literal types. `aim` ships the five scopes in
`config/install` (`aim.aim_scope.{site,role,user,case,entity}.yml`) and
the three plugins in `src/Plugin/AimScopeType/` (`user`, `case`,
`entity`; site and role have `plugin: null`). Each scope has:

- `status` - a disabled scope takes no new facts (forbidden in
  `AimFactAccessControlHandler::checkCreateAccess()`, admins included)
  and is left out of `AimMemoryManager::allowedScopes()`, so tools,
  forms and the scope guide never offer it. `AimScopeForm` refuses to
  disable a scope that has facts; `AimScopeDeleteForm` refuses to delete
  one.
- `description` - the "which scope when" wording models read
  (`aim_tool`'s `AimScopeGuide`), editable on the scope form.
- `weight` - the order everything lists scopes in (`allowedScopes()`
  sorts with `ConfigEntityBase::sort()`; config entity queries can't
  sort on two keys). Set by dragging rows at `/admin/config/aim/scopes`.
- `plugin` and `settings` - the plugin gets the scope's settings as its
  configuration (`AimScopeTypePluginManager::getTypePlugin()`), so two
  scopes sharing a plugin behave per their own settings.

Plugin fields (`getBaseFieldDefinitions()`, ADR-0028 piece 1) are merged
for every plugin in code, so the entity plugin's `target_type`/
`target_id` exist on every site, unused unless an entity scope is used.
A plugin in another module must `->setProvider()` its own module on each
field and install the storage in its `hook_install()`.

`links.field_ui_base_route` is deliberately unset - `bundle_entity_type`
and Field UI's "Manage fields" tab are independently gated, and leaving
this off keeps per-bundle fields entirely off the table. This matters
because dedicated per-field tables are exactly what breaks the next
point.

**Don't move `subject`/`user` into per-bundle fields
(`bundleFieldDefinitions()`).** Tried and reverted: it broke
`ai_vdb_provider_mariadb`'s `AiVdbProviderClientBase::isMultiple()`
(assumes every field is a base field, throws `Table
'aim_fact_vectors__subject' doesn't exist`) and core's `EntityViewsData`
(degrades the Views columns to `Broken` handlers). Both are patchable in
isolation, but the pattern - "every contrib/core integration point that
assumes all fields are base fields" - generalizes badly for a module
headed to drupal.org. Both fields stay always-present base fields, unused
on bundles that don't need them. Don't re-attempt without a concrete
reason beyond schema tidiness.

This is a lesson about *bundle* fields specifically, not about which
module may declare a base field - confirmed the hard way when ADR-0027
first over-applied it, concluding `target_type`/`target_id` "has to
live in core `aim`" for the same reason. [ADR-0028](adr/resolved/0028-scope-type-plugin.md)
corrected that: `isMultiple()` and `EntityViewsData` both key off
`EntityFieldManager::getFieldStorageDefinitions()`, which merges a base
field declared via another module's `hook_entity_base_field_info()`
indistinguishably from one declared in the entity's own class - neither
bug is about declaring-module, only about bundle-conditionality. A
scope type's own base fields (e.g. the entity plugin's
`target_type`/`target_id`) now live with the plugin that declares them
(`AimScopeTypeInterface::getBaseFieldDefinitions()`), merged generically
by `AimHooks::entityBaseFieldInfo()`.

`AimScope` deletion refuses if any `aim_fact` of that scope still exists
(`AimScopeDeleteForm`, same precedent as core's `NodeTypeDeleteConfirm`).

### Vector search

Server `aim_vector` (backend `search_api_ai_search`, VDB provider
`mariadb`), index `aim_vector_index` over `entity:aim_fact`, collection
table `aim_fact_vectors` (renamed from `aim_facts` 2026-09-28 - too close
to the `aim_fact` entity table for a raw SQL query to tell apart at a
glance; real MariaDB 11.7+ HNSW `VECTOR INDEX`, not a
brute-force scan). `text` indexed as `main_content`; `scope`/`subject`/
`user`/`source`/`trusted` as `attributes` (`user` as an
`integer`, `NULL` for every scope but user, renamed from `subject_uid`
2026-09-28; `trusted` as a `boolean`,
BTREE-indexed on the collection table same as `user`, since
`recall()` filters on it by default - see ADR-0002's addendum and
`AimHooks::BTREE_INDEXED_COLUMNS`).

**`index_directly`:** off in the shipped config, so a saved fact is not
searchable until an index run (`drush search-api:index aim_vector_index`,
or the consolidation queue worker's `reindex()`, up to a minute away on the
crontab). That breaks the obvious chat beat "tell it something, then ask
about it", so **this site turns it on** in its own config. Measured
2026-09-26 with local Ollama embeddings: ten writes take the same ~165 ms
in the request either way, and turning it on adds about 70 ms per fact of
indexing at shutdown (a fact saved in one request is recalled in the next,
verified through the demo assistant). Search API does that work after the
request, so a web visitor should not wait on it (verified in a drush
process, not over HTTP). With **hosted** embeddings (roughly 500 ms per
fact) a batch would tie up a PHP worker for seconds, which is why the
module ships it off ([ADR-0015](adr/resolved/0015-immediate-consolidation-considered-deferred.md));
turn it on only where embeddings are local. To change it:
`$index->setOptions([...$index->getOptions(), 'index_directly' => TRUE])->save()`,
then export the index config.

**Gotchas:**

- Per-field indexing role (main content vs. attribute) lives in
  `ai_search.index.<index_id>` simple config, not the index entity -
  skip a field there and it's silently ignored at embedding time.
- The collection table only gets its attribute columns on index
  *update*, not *create* - a freshly created index entity needs a second
  `->save()` (`aim.install`'s `hook_install()` does this automatically).
  Re-saving an index whose table already exists is safe:
  `AimMariaDBProvider` swallows the provider's "Table already exists"
  throw (see "`ai_vdb_provider_mariadb` is swapped for a subclass" above),
  so a stale table no longer needs a `DROP TABLE`.
- `drush search-api:clear aim_vector_index` can drop and reprovision
  `aim_fact_vectors` down to just the base columns, silently losing `scope`/
  `source`/`subject`/`text` - the next `search-api:index` then fails
  `Unknown column 'scope'`. Fix is the same index entity `->save()`
  above, not running `search-api:clear` again. After a `DROP TABLE`-and-
  reindex cycle, reindex directly and skip `search-api:clear` entirely.

- `AimMemoryManager::reindex()` (used by `aim:extract --index`, the ingest
  form and the consolidation worker) clears the items it indexes from
  Search API's post-request queue. With `index_directly` on, a fact saved
  earlier in the same request is also queued for indexing at the end of it;
  that second pass used to delete the fact's vector row and insert nothing
  (ai_search's chunk slicing starts at `processed_chunks`, which the first
  pass left equal to `total_chunks`), while the tracker still said indexed,
  so `search-api:index` reported "up to date" and only `aim:status` parity
  showed the gap. Found and fixed 2026-10-15. If parity fails anyway:
  `drush search-api:reset-tracker aim_vector_index` then
  `drush search-api:index aim_vector_index` (the reset also zeroes
  `processed_chunks`). Upstream status in TODO.md/BACKLOG.md.

### `checkViewAccess()` and skipping access on recall

`AimMemoryManager::executeSearchQuery()` and `recall()` take
`bool $accessCheck = TRUE`, the same idiom as core's entity query
`accessCheck()`. FALSE sets `search_api_bypass_access`. Three callers
pass it: `drush aim:recall`, `aim:benchmark`,
and consolidation's neighbor search. A Drush caller has no viewer to check
access for and already holds raw DB credentials, so the check is no real
boundary there; consolidation must compare against every fact in the
scope, whoever triggered it, and returns nothing to a viewer. No account
switching (see [ADR-0006](adr/resolved/0006-agent-native-write-path.md)'s
addenda: uid 1 has no core guarantee of existing or holding any role).

Everyone else (Tool API/MCP, the chatbot, any web request) runs the query
as the current account, so `AimFactAccessControlHandler`'s per-scope
permission applies per result. Being anonymous is not a reason to bypass:
an anonymous web or MCP caller gets only what anonymous may view. So the
chatbot recalls nothing for a visitor whose role lacks
`view site aim facts`, and a caller with only `recall aim facts` cannot
recall their own `scope=user` facts via `aim_recall` without
`view user aim facts`.

### User-scope role visibility

`AimScopeUser` (`src/Plugin/AimScopeType/AimScopeUser.php`) widens the
flat `view user aim facts` permission with the user scope's own settings,
edited on its scope form (`/admin/config/aim/scopes/user/edit`):
`role_visibility` (viewer role => subject roles it may see, a checkbox
grid) and `shared_role_fallback` (default on: viewer and subject share a
real role, `authenticated` excluded). Only ever allowed or neutral, ORed
against the flat permission. Case-scope access control is unbuilt.

---

## AI provider configuration

Not one "AI" - three different cost profiles:

| Step | Needs | Cost |
| --- | --- | --- |
| Discovery, extraction, conflict/merge decisions, Recipe generation | Reasoning-grade LLM | Expensive |
| Consolidation - similarity-threshold cases | Vector math only | Free |
| Embedding generation (every write and query) | Small embedding model | Cheap, local-friendly (Ollama) |

Site-wide default chat provider/model live in `ai.settings`
(`default_providers.chat`) - `AimCommands`' `--provider`/`--model`
options, `ai_assistant_api.ai_assistant.aim_assistant`'s
`llm_provider: '__default__'`, and `search_api.server.aim_vector`'s
`backend_config.chat_model` (chunk token-sizing only, no `->chat()` call
- cost-neutral either way) all follow it. `embeddings_engine` has no
`__default__` equivalent - it's a plain provider/model ID on
`search_api.server.aim_vector`'s `backend_config`, needs a manual update
on any embeddings swap. Anthropic has no embeddings API - never point
`embeddings_engine` at it.

**Caveat when swapping a decision model:** the activities in
`aim.settings:activities` name their own provider and model, but the
literals finder does not - it reads the AI module's default for the
`decision` operation type (`ai.settings:default_providers.decision`,
`LiteralChooser`). Changing a model in one place leaves the other on the old
ID; the finder then fails with the provider's "unknown model" error, which
`aim_literal` reports as "no matching literal", so the assistant tells the
visitor a value is not recorded when it is. After any decision-model swap,
update both, set `moderation` and `text_classification` defaults too so no
default names a retired model, and check with `drush literals:eval`. (A
finder-level provider/model setting would remove this trap; not built.)

**Local Ollama** (`ai_provider_ollama`): points at the *host's* Ollama
install (`http://host.docker.internal:11434`), not a container-local one
- don't run a second Ollama daemon in its own DDEV addon container
alongside this, it won't share the host's model cache. Host-side
prerequisite: `OLLAMA_HOST=0.0.0.0:11434` on the host's Ollama service
(Ollama defaults to `127.0.0.1`-only, unreachable from the DDEV network).

A Claude Pro/Max subscription cannot power an unattended `drupal/ai`
provider (Anthropic prohibits subscription OAuth for third-party
integrations) - needs a real Console API key, or stays on Ollama.

**Running a local model service on a small machine** (Ollama for
embeddings; local decision models are parked): the memory-cap and
systemd settings that worked on the 14 GB dev laptop are in
[ADR-0021](adr/0021-jev-typed-decision-provider.md).

**Gotchas:**

- Anthropic's structured-output mode requires `additionalProperties:
  false` on *every* object level of a JSON schema (already handled in
  `extractFacts()`/`classifyPair()`).
- Test provider behavior through Drupal's `ai.provider` service, not a
  direct third-party API call with an extracted key - the two paths can
  give contradictory answers (a direct amazee.ai `/v1/models` call once
  gave a misleading 401 while the real `$provider->embeddings(...)` call
  worked with the same key).
- An embeddings provider/model swap invalidates every existing vector
  (different embedding space, possibly different dimension) - full
  reindex required, not incremental. See "Setting up vector search"
  below.

---

## Submodules

Chatbot integration, Tool API + MCP exposure, and MCP OAuth setup are
each documented in their own submodule now:

- [aim_chatbot/DEVELOPING.md](modules/aim_chatbot/DEVELOPING.md) - `ai_agents`
  FunctionCall tools, the demo assistant/chat block, deepchat gotchas.
- [aim_tool/DEVELOPING.md](modules/aim_tool/DEVELOPING.md) - Tool API
  plugins, `mcp_server_tool_bridge` exposure and version constraints.
- [aim_tool_oauth/DEVELOPING.md](modules/aim_tool_oauth/DEVELOPING.md) -
  the full MCP OAuth setup runbook (dependency chain, composer gotcha,
  key generation, HTTPS exposure via Tailscale Funnel).

---

## Upgrading an existing site (no `hook_update_N()`)

`config/install` does not re-run on an installed module and aim ships no
update hooks (CLAUDE.md), so a site installed before 2026-09-26 needs these
by hand. Fresh installs get all of it from config and the shim.

1. **Retired facts out of the index**
   ([ADR-0022](adr/resolved/0022-exclude-retired-facts-from-vector-index.md)): add the
   `aim_exclude_retired` processor to `aim_vector_index`, then purge the
   retired rows already indexed (the snippet under "Enabling the processor
   on an existing site" in "Vector search").
2. **`user` as an indexed attribute** (field renamed from `subject_uid`
   2026-09-28, `aim_update_10001()`; the ADR title and file name still
   say `subject_uid`)
   ([ADR-0018](adr/resolved/0018-index-subject-uid-with-btree.md)): add an `integer`
   field `user` (datasource `entity:aim_fact`, property path
   `user`) to `search_api.index.aim_vector_index`, and
   `indexing_options.user: attributes` to
   `ai_search.index.aim_vector_index`. Saving the index makes the shim add
   the column and its BTREE index. Then `ddev drush sapi-r aim_vector_index
   && ddev drush sapi-i aim_vector_index`. This must happen before the code
   that filters on it runs: old rows have `NULL` until reindexed.
3. **The provider shim** needs nothing: it is swapped in by
   `hook_ai_vdb_provider_info_alter()` after `drush cr`. Check with the
   `get_class(...)` one-liner under "`ai_vdb_provider_mariadb` is swapped
   for a thin subclass".
4. **HNSW tuning** ([ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md)):
   add `mhnsw_ef_search: 100` to the server's `database_settings`, and
   rebuild the vector index once at `M=16` (the `ALTER TABLE` under "Tuning
   vector search accuracy").
5. **`grounding_score` field** (ADR-0037, 2026-10-11): install its storage,
   then save the AIM settings form once (adds `activities.grounding` and
   `grounding_mode`) and re-import `views.view.aim_facts` for the column and
   filter:

   ```bash
   ddev drush php:eval '$d = \Drupal::service("entity_field.manager")->getFieldStorageDefinitions("aim_fact")["grounding_score"]; \Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition("grounding_score", "aim_fact", "aim", $d);'
   ```

6. **Per-site choices, not defaults:** `index_directly` on if embeddings are
   local; `recall_max_distance` recalibrated to your dataset (ADR-0019).
7. Run `drush aim:status` to confirm steps 1-4 above actually took (it
   checks index parity, orphan rows, the provider shim, and HNSW tuning
   directly against the DB), then export the config and confirm `ddev
   drush config:status` reports no differences.

---

## Setting up vector search (fresh install, or after a provider change)

`config/install` ships the *structure* of the vector search server/index
- field mappings, backend wiring, collection-table schema - but not a
working AI provider. The shipped `chat_model`/`embeddings_engine` are
this site's working choice at last export time, referencing a specific
provider/model/key a fresh site won't have. Run this after enabling
`aim`, or whenever `drush aim:recall` starts erroring on the embeddings
call:

1. **Enable and configure a real AI provider module** for chat and
   embeddings (`ai_provider_anthropic`/`amazeeio`/`ollama` - Ollama is
   the only local/sovereign option). Anthropic has no embeddings API.
2. **Store the provider's API key as a `key` entity** (Configuration >
   System > Keys, or `drush key:`).
3. **Check available models through Drupal's own provider service**, not
   the third-party API directly (see "Test provider behavior through
   Drupal's `ai.provider` service" above):

   ```bash
   drush php:eval "print_r(\Drupal::service('ai.provider')->createInstance('<provider_id>')->getConfiguredModels('chat'));"
   ```

   Swap `'chat'` for `'embeddings'` for the embeddings side.
4. **Point `search_api.server.aim_vector`'s `backend_config` at the real
   IDs** (`<provider_id>__<model_id>` form):

   ```bash
   drush config:set search_api.server.aim_vector backend_config.chat_model '<provider>__<model>'
   drush config:set search_api.server.aim_vector backend_config.embeddings_engine '<provider>__<model>'
   ```

5. **If the new embeddings model's dimension differs from the current
   one**, verify with a real call (`$provider->embeddings(new
   EmbeddingsInput('test'), '<model>', [])`, count the array), update
   `embeddings_engine_configuration.dimensions`, and `DROP TABLE
   aim_fact_vectors` before step 6 - `VECTOR` columns are fixed-width.
6. **Re-save the index entity** to force the collection table's
   attribute columns to exist (see "Vector search" above):

   ```bash
   drush php:eval "\Drupal::entityTypeManager()->getStorage('search_api_index')->load('aim_vector_index')->save();"
   ```

   Re-saving is safe on an existing table too: `AimMariaDBProvider`
   swallows the provider's "Table already exists" throw (see "`ai_vdb_provider_mariadb`
   is swapped for a subclass" above). If that message still appears, the
   alter hook is not applying - check the class it prints there.
7. **Reindex every fact, not incrementally** - a provider/model change
   means every existing vector is in the old embedding space. If step 5
   dropped/rebuilt `aim_fact_vectors`, the table is already empty - reindex
   directly and skip `search-api:clear` (it reprovisions the table down
   to base columns, dropping `scope`/`source`/`subject`/`text` again; see
   "Vector search" above for the 2026-09-28 rename to `aim_fact_vectors`).
   If
   no dimension change happened, `search-api:clear` is safe first:

   ```bash
   drush search-api:index aim_vector_index
   ```

8. **Verify**: `drush aim:recall "<something you know is in there>"`
   should return sane, correctly-ranked results.

None of this touches `aim_fact` itself. Steps 5-7 cost real
embedding-API credit (one call per fact/chunk) and scale with fact
count - trivial at PoC scale, a real line item at volume.

---

## Plumbing audit (2026-10-15, first pass)

What aim's custom code does that Drupal AI contrib could do instead,
checked against the installed `ai`, `ai_search`, `ai_logging`, `ai_eval`
(Composer-present, not enabled) and `ai_observability`. Prompted by George
Kastanis's measure that about a fifth of his own code was plumbing. Verdict
is keep (memory rules or no contrib equivalent), thin (already an adapter),
or look (a real candidate, not yet decided). Only read, nothing replaced.

| Piece | Verdict | Why |
| --- | --- | --- |
| `AimMemoryManager` remember/recall/consolidate | keep | The memory rules; split planned (TODO.md/BACKLOG.md), no contrib equivalent |
| `AimCallLogSubscriber` | thin | Adds run ID and duration to `ai_logging` rows; `ai_logging` has neither |
| `AimEmbeddingCacheSubscriber` | keep | No query-embedding cache found in `ai` or `ai_search` (ADR-0017) |
| `AimMariaDBProvider` | thin | Shim over `ai_vdb_provider_mariadb`, deleted as upstream fixes land (ADR-0023) |
| `ExcludeRetired` processor | keep | Aim-specific Search API rule |
| `AimStatusChecker`, status page | keep | Checks aim's DB state; `ai_observability` is OTel traces, not this |
| Grounded check, merge verifier (`checkGrounding`, `verifyMerge`) | keep | Read the runner: Decision guardrails filter the input or output of a Decision call (stop with a score). Our checks are Decision calls whose answers aim acts on, so they are not guardrails. Guardrails could instead be attached to those calls |
| `ActivityBackendInterface`, `ChatBackend`, `DecisionBackend` | keep | `DecisionBackend` wraps the core Decision operation; `ChatBackend` is the fallback for models that are not decision models (sovereignty, ADR-0004) |
| Redaction (not built) | contrib helps | `RegexpGuardrail` already supports Decision calls but only stops; a redacting guardrail would extend that pattern, so the pattern-PII piece of TODO.md/BACKLOG.md's redaction item is the small upstream contribution |
| `aim_benchmark` decision-eval scripts and JSON sets | look, partial | `ai_eval` has dataset sources, a binary groundedness judge, judge validation, agreement stats and confidence intervals. Its graders judge agent responses through chat, not typed Decision calls, so the runner stays; the stats and judge validation are worth borrowing |
| `AimIngestForm` | keep | Extraction into facts; contrib chunking is for indexing, not extraction |
| `AimSettingsForm` (450 lines) | look | Mostly `ai_provider_configuration` elements and thresholds; not read line by line |
| `aim_tool_oauth` | keep, fix gap | Tried `agent_access` 1.0.0-alpha2 on a scratch DDEV copy (2026-10-15, scripted OAuth 2.1 PKCE client, not Claude itself). It does not replace our scopes: it ships only `drupal:mcp:connect` and `drupal:content:read` plus two read-only entity tools, nothing for aim. A permission-granularity scope puts exactly its one permission on the token, so with connect-only `aim_recall` is denied and `aim:recall`/`aim:remember` are still needed. Two findings hit our scopes too: (1) `aim:remember` carries only `remember aim facts`, so a non-admin's `aim_remember` fails with "No permission to create user-scope facts" (the per-scope `create {scope} aim facts` is not on the token); (2) permission-granularity scopes drop the user's real roles from the token, so the user-scope role-visibility matrix returns nothing for every non-admin. A role-granularity scope (token roles = that role) fixed both in the test: alice saw her own and a peer's fact, a manager saw staff facts via the matrix, `remember` worked, and users outside the role could not get a session. Also seen: a permission scope appeared to grant `access mcp server` to a user whose role lacks it; re-tested on the live site 2026-10-15 with `aim_tool_oauth` scopes and it does not: the effective permission is the scope's permission AND the user's own, so scopes narrow and never escalate; `entity_list` returns per-entity-checked rows but leaks total counts for `aim_fact`, `ai_log`, `consumer`, `oauth2_token`. `agent_access` is pre-release and not under the security advisory policy; accepted as fine for now. The per-tool scope requirement we set on `aim_recall`/`aim_remember` was not enforced under 1.0.0-alpha1: `mcp_server_oauth` matched tools as `<base>.<id>` but the bridge names them `tool_api__<id>`. Fixed upstream (#3604047); this site runs `1.x-dev#fb92204` until a release (TODO.md). Role-granularity scopes are documented, not shipped (role ids are site-specific), and verified live 2026-10-15: the role needs `access mcp server`, the aim permissions and `grant simple_oauth codes`, and clients request the role scope plus the aim scope. See `aim_tool_oauth`'s DEVELOPING.md "Role scopes for non-admin users". |
| `aim_tool` / `aim_chatbot` | not audited | Next: Tool API permission declarations (TODO.md/BACKLOG.md "aim_tool: declare permissions") |

Result so far: the Decision-API rows turned out to be keep, so the real
candidates are `aim_tool_oauth` (against `agent_access`), the settings form,
and borrowing `ai_eval`'s judge statistics. Not much plumbing; the larger
size win is the `AimMemoryManager` split.

`AimMemoryManager` split (2026-10-15, 2,490 to about 600 lines), one
service per job, each `Aim*` named:

| Service | ID | Holds |
| --- | --- | --- |
| `AimMemoryManager` | `aim.memory_manager` | Loading, saving, guardrails, access, account lookup, `executeSearchQuery()`, expiry sweep |
| `AimRememberService` | `aim.remember` | `remember`, `ingestText`, `rememberBatch`, `extractFacts`, `createFactsFromCandidates`, field parsing |
| `AimRecallService` | `aim.recall` | `recall`, `formatFactLine` |
| `AimConsolidationService` | `aim.consolidation` | The sweep, per-fact path, neighbor search, classify, verify, merge |
| `AimGroundingChecker` | `aim.grounding_checker` | The grounded check (ADR-0037) |
| `AimSettings` | `aim.settings_reader` | Thresholds, per-activity models, grounding mode and cutoff |

The new services depend on the manager, never the reverse, so the manager
has no delegators: callers use the service they need directly (settings
readers take `AimSettings`, the grounded check takes `AimGroundingChecker`).

## Running the tests

Kernel tests need a database that is not the site's own. Create it once
(`ddev exec "mysql -uroot -proot -e 'CREATE DATABASE IF NOT EXISTS phpunit; GRANT ALL ON phpunit.* TO db@\"%\"'"`),
then:

```bash
ddev exec "cd /var/www/html && SIMPLETEST_DB='mysql://db:db@db/phpunit' SIMPLETEST_BASE_URL=http://web vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/aim/tests web/modules/custom/aim/modules/aim_chatbot/tests"
```

A model-backed step is faked with `FakeDecisionBackend`; no test calls a
provider. An entity access handler caches per entity and account, so a test
that changes settings between two `access()` calls resets it first.

## Logging

Channel `aim` (service `logger.channel.aim`). Three tiers:

- Standard, always on: warnings and errors only (guardrail/validation
  rejections in `remember()` and `createFactsFromCandidates()`, `BLOCKED`
  consolidation merges, the queue worker's suspend cause).
- `aim.settings:log_audit` (info): writes, consolidation decisions,
  trust/untrust/retire/unretire actions.
- `aim.settings:log_verbose` (debug): recall, embedding cache, extraction.
- `aim.settings:log_query_text` (debug, needs `log_verbose`): adds the
  recall query text, for diagnosing poor or empty matches (its embedding
  call is untagged, so `ai_logging` never records it). Off by default. It
  also adds the question of each literals finder lookup. Tagged model calls
  have their full text in `ai_log` instead (see "Model-call logging").

Gate info/debug calls through `AimMemoryManager::logAudit()` /
`logVerbose()`; the embedding cache subscriber reads `log_verbose` itself
(the manager depends on it, so it cannot depend back). Do not put fact text
or recall query text on this channel outside `log_query_text` (guardrail
violation messages can quote it, and watchdog is not pruned like `ai_log`):
IDs, scope, uid, subject, decision, score, auto-versus-model,
provider and model ID only. Consolidation logging lives in the single
helper `logConsolidation()`, so reworking `decideAndApply()` doesn't
disturb it.

Reading the log: `drush watchdog:show --type=aim`, or
`/admin/reports/dblog` filtered by type `aim`. Each entry is one line of
IDs and metadata, so join to the fact via `aim_fact` ID. `log_query_text`
entries persist in watchdog (and any syslog or external aggregator)
after the setting is turned off: delete them with `drush watchdog:delete
--type=aim` if the queries were sensitive.

Constructor note: `AimMemoryManager` takes the logger as its last argument, and
action plugins now take the memory manager and current user (all built
via the container, so only a hand-constructed instance is affected).

---

## Admin UI

- `/admin/content/aim-facts` (View `views.view.aim_facts`, gated
  `administer aim`) - table of every fact, "still live" for an
  empty `expires`, `uid` ("Extracted by") and `subject`/`user`
  ("about") both shown, `trusted` shown and editable inline via the
  Edit link - this is the review queue ADR-0002's addendum leans on, not
  a bespoke UI. Per-row View/Edit/Delete dropbutton and bulk delete.
- `/admin/content/aim-facts/add` - bundle picker, then a standard
  `ContentEntityForm` per scope (`/admin/content/aim-facts/add/{aim_scope}`).
  Gated by the per-scope `create {scope} aim facts` permission (or
  `administer aim`).
- `/admin/config/system/queue-ui` - manual consolidation-queue trigger.
- `/admin/config/aim/settings` (`AimSettingsForm`, `#config_target`-backed)
  - consolidation thresholds, the chatbot recall cutoff,
  the `log_audit`/`log_verbose` checkboxes (see "Logging"),
  `default_trusted` (the initial value a new fact's `trusted` field gets,
  ADR-0002), and the extraction/consolidation prompt text, all
  admin-editable, no code deploy needed. The requested output *shape*
  (the JSON schema extraction/consolidation parse against) is not
  editable here - it's parsed by PHP downstream and isn't safe to hand to
  a text field.
- `/admin/config/aim/scopes` - the scopes: drag to order, edit each
  one's wording, Enabled and type settings (the user scope's
  role-visibility grid), add or delete.

---

## Recipes

`recipes/<name>/` holds Drupal Recipes for sites that want a starting
point. Apply one from the site root (recipes are applied by path):

```bash
ddev exec vendor/bin/dr recipe:apply web/modules/custom/aim/recipes/<name>  # dev-site path; use modules/contrib/aim/... elsewhere
```

- **`aim_demo_library`**: a fictional community library (site identity,
  chat persona, recall cutoff, `index_directly`) plus its 31 facts and
  eight literals as default content in `content/`. This site's demo kit
  (its pre-flight and reset steps are in the recipe README) is reset with it. The first archetype
  starter kit ([ADR-0014](adr/resolved/0014-usecase-archetype-starter-kits.md)).
  Details and caveats in its [README](recipes/aim_demo_library/README.md).
- **Scripted tests carry chat history.** The demo assistant uses
  `allow_history: private_tempstore_pool`, so running it twice in one PHP
  process (one `drush php:eval`, say) carries the first question's history
  into the second. Browser sessions are unaffected; use one process per
  question. Reported 2026-10-19 on a test site, not verified here.
- **Facts as recipe content skip `remember()`.** Core's importer saves
  entities directly, but calls `validate()` first, so the Guardrails
  constraint on `text` still runs (a violation aborts the apply). With
  `index_directly` on they are embedded and indexed as they are imported
  (verified 2026-10-10, 31 facts); without it, run
  `drush search-api:index aim_vector_index` after applying.
  `aim:remember --file` goes through `remember()` and always indexes.
- **No `aim_mcp` recipe, deliberately.** The remote-MCP stack is already
  shipped: `aim_tool` carries the tool configs, `aim_tool_oauth` the
  scopes and the tool-gating settings, and the `simple_oauth`/`mcp_server`
  defaults match what this site runs, apart from the key paths (aim's MCP
  server instructions come from `aim_tool_oauth`'s alter hook, not
  config). What is left is manual (key generation,
  HTTPS exposure, the `provide` composer workaround), which a recipe
  cannot do. See `aim_tool_oauth`'s [DEVELOPING.md](modules/aim_tool_oauth/DEVELOPING.md).
- **Validate without applying** (read-only, catches schema errors and
  config actions aimed at extensions that aren't installed):

  ```bash
  ddev drush php:eval '\Drupal\Core\Recipe\Recipe::createFromDirectory("/var/www/html/web/modules/custom/aim/recipes/<name>");'
  ```

  (`modules/custom/aim` is this dev site's path; use `modules/contrib/aim`
  where the module is installed by Composer.)

  No output means valid. `aim_demo_library` has been applied to this
  site (2026-10-10) and to a fresh install (2026-10-19).
- Config actions on config entities use `setProperties` (dotted keys reach
  nested values without replacing the rest); `simpleConfigUpdate` is for
  simple config like `system.site` and `aim.settings`.

---

## Default content export

```bash
ddev drush content:export aim_fact --dir=/var/www/html/web/modules/custom/aim/recipes/<name>/content  # dev-site path
```

Drush's command wraps core's exporter; core's own `dr` script and
`web/core/scripts/drupal` fail under this project's layout. Without an ID
it exports every fact (`--bundle=site` narrows it). `created`/`changed`
are left out by core, so imported facts are dated by the import. Pure read of entity
field data - never touches search_api/the vector collection table;
reindex after import is required regardless. Don't use
`--with-dependencies` to carry `uid` through - the exporter includes the
**pre-hashed password** on any exported user account. Anonymous
authorship on re-import is accepted, not a problem to solve.

## Model-call logging

Model calls tagged `aim_*` (`aim_extract`, `aim_consolidate`,
`aim_consolidate_verify`, `aim_consolidate_merge`, `aim_ground`), plus the
chat assistant's `ai_agents_aim_chatbot` (applied by `ai_agents`), are
recorded by `ai_logging` as `ai_log` entities, prompt and response text
included ([ADR-0060](adr/0060-adopt-ai-logging-for-model-calls.md)). Browse
them at the Views listing `ai_logs`.

`ai_logging.settings` is **site-wide, shared with every module**, and aim
no longer writes to it (the update hooks that did were removed). A site
turns it on at `/admin/config/ai/logging/settings`: `prompt_logging` and
`prompt_logging_output` on, `prompt_logging_tags` listing
`ActivityBackendInterface::LOGGED_TAGS` (an exact match, no wildcard, so
**a new aim tag must be added to both that constant and the site's list**;
`AimCallTagsTest` guards the constant against the code). Retention has no
per-tag control. This site runs 10000 entries or 90 days. Untagged calls
(the vector index's embeddings) are not logged. A fresh install records
nothing until set up as above; `aim:status` passes that (logging is the
site's choice) but fails a half setup: on with an aim tag missing or
excluded, replies off, or under 90 days kept. The demo recipe's pre-flight
also fails while logging is off. Planned, not built: an opt-in aim setting
(TODO.md).

`AimCallLogSubscriber` adds `aim_run_id` and `aim_duration_ms` to each call's
debug data, which lands in `extra_data`; group a replay with `setRunId()`.
Query with `JSON_VALUE`:

```sql
SELECT model, COUNT(*), AVG(JSON_VALUE(extra_data, '$.aim_duration_ms')),
       SUM(tokens_input), SUM(tokens_output)
FROM ai_log WHERE JSON_VALUE(extra_data, '$.aim_run_id') = :run GROUP BY model;
```

**Implementers:** these rows hold the text of what visitors and documents
said. Stealth mode (a presave hook on `ai_log` that blanks `prompt`,
`output_text`, `response_text`, `configuration` and `extra_data`; blanking
the prompt alone is not enough, a response can repeat it) is not built and
is off. Decide before real data goes in; a personal-memory site will want
it, a business site may not.
