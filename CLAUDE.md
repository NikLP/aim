# CLAUDE.md

Operating guide for Claude Code sessions in this repo. See
[README.md](README.md) for the project pitch and
[ADR-003-drupal-native-agent-memory.md](../../../../ADR-003-drupal-native-agent-memory.md)
for full rationale - this file is the condensed, actionable version of that
ADR plus general dev process. Don't duplicate the ADR's reasoning here; link
to it.

## Project snapshot

Drupal-native AI agent memory infrastructure. **Distinct venture** - not a
feature of, or dependency on, any sibling suite, even where the ADR draws
architectural comparisons to one. Status: a working PoC exists - real
`aim_fact` entity, real vector search, real embeddings via amazee.ai. See
"Vector search is working end to end" below for the concrete config.

**Scope stays broad; the current build track is one exploration, not a
narrowing.** Work so far follows one path from the ADR's scope model:
conversational input, spoken or typed, turned into extracted facts (the
"mouth-to-website" track, informally). That's a convenient concrete path to
build against first, not a decision to drop the other three categories -
role memory (e.g. what an editorial team collectively knows), site memory
(e.g. a support knowledge base), and general per-user/per-developer memory
all remain equally in scope. Don't let future work assume this project is
"the conversational site-builder thing" - it's the memory infrastructure
underneath several use cases, and this track is just the first one being
exercised end to end.

Environment: DDEV, `drupal11` type, PHP 8.4, MariaDB 11.8, docroot `web/`.

**Installed so far (2026-09-08):** `drupal/ai_provider_amazeeio` (pulled in
`drupal/ai`, `search_api`, `config_ignore` as dependencies) - composer-present
but not yet enabled or configured, amazee.ai account/API config still being
set up. Custom module `aim` (`web/modules/custom/aim`) is
enabled, providing the `aim_fact` content entity - proven with a real
save/load via `drush php:eval`. Not yet added: `ai_vdb_provider_mariadb`,
`ai_provider_ollama`, `tool`/`mcp_server`.

**PoC deviations from the binding decisions below (temporary, not a
redesign):** at the user's direction, the governance layer is deferred to
keep the first slice small. `aim_fact` has no moderation state, no
Guardrails call, and no draft-to-trusted gate - every fact is live the
moment it's saved. It also uses one flat `scope` list field (user/role/
site/case) rather than four separate bundles. Both are meant to be added
back, not abandoned: re-introduce Content Moderation + Guardrails before any
non-PoC data goes in, per decisions 3, 7 and 8. `no-update-hooks-yet` still
applies, so this is a cheap reinstall away from becoming bundle-per-scope
whenever that's worth doing.

**Correction to the storage decision below:** `ai_vdb_provider_mariadb` does
not expose a Field API field type to attach a `VECTOR` column directly to an
arbitrary entity (verified against its source - it only ships a `VdbProvider`
plugin, `MariaDBProvider`/`MariaDBVectorClient`, no `FieldType` plugin
anywhere in the module). The vector lives in a separate "collection" table
the provider manages itself, keyed by item ID, populated through a Search
API index configured with the AI Search backend (from `ai`'s bundled
`ai_search` submodule).

**Vector search is working end to end (2026-09-08).** Server `aim_vector`
(backend `search_api_ai_search`, VDB provider `mariadb`, embeddings engine
`amazeeio__titan-embed-text-v2:0`, 1024 dimensions, embedding strategy
`contextual_chunks`), index `aim_vector_index` over the `entity:aim_fact`
datasource, collection/table `aim_facts`. `text` is indexed as `main_content`
(goes into the vector + the `content` metadata column); `scope`, `subject`,
`source` are indexed as `attributes` (each gets its own metadata column,
filterable, not embedded). `index_directly` is off - **decision, not just
current state**: inline indexing calls the embedding API synchronously
inside whatever request saved the fact, which is fine for a drush script but
wrong the moment a fact gets created from a live web request (blocks the
response on an external API call, and fights decision 4's async
requirement). Default stays off; index via `drush search-api:index
aim_vector_index`, on cron, or on a dedicated crontab entry once there's a
real write path. Only flip it on for a specific queue-worker-driven write
that's already off the request path anyway. Proven with a real query through
`$index->query()->keys(...)->execute()` returning the correct fact with a
cosine score.

Two non-obvious things hit while wiring this up, worth knowing before
touching this config again:

- **Per-field indexing role (main content vs. attribute) lives in a separate
  simple config, not the index entity itself:** `ai_search.index.<index_id>`,
  keyed by field machine name, each entry `{field_name, indexing_option}`
  where `indexing_option` is `main_content` / `contextual_content` /
  `attributes` / `ignore`. Skip this and every field is silently ignored at
  embedding time - no error, just an empty embedding.
- **The collection table only gets created/ALTERed on index *update*, not
  *create*:** `ai_vdb_provider_mariadb`'s `hook_search_api_index_update()` is
  what calls `createCollection()` + `updateFields()` (the `ALTER TABLE ADD
  COLUMN` for attribute fields). Creating the index entity via `Index::
  create()->save()` fires an insert, not an update, so that hook never runs
  and the table ends up with only the native columns (`content`,
  `drupal_entity_id`, `embedding`, etc.), missing every attribute column.
  Fix: save the index a second time after creating it. Also,
  `createCollection()` is not actually idempotent despite its docstring
  claiming otherwise - re-running it against an existing table throws an
  uncaught `mysqli_sql_exception` ("Table already exists") instead of the
  `CreateCollectionException` its own catch block expects. Drop and recreate
  the table if this happens rather than fighting it; there's nothing
  irreplaceable in a vector collection, it's a derived index.

**Confirmed `aim_facts` has a real ANN index, not a brute-force scan
(2026-09-09)**, checked directly against the running DB rather than assumed:
`SHOW CREATE TABLE aim_facts` on this DDEV site (MariaDB 11.8.9) shows
`` VECTOR KEY `embedding` (`embedding`) `DISTANCE`='cosine' `` -
MariaDB 11.7+'s native HNSW-based approximate-nearest-neighbor vector index,
created automatically by `ai_vdb_provider_mariadb`'s `createCollection()`,
no manual step needed. This directly answers a scale worry raised the same
day: whether `aim` growing into thousands of facts (e.g. a grilling-skill
session run at volume) would degrade query performance on a site sharing
its DB/CPU with a live working site. It won't, on the similarity-search
side: HNSW is built for exactly this scale (hundreds of thousands to low
millions of vectors on modest hardware), so raw query cost isn't the
limiting factor here. The earlier DB-pressure note under "Ideas raised, not
designed" (`aim_facts` ~50% bigger per row than `aim_fact`) is still true and
still worth watching, but as a storage/IO capacity question, not evidence
the index degrades under load.

**What would actually strain the shared DB/CPU at real volume is the
write-path workload, which isn't built yet - not the vector engine.** Per
the AI dependency map, every fact write costs an embedding call regardless
of volume, and decision 7's Guardrails can add an LLM call per candidate
fact - both real costs that scale linearly with fact count, unlike HNSW
query cost. A grilling session naively writing one raw fact per sentence at
volume would hit embeddings/Guardrails rate limits and cost before it ever
troubled MariaDB, and would also flood `aim_fact` with near-duplicate rows
with no way to merge them at scale - `drush aim:consolidate` (see
"Consolidation" below, built 2026-09-09) covers the on-demand phase 1 case,
but nothing runs it automatically yet. So: route real volume through
decision 4's queue, with consolidation wired into that same worker, rather
than pointing a real grilling session at high fact throughput and sweeping
by hand after the fact - that's the actual scale risk in "thousands of
facts in days," not the database or the bundle/scope model discussed above.

**Storage math and pattern-recognition, asked the same day (2026-09-09).**
Back-of-envelope from the measured numbers above (9 `aim_fact` rows =
0.06 MB, 8 `aim_facts` rows = 0.14 MB): roughly 7KB/row on the entity side,
18KB/row on the vector side (`VECTOR(1024)` as float32 is 4KB of raw vector
data alone; the rest is HNSW graph overhead plus the attribute columns). At
1,000 facts that's roughly 7MB + 18MB; at 10,000, roughly 70MB + 180MB -
both trivial for a shared DB host, nowhere near "concern" territory. Disk
space only becomes a real line item in the hundreds-of-thousands-to-millions
range, a couple of orders of magnitude past what a grilling-session-driven
store would plausibly reach. What would show up first at real volume isn't
raw disk, it's `aim_facts` outgrowing the shared host's InnoDB buffer pool -
HNSW's query-speed advantage depends on the index staying memory-resident,
so a large vector table competing for buffer-pool room alongside a live
site's own working set is the more realistic pressure point than disk
filling up. Worth checking once fact count reaches the tens of thousands,
not now.

Yes, this is a recognized pattern, not something unusual: structured
records in the system of record (`aim_fact`) plus a separate vector index
keyed by entity ID for semantic retrieval is the standard shape of RAG/
vector search in production - Postgres+pgvector, Elasticsearch's
`dense_vector`, or an app DB paired with a dedicated vector store like
Pinecone/Weaviate/Qdrant are all the same two-part shape, all commonly
HNSW-based ANN under the hood, same as MariaDB's native vector index here.
The one less-mainstream choice is putting the vector index *inside* the
same relational DB as the app data rather than a dedicated vector-store
service - MariaDB's native `VECTOR` type is genuinely new (11.7, 2025) -
but that choice is what buys decision 1's actual goal (in-database mode,
ACID/transaction guarantees with the rest of Drupal intact), a real
advantage over the dual-write consistency problem most RAG stacks have to
manage by hand between an app DB and a separate vector store.

**Config now ships in `config/install`, not just the live site (2026-09-09).**
`search_api.server.aim_vector`, `search_api.index.aim_vector_index` and the
simple config `ai_search.index.aim_vector_index` were all hand-built
directly against this DDEV site (drush, the UI) and only lived in the
database. They're now exported into
`web/modules/custom/aim/config/install/`, so `drush en aim` on a fresh site
reproduces the same server/index/field setup instead of requiring the same
manual steps again. Two things had to happen alongside the export, not just
a file copy:

- `aim.info.yml` gained `ai:ai_search` and
  `ai_vdb_provider_mariadb:ai_vdb_provider_mariadb` as dependencies - the
  exported server config's own `dependencies.module` needs both, and config
  import fails on an unmet dependency. Deliberately did **not** add
  `ai_provider_amazeeio` as a hard dependency even though the shipped
  `backend_config` hard-codes amazee.ai plugin IDs (`chat_model`,
  `embeddings_engine`) - decision 5 keeps Ollama as an equal option, so
  forcing amazee.ai as a module dependency would be wrong for a
  sovereign-only deployment. The shipped config is a starting default, not
  a contract: a site running pure Ollama overrides
  `search_api.server.aim_vector`'s `backend_config` after install to point
  at its own provider's plugin IDs.
- Added `aim.install` with a `hook_install()` that reloads and re-saves the
  `aim_vector_index` entity. This exists purely to trigger the
  create-vs-update gotcha's own documented fix (two paragraphs up)
  automatically: config/install's module-enable import does a single insert,
  same as `Index::create()->save()`, so without this the collection table
  and its attribute columns would silently not exist after a fresh install,
  exactly as when this was first built by hand.

