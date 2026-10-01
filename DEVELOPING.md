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
drush aim:remember <text> [--scope] [--subject] [--source] [--state] [--category] [--asserted] [--target-type] [--target-id]
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
- `scope=case` with no `--subject` mints a case ID server-side
  (`case-<8 hex chars>`, from Drupal's `uuid` service) and stamps it onto
  the fact - the success message (and `aim_tool`'s `aim_remember`
  `case_id` output) surfaces the resolved ID so later calls can pass it
  back as `--subject` to append to the same case. Passing `--subject`
  explicitly always wins over minting.
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
`--max-distance=0.45` drops matches past that distance, as the chatbot and
the MCP tool do by default with `aim.settings:recall_max_distance`.

Untrusted facts (ADR-0002's addendum) are excluded unless
`--include-untrusted` is given - the interim way to review what
`aim.settings:default_trusted` is holding back, until a dedicated review
queue exists.

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

**Skill:** `.claude/skills/aim-discovery/` ("the grill") - a structured
discovery interview that distills each topic into a summary and runs it
through `aim:extract`, mostly `scope: site`.

**Skill:** `.claude/skills/aim-memory/` - reach for `aim:remember`/
`aim:recall` continuously through a session, framed as one capability
with two directions.

### `aim:consolidate` - dedup/merge sweep

```bash
drush aim:consolidate [--scope] [--provider] [--model] [--auto-threshold] [--ambiguous-threshold] [--dry-run]
```

On-demand sweep. Algorithm, schema, and thresholds in
[ADR-0005](adr/resolved/0005-consolidation-algorithm.md). Current defaults:
`auto_threshold: 0.09`, `ambiguous_threshold: 0.45`, calibrated against
`ollama__nomic-embed-text:latest` - both live in `aim.settings`, editable
at `/admin/config/aim/settings` ("Consolidation thresholds"), not just a
code constant. **Retune if the embeddings model changes** - a threshold
tuned for one model's distance distribution can silently auto-merge
genuinely distinct facts under another (hit live: 0.12 auto-merged two
related-but-distinct facts under this embedding model; pulled back to
0.09).

**Nothing is destroyed** (ADR-0005 addendum, 2026-10-01). Every outcome
that removes a fact from recall is a soft retire (`expires` set), so it
is auditable and reversible with the Un-retire action (which also clears
`superseded_by_reason`):

- `NOOP` (and the auto path): candidate retired, `superseded_by` = kept.
- `DELETE`: candidate retired, `superseded_by` left empty.
- `UPDATE`: a **new merged fact** is created and both inputs are retired
  pointing at it. The old wording survives on the retired rows. Metadata
  carried over: scope, subject/user, target and owner from the kept fact;
  `asserted` from the candidate only; `state`/`source` from the candidate
  else the kept fact; `category` the union; `trusted` only if both inputs
  were. The merged fact is queued for consolidation like any new fact.
- `superseded_by_reason` (on the retired candidate's row only; JSON,
  never fact text): `decision`, `by` (`auto` or `model`), `score`,
  `provider`, `model`. Installed by `aim_update_10004()`.

Retired facts stay out of the vector index (`aim_exclude_retired`), so
history costs no recall precision or index size. They are still personal
data: no parallel archive exists, the retention policy (TODO) is the later
answer.

**Merge verification** (2026-10-01). Guardrails check policy, not
fidelity, so an UPDATE's merged text must also pass `verifyMerge()`
before it is accepted; failure downgrades the pair to **ADD** (keep both)
and logs an audit line (`@check` = `model`, `distance` or `error`, never
text). Dry-run shows such rows as `ADD` with a non-empty "Merged text".
Settings (`aim.settings`, `/admin/config/aim/settings`):

- `merge_verify` (default on): a verifier model gets both inputs and the
  merge (`merge_verify_prompt`) and must confirm every detail survives
  and nothing is invented. Verifier errors count as failure.
- `merge_verifier_model` (`provider__model`, empty = the classifier's own
  model): set it to a different model so one checks the other. A local
  Ollama model (Ollaya) is the intended candidate, but it is not
  weight-compatible, so shadow it against the hosted verdicts first.
- `merge_max_distance` (default 0 = off): merged embedding must sit within
  this cosine distance of each input. Measured 2026-10-01 on one pair, it
  barely separates a faithful merge (0.02 / 0.11) from a lossy one
  (0.08 / 0.14), so it is a weak tripwire, not a verifier; calibrate
  before enabling.

Both check faithfulness to the inputs, not real-world truth. The shipped
consolidation prompt also now says to choose ADD when unsure
(`aim_update_10005()` adds that line to an unedited prompt and the new
keys to an existing site).

`--dry-run` still calls the model and prints the merged text in a
"Merged text" column (also for `BLOCKED`), for reviewing UPDATE fidelity
by hand. **Auto-path caveat:** the auto branch keeps the *older* fact and
retires the newer one with no model check. A synthetic "coffee machine
moved from the second to the third floor" pair scored 0.068 (under 0.09)
and was retired as NOOP, leaving the stale statement live; with
`--auto-threshold=0` the model classified it UPDATE correctly. Setting the
shipped default to 0 is not applied yet: it needs the cost measurement
(one hosted call per pair under `ambiguous_threshold`) from the TODO
baseline work.

**Automated path:** `AimConsolidateQueueWorker` (plugin ID
`aim_consolidate`) - `remember()`/`createFactsFromCandidates()` enqueue
each new fact right after save. Carries no `cron` key, so
`hook_cron`/`drush cron` never touches it - drain via:

```crontab
* * * * * ddev exec drush queue:run aim_consolidate
```

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
rejects any fact with `expires` set, and Search API deletes a rejected
item from the server. Retiring a fact is a plain `save()` that re-tracks
it, so its row goes on the next `sapi-i` (`index_directly` is off), and
clearing `expires` brings it back. `recall()`/`findNearestNeighbor()` keep
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
`recall()` (with `--subject-uid`) and user-scope `findNearestNeighbor()`
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
TODO.md and goes when the provider releases the fix, and aim ships tuned
values under the provider's own config key names rather than inventing
its own (rule and per-override table in
[ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md)). If a symptom
returns, check the alter hook still applies
(`ddev drush php:eval 'echo get_class(\Drupal::service("ai.vdb_provider")->createInstance("mariadb"));'`
should print the aim class). Bug details in
[ADR-0022](adr/resolved/0022-exclude-retired-facts-from-vector-index.md).

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

Five checks `config:status` can't do, because they're runtime DB state,
not config: vector index parity (live fact count vs. `aim_fact_vectors` row
count), orphan vector rows, the `mariadb` VDB provider plugin still
resolving to `AimMariaDBProvider`, the collection table's build-time HNSW
`M` and the server's `mhnsw_ef_search`, and `aim.settings:recall_max_distance`
actually being set rather than silently using the in-code fallback. Exits
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
  $source, ?bool $state, ...): AimFact` - validated direct write. The
  created fact's `trusted` field is not set here - it takes
  `aim.settings:default_trusted` via `AimFact::getDefaultTrusted()`, a
  base field default value callback, so every creation path (this,
  `createFactsFromCandidates()`, the entity add form) gets the same
  policy uniformly.
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
- `getAutoThreshold()`/`getAmbiguousThreshold()`/`getRecallMaxDistance()`
  - live `aim.settings` values, not the class constants (those are only
  the shipped defaults and in-code fallback). `recall_max_distance` is
  applied only when a caller passes it to `recall()` as `$maxDistance`:
  `aim_chatbot:recall` and `aim_tool`'s `aim_recall` do; `drush aim:recall`
  does with `--max-distance` and is raw otherwise.
- `getDefaultChatProvider(): ?array` - resolves the site-wide default via
  `AiProviderPluginManager`; `NULL` if none configured. `AimCommands`'
  `--provider`/`--model` options default to `NULL` and fall through to
  this, so they follow whatever the site default is.

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

### Scope/bundle model

`aim_fact`'s bundle key field is named `scope`, deliberately not `type` -
core has no single convention here (node uses `type`, media uses
`bundle`, comment uses `comment_type`, taxonomy_term uses `vid`), so
`scope` is a legitimate domain-specific choice like the others. Bundles
are real `aim_scope` config entities (`bundle_entity_type` on `AimFact`'s
`#[ContentEntityType]` attribute) - `getBundleInfo('aim_fact')` derives
automatically, no `hook_entity_bundle_info()` needed. Core `aim` ships
zero scope instances itself (ADR-0026) - the default four each come from
their own submodule (`aim_scope_user`/`role`/`site`/`case`). A fifth,
`aim_scope_entity` (ADR-0027), ships the same way and shows both halves
of the pattern in one real example: zero PHP would suffice if it only
needed `config/install/aim.aim_scope.<id>.yml` plus an `enforced`
dependency on the shipping module (needed for `ScopeUninstallValidator`
to find it - see [aim_scope_user's CLAUDE.md](modules/aim_scope_user/CLAUDE.md)
for why), but it also needs its own access rule and its own base fields
(`target_type`/`target_id`), so it adds a dedicated
`AimScopeTypeInterface` plugin (`AimScopeEntity`) the same way
`aim_scope_user`'s `requires_account` adds a `settings` entry instead for
a config-shaped (not behavior-shaped) scope difference. The plugin's
`getBaseFieldDefinitions()` (ADR-0028 piece 1) is how its own fields get
onto `aim_fact` without core `aim` hardcoding them - see
[aim_scope_entity's CLAUDE.md](modules/aim_scope_entity/CLAUDE.md) for
the field-ownership gotcha (`->setProvider()`) and the uninstall-hook
mistake that pattern surfaced.

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
scope type's own base fields (e.g. `aim_scope_entity`'s
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
module ships it off ([ADR-0015](adr/0015-immediate-consolidation-considered-deferred.md));
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

### `checkViewAccess()`/anonymous drush callers

`recall()` (via `AimMemoryManager::executeSearchQuery()`) sets
`search_api_bypass_access` for an anonymous caller (drush/cron) instead
of elevating to any particular account - a drush/cron caller has no real
"viewer" to check access on behalf of, and already holds raw DB
credentials, so the access check was never a real security boundary at
that call site. See [ADR-0006](adr/resolved/0006-agent-native-write-path.md)'s
addendum for the full reasoning (an earlier uid-1-elevation approach was
reworked away from - uid 1 has no core guarantee of existing or holding
any particular role). A real authenticated caller (Tool API/MCP, an
interactive admin) runs the query as themselves, so
`AimFactAccessControlHandler`'s real per-scope permission applies
per-result - a caller with only the flat `read aim memory` permission
cannot recall their own `scope=user` facts via `aim_recall` unless also
granted `view user aim facts`.

### User-scope role visibility

`AimScopeUser` (`aim_scope_user/src/Plugin/AimScopeType/AimScopeUser.php`,
renamed from `AimUserScopeVisibility` and moved out of core `aim` when it
became the first `AimScopeTypeInterface` plugin, ADR-0025/ADR-0026)
adds a narrower, additive grant path beyond the flat `view user aim
facts` permission: a `user_scope_role_visibility` matrix in
`aim.settings` (viewer role => visible subject roles), editable at
`/admin/config/aim/user-scope-access`, plus a
`user_scope_shared_role_fallback` boolean (default `TRUE`) granting
access when viewer and subject share any real role (excluding the
implicit `authenticated` role both accounts always carry). Only ever
returns allowed or neutral, never forbidden, so
`AimFactAccessControlHandler` ORs it against the flat permission without
risk of it revoking a grant it knows nothing about. Discovered generically
via `plugin.manager.aim_scope_type` (`Drupal\aim\AimScopeTypePluginManager`,
attribute-scanned from any enabled module's `src/Plugin/AimScopeType/`)
rather than a hardcoded `bundle() === 'user'` branch; `case`-scope access
control is deferred (no `aim_case` entity yet, Nik's call).

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
options, `ai_assistant_api.ai_assistant.aim_demo_assistant`'s
`llm_provider: '__default__'`, and `search_api.server.aim_vector`'s
`backend_config.chat_model` (chunk token-sizing only, no `->chat()` call
- cost-neutral either way) all follow it. `embeddings_engine` has no
`__default__` equivalent - it's a plain provider/model ID on
`search_api.server.aim_vector`'s `backend_config`, needs a manual update
on any embeddings swap. Anthropic has no embeddings API - never point
`embeddings_engine` at it.

**Local Ollama** (`ai_provider_ollama`): points at the *host's* Ollama
install (`http://host.docker.internal:11434`), not a container-local one
- don't run a second Ollama daemon in its own DDEV addon container
alongside this, it won't share the host's model cache. Host-side
prerequisite: `OLLAMA_HOST=0.0.0.0:11434` on the host's Ollama service
(Ollama defaults to `127.0.0.1`-only, unreachable from the DDEV network).

A Claude Pro/Max subscription cannot power an unattended `drupal/ai`
provider (Anthropic prohibits subscription OAuth for third-party
integrations) - needs a real Console API key, or stays on Ollama.

**Running local model services on a small machine** (Ollama, and Ollaya
for the plausibility spike, [ADR-0033](adr/0033-plausibility-gate-processing-modes.md)).
Measured on the dev laptop (14 GB RAM, no GPU, 4 GB swap), where
unbounded services OOM-killed the editor on 2026-09-28. Both run as
systemd services; limit them with drop-ins (`sudo systemctl edit <unit>`),
then check with `systemctl show <unit> -p MemoryPeak -p MemoryMax`.

| | Ollama (`nomic-embed-text`) | Ollaya (`laya:en`) |
| --- | --- | --- |
| Measured working set | ~650 MB peak | ~3.8 GB resident, up to ~4.3 GB on full-context input |
| `MemoryMax` | `1G` | `5G` |
| `MemoryHigh` | not set | `4500M` |
| `OOMScoreAdjust` | `500` | `500` |
| Keep-alive | `OLLAMA_KEEP_ALIVE=30s` | `OLLAYA_KEEP_ALIVE=10m` while testing (default `5m`) |
| Listens on | `0.0.0.0:11434` (DDEV needs it) | `127.0.0.1:11435` |

- **`OOMScoreAdjust=500`** makes the kernel pick these services before
  the editor when memory runs out. A `MemoryMax` hit kills only the
  service.
- **Set `MemoryHigh` from a measured peak, not a guess.** It is a soft
  limit that throttles instead of killing, so a cap below the working
  set does not fail, it just makes the model load slowly (Ollaya's cold
  load was 36 s at 2G, 21 s at 2.5G, 7 s at 3.5G and above). A
  `MemoryPeak` equal to the cap means the cap is binding.
- **Apply one edit at a time and restart.** `systemctl set-property
  --runtime` changes the limit live but does not unload the model, so a
  timing test right after it measures a warm model. Restart the unit,
  then time the first call.
- **Do not use `systemd-zram-generator`** on the laptop: extra CPU, and
  it interferes with suspend. The existing swap file is the backstop.
- **Keep-alive trades load time for memory.** A short value frees RAM
  between uses; a long one (10m+) avoids repeated cold loads during
  testing. The memory cap, not keep-alive, is what protects the editor.
- **CPU-bound without a GPU.** Parallel requests to Ollaya queue rather
  than speed up, so one worker at a time is the right setting. Input
  over the model's context window is rejected (`STATE_TRUNCATED`), so
  bound fact length on write.
- **Available, not free.** `free -h` "free" excludes disk cache; use the
  "available" column to judge headroom.
- **Browsers dominate.** On the same machine Brave used ~7.9 GB with many
  tabs, more than either model service.

Quick Ollaya check (host, not DDEV):

```bash
curl -s localhost:11435/v1/models
curl -s localhost:11435/v1/systemone -H 'content-type: application/json' \
  -d '{"model":"laya:en","state":"Some fact.","questions":{"q":{"type":"noul","instructions":"Is this plausible?"}}}'
```

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
5. **Per-site choices, not defaults:** `index_directly` on if embeddings are
   local; `recall_max_distance` recalibrated to your dataset (ADR-0019).
6. Run `drush aim:status` to confirm steps 1-4 above actually took (it
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

## Logging

Channel `aim` (service `logger.channel.aim`). Three tiers:

- Standard, always on: warnings and errors only (guardrail/validation
  rejections in `remember()` and `createFactsFromCandidates()`, `BLOCKED`
  consolidation merges, the queue worker's suspend cause).
- `aim.settings:log_audit` (info): writes, consolidation decisions,
  trust/untrust/retire/unretire actions.
- `aim.settings:log_verbose` (debug): recall, embedding cache, extraction.
- `aim.settings:log_query_text` (debug, needs `log_verbose`): adds the
  recall query text, for diagnosing poor or empty matches. Off by default;
  the only place any user-supplied text is logged.

Gate info/debug calls through `AimMemoryManager::logAudit()` /
`logVerbose()`; the embedding cache subscriber reads `log_verbose` itself
(the manager depends on it, so it cannot depend back). Never log fact text
or, outside `log_query_text`, recall query text (personal data, and guardrail violation messages can
quote it): IDs, scope, uid, subject, decision, score, auto-versus-model,
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
  `administer aim memory`) - table of every fact, "still live" for an
  empty `expires`, `uid` ("Extracted by") and `subject`/`user`
  ("about") both shown, `trusted` shown and editable inline via the
  Edit link - this is the review queue ADR-0002's addendum leans on, not
  a bespoke UI. Per-row View/Edit/Delete dropbutton and bulk delete.
- `/admin/content/aim-facts/add` - bundle picker, then a standard
  `ContentEntityForm` per scope (`/admin/content/aim-facts/add/{aim_scope}`).
  Gated by the per-scope `create {scope} aim facts` permission (or
  `administer aim memory`).
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
- `/admin/config/aim/user-scope-access` - the role-visibility matrix, see
  "User-scope role visibility" above.
- `/admin/config/aim/scopes` - add/edit/delete `aim_scope` entities.

---

## Recipes

`recipes/<name>/` holds Drupal Recipes for sites that want a starting
point. Apply one from the site root (recipes are applied by path):

```bash
ddev exec vendor/bin/dr recipe:apply web/modules/custom/aim/recipes/<name>
```

- **`aim_demo_library`**: a fictional community library (site identity,
  chat persona, recall cutoff, `index_directly`) plus `facts.json`, loaded
  afterwards with `drush aim:remember --file`. The first archetype starter
  kit ([ADR-0014](adr/0014-usecase-archetype-starter-kits.md)). Details and
  caveats in its [README](recipes/aim_demo_library/README.md).
- **Facts are not recipe content.** A recipe's default content saves
  entities directly, and it is unverified whether that runs Guardrails,
  embedding and indexing. `aim:remember --file` goes through `remember()`,
  so they always do.
- **No `aim_mcp` recipe, deliberately.** The remote-MCP stack is already
  shipped: `aim_tool` carries the tool configs, `aim_tool_oauth` the
  scopes and the tool-gating settings, and the `simple_oauth`/`mcp_server`
  defaults match what this site runs, apart from the key paths and the
  `server_instructions` text. What is left is manual (key generation,
  HTTPS exposure, the `provide` composer workaround), which a recipe
  cannot do. See `aim_tool_oauth`'s [DEVELOPING.md](modules/aim_tool_oauth/DEVELOPING.md).
- **Validate without applying** (read-only, catches schema errors and
  config actions aimed at extensions that aren't installed):

  ```bash
  ddev drush php:eval '\Drupal\Core\Recipe\Recipe::createFromDirectory("/var/www/html/web/modules/custom/aim/recipes/<name>");'
  ```

  No output means valid. That is all that has been checked so far: no
  recipe here has been applied to a site.
- Config actions on config entities use `setProperties` (dotted keys reach
  nested values without replacing the rest); `simpleConfigUpdate` is for
  simple config like `system.site` and `aim.settings`.

---

## Default content export

```bash
vendor/bin/dr content:export aim_fact <id>
```

Not `web/core/scripts/drupal` - its autoload path assumes `vendor/`
inside the docroot, wrong for this project's layout. Pure read of entity
field data - never touches search_api/the vector collection table;
reindex after import is required regardless. Don't use
`--with-dependencies` to carry `uid` through - the exporter includes the
**pre-hashed password** on any exported user account. Anonymous
authorship on re-import is accepted, not a problem to solve.