Not yet verified by an actual fresh-install run - doing that on this site
would mean `drush pmu aim` first, which drops the `aim_fact` base table and
destroys the 9 real fact rows and their vector data that exist right now.
That's exactly the kind of data the "Schema/config changes during early
development" rule below stopped covering once the `state` field went in;
don't reinstall this module to test the export without asking first, and
prefer a disposable environment for that test over this one.

Not "a Recipe" deliberately: a Drupal Recipe is a one-time,
`drush recipe apply`-driven bundle, and decision 8 already gives "recipe"
its own specific, higher-stakes meaning here (an AI-generated site-building
recipe behind a dry-run/review gate). Using the same word for "the config
this module ships with" would conflate two different things. `config/install`
is also just the ordinary mechanism for "this config should exist whenever
this module is enabled," which is what's actually needed here.

**Exporting real fact rows as default content: works, tested 2026-09-09,
doesn't touch the vector index.** Core (not the old contrib `default_content`
module - this is Drupal 11's own experimental `Drupal\Core\DefaultContent`
system) ships `content:export`/`content:import` commands. Correction to how
to invoke them: `web/core/scripts/drupal` (the form the annotations project
documents) fails in this repo's layout - its fallback autoload path assumes
`vendor/` sits inside the docroot, but this project has `web/` as docroot
with `vendor/` one level up, so it throws
`Failed opening required '.../web/vendor/autoload.php'`. Use
`vendor/bin/dr` instead (`ddev exec /var/www/html/vendor/bin/dr
content:export aim_fact <id>`), the composer-installed shim that sets the
autoload path correctly - same command set, this repo's layout just needs
the other entry point.

Confirmed empirically, not just from reading the exporter's source: it's a
pure read of entity field data (`$entity->getFieldDefinitions()`), so it
cannot touch `search_api` or the `aim_facts` vector collection table -
exported facts 1 and 9 while both were live-indexed, nothing in either
table changed. `state` round-trips cleanly: fact 9 (state TRUE) exported a
real `state: [{value: true}]` key; fact 1 (state NULL) omitted the `state`
key from the YAML entirely, rather than emitting `value: null` - clean,
unambiguous, matches the field's own "absent means not a flag" semantics.

One real gotcha before actually shipping this as module content: the `uid`
owner reference exports as `target_id: 0` (anonymous) for both facts,
because neither export pulled in `--with-dependencies`. Without that flag,
a reference to an entity that isn't part of the export set gets flattened
to a dummy/zero target rather than carried through some other way - so
re-importing these YAML files anywhere would silently reassign every
fact's owner to Anonymous. `--with-dependencies` would fix that by also
exporting the referenced `user` entity, but the exporter deliberately
includes the **pre-hashed password** on any exported user account (see
`Exporter::export()`), which is exactly wrong for an admin/personal
account ending up in a git-committed module. Don't reach for
`--with-dependencies` here; if authorship needs to survive export, that's
a reason to reconsider what `uid` should even reference for demo/PoC
facts, not a reason to export real user accounts. **Settled 2026-09-09:
exported facts coming back in as Anonymous is fine, not a concern worth
solving.**

**No equivalent way to export the vector data itself (2026-09-09).**
`content:export` only knows about content entities; `aim_facts` (the
MariaDB collection table `ai_vdb_provider_mariadb` manages) isn't one - it's
a derived index the same way a search index or a cache table is, outside
Drupal's Entity/Field API entirely, so core's DefaultContent system has no
way to reach it, and never will without aim writing its own exporter for
it specifically. That's not a gap worth closing at current scale: "cheap
and local-friendly" (AI dependency map above) describes the per-call cost
and the option to run with zero marginal $ (Ollama), **not** that
reindexing is free - correcting the framing here, 2026-09-09, called out
correctly: reindexing after import is a genuine from-scratch recompute,
every fact's text gets re-sent to the embeddings engine and a fresh vector
gets stored, nothing carries over from the export. At 9 facts that's
trivial; at real volume it's a real batch job whose time scales with fact
count, and whose $ cost scales too if pointed at a paid provider rather
than local Ollama. Reindexing after import (`drush search-api:index
aim_vector_index`, or `--index` on a fresh `aim:extract`) is still the
right default - a bespoke dump/restore would also have to remap
`drupal_entity_id` (keyed by the serial ID, which changes across
environments/imports - see below) from the old ID to whatever the
newly-imported entity gets, matched via UUID, real custom tooling either
way. Revisit building that tooling specifically once fact volume or a paid
embeddings bill makes "just reindex" a real cost, not while it's still a
handful of facts.

The serial `id` and the entity's own `uuid` key are deliberately excluded
from the exported field data too (core's own design, not aim-specific) -
`_meta.uuid` is what identifies the entity on import instead. That also
means the vector collection table's `drupal_entity_id` (keyed by the
serial ID, e.g. `entity:aim_fact/9:en` - confirmed via `DESCRIBE
aim_facts`) has no relationship to what gets exported: importing this
content anywhere (a fresh site, or back into this one) will not
reproduce any vector rows, by design. That's expected, not "breaking" the
index - reindex after import (`drush search-api:index aim_vector_index` or
`--index` on a fresh extract), same as any other newly-written batch of
facts.

**`aim_fact` gained an optional `state` boolean field (2026-09-09).** Not
every fact is a flag, so it's nullable - set it when a fact is itself an
on/off assertion (e.g. "opted out of marketing email" = TRUE), leave it
unset for plain prose facts. Applied via
`\Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition()`
rather than a reinstall, because there was real data by this point (8 facts,
already indexed) that a reinstall would have destroyed - this is exactly the
moment the "reinstall, no update hooks" rule up in "Schema/config changes
during early development" said to stop applying. `getFieldStorageDefinitions()`
gives the storage definition object the method needs; `updatedb:status`
does *not* surface pending entity-definition changes (it only covers
`hook_update_N`/post_update) - check `\Drupal::entityDefinitionUpdateManager()
->needsUpdates()` / `->getChangeSummary()` directly instead. Verified: all 8
existing facts survived with `state` NULL, new facts can set it.

**NULL is sufficient for "not a state fact," confirmed both ways
(2026-09-09).** Live-tested the unset direction too, not just "never set":
loaded fact 9 (`state` TRUE), set it to `NULL` and saved, confirmed the raw
DB column read back `NULL`, then set it back to `TRUE` and confirmed that
too - `$fact->set('state', NULL)` genuinely clears the field rather than
coercing to `FALSE`, symmetric with a fact that never had `state` set in
the first place. So yes: unset (`NULL`) means "this fact carries no
boolean assertion," `TRUE`/`FALSE` mean it does, and flipping a fact back
to unset later (e.g. a flag that turns out not to have been a flag) is a
plain field update, nothing special required.

See "Ideas raised, not designed" below for the taxonomy idea this pairs
with: this boolean field covers true on/off flags; a small curated set of
named values (contact preference, a "traits" tag) still wants a taxonomy
term reference, not this field.

## Architecture decisions (binding until superseded by a new ADR)

1. **Storage:** entities with `VECTOR` fields via `ai_vdb_provider_mariadb`,
   in-database mode only (not the external-DB option - it breaks Drupal's
   transaction guarantees).
2. **Scope model:** four bundles - user, role, site, case (support tracking)
   - each with its own retention/visibility rules, composable at retrieval.
3. **Governance:** Drupal permissions/roles for access control; Content
   Moderation for provenance/audit and a draft-to-trusted human-review gate.
   Nothing extracted by an LLM is auto-trusted on write.
4. **Processing:** Queue API + a dedicated crontab entry, separate from
   `hook_cron`. Don't mix extraction/consolidation workload into general
   site cron - a long extraction run can starve unrelated housekeeping
   tasks. Only add a dedicated async queue worker (its own crontab entry,
   1-5 min cadence) if a feature genuinely needs low-latency inline
   extraction during a live chat turn.
5. **Sovereignty:** extraction/consolidation must support a local model via
   `drupal/ai`'s Ollama-class provider. Embeddings may use a smaller local
   model regardless of sovereignty tier, purely on cost grounds.
   `drupal/ai_provider_amazeeio` (amazee.ai's hosted "Private AI" provider)
   is installed as a second, non-local reasoning/embedding option - use it
   where convenient, but it doesn't satisfy the local/self-hosted
   requirement above for a genuinely sovereign deployment. Don't drop
   `ai_provider_ollama` from the plan on account of it; add both.
6. **Build order - PoC before any provider is configured.** Prove schema and
   consolidation logic with an interactive Claude Code session doing the
   reasoning and a local Ollama container (DDEV service) doing embeddings.
   Zero API keys, zero recurring cost. Don't wire an unattended `drupal/ai`
   provider until that's proven.
7. **Guardrails is mandatory, not optional.** Every candidate fact the
   extraction step proposes runs through `drupal/ai`'s Guardrails submodule
   (input/output filtering) before it's written anywhere - ahead of the
   draft-to-trusted human-review gate, not instead of it. Treat every
   LLM-proposed fact as untrusted input by default.
8. **Generative/planning surface** (spec → Recipe → built site) is in scope
   as a second product surface. Its own hard rule: **no `drush recipe apply`
   on an AI-generated recipe without a dry-run/human-review step first** -
   this mutates live site structure, a different and higher-stakes risk than
   writing a memory fact. **Confirmed 2026-09-09: a Tool API implementation
   already exists** - `mcp_tools_recipes` (submodule of the `mcp_tools`
   project, 352 installs) ships `CreateRecipe`, `ValidateRecipe`,
   `ApplyRecipe`, `GetRecipe`, `ListRecipes`, `GetAppliedRecipes` as real
   Tool API plugins. Not installed, not vetted for the dry-run/review gate
   above - beta-only releases, no official security-advisory coverage yet,
   pulls in a fairly heavy dependency chain (pathauto, metatag, webform
   among 13 deps). Check whether it already implements a dry-run pattern
   before building one from scratch, when this surface actually gets built.

## AI dependency map

Not one "AI" - several steps with different cost/sovereignty profiles. Check
this before assuming a step needs a paid API call:

| Step | Needs | Cost |
| --- | --- | --- |
| Discovery conversation, extraction, conflict/merge decisions, Recipe generation, annotation generation | Reasoning-grade LLM | Expensive |
| Consolidation - similarity-threshold cases | Vector math only | Free |
| Embedding generation (every write and every query) | Small dedicated embedding model | Cheap, local-friendly (Ollama) |

A Claude Pro/Max subscription cannot power the unattended `drupal/ai`
provider - Anthropic prohibits subscription OAuth for third-party
integrations. That path needs a real Anthropic Console API key (or stays on
local Ollama). Subscription quota only covers Claude Code/claude.ai/Desktop
sessions, i.e. the interactive PoC reasoning step, not the shipped product's
cron-driven extraction.

**No free tier on the Anthropic Console API, confirmed by a real call
(2026-09-09).** `ai_provider_anthropic` is installed, enabled, and
correctly configured (a real `sk-ant-api03-...` key via the `key` module,
`getConfiguredModels('chat')` lists the current Claude lineup fine). With
zero credit purchased on the account, even a single one-word chat request
to the cheapest model (`claude-haiku-4-5-20251001`) was rejected outright
with `Drupal\ai\Exception\AiQuotaException`: "Your credit balance is too
low to access the Anthropic API" - Anthropic's API rejecting the request
pre-flight, before generating or billing any tokens, not a Drupal-side
wiring problem. Confirms there is no free quota to prototype against here,
unlike Ollama (free, local) or amazee.ai (already working on this project).

**Credit added same day, provider now live.** The identical call
(`claude-haiku-4-5-20251001`, one-word prompt) now returns a real
completion instead of `AiQuotaException`. `ai_provider_anthropic` is a
working third chat/reasoning option on this site as of 2026-09-09,
alongside amazee.ai and (once configured) Ollama - decision 5's sovereignty
point still stands, this is a second non-local option, not a local one.

**Default reasoning provider swapped from amazee.ai to Anthropic, same
day, once credit made it usable.** `ai.settings`'s site-wide default chat
provider was already `anthropic`/`claude-sonnet-5` by this point (set
during the module setup above). Two more `amazeeio` references still
needed updating by hand, both chat-only, not embeddings - **Anthropic has
no embeddings API at all**, confirmed via `$provider->isUsable('embeddings')`
returning `FALSE` (Anthropic has never shipped one; they point people at
Voyage AI). Don't try to point `embeddings_engine` at `anthropic` - it is
structurally not possible, not just unconfigured.

- `AimCommands::extract()`/`consolidate()`'s PHP default option arrays -
  `provider`/`model` now default to `anthropic`/`claude-sonnet-5` instead
  of `amazeeio`/`claude-4-5-sonnet` (a stale model id besides).
- `search_api.server.aim_vector`'s `backend_config.chat_model` -
  `amazeeio__claude-4-5-haiku` to `anthropic__claude-sonnet-5`. This one is
  a genuine chat call (the `contextual_chunks` embedding strategy uses a
  chat model to write a contextual blurb per chunk before embedding it),
  not embeddings, so it's swappable - unlike `embeddings_engine` on the
  same config, which stays on amazee.ai (still broken, see "No equivalent
  way to..." above's sibling note on the recall bug). Updated in both the
  live config and the shipped `config/install/search_api.server.
  aim_vector.yml`, so a fresh install matches what's actually running here
  rather than drifting. Deliberately did **not** add `ai_provider_anthropic`
  as a hard `aim.info.yml` dependency for this, same neutrality reasoning
  already applied to `ai_provider_amazeeio` above - it's a second non-local
  provider, not a special case.

**`AimCommands`' hardcoded `'anthropic'`/`'claude-sonnet-5'` PHP defaults
removed the same day, once they'd already needed hand-editing twice.**
`extract()`/`consolidate()`'s `--provider`/`--model` options now default to
`NULL`; a new `AimMemoryManager::getDefaultChatProvider()` (a thin wrapper
over `AiProviderPluginManager::getDefaultProviderForOperationType('chat')`,
the exact same call `ai_assistant_api`'s own `AiAssistantApiRunner::
getProviderAndModel()` makes for its `__default__` case) resolves
`ai.settings`' site-wide default chat provider instead, via a new shared
`AimCommands::resolveChatProvider()` helper, erroring clearly if neither an
explicit option nor a site default is available. These commands now follow
whatever the site-wide default is set to, rather than needing a code change
every time it changes - closing the exact class of drift this session hit
twice already (see the two provider-swap notes above).

**Real gotcha hit swapping this, not a config mistake: Anthropic's
structured-output mode is stricter than amazee.ai's Bedrock-backed one.**
The same `extractFacts()`/`classifyPair()` calls that already needed an
explicit top-level `'type' => 'object'` for amazee.ai (see the gotcha under
"Extraction" below) failed against Anthropic with `response_format.
json_schema.schema: For 'object' type, 'additionalProperties' must be
explicitly set to false` - Anthropic requires `additionalProperties: false`
on *every* object level of the schema, not just the top one. Fixed by
adding it to both the top-level schema and the nested `items` object in
`extractFacts()`'s facts-array schema, and the top-level schema in
`classifyPair()`'s decision schema (`AimMemoryManager.php`). This is a pure
addition to the schema (a stricter, still-valid JSON Schema constraint),
not an Anthropic-only branch, so it doesn't regress the amazee.ai path -
untested against amazee.ai after the change, but nothing about the fix is
provider-conditional. Verified against real Anthropic calls after the fix:
`extractFacts()` returned a correctly-shaped fact from a real sentence,
and `classifyPair()` returned a correct NOOP decision on a real
near-duplicate pair (two disposable test facts, created and deleted in the
same check, not left in the store).

**The amazee.ai embeddings bug (the one blocking `aim_recall` since earlier
this session) is fixed, same day - root cause was the account's model
lineup changing, not a lingering config mistake.** `titan-embed-text-v2:0`
is gone from the account entirely; `mistral-embed` is now the only
embeddings-shaped model available (confirmed against the account's current
model list - everything else offered is chat, vision, transcription, or
image-gen). `search_api.server.aim_vector`'s `backend_config.embeddings_engine`
changed to `amazeeio__mistral-embed`, in both the live config and shipped
`config/install/search_api.server.aim_vector.yml`. `mistral-embed` outputs
1024-dim vectors, confirmed by a real call before changing anything -
matches the existing `VECTOR(1024)` column exactly, no schema change
needed.

**Real lesson on how this got diagnosed, worth remembering:** a raw `curl`
straight to amazee.ai's `/v1/models` with the stored key's raw value
(extracted via the `key` module purely for that one diagnostic call)
returned a `401`/"Invalid proxy server token" - looked like a stale
credential. It was a red herring. The actual representative test - calling
`$provider->embeddings(...)` through Drupal's own `ai.provider` service,
the exact code path `aim` itself uses - worked fine with the same stored
key, no auth error at all. `/v1/models` is evidently a separate, stricter
(or just differently-behaved) endpoint on amazee's gateway than the
completion/embeddings endpoints actually used; testing against it directly
was testing the wrong thing. The general principle holds and is worth
keeping: test AI provider behavior through Drupal's own provider
abstraction, not by extracting the raw key and calling the third-party API
directly - not just because it's the more representative test, but because
in this case the two paths gave genuinely different, contradictory
answers, and the direct-API one was the misleading one.

**Reindexing after this hit both collection-table gotchas already
documented above, back to back, in a new context (`search-api:clear` +
`search-api:index`, not a fresh module install this time) - same fixes
applied, worth confirming the documented remedies still hold under a
different trigger:** clearing the index left `aim_facts` with only its
native columns (`scope`/`subject`/`source`/`text` gone), reproducing "the
collection table only gets created/ALTERed on index *update*, not
*create*." Re-saving the index entity to trigger the fix hit the second
documented gotcha immediately - `createCollection()` throwing "Table
'aim_facts' already exists" instead of being idempotent. Same remedy as
before: `DROP TABLE aim_facts`, re-save the index entity (recreated with
every attribute column present), then `drush search-api:index
aim_vector_index` - 10/10 items indexed successfully under the new
embeddings engine.

**Full pipeline verified end to end after all of this, not just
individual pieces:** `drush aim:recall "email preference"` returned real,
sensibly-ranked results (the two actual email-preference facts scored
well ahead of everything else). Then closed the loop on the chatbot path
specifically, since that's exactly where recall failed earlier in this
session - asked `aim_demo_assistant` "When is the site down for
maintenance?" in a fresh `/api/deepchat` request, and it correctly
recalled the fact remembered earlier in this same session ("closes for
maintenance every Sunday morning") and answered from it. Remember and
recall both now work through every consumer that matters: CLI, and the
live chat widget.

**Cost of un-deferring decision 3's governance layer, raised 2026-09-09
because `aim` runs beside an existing site sharing its DB/CPU, not on
dedicated infrastructure.** The two halves have very different cost
profiles and shouldn't be lumped together as one worry:

- **Content Moderation's own footprint is cheap.** A `moderation_state`
  field plus a revision table and one extra row per save is the same
  mechanism `node` uses at sites with orders of magnitude more content and
  more revisions per item than this will ever see at PoC/small-deployment
  scale - not the part worth being cautious about. The real schema lift
  (noted above, under "facts and state, same entity or split") is making
  `aim_fact` revisionable in the first place, a one-time change, not an
  ongoing per-write cost.
- **Guardrails (decision 7) is the actual added cost, and it's an LLM
  call, not DB/CPU load as such.** It runs once per candidate fact, before
  write. Decision 5 already makes the provider a choice, not a mandate:
  pointing Guardrails at amazee.ai keeps this to network latency and API
  cost on the shared box, no local compute contention at all. Local Ollama
  is the option that would actually compete with the host site for CPU
  (or GPU) - a real concern if that path gets used, but not the only path,
  and not the default one right now (decision 6: PoC reasoning happens in
  an interactive Claude Code session, nothing unattended is configured
  yet).

Net: don't let "the trust gate is heavy" become the read-out here. Content
Moderation's DB cost is negligible at this scale; Guardrails' cost is real
but is a provider choice already covered by decision 5, and defaults to
hosted (network), not local (shared-box CPU), unless deliberately pointed
at Ollama. Measure actual headroom on the shared box before treating this
as a blocker either way, rather than assuming.

**What Guardrails' LLM cost actually is, checked against the real plugins
shipped in `web/modules/contrib/ai/src/Plugin/AiGuardrail/` (2026-09-09),
not assumed:** Guardrails is a plugin type, not one fixed mechanism, and a
Guardrail Set is a chosen combination of them - the cost depends entirely
on which plugins are in the set applied to fact-writing. Two of the three
shipped plugins cost nothing extra in LLM terms, confirmed by reading their
`processInput()` - neither calls `->chat()` nor implements
`NonDeterministicGuardrailInterface`:

- `RegexpGuardrail` - pattern matching against the text. Free.
- `InputLengthLimit` - a length/token-count check. Free.
- `RestrictToTopic` - the one real cost. One extra chat completion call per
  protected input (so, per candidate fact if wired into extraction),
  prompt = the candidate text plus a configured valid/invalid topic list,
  asking for a small JSON list of which topics are present - a
  classification-shaped call, not a generation-shaped one, and it has its
  own `llm_provider`/`llm_model` config independent of whatever model does
  extraction (defaults to the site-wide default chat provider if unset, so
  it can deliberately be pointed at something cheaper).

So "mandatory Guardrails" (decision 7) doesn't imply a mandatory extra LLM
call - a Guardrail Set built from just `RegexpGuardrail` +
`InputLengthLimit` satisfies the decision at zero extra LLM cost;
`RestrictToTopic` is only as expensive as choosing to add semantic topic
judgment on top, and that choice comes with its own model/provider knob.

**Why the ECA plugins, when ECA already has a State API
(`Drupal\eca\EcaState`)? Checked against its actual source
(2026-09-09).** `EcaState extends \Drupal\Core\State\State` - it
literally *is* Drupal core's State API, just pointed at its own key/value
collection (`'eca'`) instead of the default one, with a couple of
timestamp/timeout helpers (`setTimestamp()`, `hasTimestampExpired()`) layered
on top for automation bookkeeping like debouncing a repeated event. That
makes it a poor fit for anything this project means by "memory," on every
axis this conversation has touched:

- **Flat and global**, not scoped by subject - no `scope`+`subject`
  addressing at all, just a bare key you'd have to invent a naming
  convention for by hand.
- **No governance** - no permissions, no Content Moderation, no revisions,
  nothing decision 3 requires.
- **No retrieval** - no vector index, no Search API, no semantic query, no
  attribute filtering.
- **Not exportable**, tying directly into the export question above: State
  API data is deliberately outside Drupal's Config and Content Entity
  systems (that's the whole point of State vs. Config in core), so it can
  never be reached by `content:export` either - the exact same "not an
  entity" gap the vector table has, for the same underlying reason.

So: keep `aim_fact.state`, don't move it to `EcaState` - and the ECA
plugins aren't competing with the State API at all, they're the only bridge
from ECA's automation world into the actual memory store (governed,
subject-scoped, searchable, exportable). `EcaState` is exactly the kind of
thing the earlier "don't conflate facts with what's directly available from
the current request/automation context" caution was warning about -
automation scratch space, not memory, and a model reaching for it instead
of `FactWrite` would be making that exact mistake.

Side note found while checking this: `ai`'s own `ai_eca` submodule is
deprecated (`lifecycle: deprecated`, being removed in `ai:2.0.0`,
superseded by an external `ai_integration_eca` project). Not relevant to
what's built here - `aim_eca` depends on `eca:eca` directly, not `ai_eca` -
but worth knowing before reaching for it if AI-flavored ECA integration
ever comes up for another reason.

## Extraction (design notes, not built yet)

Nothing here is implemented. Capturing the shape agreed so far before it's
lost between sessions.

**No conversation or transcript recording (current default, reopened
2026-09-09, not re-decided).** Pinned for later: the user may want to keep
raw transcripts after all, prompted by having OpenAI credits available
(Whisper/gpt-4o transcribe makes speech-to-text practical if "mouth" in
"mouth-to-website" means literal spoken input). Until that's resolved,
current behavior stands: whatever the source
interaction is - a spoken conversation, a chat, a support ticket - only the
extracted atomic facts persist as `aim_fact` entities. The `source` field is
a short provenance pointer (e.g. `poc:manual-entry`, or later a reference to
a case/content entity), never the raw dialogue itself. This is a standing
constraint, not a PoC shortcut to revisit later - do not add a field or a
table that stores full transcripts without this being explicitly revisited
as its own decision first.

**Prototype built and working (2026-09-09):** `drush aim:extract <file>`
(`src/Drush/Commands/AimCommands.php`). Reads a text
file, sends it to a configured `drupal/ai` chat provider (`--provider`,
default `amazeeio`; `--model`, default `claude-4-5-sonnet`) with a
structured-JSON-schema request (`StructuredOutputSchema` /
`ChatInput::setChatStructuredJsonSchema()`) asking for atomic facts plus a
scope classification (user/role/site/case) and a short subject, then creates
one `AimFact` per returned item. `--source` sets the provenance tag (default
`extract:<filename>`); `--index` reindexes immediately, otherwise the next
cron run picks the new facts up (see the auto-indexing note above). Proven
on a real notes file: 6 correct facts extracted with correct scope
classification, one irrelevant sentence correctly discarded, then retrieved
via a real semantic query with sane relevance ordering.

The source file itself is never stored anywhere - only what the model
returns as facts. Still respects the no-recording default described above
as it stands today; if that gets reopened toward keeping raw input, this
command's `source` field is the obvious place a stored reference would go.

Gotcha hit building this: amazee's Bedrock-backed structured-output mode is
stricter than the DTO's own docblock examples show - the **top-level**
`json_schema` array needs an explicit `'type' => 'object'` key or the
request is rejected (`Schema type is missing for schema`). The DTO's
`StructuredOutputSchema` class doesn't add this for you; only nested
`items`/`properties` types are your responsibility to declare, and so is
the top-level one.

**Two build phases**, per decision 6:

1. **Now, working:** `drush aim:extract` above - real chat call per
   invocation, on demand, not unattended.
2. **Later:** wrap the same `extractFacts()` logic in a Queue API worker,
   its own crontab entry (decision 4), triggered by whatever the real
   source turns out to be (a webhook, new content, a case update) instead
   of a manually-supplied file. Same write path, just automated.

**Extraction as a curated Skill: built (2026-09-09).**
`.claude/skills/aim-discovery/SKILL.md` ("the grill") is a structured,
caring discovery-interview Skill covering purpose/audience, current state,
content/structure, voice and personality, policy/constraints, and
operational facts. It doesn't extract or write facts itself - it distills
each topic into a short summary, writes it to a scratch file, and runs
`drush aim:extract` against it, same pipeline as everything else, mostly
`scope: site`. Explicitly out of scope: generating a Recipe from what's
gathered (decision 8's heavier, dry-run-gated territory) - this Skill only
populates memory. Voice/personality is deliberately just one of its topic
sections, not a separate mechanism - see ADR-003's CCC discussion for why
brand voice already lives conceptually in the site-memory scope.

This is the same territory as ADR-003's open question 8 ("Speckit-for-
Drupal's actual question sets") and its "second product surface"
(spec-gathering conversation → Drupal Recipe) - a well-curated elicitation
Skill could plausibly also front the generative spec-gathering case later,
sharing this same structured-conversation mechanism. Not attempted yet.

## CLI agent adapter (built 2026-09-09)

`drush aim:extract` is the wrong tool for a Claude Code session (or any
agent already doing its own reasoning) to write memory with - it makes its
*own* LLM call to decide what's worth remembering, which is redundant when
the calling agent already did that reasoning as part of the conversation.
The direct pair, no LLM round-trip:

- **`drush aim:remember <text> [--scope] [--subject] [--source] [--state]`**
  - validates `scope` against the same four values `AimFact`'s field
  definition allows (defaults to `site`), `$storage->create([...])->save()`
  exactly like `extract()`'s loop body already does per-fact, minus the LLM
  call around it, then prints the created fact's ID back so a caller has it
  for any follow-up.
- **`drush aim:recall <text> [--scope] [--subject] [--limit] [--format]`** -
  loads `aim_vector_index` the same way `extract()`'s indexing branch does,
  runs `$index->query()->keys($text)`, adds `addCondition('scope', ...)` /
  `addCondition('subject', ...)` only when those options are set, caps with
  `range(0, $limit)`, `->execute()`, prints each result's score plus the
  underlying fact's `scope`/`subject`/`text`/`source`/`state`. Supports
  `--format=json` for an agent that intends to parse output rather than
  read a table.

Both live on the existing `AimCommands` class
(`src/Drush/Commands/AimCommands.php`, alongside `extract()`), not a new
class - same DI, same `entityTypeManager`/`aiProvider` constructor already
there (`recall()` only needs `entityTypeManager`, same as `extract()`'s
`--index` branch). No schema changes, no new dependencies.

**Gotcha hit while building `recall()`, confirmed 2026-09-09:
`$index->query()->keys(...)->execute()` silently returns zero results when
run from drush, no error at all.** Drush runs as the anonymous user (uid 0)
by default, and `ai_search`'s backend (`SearchApiAiSearchBackend::doSearch()`)
applies a real entity access check per match
(`$this->checkEntityAccess($match['drupal_entity_id'])`) unless the query
sets `search_api_bypass_access`; a match anonymous can't view is silently
dropped from the loop, not reported as an error or a permission problem.
`aim_fact` grants no view access to anonymous, so every match was being
filtered out - confirmed by comparison: the identical query returned 0
results as anonymous and 8 as uid 1 (via `account_switcher`).

First fix tried was `$query->setOption('search_api_bypass_access', TRUE)`
unconditionally - rejected on review: an unconditional access bypass sitting
in committed code is a bad default to leave lying around, independent of
whether today's trust model happens to make it harmless. **What `recall()`
actually does instead:** loads user 1 and runs the query through
`\Drupal::service('account_switcher')` (`switchTo()` before `execute()`,
`switchBack()` in a `finally`), so the query is still subject to real entity
access, just evaluated as an actual account rather than skipped entirely.

**This is a genuine improvement, not a solved problem - flag it if it comes
up again.** It swaps one implicit assumption for a narrower one: instead of
"skip the check," it's now "uid 1 holds a role with `is_admin: true`"
(confirmed as the actual mechanism - core's blanket-permission grant lives
on the role, via `UserRolesAccessPolicy` reading `Role::isAdmin()`, the same
mechanism behind the existing "administrator role has every permission"
gotcha under Drupal gotchas below, not a hardcoded uid-1 special case
anywhere in core). If uid 1 on some environment doesn't hold an is_admin
role, `recall()` goes back to silently returning zero results, the exact
failure mode this was meant to fix - `recall()` only guards against uid 1
not existing at all, not against it lacking the right role. The actually
correct fix is decision 3's governance layer: a real `aim_fact`-specific
permission plus a dedicated non-superuser service account for CLI tooling,
deferred along with the rest of governance. Worth knowing before writing
`QueryFacts`'s eventual CLI-adjacent testing, or any other `search_api`
query against an access-controlled entity run from drush/cron context - the
same silent-empty-result trap applies there too, and the same "account-
switch to uid 1, not a bypass" pattern is the fix, with the same caveat.

**Precedent check against Hermes Agent (Nous Research), researched
2026-09-09** to inform the shape of this before building it, prompted by
"what are other tools doing for agent memory." Hermes exposes a single
`memory` tool to its agent with an `action` enum (`add`/`replace`/`remove`),
a `target` (`"memory"` vs `"user"`), and for `replace`/`remove` a
substring-match `old_text` instead of an ID. What transfers: **one unified
entry point with an action parameter** beats several separate tool
definitions for agent ergonomics - `aim:remember`/`aim:recall` are shaped
that way, and the wrapping Skill below frames them as one capability, not
two commands. What doesn't transfer, and shouldn't: Hermes has **no
read/search tool for its core memory at all** - `MEMORY.md`/`USER.md` are
hard-capped at ~800/~500 tokens and injected wholesale into the system
prompt every session, which only works because the store is deliberately
tiny. Substring-matched `replace`/`remove` is a reasonable hack at that
scale but would be fragile and a poor fit for `aim_fact`, which is ID/UUID
and scope+subject addressed and meant to scale past what fits in a prompt -
`aim:recall` (real semantic query, not "dump everything") is the correct
divergence, not a gap to close. Hermes's own `replace` is functionally
aim's already-planned similarity-threshold consolidation step (AI
dependency map above), just done via vector distance instead of substring
matching - a better fit for this project's scale, nothing to import there.

**The Skill**, `.claude/skills/aim-memory/SKILL.md` (site-root `.claude`,
same location as `aim-discovery`), same bare frontmatter shape (`name` +
one-line `description`, nothing else). Unlike `aim-discovery` (which
distills a conversation into a file and shells out to `aim:extract` once),
this one is meant to be reached for continuously through a session:
instructs the agent to call `drush aim:remember`/`drush aim:recall`
directly via Bash whenever it learns or needs something scope-appropriate,
framed explicitly as one memory capability with two directions so a future
edit doesn't quietly drift back into N narrow tool-shaped instructions.
Includes a "don't remember what's already available from context" line,
echoing the same design boundary `aim_eca`'s `FactWrite` action follows
(see "ECA integration" below) - it applies just as much to an agent
deciding what to write via this Skill as it does to an ECA model.

**Service extraction, built 2026-09-09, prompted by "how does an average
Joe or average Drupal person interact with this?"** Drush-only access is a
dead end for anyone who isn't a developer or a CLI-capable agent - there is
still no form, page, or block anywhere in the module. The two real fronts
for that (a Tool API plugin so a chatbot can call remember/recall on a
visitor's behalf, and a human-facing "here's what we remember about you"
review page, see "Fact verification as a user-facing feature" under "Ideas
raised, not designed") both need the same logic `AimCommands` already had,
without a `$this->io()` call baked into the middle of it. All of it moved
out into `Drupal\aim\Service\AimMemoryManager`
(`src/Service/AimMemoryManager.php`, registered as `aim.memory_manager` in
`aim.services.yml`): `resolveAccount()`, `loadVectorIndex()`, `reindex()`,
`executeAsAdmin()`, `extractFacts()`, `createFactsFromCandidates()`,
`remember()`, `recall()`, `consolidate()`, and consolidate's private
`findNearestNeighbor()`/`classifyPair()` helpers - every gotcha documented
above (the drush-runs-as-anonymous account-switch fix, the `expires`
not-an-indexed-attribute filtering, the `subject_uid` post-filter
over-fetch) moved with the code, unchanged.

`AimCommands` is now a thin front end: it parses CLI option strings (e.g.
`--state=true` to a real bool), calls the service, and prints the result or
catches `\InvalidArgumentException`/`\RuntimeException` to print via
`$this->io()->error()`. The service methods that can fail on bad input
throw those two exception types rather than talking to `io()` directly, so
a Tool API plugin or a Form can catch and present the same failures in its
own idiom instead of a CLI-shaped one. This also closes a real duplication
`aim_eca` had already hit: its `AccountResolverTrait` reimplemented
`resolveAccount()` because there was nothing shared to call - not migrated
to the service in this pass (out of scope for the extraction itself), but
migrated the same day, see below.

**`aim_eca` pointed at the shared service, same day.**
`AccountResolverTrait::resolveAccount()` (`aim_eca/src/
AccountResolverTrait.php`) now just calls
`\Drupal::service('aim.memory_manager')->resolveAccount($value)` instead of
re-running the uid/username lookup by hand - one line instead of the
duplicated logic. Not constructor injection: `eca`'s own `ActionBase` and
`ConditionBase` both declare a `final __construct()` (see "ECA
integration" below), so `FactWrite`/`FactQuery`/`FactState` cannot add a
new injected argument the way a normal Drupal plugin would - the service
locator call is the pragmatic way around that constraint, not a stylistic
regression. Scoped deliberately narrow: `FactWrite`'s create-and-save body
and `FactQuery`'s index-query body still duplicate (rather than call)
`remember()`/`recall()` - both are close mirrors of the service methods
already, but folding them in changes more surface (error-message wording,
`FactQuery`'s token-data shape, `FactWrite`'s ECA-specific `source`
defaulting) than the account-resolver dedup does, and none of `aim_eca` is
exercised through a real ECA model yet (`eca` still isn't enabled on this
site). Left as a follow-up, not done speculatively. Verified by `php -l`
and a full phpcs pass only, same caveat every other `aim_eca` change in
this file already carries - the underlying `aim.memory_manager` call this
now delegates to was itself confirmed working via `drush php:eval` in the
service-extraction work above, so behavior parity is inferred from that,
not independently re-tested end to end.

Verified via `drush php:eval` against `aim.memory_manager` (resolved uid 1,
loaded `aim_vector_index`) and a full phpcs pass, no behavior change
intended or observed. `drush aim:recall` itself couldn't be exercised
end-to-end in the same pass: the amazee.ai embeddings call failed with
"Invalid model name passed in model=titan-embed-text-v2:0" - a provider/API
issue surfaced deep inside `search_api`/`ai_search`'s HTTP layer, unrelated
to this refactor (the failure is before any `aim` code runs), not yet
investigated.

## Consolidation (Phase 1 built 2026-09-09)

`drush aim:consolidate [--scope] [--provider] [--model] [--auto-threshold]
[--ambiguous-threshold] [--dry-run]` is the on-demand sweep from decision
6's build order (phase 1: real chat call on demand, not unattended) - the
Queue API + crontab phase 2 (decision 4) isn't built. Design informed by a
precedent check against Mem0 and Hindsight before building: Mem0's
extraction-then-"update" stage picks one of ADD/UPDATE/DELETE/NOOP per
candidate against retrieved neighbors - that vocabulary is adopted directly.
Hindsight calls its own mechanism "LLM-powered consolidation," which
validated the AI dependency map's existing split (conflict/merge decisions
= reasoning-grade LLM, similarity-threshold cases = vector math only) rather
than requiring a fix to it - what was missing was the algorithm under the
table, not the table. Hindsight also skips hard-deleting on contradiction,
letting a superseded fact fade via recency-weighted retrieval instead - that
argues for soft-supersede over DELETE here too, which is what got built.

**Schema addition, same pattern as `state`:** two new nullable base fields
on `aim_fact`, added via `installFieldStorageDefinition()` (real data
existed - 9 facts - so this needed the same non-destructive path as `state`,
not a reinstall):

- `expires` (timestamp) - when set, the fact is superseded. Consolidation
  sets this instead of deleting, to preserve an audit trail per decision 3's
  governance concerns.
- `related` (`entity_reference` to `aim_fact`, unlimited cardinality) - the
  fact(s) this one is linked to, e.g. the survivor that superseded it. This
  is the "explicit graph" half of the fact-to-fact-relations idea below,
  now actually populated by consolidation rather than just proposed.

Gotcha hit installing these: `EntityDefinitionUpdateManager::
getFieldStorageDefinition()` reads the *last installed* schema, so it
returns NULL for a field that was never installed - the working call is
`\Drupal::service('entity_field.manager')->getBaseFieldDefinitions('aim_fact')`,
which reads the entity class's current definitions, and even that needs a
`drush cr` first if the class was just edited (the base field definitions
are cached too).

**`aim:consolidate`'s algorithm, v1 (simplest thing - sweep everything,
trivial at current scale):** for each `aim_fact` without `expires` already
set (optionally filtered by `--scope`), run the same `aim_vector_index`
query `recall()` already uses, scoped to that fact's own scope+subject,
excluding itself and anything already consumed by a decision earlier in
this run. Whichever of the pair has the lower id is treated as "kept" (the
established fact), the higher id as "candidate" (the newer restatement
being evaluated against it) - a stand-in for real write-order until phase 2
triggers consolidation from actual fact creation instead of a retroactive
sweep.

- Score above `--ambiguous-threshold`: not related enough, skip, no cost.
- Score at or below `--auto-threshold`: obvious duplicate, no LLM call -
  candidate gets `expires` set and `related` pointing at kept (NOOP shape).
- Otherwise (the ambiguous band): one `StructuredOutputSchema` chat call,
  same pattern `extractFacts()` already uses, asking the model to choose
  ADD/UPDATE/DELETE/NOOP for the specific pair. Applied as: **ADD** - both
  stand, nothing changes. **UPDATE** - kept's `text` is overwritten with the
  model's merged statement, candidate gets `expires`/`related` (soft
  superseded, but contributed new information to the surviving fact).
  **NOOP** - candidate gets `expires`/`related`, kept's text is left alone
  (pure restatement, nothing to merge). **DELETE** - candidate is hard
  deleted; reserved for cases the model judges shouldn't exist as a memory
  at all, not the default outcome.

`--dry-run` prints the same decision table without saving anything - this
is the first command in the module that can mutate or retire existing
facts rather than only ever creating new ones, so a preview mode is a real
safety need, not overengineering.

**Threshold defaults, empirically set against real data (2026-09-09), not
borrowed from Mem0's or Hindsight's own (different) embedding models, per
the open question the design brief flagged.** Watching `aim:recall`'s score
column (lower = closer match) against this site's actual 9 facts and the
`amazeeio__titan-embed-text-v2:0` embeddings: a genuine near-duplicate pair
(fact 2 "The support desk is staffed 9-5 UK time." vs fact 7 "The site's
support desk is only staffed Monday to Friday, 9 to 5, UK time.") scored
0.338; a distinct fact about the same person (contact preference vs.
timezone) scored 0.563. `--auto-threshold` defaults to 0.35,
`--ambiguous-threshold` to 0.65 - a narrow gap by design, since the real
data showed "obvious dupe" and "same subject, different fact" sitting only
about 0.2 apart. Revisit as real fact volume grows past a handful.

**Gotcha, found by rerunning the sweep right after an apply and getting the
same pair again:** the vector index has no idea `expires` exists (it isn't
an indexed attribute), so a fact already retired by an earlier consolidation
run keeps resurfacing as everyone's nearest neighbor. Fixed in two places:
`findNearestNeighbor()` skips any candidate whose `expires` is already set,
and `recall()` now filters `expires`-set facts out of its own results for
the same reason - a retired fact was still fully answering live queries
until this was added, which defeats the entire point of retiring it.

**Real finding that led to a real fix, same day: two known-duplicate facts
(1 and 3, both "Nik prefers email over phone," worded differently) never
got compared, because the neighbor search scoped strictly by `subject`
string and one used subject `1` (a uid) while the other used `Nik` (a
name).** This was the free-text `subject` drift the taxonomy idea below
already worried about, showing up live rather than hypothetically. Fixing
consolidation's matching logic wouldn't have addressed the root cause
(subject *addressing*, not matching) - see "User-scope facts now require a
real account" below for what actually fixed it.

**User-scope facts now require a real account (decided and built
2026-09-09), fixing the drift above at the source.** `subject` was always
documented as meant to hold a uid for `scope: user` facts, but it was a
free string that merely happened to contain one - nothing stopped "1" and
"Nik" from addressing the same person without ever matching. Decision:
a `scope: user` fact must be about an account that actually exists on this
site. Mechanism:

- New base field `subject_uid` (`entity_reference` to `user`, cardinality
  1), installed the same `installFieldStorageDefinition()` way as `state`
  and `expires`. `subject` (the string field) is now only meaningful for
  `role` (a role machine name) and `case` (a case ID) scope - for
  `scope: user` it stays empty, `subject_uid` is the single source of
  truth.
- **`aim:remember`** resolves `--subject` (a uid or a username) to a real
  account via a new `resolveAccount()` helper when `--scope=user`, and
  refuses to save if it doesn't resolve - the same "reject rather than
  silently corrupt" posture as `--state`'s validation.
- **`aim:extract`** does the same resolution automatically against
  whatever subject string the model returned, per fact. If it doesn't
  resolve, that candidate fact is skipped (with a warning naming the
  count), not saved with a broken/unaddressable subject. This is a real,
  deliberate behavior change: extraction can now silently drop a
  correctly-extracted fact about a real person who simply has no account
  yet - an accepted consequence of "must have an account," not a bug to
  fix later.
- **`findNearestNeighbor()`** (inside `aim:consolidate`) no longer adds a
  `subject` index condition for `scope: user` - `subject_uid` isn't an
  indexed attribute, so it over-fetches (range 20 instead of 5) and
  post-filters candidates in PHP for exact `subject_uid` equality instead.
- **`aim:recall`** gained a separate `--subject-uid` option (uid or
  username, resolved the same way) rather than overloading `--subject`
  with scope-dependent meaning - a CLI flag that means two different
  things depending on another flag's value is a footgun worth avoiding
  even at the cost of one more option. Same over-fetch-then-post-filter
  shape as consolidation's fix.
- The 5 existing `scope: user` facts (1, 3, 4, 5, 9 - all genuinely about
  the same person, confirmed against this site's actual accounts, only
  uid 1 and anonymous exist here) were migrated by hand: `subject_uid` set
  to 1, `subject` string cleared.

**Verified end to end after the fix:** facts 1 and 3 now correctly appear
as a pair (score 0.474, the ambiguous band) and the classification call
returned UPDATE - fact 1's `text` became "Nik prefers to be contacted by
email rather than phone for support follow-ups" (a real merge of both
statements), fact 3 got `expires`/`related` set. This is the fix actually
resolving the bug it was built for, not just passing a synthetic test.

**Follow-up closed same day: `aim_eca`'s three plugins updated to match.**
All still expose the same plain "Subject" textfield in the model editor
(a config field can't conditionally change shape based on another field's
value without more machinery than this warranted) but now resolve it to a
real account at execution time when scope is user, via a new shared
`AccountResolverTrait` (`aim_eca/src/AccountResolverTrait.php`, `use`d by
all three) - the same uid-or-username resolution `AimCommands::
resolveAccount()` does, duplicated rather than shared across modules since
`aim_eca` and `aim`'s Drush commands don't otherwise share code.
`FactWrite` throws if scope=user and the subject doesn't resolve, same
posture as `aim:remember`'s validation, and sets `subject_uid` instead of
`subject` on the created fact. `FactQuery` resolves the same way, then
post-filters (subject_uid isn't an indexed attribute, over-fetches at 5x
limit) - and picked up the `expires`-filtering fix `recall()` got earlier,
which it had drifted out of sync with since it was built by mirroring an
older version of that method. `FactState` matches on `subject_uid` instead
of `subject` in its `loadByProperties()` lookup when scope=user, and
short-circuits to a non-match if the configured subject doesn't resolve to
an account (an unresolvable subject can't have written a fact to begin
with, so the condition just evaluates false rather than throwing - a
condition failing to match is a normal outcome, not an error, unlike
`FactWrite`/`FactQuery` where garbage input should stop a write or a
query). Not yet exercised through an actual ECA model - `eca` still isn't
enabled on this site - so this is verified by phpcs and `php -l` only, same
caveat the original build already carried.

**Open governance question, flagged not resolved, carried over from the
design brief:** decision 7 says every LLM-proposed candidate fact goes
through Guardrails before being written "anywhere," worded with extraction
in mind - one new fact at a time. A consolidation UPDATE/DELETE is also an
LLM-proposed write, just targeting an existing fact instead of a new one.
Decision 7 doesn't explicitly say whether that counts, and `aim:consolidate`
doesn't call Guardrails today. Doesn't block anything right now (every
write already goes live unreviewed regardless of source, per the PoC
deviations noted at the top of this file), but settle it before decision
3's governance layer gets un-deferred, not after consolidation is already
running unattended via phase 2.

## ECA integration (built 2026-09-08)

`aim_eca` (`web/modules/custom/aim/modules/aim_eca`) is a submodule, not a
change to `aim` itself: `aim.info.yml` has no ECA dependency, so the site
stays exactly as installed until someone deliberately enables both `eca` and
`aim_eca`. `drupal/eca` (`^3.1`, currently `3.1.7`) is already
composer-present in this repo but not enabled - confirmed via `drush pml`,
only `aim` shows enabled. That is the intended state; don't enable `eca` on
this site without being asked.

`aim_eca` ships three plugins, all named `Fact<Verb>`/`aim_fact_<verb>`
for a consistent, alphabetized set in ECA's model editor (`Fact Query`,
`Fact State`, `Fact Write`): the write path, `FactWrite`
(`src/Plugin/Action/FactWrite.php`, plugin ID `aim_fact_write`; named
`WriteFact`/`aim_write_fact` until the naming was made consistent
2026-09-09, before `eca` was ever enabled anywhere so nothing depended on
the old ID), that maps an ECA model's configured values (with full token
support: scope, subject, text, source, and an optional true/false/token
`state`) onto a new `aim_fact`, the same shape `drush aim:extract` writes;
and two read-side complements, `FactQuery` (action, `aim_fact_query`) and
`FactState` (condition, `aim_fact_state`) - see "Retrieve/condition ECA
plugins" under "Ideas raised, not designed" below for what those two do.
`FactWrite` extends
`Drupal\eca\Plugin\Action\ConfigurableActionBase`, not Drupal core's own
bare `Action` base class - worth knowing before writing another ECA plugin
here: **ECA does not have its own action plugin type.** It reuses Drupal
core's native `#[Action]` attribute and `plugin.manager.action` wholesale
(confirmed against `web/core/lib/Drupal/Core/Action/`); any core Action
plugin, ECA-aware or not, already shows up in ECA's model editor the moment
ECA is enabled, zero extra dependency required. `eca`'s own
`Plugin\Action\ActionBase`/`ConfigurableActionBase` just layer extra
constructor-injected services (`tokenService`, `EcaState`, a dedicated
logger channel) on top of that same plugin type via a `final __construct()`,
and the only reason to extend eca's version instead of core's bare one is to
get `$this->tokenService->replaceClear()` / `addTokenData()`, i.e. token
support, which is the entire point of a fact-writing action driven by a
model's event context. That's why `aim_eca` still has to depend on `eca`
even though the underlying plugin type doesn't require it.

**Design boundary, not yet enforced anywhere except by convention:** this
action is a write path, same as `drush aim:extract` - it stores whatever the
model hands it, it doesn't decide what's worth remembering. Don't wire an
ECA model to write a fact for something already available for free from the
current request or entity context (the acting user's roles, the node being
viewed, a field value still live on an unchanged entity) - that's a stale
duplicate waiting to happen, not memory. This action exists for values that
would otherwise be lost once the triggering event passes: a submitted
webform value, a decision made mid-workflow, something pulled from an
external system during that one event.

`https://ecaguide.org/llms.txt` is a real, working agent-friendly docs
index (confirmed 2026-09-08) - one Markdown-suffixed page per doc URL (e.g.
`https://ecaguide.org/eca/extend/plugins/index.md`), covering install,
modelers, and a plugin reference for every bundled Event/Action/Condition.
Thin on custom-plugin-authoring specifics in practice, though (the
"Developing Plugins" page mostly points at `drush gen eca-action` and the
existing plugin source rather than spelling out the base class/attribute
contract) - reading the installed module's own source
(`web/modules/contrib/eca/src/Plugin/Action/`) plus a real shipped example
(`ConfigurableActionBase`, `ActionBase`, and any concrete `#[Action]`
implementation) was more reliable for this than the docs site.

## Chat interface (demo, built 2026-09-09)

The "average Joe" access path from CLAUDE.md's earlier service-extraction
note: a real, visitor-facing chat widget that can remember and recall
`aim` facts, not just drush/CLI access. Built for a short demo, not
production - see "Deliberately narrowed scope" below for what's cut.

**Mechanism: `drupal/ai`'s own AI Assistant API, not the separate Tool API
module.** `ai_assistant_api` and `ai_chatbot` are submodules bundled inside
`drupal/ai` (already a hard dependency of `aim`) - composer-present, just
not enabled, zero new packages needed. They are unrelated to the `tool`/
MCP module (still not installed) - `ai_assistant_api` has its own
function-calling plugin type, `#[AiAssistantAction]`
(`AiAssistantActionInterface`/`AiAssistantActionBase`), a different
mechanism from both ECA's reuse of core Action plugins and a Tool API
plugin. Found a real shipped example to build from: `ai_search`'s
`RagAction` does search_api-backed retrieval in the same shape `aim`'s
`recall()` needs.

**`Drupal\aim\Plugin\AiAssistantAction\AimMemoryAction`**
(`src/Plugin/AiAssistantAction/AimMemoryAction.php`, plugin id
`aim_memory_action`) exposes two actions to the assistant, `aim_remember`
and `aim_recall`, both thin wrappers over `AimMemoryManager` - same pattern
as `aim_eca`'s plugins, third consumer of the shared service. Unlike
`aim_eca`, `AiAssistantActionBase`'s constructor is **not** final, so this
one uses normal constructor DI for `AimMemoryManager`, no service-locator
workaround needed. `aim.info.yml` gained `ai:ai_assistant_api` as a
dependency.

**Deliberately narrowed scope, decided before building:** locked to
`scope: site` on both `aim_remember` and `aim_recall`, hardcoded in the
plugin, not exposed as a parameter the LLM can set. Two separate reasons,
not one:

- **Identity isn't resolved.** A chat visitor here is not tied to a real
  Drupal account, and `scope: user` facts require one
  (`AccountResolverTrait`/"User-scope facts now require a real account"
  above). Per-visitor identity binding (session-to-account mapping,
  consent) is real, unbuilt complexity, correctly out of scope for a demo.
- **Privacy, not just an unbuilt feature.** Locking `aim_recall` to
  `scope: site` too (not just `aim_remember`) matters independently: with
  no scope restriction, `aim_recall` could have surfaced a real `scope:
  user` fact (e.g. a contact preference) back to an anonymous demo
  visitor who has no business seeing it. This was caught before building,
  not found as a bug afterward.

Since `scope` is fixed by the plugin rather than left for the model to
classify, there is no live "how do we classify this fact" question for
this demo - every fact this action touches is site-scoped by construction,
the same default `aim-discovery` already uses.

**Gotchas hit standing this up, all environment/contrib quirks, not `aim`
bugs:**

- **`ai_assistant_api`'s newer versions push new assistants toward the
  `ai_agents` module** - `AiAssistantForm::form()` refuses to render a
  create form for a new, non-agent assistant unless `ai_agents` is also
  enabled ("All assistants going forward will be agents"). This is a
  **UI-only** gate: `AiAssistantApiRunner::process()` still fully supports
  the legacy `actions_enabled`/`AiAssistantActionPluginManager` path at
  runtime as long as the entity's `ai_agent` field is empty (confirmed by
  reading the runner's source, not assumed). Worked around by creating the
  `ai_assistant` config entity directly via `drush php:eval` /
  `EntityStorage::create()->save()` instead of the admin form - `aim_demo_
  assistant`, `llm_provider: anthropic`, `llm_model: claude-haiku-4-5-
  20251001`, `actions_enabled: {aim_memory_action: []}`, `use_function_
  calling: FALSE` (uses the module's default two-pass JSON pre-prompt flow,
  not native tool calling - simpler, and what `listActions()`/
  `provideFewShotLearningExample()` are actually for in this flow).

  **Sharpened same day, from the admin UI's own deprecation warning on
  `aim_demo_assistant`'s edit form:** "This assistant is using the old AI
  Assistant API for 1.0.0... The old one will be removed in 2.0.0." Not
  just discouraged, an actual removal date - `AimMemoryAction`'s whole
  integration mechanism (`#[AiAssistantAction]`/`actions_enabled`) has a
  real expiration once `drupal/ai` ships 2.0.0. Checked what migrating
  would actually involve before deciding whether to do it now:
  `ai_agents` (the replacement, using "Tools" instead of "Actions") is
  **not composer-present in this project at all** - not a config flip,
  a new `composer require` plus an unfamiliar plugin contract, and
  recreating `aim_demo_assistant` as agent-backed. Decision: don't migrate
  now - this is still explicitly a demo, the current mechanism is proven
  working end to end, and `ai:2.0.0` isn't imminent. Track as real, dated
  debt; revisit once this moves past demo status or `ai:2.0.0` gets closer,
  not before.
- **Can't functionally test via `drush php:eval`.**
  `AssistantMessageBuilder::getPrePromptDrupalContext()` calls Drupal's
  title resolver against the *current route*, which is null outside a real
  HTTP request - `drush php:eval` has no routed request, so this throws a
  `TypeError` immediately. Not a bug in `aim`'s plugin; genuine end-to-end
  verification needs a real HTTP request. Worked around with `curl` against
  the live `/api/deepchat` endpoint (`ai_chatbot`'s newer, recommended
  DeepChat widget, not the older `ai_chatbot_block` form - the admin UI
  itself flags the older block as being replaced by this one) rather than
  a browser, since no browser tool is available in this environment - this
  is real HTTP-request verification, just not visual/interactive.
- **The CSRF token for `/api/deepchat` is a `token` query parameter, not a
  header, and not literally named `csrf_token` despite the route
  requirement key (`_csrf_token: 'TRUE'`) and even the error message text
  both saying "csrf_token"** - confirmed by reading core's
  `CsrfAccessCheck::access()` directly: it reads `$request->query->get(
  'token', '')`. `POST /api/deepchat/session` returns a raw token string
  (tied to the session cookie); append it as `?token=...` on the actual
  `/api/deepchat` request. Cost real time guessing wrong twice (header,
  then `?csrf_token=`) before reading the actual check.
  Also needed the `access deepchat api` permission granted to `anonymous`
  (not granted by default) for an unauthenticated demo visitor to reach
  either endpoint at all.

**Verified working, not just wired up:** a real `curl` conversation through
`/api/deepchat` against `aim_demo_assistant` - "Please remember that the
demo site closes for maintenance every Sunday morning." - produced a real
new `aim_fact` (id 12, `scope: site`, `source: chatbot:aim_demo_assistant`),
confirmed by querying the entity afterward, not just trusting the chat
reply text. `aim_remember` works end to end through the live chat pipeline.

**`aim_recall` through the chatbot hits the same pre-existing amazee.ai
embeddings bug already flagged under "No free tier on the Anthropic
Console API" above** (`Invalid model name passed in model=titan-embed-
text-v2:0`), not a new problem - a follow-up question in the same chat
thread failed with the identical error `drush aim:recall` already hit.
Because `AimMemoryAction` calls the same `AimMemoryManager::recall()` every
other consumer does, fixing the amazee.ai embeddings model name (once the
correct current model id is known - the account's own error suggests
calling `/v1/models` to check) fixes recall for CLI, ECA, and the chatbot
simultaneously. Left alone in this pass - it's a separate provider/account
config issue, not something to fix silently while wiring up a different
feature.

**A `ai_deepchat_block` was placed** (`olivero_aimdemochat`, `content`
region, theme `olivero`) pointed at `aim_demo_assistant`, so the widget is
also reachable through an actual browser, not just `curl` - confirmed the
front page still returns 200 with the widget's markup/library attached
after placing it.

**Parked, not acted on:** the user suggested splitting chatbot-facing code
into its own submodule (`aim_chatbot`, mirroring `aim_eca`'s pattern)
rather than living inside `aim` itself - explicitly "not for now," worth
doing once this moves past demo status.

## Ideas raised, not designed

Four more, not yet built, kept here so they don't evaporate between sessions:

- **Typed/boolean facts could skip the vector pipeline entirely.** A
  flag-shaped fact ("user prefers email over phone") doesn't need semantic
  similarity to retrieve, just an indexed lookup by scope+subject against
  `aim_fact`'s own table. Refined 2026-09-09: a generic `value_type`/`value`
  field pair isn't the smart version of this - **taxonomy** is. A true on/off
  flag is a plain Boolean field, nothing gained from taxonomy. A categorical
  fact with a small curated set of values (contact preference: email/phone/
  SMS; a "traits" tag) is genuinely better as a taxonomy term reference than
  free text or an enum column - controlled vocabulary Drupal already
  manages, editors can prune/merge terms normally, Views/Search API facet on
  it for free, avoids the free-text drift a plain `subject` string invites
  ("Nik" vs "nik" - the *identifier* half of this got fixed 2026-09-09, see
  "User-scope facts now require a real account" above: `subject_uid` is now
  a real `entity_reference` to `user` for `scope: user` facts specifically.
  This taxonomy idea is still open for the separate *categorical-value*
  case - contact preference, traits - which is a different field, not
  `subject`). A fact becomes "this subject is tagged with this term",
  an entity reference, not a value pair. A Drupal cache API layer (keyed by
  scope+subject, tagged so a save invalidates it) makes sense on top of
  either shape for a hot path, but only once there's a real recurring
  category worth curating (contact preference is the first candidate). Don't
  add this speculatively; wait for an actual hot-path check that needs it.
  (2026-09-09: `aim_eca`'s `FactWrite` action only knows about the two
  shapes that exist today, prose `text` and boolean `state` - if the
  taxonomy shape gets built, that action needs a matching entity-reference
  config field, not a third string field bolted on.)

  **Placement, if this gets built (2026-09-09):** one vocabulary per
  category type (`contact_preference`, `traits`, ...), shipped as module
  default config the same way the search_api config above now is, each kept
  independently prunable. On `aim_fact` side: a single new
  `entity_reference` field (e.g. `category`), cardinality 1, with
  `target_bundles` listing every relevant vocabulary at once - Drupal
  already lets one field span several vocabularies (it's how core's own
  default "Tags" field works), and which category a term belongs to falls
  out of `$term->bundle()`, no second field needed to track that. If a
  subject needs more than one categorical tag, that's more `aim_fact` rows
  (one term each), not a multi-value field on one row - matches every other
  field on this entity being "one row, one atomic assertion."

  **Facets are not an alternative to this (2026-09-09), asked because
  `search_api` is already in play and `drupal/facets` (`^3.0`) has since
  been added to composer.json (not yet enabled).** Facets are a query-time
  filtering UI over an already-indexed attribute - they answer "let someone
  narrow search results by this value," not "how is this value stored and
  curated," which is what taxonomy is actually for. The two aren't
  competing: a taxonomy term reference would typically be indexed as an
  attribute (same as `scope`/`subject`/`source` already are) and *then*
  optionally exposed as a facet on top, if there's ever a human-facing
  results page. There isn't one yet - every current and planned consumer
  (`drush aim:extract`'s retrieval, `FactWrite`, an eventual query action)
  talks to the index directly via `$index->query()->keys(...)->execute()`,
  so a facets UI has nothing to attach to right now.

  **Actual DB pressure, measured 2026-09-09, not guessed:** 9 `aim_fact`
  rows = 0.06 MB, the `aim_facts` vector collection table (8 rows indexed
  so far) = 0.14 MB, whole site DB = 18.5 MB. A plain attribute or taxonomy
  reference field costs nothing worth mentioning at this scale, same as
  `scope`/`subject`/`source` already indexed as attributes today. The real
  cost driver, if this ever needs revisiting, is the `VECTOR(1024)` column
  itself: `aim_facts` is already ~50% bigger than `aim_fact` per equivalent
  row despite storing far less data, and that ratio is what to watch as
  fact volume grows, not anything to do with taxonomy or facets.
- **Fact verification as a user-facing feature, not just a governance
  gate.** The draft-to-trusted review step (decisions 3/7) could double as
  a mobile-friendly "here's what I remember about you, confirm or correct"
  aide-memoire, valuable to the person reviewing it independent of it also
  being the safety gate. When that review UI actually gets built, default
  to something a person would want to use, not an admin moderation queue.
- **Fact-to-fact relations (2026-09-09), prompted by "is this Obsidian?".**
  Not built, and today's schema has no link between facts at all - the only
  connective tissue is the shared `scope`+`subject` pair and whatever the
  vector index finds semantically similar. Obsidian's actual value isn't
  atomic notes, it's the graph those notes form via authored `[[links]]`
  plus a graph view over it. Two ways to get graph-shaped value here,
  neither built, cheap to add later, don't add speculatively:
  1. **Explicit graph:** a multi-value `entity_reference` base field on
     `aim_fact` targeting `aim_fact` itself (same pattern as every other
     field already on this entity - plain SQL, no new infrastructure).
     Gives authored, curated edges, but requires something to actually
     author them (a human reviewer, or an extraction step told to look for
     relations).
  2. **Implicit graph, already half-there for free:** the vector index
     already answers "what's related to this fact" via cosine similarity,
     with zero schema change - this is Obsidian's "graph view" without the
     authoring step, computed rather than typed by hand. A "related facts"
     feature could just be a query against `aim_vector_index`, not a
     stored relation at all.
  A graph *view* (force-directed visualization) is a separate, purely
  presentational layer on top of either - Drupal has nothing built-in for
  this, would need a small custom JS visualization fed by either source.
  Don't build any of this until there's an actual reason to browse facts as
  a graph rather than query them, per the module's own git-vs-SQL framing:
  this project stores facts in SQL specifically because it doesn't want
  Obsidian's file-vault model, so "graph view" here means a query result
  rendered as a graph, not a stored file network.

  **Built 2026-09-09, alongside consolidation:** `related` (`entity_reference`
  to `aim_fact`, unlimited cardinality) is now a real base field, added the
  same way `state` was. The prediction two paragraphs up held: no human
  types the equivalent of `[[links]]` - `drush aim:consolidate` populates
  `related` itself, from the same vector-similarity comparison it already
  has to run to decide ADD/UPDATE/DELETE/NOOP, at zero extra cost. See
  "Consolidation" above for the actual mechanism, thresholds, and gotchas.
  What's still true from the original idea: `related` only records the
  supersedes/superseded-by edge consolidation creates, not a general
  authored graph - that's still the unbuilt "explicit graph" half if it's
  ever wanted for its own sake, independent of consolidation.

  **Facts and state, same entity or split (2026-09-09)?** Recommendation:
  same entity, don't split. Every shape so far (prose, boolean, and
  taxonomy/categorical if it gets built) is still the same conceptual
  object - one atomic assertion - just with a different optional structured
  field riding alongside the required `text`. Splitting into a second
  entity type would duplicate governance, Search API indexing, permissions
  and every ECA action across both for no real gain. The dimension actually
  worth separating is *bundle*, not entity type - and that's already
  spoken for: bundles are earmarked for the *scope* axis (user/role/site/
  case), deferred per the PoC-deviations note near the top of this file.
  Drupal entities get exactly one bundle dimension, so scope-as-bundle and
  shape-as-bundle (statement/flag/category) can't both happen on the same
  entity type. Don't bundle on shape; revisit only if scope bundles
  actually get built and this becomes a real conflict instead of a
  hypothetical one.
- **Fact expiry and a possible review status (2026-09-09).** Originally
  raised as forking into two different-sized pieces; half 1 got built, but
  as a side effect of consolidation, not as the standalone TTL mechanism
  described below:
  1. **Expiry is cheap and additive - built, but not the way this
     originally proposed.** The nullable `expires` timestamp base field
     exists (see "Consolidation" above), but nothing sets it on a schedule
     or by staleness/age - only `drush aim:consolidate` sets it, as a
     "this fact is superseded" marker, and only `aim:recall` currently
     checks it (filters expired facts out of results at query time, not a
     scheduled job that acts on anything past its date). A real
     scheduled-TTL mechanism, if one still gets wanted independent of
     consolidation, is still unbuilt - decision 4's Queue API + dedicated
     crontab, not `hook_cron`, same as originally proposed here.
  2. **"Force into review status" is a bigger decision than it sounds.**
     This isn't a new concern alongside decision 3's draft-to-trusted gate -
     it's that gate arriving early, just triggered by staleness instead of
     by extraction. Building a separate ad hoc `review_status` field now
     would just get replaced by Content Moderation later, which decision 3
     already commits to. The real cost of pulling decision 3 forward:
     Content Moderation requires the entity to be **revisionable**, and
     `aim_fact` has no revision support at all today (no revision entity
     key, no revision table) - so "ship a workflow" isn't just adding a
     field, it's adding revisions to the entity first, a real schema lift,
     not a small one.
  Don't build either half without confirming which one is wanted: `expires`
  alone doesn't need a workflow, but a real review-status gate should be
  decision 3's Content Moderation, not a smaller thing that gets thrown
  away once decision 3 actually gets un-deferred.
- **Retrieve/condition ECA plugins - built (2026-09-09).** Read-side
  complements to `FactWrite`, same use case as originally scoped: a model
  that needs to branch on, or pull in, memory it already has. Named
  `FactQuery`/`FactState` (not `QueryFacts`) so all three `aim_eca` plugins
  share one `Fact<Verb>` shape - `FactWrite` was itself renamed from
  `WriteFact` in the same pass, purely for this consistency, see "ECA
  integration" above.
  - **`FactQuery` Action**, `aim_eca/src/Plugin/Action/FactQuery.php`,
    plugin ID `aim_fact_query`. Config: token-supported search text,
    optional `scope`/`subject` filters (`scope` uses the same
    select-with-`#eca_token_select_option` pattern as `FactWrite`, left
    `#required => FALSE` so ECA's own "undefined" option means "no scope
    restriction" rather than a bespoke sentinel value), a result-limit
    (plain number field, default 10, matching `aim:recall`'s default), and
    a required `token_name`. `execute()` loads `aim_vector_index` and runs
    `$index->query()->keys(...)->execute()`, the exact same call already
    proven and shipped in `drush aim:recall` (`AimCommands::recall()`) -
    mirrored that working code rather than the earlier, only-planned
    sketch of it.
  - **Resolved: what `addTokenData()` does with a result list.** Checked
    against `eca`'s own source
    (`Drupal\eca\Token\TokenDecoratorTrait::addTokenData()`), not assumed.
    A plain PHP array has no resolvable token type (`getTokenType()` only
    recognizes `EntityInterface` and `DataTransferObject`), so passing one
    directly falls through to the same branch that wraps chained-token
    data: a new `DataTransferObject` gets registered under the key, then
    `$dto->setValue($data)` stores the array as that DTO's value. This is
    exactly the mechanism `eca`'s own `ListOperationBase` relies on (it
    explicitly does `DataTransferObject::create([])` for the same reason)
    - so `FactQuery` hands `addTokenData()` a plain array of loaded
    `aim_fact` entities directly, no manual DTO wrapping needed on this
    module's side.
  - **`FactState` Condition**, `aim_eca/src/Plugin/ECA/Condition/
    FactState.php`, plugin ID `aim_fact_state`, extends `Drupal\eca\
    Plugin\ECA\Condition\ConditionBase` (confirmed: conditions are ECA's
    own plugin type, not core's like Action - attribute is `Drupal\eca\
    Attribute\EcaCondition`). Config: `scope`, `subject`, expected boolean
    `state`, all token-capable, same field pattern as `FactWrite`.
    `evaluate(): bool` does a direct `loadByProperties(['scope' => ...,
    'subject' => ..., 'state' => $expected])` against `aim_fact`'s own
    table, not a vector query, per the boolean-facts-skip-the-vector-
    pipeline idea below - `state` is a boolean column, so this condition
    can never match a fact that never had `state` set, only ones
    explicitly `TRUE` or `FALSE`. Respects the negate checkbox every ECA
    condition gets from `ConditionBase`, via `$this->negationCheck(...)`.
  No DB migration needed for either - both are new plugin files in the
  already-`eca`-dependent `aim_eca` submodule, `aim_fact`'s schema
  unchanged. Not yet exercised through an actual ECA model - `eca` isn't
  enabled on this site (see "ECA integration" above) - so discovery of
  these plugin IDs in ECA's model editor is unverified beyond syntax and
  matching the shape of the already-proven `FactWrite`/`recall()` code
  they're built from.
- **Pre-extraction summarization as a dedup lever, raised 2026-09-09.**
  Idea: reduce duplicate/near-duplicate facts at the source by having an AI
  pass summarize a conversation before extraction runs against it, rather
  than relying only on post-write similarity-threshold consolidation (AI
  dependency map above). Not a substitute for consolidation, complementary
  to it: pre-extraction summarization only catches duplication *within* one
  session's raw material before facts are ever written; it does nothing for
  two separate sessions independently producing the same or a
  near-identical fact, which is exactly what post-write vector-similarity
  consolidation exists to catch. Likely wanted together once consolidation
  gets built, not instead of it.

  Already partially the existing pattern, not a new mechanism: this is what
  the aim-discovery Skill ("the grill") already does today, per-topic - it
  distills a topic into a short summary, writes it to a scratch file, then
  runs `aim:extract` against that file rather than raw conversation (see
  "Extraction as a curated Skill" above). What "summarize/write the
  conversation out before extraction" would add on top is doing this
  generally, for any conversational memory-write path, not just the
  grilling Skill's structured interview.

  **Flag, not yet resolved:** writing a conversation out to markdown ad hoc
  brushes directly against the standing no-transcript-recording constraint
  above - that constraint is about what gets *persisted*, not about
  ephemeral working files. `aim:extract`'s own input file, and the grilling
  Skill's scratch summary file, are already never stored anywhere, which is
  the precedent to follow: a summarization-before-extraction step is fine
  as long as the intermediate markdown stays scratch/ephemeral (discarded
  after the extract call) and never becomes a committed field, table, or
  module asset. If it's ever meant to persist - e.g. for audit or
  debugging - that's the no-recording default being reopened for real, not
  a side effect of building this dedup mechanism, and needs its own
  explicit decision first, same as the reopened note above already says.
- **EU AI Act disclosure obligation, flagged 2026-09-09, not designed.**
  `aim` mediates AI-generated content back to end users (extracted facts,
  eventually recipes/recommendations) - the EU AI Act's transparency
  obligations (Article 50: users must be told they're interacting with an
  AI system or viewing AI-generated content) are a plausible fit once this
  moves past PoC into anything user-facing.
  `drupal/ai_disclosure` (`https://www.drupal.org/project/ai_disclosure`) is
  a candidate module for this - not evaluated yet (maturity, what it
  actually covers, whether it fits this project's shape). Revisit before
  any non-PoC/public-facing surface ships, alongside decision 3's
  governance layer - this is a legal requirement, not an architectural
  nice-to-have, so don't let it slide past an actual launch.

## Dev process and rules

### Git

- **Never auto-commit.** Create and edit files freely; stop after writing.
  Don't `git add`/stage or commit unless explicitly asked in the moment,
  even if an earlier plan implied a commit point. If work naturally ends at
  a commit-worthy point, say so and let the user decide.
- This module is meant to be committed as its own git repo, nested inside
  the `aim` site shell (same pattern as the user's other Drupal project:
  site shell has no git tracking of the module, the module is its own
  repo). Don't run `git init` unprompted even so.

### Schema/config changes during early development

- No migration scripts or `hook_update_N()`/install hooks needed while
  there's no real data to preserve - just make the change and reinstall
  (`drush pmu` / `drush en`) to apply it. Revisit once real content/memory
  data exists that reinstalling would destroy; at that point update hooks
  become the required mechanism, not optional.

### Code style (Drupal/PHP/JS/CSS)

- **No em dash character** (or its HTML entity `&mdash;`) anywhere in code,
  comments, docblocks, strings, or YAML. Use a hyphen, parentheses, or a
  colon instead.
- **No banner/divider comment blocks** (`// --- Helpers ---`). Use a single
  plain `// Helpers.` line comment instead.
- **`Html::escape()`** (`Drupal\Component\Utility\Html`), not
  `htmlspecialchars()`, for escaping in Drupal PHP.
- **American English spelling** in code, comments, and docs (`color`,
  `behavior`, `license`, `-ize`/`-ization`).
- No `/** */` block comments inside method bodies - use `//` line comments.
  Exception: inline `/** @var Type $var */` type narrowing.
- Docblock short description is one line only; wrap the rest after a blank
  `*` line. `@return` description goes on the line after `@return`, not
  inline. Every constructor param needs a `@param`, including ones added to
  an existing promoted-property constructor.

### Linting - run before calling PHP/JS/CSS work done

```bash
# phpcs
ddev exec "cd /var/www/html && vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/aim --extensions=php,module,inc,install,test,profile,theme"

# phpstan (once a phpstan.neon exists for this module)
ddev exec "cd /var/www/html && vendor/bin/phpstan analyse web/modules/custom/aim --memory-limit=512M"
```

Run JS/CSS/spelling tools via core's pinned toolchain
(`web/core/node_modules/.bin/<tool>`), not `npx` - `npx` resolves against
core's loose semver range and can drift from whatever version CI actually
pins. `corepack enable && cd web/core && yarn install --immutable` installs
the exact pinned versions first.

Fix small findings (typos, style violations, a genuine new dictionary word)
inline in the same pass rather than queuing them for later review.

`drupal/core-dev` (dev-only) is what provides `drupal/coder` (phpcs) and
phpstan/mglaman-phpstan-drupal. Installed 2026-09-09 via `ddev composer
require --dev drupal/core-dev:^11.4 -W` - plain `composer require` fails
here without `-W` (`--with-all-dependencies`): `phpunit/phpunit` needs
`sebastian/diff ^6.0.2`, but this project's lock had it pinned at `7.0.1`.
`-W` downgrades it to `6.0.2`, which `drupal/core`'s own constraint
(`^4 || ^5 || ^6 || ^7`) still permits, so this is safe - confirmed via a
`--dry-run` first: 85 new dev-only packages, that one downgrade, zero
removals. `vendor/bin/phpcs` only works through `ddev exec` (or inside the
container generally) - the host has no `php` on `PATH`, so running it
directly from a host shell fails with `env: 'php': No such file or
directory`; that's an environment gap, not a composer.json problem.

### Drupal gotchas

- The `administrator` role has **every** permission implicitly, including
  dynamically registered ones. Never diagnose it as missing a permission -
  look at other roles when a permission check unexpectedly fails.

### Docs

"Update docs" means CLAUDE.md and README.md together (and a DEVELOPING.md if
one exists for technical/architecture detail). Keep user-facing content in
README.md, technical/developer detail in DEVELOPING.md, and operating rules
here in CLAUDE.md. Don't create a DEVELOPING.md speculatively - only once
there's enough implementation detail to warrant separating it from README.md.
