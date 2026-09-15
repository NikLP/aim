# CLAUDE.md

Operating guide for Claude Code sessions in this repo. See
[README.md](README.md) for the project pitch and
[adr/](adr/0000-index.md) for the decision records - including
[ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md), the original,
deeper rationale (market comparison, risk analysis) ADR-0001 through 0009
are downstream of, folded into this directory 2026-09-10 (previously a
standalone root-level document). This file is the day-to-day reference:
current state, exact commands, and gotchas worth not rediscovering. Don't
restate an ADR's reasoning here - link to it.

## Project snapshot

Drupal-native AI agent memory infrastructure. **Distinct venture** - not a
feature of, or dependency on, any sibling suite. Status: working PoC - real
`aim_fact` entity, real vector search, real embeddings.

**Scope stays broad.** Work so far follows one path (conversational input
turned into extracted facts, the "mouth-to-website" track) as a convenient
first exercise - not a decision to drop the other three categories from
[ADR-0001](adr/0001-storage-and-scope-model.md)'s scope model: role memory,
site memory, general per-user/per-developer memory are all equally in
scope.

Environment: DDEV, `drupal11` type, PHP 8.4, MariaDB 11.8, docroot `web/`.

**Enabled:** `aim`, `aim_chatbot`, `aim_tool`, `aim_tool_oauth`, `tool`,
`mcp_server`, `mcp_server_tool_bridge`, `mcp_server_oauth`, `simple_oauth`,
`simple_oauth_21`, `simple_oauth_server_metadata`,
`simple_oauth_client_registration`, `simple_oauth_pkce`, `consumers`,
`ai_agents`, `ai_assistant_api`, `ai_chatbot`, `ai_search`,
`ai_provider_anthropic`, `ai_provider_amazeeio`, `ai_provider_ollama`,
`ai_vdb_provider_mariadb`, `search_api`, `views`, `queue_ui`,
`serialization` (core - see `mcp_server_tool_bridge` gotcha below,
enabled 2026-09-13, not previously required). See "MCP OAuth" below for
the OAuth block (`mcp_server_oauth` through `consumers`), added
2026-09-13.
**Composer-present but not enabled:** `eca`/`aim_eca` (don't enable without
being asked), `ai_context` (CCC).
**Gotcha:** `mcp_server_tool_bridge`'s Composer package name is
self-doubled (`drupal/mcp_server_tool_bridge-mcp_server_tool_bridge`) due
to a real drupal.org packaging bug for this project - the plain
`drupal/mcp_server_tool_bridge` name resolves to an empty metapackage stub
with no installable code. See ADR-0013's build addendum before assuming
the plain name is broken beyond repair or re-deriving this. Same bug hit
`mcp_server_oauth` too, see "MCP OAuth" below.
**Gotcha, fixed 2026-09-13:** pinned at `1.0.0-beta1` (only tagged
release), `McpToolConfigDeriver::convertInputDefinitionToSchema()` mapped
Tool API's list/map inputs straight to bare `{"type": "array"}`/
`{"type": "object"}` with no recursion into `getItemDefinition()`/
`getPropertyDefinitions()` - confirmed live via `aim_remember`'s `facts`
input (a list of maps), which produced no `items`/`properties` at all, no
way for an MCP client to know the nested shape. Traced upstream: the
`1.x` branch had already deleted that method entirely (commit `569be5d`,
issue #3613896, merged 2026-09-11, unreleased) in favor of delegating to
`drupal/tool`'s `ToolDefinitionSerializer`/`ContextDefinitionNormalizer`,
which already recurses correctly. No MR needed - moved the Composer
constraint to `1.x-dev` instead of patching a method that no longer
exists on the target branch. Re-pin to a real tag once one ships past
beta1. This pulled in a new hard dependency on core's `serialization`
module (not declared by beta1) - schema generation throws
`LogicException` without it enabled.

**Custom module dependency audit, 2026-09-13.** First pass on this
wrongly concluded `aim` didn't need its own `composer.json`, reasoning
only from this site's build (root `composer.json` already covers
everything actually installed here). That missed the real point: `aim`
is its own git repo *because* it is meant to ship as an independent
drupal.org project eventually, not just live conveniently inside this
site shell - see this file's Project snapshot section. A future site
running `composer require drupal/aim` gets none of `aim`'s real Drupal
package dependencies unless `aim` declares them itself; the site's root
`composer.json` is only this site's own manifest, not something a
downstream consumer of a published `drupal/aim` ever sees.

Added `web/modules/custom/aim/composer.json` (one file covering `aim` +
`aim_chatbot` + `aim_tool` + `aim_eca`, since they all live in this one
repo/project - mirrors how real multi-submodule contrib projects package):
`require` holds `drupal/ai`, `drupal/ai_vdb_provider_mariadb`,
`drupal/search_api`, `drupal/ai_agents`, `drupal/tool
(^1.0.0-beta8)`, and `drupal/mcp_server_tool_bridge-mcp_server_tool_bridge`
pinned to `1.x-dev` - deliberate choice (Nik's call, 2026-09-13, over the
safer stable-`^1.0` alternative) to ship the nested-schema fix by default
rather than wait for a tagged release; re-pin to a real tag once one
ships past beta1. Note for whoever revisits this: a `-dev`-suffixed
constraint string implies its own stability flag, so this does *not*
force a consuming site into `minimum-stability: dev` site-wide - only
this one requirement is affected, same as how this project's own root
`composer.json` picked it up earlier without touching its
`minimum-stability: stable`. AI *provider* modules
(`ai_provider_anthropic`/`amazeeio`/`ollama`) are deliberately excluded -
`aim` only calls the generic `ai.provider` service, provider choice is a
site config decision, not aim's dependency. `drupal/eca`,
`drupal/ai_context`, `drupal/queue_ui` are `suggest`, not `require` -
optional, not needed for `aim`'s own code to run. Composer package name
for the bridge dependency uses the self-doubled name from this file's
gotcha above, not the semantically-correct plain name - the plain name is
still the broken empty-stub package upstream; revisit both this and the
`1.x-dev` pin together once drupal.org's packaging bug and a real tagged
release both land.

Drupal's own `.info.yml` `dependencies` key (which supports version
floors, e.g. `tool:tool (>=1.0.0-beta8)`) is the *complementary*
mechanism, not a substitute - it governs Drupal's own enable/disable
dependency checks between already-installed modules, while
`composer.json` governs what Composer pulls in for a fresh install.
`aim_tool.info.yml` now floors `tool:tool` at `(>=1.0.0-beta8)` to match
`mcp_server_tool_bridge.info.yml`'s own floor, since both consume the
same `ListInputDefinition`/`MapInputDefinition` API - `mcp_server_tool_bridge`
itself can't get an info.yml floor from `aim_tool` the same way yet: a
`1.x-dev` checkout carries no `version:` in its own `.info.yml`, so there
is nothing to compare against until a real tag ships past beta1 (the
`composer.json` pin above is the only lever available for that one until
then). Also fixed in passing: `aim.info.yml` declared `views:views`,
copied from the `project:module` pattern used elsewhere in this file -
Views is core, real convention (checked against core's own
`views_ui.info.yml`) is `drupal:views`. Checked every other custom
module's declared `dependencies:` against its actual
`use Drupal\*`/service/entity-storage calls (`aim`, `aim_chatbot`,
`aim_eca`) - no other mismatch found.

**Trust CLAUDE.md's module lists as intent, verify before relying on
them.** Found 2026-09-12: `core.extension` config had `ai_agents` recorded
as installed while its files were absent from the codebase entirely and
missing from composer.json/composer.lock - broken silently for an unknown
period despite this file listing it as enabled, discovered only because
enabling an unrelated module (`aim_tool`) tripped a dependency-graph
validation error. Fixed via `composer require drupal/ai_agents`. If a
`drush en`/`drush cr` fails referencing a module this file says is already
enabled, check `ddev drush pm:list` and the module's actual directory
before assuming the failure is about what you just changed.

**PoC deviation from [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)
(temporary, not abandoned):** no Content Moderation / draft-to-trusted gate -
every fact is live the moment it's saved. Guardrails itself is *not* part of
this deviation - see below, it's live today. Re-introduce before any non-PoC
data goes in. The scope-bundle deviation from
[ADR-0001](adr/0001-storage-and-scope-model.md) is resolved - see "Scope as
bundles" below.

No Drupal module competes with `aim`'s actual scope (governed,
Guardrail-checked, extracted-and-consolidated, vector-searchable memory) -
checked directly against drupal.org, not assumed. The one real overlap,
CCC, is handled per [ADR-0008](adr/0008-chatbot-integration-mechanism.md).

## Architecture decisions

Binding until superseded. Full context/consequences for each in
[adr/](adr/0000-index.md):

1. **Storage** - entities + `VECTOR` fields via `ai_vdb_provider_mariadb`,
   in-database mode only. ([ADR-0001](adr/0001-storage-and-scope-model.md))
2. **Scope model** - four bundles (user/role/site/case), composable at
   retrieval. ([ADR-0001](adr/0001-storage-and-scope-model.md))
3. **Governance** - permissions/roles + Content Moderation draft-to-trusted
   gate; nothing LLM-extracted is auto-trusted.
   ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md))
4. **Processing** - Queue API + a dedicated crontab entry, never
   `hook_cron`. ([ADR-0003](adr/0003-async-processing-dedicated-crontab.md))
5. **Sovereignty** - extraction/consolidation must support Ollama; hosted
   providers are additional options, not replacements.
   ([ADR-0004](adr/0004-sovereignty-and-poc-build-order.md))
6. **Build order** - prove schema/logic with interactive Claude Code +
   local embeddings before any unattended provider.
   ([ADR-0004](adr/0004-sovereignty-and-poc-build-order.md))
7. **Guardrails mandatory** - every candidate fact runs through
   `drupal/ai`'s Guardrails before it's written anywhere.
   ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md))
8. **Generative/planning surface** (spec → Recipe → built site) is in
   scope; no unattended `drush recipe apply` on an AI-generated recipe.
   ([ADR-0009](adr/0009-recipe-apply-safety-gate.md))

## AI dependency map

Not one "AI" - check this before assuming a step needs a paid API call:

| Step | Needs | Cost |
| --- | --- | --- |
| Discovery, extraction, conflict/merge decisions, Recipe generation | Reasoning-grade LLM | Expensive |
| Consolidation - similarity-threshold cases | Vector math only | Free |
| Embedding generation (every write and query) | Small embedding model | Cheap, local-friendly (Ollama) |

**Current site config:** site-wide default chat provider is
`amazeeio`/`claude-5-sonnet` (`ai.settings`), switched from
`anthropic`/`claude-sonnet-5` on 2026-09-11 - amazee's endpoint is
currently free, Anthropic's Console API key is metered, and this module
had no cost visibility into what was hitting it. Same swap applied to
`search_api.server.aim_vector` `backend_config.chat_model` (cost-neutral
either way - confirmed by
reading `ai_search`'s `EmbeddingBase`/`ContextualEmbeddingStrategy`, this
value only feeds `textChunker->setModel()` for chunk token-sizing, no
`->chat()` call is ever made from it). `search_api.server.aim_vector`
`backend_config.embeddings_engine` is `ollama__nomic-embed-text:latest`
(768-dim, switched from `amazeeio__mistral-embed`/1024-dim on 2026-09-10 -
see "Local Ollama" below) - Anthropic has no embeddings API at all, don't
try to point `embeddings_engine` at it. `AimCommands`' `--provider`/
`--model` options default to `NULL` and resolve the site-wide default via
`AimMemoryManager::getDefaultChatProvider()` rather than a hardcoded PHP
default, so they follow whatever the site default is set to (this is why
the `ai.settings` change alone was enough to move `aim:extract`/
`aim:consolidate`, no code change needed).
`ai_assistant_api.ai_assistant.aim_demo_assistant`'s `llm_provider`/
`llm_model` follow the site default the same way, as of 2026-09-14 - see
Chatbot section.

**Local Ollama** (`ai_provider_ollama`, host_name/port config): points at
the *host's* Ollama install (`http://host.docker.internal:11434`), not a
container-local one. Don't use the `tyler36/ddev-ollama` DDEV addon
alongside this - it runs a second Ollama daemon in its own container with
its own empty model volume, completely unaware of the host's model cache,
so anything pulled through it (or through a provider pointed at it)
re-downloads from scratch even if the same model already exists on the
host. Host-side prerequisite: Ollama defaults to binding `127.0.0.1` only,
which the DDEV network can't reach - needs `OLLAMA_HOST=0.0.0.0:11434` set
on the host's Ollama service (e.g. `systemctl edit ollama` + restart)
before `http://host.docker.internal:11434` becomes reachable from the web
container. Only `nomic-embed-text` (embeddings-only, 768-dim) is loaded so
far - extraction/consolidation still need a chat-capable model pulled
before Ollama can cover the "Reasoning-grade LLM" row above.

A Claude Pro/Max subscription cannot power an unattended `drupal/ai`
provider (Anthropic prohibits subscription OAuth for third-party
integrations) - needs a real Console API key, or stays on Ollama.

**Gotchas:**

- Anthropic's structured-output mode requires `additionalProperties: false`
  on *every* object level of a JSON schema (amazee's Bedrock-backed mode
  only needed it, and an explicit top-level `'type' => 'object'`, at the
  top level) - already fixed in `extractFacts()`/`classifyPair()`
  (`AimMemoryManager.php`).
- Test AI provider behavior through Drupal's own `ai.provider` service, not
  by calling a third-party API directly with an extracted key - a direct
  call to amazee.ai's `/v1/models` once gave a misleading `401` while the
  actual code path (`$provider->embeddings(...)`) worked fine with the same
  key. The two paths can give contradictory answers; trust the abstraction.
- An embeddings provider/model swap invalidates every existing vector
  (different embedding space, possibly different dimension) - full reindex
  required, not incremental. See README.md's "Setting up vector search"
  runbook.

## Vector search

Server `aim_vector` (backend `search_api_ai_search`, VDB provider
`mariadb`), index `aim_vector_index` over `entity:aim_fact`, collection
table `aim_facts`. `text` indexed as `main_content`; `scope`/`subject`/
`source` as `attributes`. `index_directly` is off by default (inline
indexing blocks the request on an external API call) - index via
`drush search-api:index aim_vector_index`, or let the consolidation queue
worker's own `reindex()` call handle it. `aim_facts` has a real MariaDB
11.7+ HNSW `VECTOR INDEX`, not a brute-force scan (confirmed via
`SHOW CREATE TABLE aim_facts`).

**Gotchas:**

- Per-field indexing role (main content vs. attribute) lives in
  `ai_search.index.<index_id>` simple config, not the index entity itself -
  skip a field there and it's silently ignored at embedding time, no error.
- The collection table only gets created/ALTERed on index *update*, not
  *create* - a freshly created index entity needs a second `->save()` to
  get its attribute columns (`aim.install`'s `hook_install()` does this
  automatically on module enable). `createCollection()` is not idempotent
  despite its docstring - rerunning it against an existing table throws
  instead of no-op'ing; fix is `DROP TABLE aim_facts` and re-save, not
  fighting the exception.
- `drush search-api:clear aim_vector_index` can itself drop and reprovision
  `aim_facts` down to just the base columns (`content`/`drupal_entity_id`/
  `drupal_long_id`/`server_id`/`index_id`/`embedding`), silently losing
  `scope`/`source`/`subject`/`text` - a subsequent `search-api:index` then
  fails `mysqli_sql_exception: Unknown column 'scope'`. Fix is the same
  index entity `->save()` as above to restore the attribute columns, not
  running `search-api:clear` again. After a `DROP TABLE`-and-reindex cycle
  (e.g. following an embeddings dimension change), reindex directly with
  `search-api:index` and skip the `search-api:clear` step entirely.
- `drush config:status` is the wrong tool for checking whether live config
  matches a module's `config/install` - it compares against the site's
  config **sync** directory, a separate mechanism.
- Never hand-type a config entity's `dependencies` or (Views) `cache_metadata`
  key when authoring `config/install` - both are computed by Drupal on
  `->save()` and silently diverge from a hand-typed guess. Re-fetch
  `\Drupal::config($name)->getRawData()` after a real save and use that
  (module/`uuid`/`_core` stripped).

## Scope as bundles

Built 2026-09-14, closing ADR-0001's flat-field PoC deviation. `aim_fact`'s
four scopes (user/role/site/case) are now real, code-defined bundles -
`entity_keys.bundle` on `AimFact`'s `#[ContentEntityType]` attribute, with
`AimHooks::entityBundleInfo()` (`src/Hook/AimHooks.php` - moved out of a
procedural `hook_entity_bundle_info()` in `aim.module` 2026-09-15, see
"Guardrails") declaring the four bundle labels.
No `bundle_entity_type` (no config entity like node types) - a site or
contrib module can register a fifth scope purely by implementing
`hook_entity_bundle_info_alter()` against `aim_fact`, no admin UI step
required. `AimMemoryManager::allowedScopes()` (public) replaced the old
hardcoded `ALLOWED_SCOPES` constant, reading
`entity_type.bundle.info`'s `getBundleInfo('aim_fact')` instead - every
scope-validation call site (`remember()`, `generateBenchmarkFacts()`,
`AimCommands::benchmark()`) now honors a bundle registered this way
automatically, without code changes.

**Deliberate design choice: the bundle key field is still named `scope`**,
not renamed to `type`. Checked core's own precedent before assuming `type`
was "the" convention (it isn't): node uses `type`, media uses `bundle`
literally, comment uses `comment_type`, taxonomy_term uses `vid` - four
core entities, four different literal names, all domain-specific rather
than a fixed generic word. `scope` fits that same pattern. Bundle fields
are never auto-created by Drupal for a `bundle_entity_type`-less entity
either way - they must be explicitly defined in `baseFieldDefinitions()`
same as any other field, so there was a real name choice to make here.
Keeping the name `scope` meant every
existing `'scope' => $value` in a `create()` values array, every
`$query->addCondition('scope', ...)`/`->condition('scope', ...)`, the
Views field ID, and `search_api.index.aim_vector_index.yml`'s
`field_settings.scope` (`property_path: scope`) all kept working completely
unchanged - only reads of the value now go through `$fact->bundle()`
instead of `$fact->get('scope')->value` (idiomatic, and the field itself
dropped its old `list_string`/`allowed_values` shape in favor of a plain
required `string`, since bundle validity is governed by
`hook_entity_bundle_info()` now, not a field setting). This is why the
"would restricting search_api's `datasource_settings` by bundle help"
question (raised the same day) resolved to "no, not as a blanket policy" -
`recall()`'s existing `addCondition('scope', $scope)`, only added `if
(!empty($scope))`, already gives ADR-0001's "composable at retrieval"
behavior (a no-scope query searches every bundle in one call) without an
index restriction, and the field being real-not-fake meant a rename wasn't
forced either.

The only file needing an actual formatter change was
`views.view.aim_facts.yml`'s `scope` column: its `type: list_default`
formatter required an `AllowedValuesInterface` field and would have broken
against the new plain `string` field, so it moved to `type: string`
(matching `subject`/`source`'s own formatter) - everything else in that
View (no scope filter existed, `filters: {}`) needed no change.
`aim_tool`'s `aim_remember`/`aim_recall` Tool plugins and `aim_chatbot`'s
locked-`scope=site` FunctionCall plugins needed zero changes - both only
ever pass `scope` through as a string to `AimMemoryManager`, never touch
`$fact->get('scope')` directly. `aim_eca`'s `FactWrite`/`FactQuery`/
`FactState` were deliberately left unconverted, per this file's ECA
integration section (`eca_tool` may make the whole submodule redundant).

No migration needed (PoC, no real data - see "Schema/config changes during
early development" below) - a straight `drush pmu`/`drush en` cycle of
`aim` re-provisions the entity type fresh. Real gotchas hit doing that
reinstall, none specific to bundles but all reproducible and worth not
rediscovering:

- **A `drush en modA modB modC` batch does not roll back per-module on a
  later failure.** Drupal appears to flag every requested module Enabled in
  `core.extension` up front, then installs each module's config +
  `hook_install()` in dependency order one at a time. If an early module's
  `hook_install()` throws (as `aim`'s did here, see next point), later
  modules in the same batch are left `Enabled` per `drush pm:list` while
  their own config was never imported and `hook_install()` never ran - a
  genuinely broken half-installed state, not something a repeat of the
  same batch command fixes (it just says "Already installed" for the
  stuck modules). Fix: uninstall exactly the stuck modules (not the ones
  that installed cleanly) and re-enable them on their own.
- **`drush pmu` does not reliably remove all of a module's own
  `config/install` entities**, reproduced twice (`aim`'s own 5 configs,
  `aim_chatbot`'s 2) across separate uninstall cycles in the same session.
  The orphaned config then throws `PreExistingConfigException` on the next
  install attempt, naming the exact leftover config names - fix is
  deleting those specific entities directly (`drush config:delete`), not
  fighting the exception or assuming the uninstall itself needs retrying.
- **`config/install/search_api.server.aim_vector.yml` was stale**,
  unrelated to bundles but only surfaced by actually reinstalling `aim` for
  the first time since the 2026-09-10 embeddings switch documented in this
  file's AI dependency map: it still shipped the pre-switch
  `amazeeio__mistral-embed`/1024-dim settings, not the live
  `ollama__nomic-embed-text:latest`/768-dim config. A straight reinstall
  would have silently reverted the site's embeddings engine with no error
  (the collection table would just get rebuilt at the wrong dimension).
  Fixed the file to match the documented live state. Lesson: a config/
  install file drifts silently the moment live config is changed via the
  UI/`drush config:set` without a matching re-export - re-verify any
  config/install file against documented live state before trusting a
  reinstall to reproduce it, the same posture this file's other config
  gotchas already take.
- `aim.install`'s `aim_install()` re-save (to force the collection table's
  attribute-column ALTER, see "Vector search" above) can itself collide
  with a stray `aim_facts` table left over from a prior failed attempt in
  the same debugging session, throwing the same non-idempotent
  `createCollection()` error this file's Vector search gotchas already
  describe. Same fix applies: `DROP TABLE aim_facts` and re-save once,
  cleanly, not on top of a half-finished attempt.

**`subject`/`subject_uid` as per-bundle fields - tried and reverted,
2026-09-14.** Briefly moved both out of `baseFieldDefinitions()` into
`AimFact::bundleFieldDefinitions()` (`subject_uid` only on `user`,
`subject` only on `role`/`case`), prompted by a design question (why
carry a dead field on a bundle that doesn't use it). Reverted the same
day: the split broke two unrelated core/contrib consumers that both
assume a field list built from `getBaseFieldDefinitions()`/
`getFieldStorageDefinitions()` is the whole story and never walk
`bundleFieldDefinitions()` - `ai_vdb_provider_mariadb`'s
`AiVdbProviderClientBase::isMultiple()` (threw `Table
'aim_facts__subject' doesn't exist` against real role/case consolidation
queries) and, separately, core's own `Drupal\views\EntityViewsData`
(silently degraded `views.view.aim_facts`'s `subject`/`subject_uid`
columns to `Broken` field handlers, throwing `Undefined array key
"element_type"` warnings on every render). Each is patchable in
isolation (a `hook_entity_field_storage_info()` implementation fixed the
first; a `hook_views_data_alter()` implementation fixed the second), but
the pattern generalizes badly - `aim` is headed for drupal.org (see
Project snapshot), so every future contrib/core integration point
(export, REST, a different Views display, anything else that assumes
"all fields are base fields") is a fresh chance to rediscover the same
bug class. The cleanliness win (no unused field on a bundle that doesn't
need it) wasn't worth an open-ended maintenance tax against a purely
cosmetic problem - `AimMemoryManager::subjectValue()`'s abstraction
already hid the two-fields-in-one-column reality from every call site
regardless of whether they're base or bundle fields, so reverting cost
nothing on the read side. Back to two always-present base fields, both
`git checkout`-reverted to their pre-split shape (`subject` and
`subject_uid` both defined in `baseFieldDefinitions()`, unused on a given
bundle rather than absent). **Don't re-attempt this split** without a
concrete reason beyond schema tidiness.

## Guardrails

Live today, not part of the governance deferral
([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)). Set
`aim_write_guardrails` (`config/install`): `aim_max_length`
(`input_length_limit`, 2000 chars), `aim_no_markup` (`regexp_guardrail`,
blocks `<script>`-shaped markup), `stop_threshold: 1.0`. Neither plugin
calls `->chat()` - zero LLM cost.

Wired in via `AimMemoryManager::runGuardrails()` (public, still used
directly by consolidation's UPDATE path - see below) and, as of
2026-09-15, `AimHooks::factPresave()` (`src/Hook/AimHooks.php`), which
calls it on every `aim_fact` save regardless of path. A stop throws
`\InvalidArgumentException` (same exception every caller already catches
for scope validation). If the guardrail set is ever removed from a site,
checking is silently skipped rather than blocking every write.

**Guardrails + consolidation enqueue moved to entity hooks, 2026-09-15.**
`remember()`, `createFactsFromCandidates()`, and `aim_eca`'s `FactWrite`
used to call `runGuardrails()` and `enqueueForConsolidation()` explicitly,
each remembering to do both. Now `AimHooks::factPresave()` (runs
`runGuardrails()` against the `text` field, can throw to abort the save)
and `AimHooks::factInsert()` (runs `enqueueForConsolidation()`) -
universal for every save path, including the new entity add/edit forms
below, default content import, and anything future, not just the callers
that remembered to call both explicitly. All three original call sites
had their explicit calls stripped. Consolidation's UPDATE path
(`decideAndApply()`) is deliberately untouched - it calls
`runGuardrails()` *before* deciding whether to save at all, to downgrade
the decision to a synthetic BLOCKED outcome even in dry-run mode when
nothing gets saved; that can't move into a presave hook, which only fires
on an actual save.

**Hooks live in a class, not `aim.module` - built this way from the
start, not migrated.** `src/Hook/AimHooks.php` (`#[Hook('aim_fact_presave')]`/
`#[Hook('aim_fact_insert')]`/`#[Hook('entity_bundle_info')]`, the last one
moved here too for consistency rather than leaving one procedural hook
behind on its own) uses core's OOP hook system (`\Drupal\Core\Hook\Attribute\Hook`,
stable since Drupal 11.1, confirmed available and exercised live on this
site's Drupal 11.4.6) instead of a `.module` file - `aim.module` itself
was deleted, nothing else needed it. Real precedent copied directly: the
`annotations` module (`dotdev` suite, a sibling project) has its own
`AnnotationsHooks` class, same shape. `AimHooks`' constructor
type-hints `AimMemoryManager` directly rather than pulling it via
`\Drupal::service('aim.memory_manager')` - Hook-attributed classes are
auto-registered as autowired services (per the attribute's own docblock),
so constructor DI works here the same as everywhere else in this
codebase.

**Real gotcha hit and fixed the same session: autowiring `AimMemoryManager`
failed outright** (`drush cr` error: "no such service exists... maybe
alias this class to the existing 'aim.memory_manager' service").
Symfony's autowiring matches a constructor's type-hint against a service
ID equal to the fully-qualified class name - `aim.memory_manager`
(`aim.services.yml`) is a manually-defined service under a symbolic ID
with explicit constructor arguments, not that FQCN, so nothing satisfied
`AimHooks`' `AimMemoryManager $memoryManager` parameter. Fixed with a
one-line class alias in `aim.services.yml`
(`Drupal\aim\Service\AimMemoryManager: alias: aim.memory_manager`) -
same fix `annotations.services.yml` already uses for its own
`AnnotationStorageService`/`AnnotationsHooks` pair, confirmed by reading
it before applying this. No manual `aim.hooks` service entry was needed
on top of the alias - autowiring alone resolved `AimHooks` once the alias
existed, unlike `annotations.services.yml`'s belt-and-braces manual entry
(that module's `annotations.hooks` service, defined explicitly alongside
its own alias - not required here, and not added, to keep this to the
minimal fix). Every write path re-verified live after this fix
(guardrail-rejected `remember()`, a batch with one blocked candidate,
`generateBenchmarkFacts()`'s bypass, all four entity routes) - identical
behavior to the procedural version.

**Real bug hit and fixed the same session: guardrail rejections stopped
matching `catch (\InvalidArgumentException)`.**
`\Drupal\Core\Entity\Sql\SqlContentEntityStorage::save()` catches any
`\Exception` thrown by a presave hook and rethrows it as
`EntityStorageException($e->getMessage(), $e->getCode(), $e)` - same
message, different class. With guardrails now running inside `save()`
instead of before it, every existing `catch (\InvalidArgumentException)`
around a fact write (`AimCommands`, `aim_tool`'s `AimRemember`,
`aim_chatbot`'s `AimRemember`, `createFactsFromCandidates()`'s
per-candidate catch) would have silently stopped catching guardrail
rejections, surfacing an uncaught `EntityStorageException` instead - a
real regression only caught by testing the rejection path live via
`drush php:eval`, not by `phpcs`/`drush cr` alone. Fixed with
`AimMemoryManager::saveFact()` (public): saves the entity and, if
`EntityStorageException::getPrevious()` is an `\InvalidArgumentException`,
rethrows that original exception instead - restores the "same exception
every caller already catches" contract in one place. `remember()`,
`createFactsFromCandidates()`, and `aim_eca`'s `FactWrite` all call
`saveFact()` now instead of `$entity->save()` directly. Verified live:
a guardrail-rejected `remember()` call, a batch with one blocked
candidate (batch continues, `blocked` count increments), and
`generateBenchmarkFacts()`'s bypass (see below) all behave exactly as
before.

`createFactsFromCandidates()` still catches per-candidate so one rejected
fact doesn't abort a batch - the catch now wraps `saveFact($entity)`
instead of a standalone pre-check.

## Extraction

`drush aim:extract <file> [--provider] [--model] [--source] [--index]`
sends the file to a chat provider with a structured-JSON-schema request,
creates one `AimFact` per returned item (scope/subject classified by the
model). The source file itself is never stored - only the extracted facts
persist. **Standing constraint, not a PoC shortcut:** no conversation or
transcript recording - the `source` field is a short provenance pointer,
never the raw dialogue. Don't add a field/table storing full transcripts
without this being explicitly revisited first.

`--subject-uid` (uid only, see below) attaches every model-classified
`scope=user` candidate to that one real account; without it every
`scope=user` candidate is skipped (with a warning), unconditionally -
extraction never attempts to match the model's own freeform subject text
against the accounts table. See
[ADR-0007](adr/0007-user-scope-requires-real-account.md) for why user
scope requires a real account at all, and
[ADR-0011](adr/0011-extraction-explicit-subject-uid.md) for why the match
can't be model-guessed.

**`resolveAccount()` tightened to uid-only for write paths, 2026-09-15.**
`AimMemoryManager::resolveAccount()` accepts either a numeric uid or an
exact username-string match (`loadByProperties(['name' => $value])`) - not
fuzzy, but still a real risk: an exact match on a typo'd string can
silently attach a fact to the wrong real account if the typo happens to
collide with someone else's actual username. A new
`resolveAccountByUid()` (uid only, no username fallback) replaced it on
every write path - `remember()`'s `subject` for `scope=user` (so
`aim:remember --subject` and `aim_tool`'s `aim_remember` too, both call
`remember()`/share its docs) and `createFactsFromCandidates()`'s
`$subjectUid` (`aim:extract --subject-uid` above). `resolveAccount()`
itself is untouched and still dual-format, since it also backs read paths
(`recall()`'s `--subject-uid` filter, `aim_eca`'s `FactQuery`/
`FactState`) where a wrong match only returns a wrong query result, not a
permanent misattributed write - a materially different risk. The two
legitimate sources of a write-path subject_uid value stay: the current
authenticated user (already how `AimRemember`'s MCP-tool default works)
and a widget-selected value (the still-unbuilt fact-ingress form's
`entity_reference` autocomplete, see "Ideas raised", never emits freeform
text either). `aim_eca`'s `FactWrite` still calls the dual-format
`resolveAccount()` for its own `scope=user` subject - not touched here,
narrower scope than this decision covered; revisit if `aim_eca` is ever
un-deprioritized.

**Skill:** `.claude/skills/aim-discovery/` ("the grill") - a structured
discovery interview that distills each topic into a summary and runs it
through `aim:extract`, mostly `scope: site`. Doesn't generate a Recipe
(that's [ADR-0009](adr/0009-recipe-apply-safety-gate.md)'s territory).

## CLI agent adapter

For a caller (Claude Code, or any agent) that already decided what's worth
remembering - no extraction LLM round-trip. See
[ADR-0006](adr/0006-agent-native-write-path.md) for why this exists
alongside `extract()`.

- `drush aim:remember <text> [--scope] [--subject] [--source] [--state]
  [--category] [--asserted]` - validates scope, creates the fact directly,
  prints its ID. `--category` resolves comma-separated term names against
  the `aim_category` vocabulary (admin-curated - a name with no matching
  term is skipped, never auto-created). The vocabulary itself ships in
  `config/install/taxonomy.vocabulary.aim_category.yml` (created
  programmatically 2026-09-11, not hand-typed, per this file's own "never
  hand-type a config entity" gotcha) - a fresh install gets it
  automatically; terms are deliberately not seeded, add real ones via
  `/admin/structure/taxonomy/manage/aim_category/add` as they emerge.
  `--asserted`
  sets when the fact became true in reality if different from today (any
  `strtotime()`-parseable string); empty means "same as created" - see
  CLAUDE.md's "Ideas raised" section, formerly "Bi-temporal fact validity,"
  for why a single field was judged enough.
- `drush aim:recall <text> [--scope] [--subject] [--subject-uid] [--limit]
  [--format]` - real semantic query against `aim_vector_index`.
  `--format=json` for a parsing caller.

Both live on `AimCommands`, backed by `AimMemoryManager` (also used by
`aim_eca` and the chatbot).

**Case IDs are minted server-side, not by convention.** Built 2026-09-14,
following on from "Scope as bundles" above. `remember()` with `scope=case`
and no `subject` mints one (`case-<8 hex chars>`, from Drupal's own `uuid`
service, truncated) and stamps it onto the created fact rather than
requiring every caller to invent and agree on a subject-naming format
themselves - `aim:remember`'s success message and `aim_tool`'s
`aim_remember` (`case_id` output) both surface the resolved ID so the
caller can pass it back as `subject` on later calls to add to the same
case. Passing `subject` explicitly (an existing case ID) always wins -
minting only happens when it's omitted. Deliberately narrow: `subject` is
the only field this touches, `source` is untouched and still means
whatever provenance the caller already passes - keeping the two apart was
the whole point of this design, see the case-scope-as-session-id notes
this superseded.

**Gotcha:** drush runs as anonymous by default, and `ai_search`'s backend
drops any match anonymous can't view - silently, no error. `recall()`
account-switches to uid 1 for the query duration (`account_switcher`
service) rather than setting `search_api_bypass_access` - narrower than a
blanket bypass, but still depends on uid 1 holding an `is_admin` role. The
actually-correct fix is ADR-0002's deferred governance layer. Same trap
applies to any `search_api` query against an access-controlled entity run
from drush/cron.

**Skill:** `.claude/skills/aim-memory/` - reach for `aim:remember`/
`aim:recall` continuously through a session, framed as one capability with
two directions. Don't remember what's already available from context.

## Consolidation

`drush aim:consolidate [--scope] [--provider] [--model] [--auto-threshold]
[--ambiguous-threshold] [--dry-run]` - on-demand sweep. Algorithm, schema,
and thresholds in
[ADR-0005](adr/0005-consolidation-algorithm.md). **Current threshold
defaults:** `auto_threshold: 0.09`, `ambiguous_threshold: 0.45` -
recalibrated 2026-09-10 against `ollama__nomic-embed-text:latest`
(previously 0.05/0.20, tuned for `mistral-embed`, went stale the moment
`embeddings_engine` switched - see AI dependency map above).

**Built 2026-09-14: admin-editable, not just a code constant.** Both
thresholds live in `aim.settings` config, editable at
`/admin/config/aim/settings` ("Consolidation thresholds" group) - see
"Admin settings" below. `AimMemoryManager::DEFAULT_AUTO_THRESHOLD`/
`DEFAULT_AMBIGUOUS_THRESHOLD` still exist as the value `config/install`
ships on a fresh install and the in-code fallback if `aim.settings` is
ever missing, read via the new `getAutoThreshold()`/
`getAmbiguousThreshold()` methods - nothing reads the constants directly
at runtime anymore (`AimConsolidateQueueWorker` and `AimCommands`'
`aim:consolidate` both switched to the getters, the CLI's
`--auto-threshold`/`--ambiguous-threshold` options now default to `NULL`
and fall through to the live config value, same pattern
`resolveChatProvider()` already used for provider/model).

First pass set auto-threshold to 0.12 from two known duplicate pairs
(0.059/0.082). Caught too loose by live testing the same day: "Nik likes
chocolate biscuits" vs. "Nik likes chocolate digestives" (genuinely
distinct, not a restatement) scored 0.1186 and auto-merged with zero LLM
review, silently dropping the digestives fact from recall. Pulled back to
0.09, clearly below that false positive - this embedding model apparently
packs "related but distinct" closer to "duplicate" than mistral-embed did,
so the safe no-review auto-merge band is narrower here. Small sample -
re-check as real data grows, and prefer widening the ambiguous band over
the auto-merge band if it happens again.

**Automated path:** `AimConsolidateQueueWorker` (plugin ID
`aim_consolidate`) - `remember()`/`createFactsFromCandidates()` enqueue
each new fact right after save. Carries no `cron` key on its
`#[QueueWorker]` attribute, so `hook_cron`/`drush cron` never touches it -
drain only via:

```crontab
* * * * * ddev exec drush queue:run aim_consolidate
```

`processItem()` calls `reindex()` before `consolidateFact()` - without
this, two facts enqueued back to back would each be asked to consolidate
before the other was indexed (`index_directly` is off) and never find each
other as neighbors.

**Gotchas:**

- `EntityDefinitionUpdateManager::getFieldStorageDefinition()` returns
  `NULL` for a field that was never installed via the update manager - use
  `\Drupal::service('entity_field.manager')->getBaseFieldDefinitions('aim_fact')`
  instead (needs `drush cr` first if the class was just edited).
- The vector index has no idea `expires` exists (not an indexed attribute)
  - `findNearestNeighbor()` and `recall()` both filter out
    already-superseded facts in PHP, or a retired fact keeps resurfacing.
- `scope: user` neighbor matching over-fetches (5x the limit) and
  post-filters on `subject_uid` in PHP, since it isn't an indexed
  attribute. Fix identified 2026-09-10, not yet built: index `subject_uid`
  as a search_api attribute (same mechanism `scope`/`subject`/`source`
  already use) and filter server-side instead of over-fetching.

Manual trigger without a terminal: `drupal/queue_ui` at
`/admin/config/system/queue-ui` (per-queue "Run" button, explicit click
only - deliberately not a `cron` key on the worker, see ADR-0003).

## Benchmarking

`drush aim:benchmark [--scope] [--checkpoints] [--queries] [--cleanup]` and
`drush aim:benchmark-cleanup <tag>` - built 2026-09-10 to answer ADR-0010's
open question 5 (retrieval latency asserted safe, never measured) with a
real number instead of more reasoning about the SQL layer. Generates
synthetic facts from a small template/word-pool generator (not
`devel_generate` - composer-present but not wired to `aim_fact`'s bundle),
reindexes, and times a batch of `recall()` calls at each requested
fact-count checkpoint.

Deliberately bypasses the guardrail check and the consolidation queue:
synthetic text needs neither, and queuing thousands of facts for
LLM-mediated consolidation would turn a latency benchmark into an
uncontrolled reasoning-call bill the moment `aim_consolidate`'s crontab next
runs. Cost is predictable - one embedding-API call per generated fact, at
`reindex()` time, nothing else. Every generated fact is tagged
`source=<run tag>` so `aim:benchmark-cleanup` (or `--cleanup` on the same
invocation) can remove exactly that run's data. Since the 2026-09-15 move
of guardrails/enqueue into entity hooks (see "Guardrails" above), this
bypass now works by setting `aim_skip_hooks` (a plain, non-field property
on the created `AimFact`, not a real field) on every generated entity's
values array - `AimHooks::factPresave()`/`factInsert()` both check it
first and return early. Verified live 2026-09-15: the
consolidation queue's item count is unchanged before/after a
`generateBenchmarkFacts()` call.

**First real numbers (5 then 15 site-scope facts,
`amazeeio__mistral-embed`):** `recall()` averaged 450-540ms. At this scale
that's dominated by `recall()`'s own query-embedding network round trip to
the hosted provider, not by the SQL/HNSW search itself - see ADR-0010's
updated open question 5.

**Confirmed 2026-09-10, same checkpoints, `ollama__nomic-embed-text:latest`
(local):** `recall()` averaged 33-35ms - roughly 15x faster, confirming the
network-hop theory rather than the SQL/HNSW layer being the cost. Still not
yet run at meaningful scale (thousands of facts, to see whether recall time
actually grows with corpus size independent of the embedding call).

**Recommended next fix, not yet built:** cache query embeddings via
Drupal's Cache API, keyed on (query text, embeddings model ID) - the
mapping is deterministic per model, so this needs no invalidation logic at
all. This site has no Redis today (`cache.default` resolves to
`Drupal\Core\Cache\DatabaseBackend`), so start DB-backed; add Redis only if
that itself becomes a bottleneck. Complementary to a local Ollama
embeddings provider, not a substitute - caching only helps *repeat*
queries, a local model helps every query. See TODO.md.

## Chatbot

Migrated to `ai_agents` and split into the `aim_chatbot` submodule - see
[ADR-0008](adr/0008-chatbot-integration-mechanism.md) for the mechanism
and why. Current shape:

- **`aim_chatbot`** (`web/modules/custom/aim/modules/aim_chatbot/`) ships
  two `#[FunctionCall]` tools (`aim_chatbot:remember`/`aim_chatbot:recall`,
  `src/Plugin/AiFunctionCall/`) and the `ai_agents.ai_agent.aim_chatbot`
  config entity (`guardrail_set: aim_write_guardrails`, reusing the same
  set as every other write path). Both tools are locked to `scope: site`,
  hardcoded, not model-settable - a chat visitor isn't resolved to a real
  account, and unlocking `recall` too would let an anonymous visitor's
  broad question surface a real `scope: user` fact.
- **`ai_assistant_api.ai_assistant.aim_demo_assistant`** and
  **`block.block.olivero_aimdemochat`** also live in `aim_chatbot`'s
  `config/install` (moved there, not duplicated - `aim` core no longer
  depends on `ai_assistant_api`/`ai_chatbot` at all). The assistant's
  `ai_agent` field points at `aim_chatbot`; `AiAssistantApiRunner::process()`
  short-circuits to `AgentRunner::runAsAgent()` when that field is set, so
  the assistant's own `system_prompt`/`instructions` are **not** used -
  only the `ai_agent` entity's `system_prompt` governs. Block placement is
  `bottom-right` (`placement: toolbar` silently fails to render on this
  theme - a real, previously-hit bug, don't revert to it).
- **`llm_provider`/`llm_model` follow the site default, not a pinned
  value - flipped 2026-09-14.** Previously pinned to `amazeeio`/
  `claude-4-5-haiku` explicitly (a second place that had to be
  hand-updated on every provider swap, alongside `ai.settings` itself and
  `search_api.server.aim_vector`'s `chat_model`, and the exact kind of
  drift this file's other config gotchas warn about). Now
  `llm_provider: '__default__'` (`llm_model` empty) -
  `AiAssistantApiRunner::getProviderAndModel()` special-cases
  `'__default__'` and resolves via `AiProviderPluginManager
  ::getDefaultProviderForOperationType('chat')`, which reads
  `ai.settings`'s `default_providers.chat` fresh on every call (no cache
  layer of its own) - confirmed by reading
  `web/modules/contrib/ai/src/AiProviderPluginManager.php`. This covers
  the agent path too, not just a plain assistant: `AgentRunner::
  runAsAgent()` only receives whatever `getProviderAndModel()` already
  resolved, and the `ai_agent` config entity itself carries no separate
  provider/model of its own. Net effect: changing the site-wide default
  chat provider/model in the UI (`/admin/config/ai/settings`) now
  propagates to the demo assistant immediately, no code or config change
  needed - same self-following behavior `AimCommands`' `--provider`/
  `--model` already had (see AI dependency map above). **Does not
  extend to `search_api.server.aim_vector`'s `backend_config.chat_model`**
  - checked `SearchApiAiSearchBackend`'s settings form
  (`ai_search/src/Plugin/search_api/backend/SearchApiAiSearchBackend.php`),
  it has no `__default__`/site-default option, just a plain tokenizer-model
  dropdown with its own hardcoded gpt-3.5-shaped fallback - and it isn't
  really the same setting either way, per the AI dependency map above
  (token counting for chunk sizing, no `->chat()` call). That field still
  needs a manual update on any future chat-provider swap.

**Gotchas:**

- CSRF for `/api/deepchat` is a `token` **query parameter**, not a header
  and not literally named `csrf_token` despite the route/error text saying
  so - `POST /api/deepchat/session` returns the token, append as
  `?token=...`. Anonymous also needs the `access deepchat api` permission.
- `drush php:eval` can't exercise this - `AssistantMessageBuilder`
  resolves the current route, which is null outside a real HTTP request.
  Use `curl` against the live endpoint instead.
- A stale container cache after `AimMemoryManager`'s constructor gains an
  argument throws `ArgumentCountError`, not a code bug - `drush cr` fixes
  it. Check the cache before the code for this failure shape.
- `ai_agent`'s "tools" are the same `#[FunctionCall]`/
  `plugin.manager.ai.function_calls` type CCC's own tools use, not a
  bespoke `ai_agents`-only mechanism.
- `AimRecall::execute()` (the `aim_chatbot:recall` tool) still has no
  similarity-score threshold - any non-empty result set, even one where
  the best match is a poor one, gets formatted as "Relevant facts:" and
  handed to the model as-is, only the zero-rows case gets a special "No
  relevant facts found." **Tested end to end 2026-09-11** (real
  `/api/deepchat` calls against `aim_demo_assistant`, `amazeeio__
  claude-5-sonnet`) against the same 50-fact benchmark: a negation
  question ("has NeuralPulse acquired DataStream Tech?") answered
  correctly despite several unrelated facts in the retrieved set's low-
  score tail, and a genuinely unanswerable question (NeuralPulse's stock
  ticker, not in the corpus at all) got an honest "I don't have that on
  record" instead of a fabricated answer - the model's own judgment
  covered the gap this time. Not proof the gap is safe to leave: this is
  one capable model on a small, clean corpus, not a guarantee across
  models or scale. A minimum-score cutoff before formatting output is
  still the structural fix, not yet built.

**CCC (`ai_context`), not enabled on this site.** No Guardrails-equivalent
- its governance is Content Moderation for its own curated
`ai_context_item` entities, a different concern from filtering untrusted
LLM output. If/when CCC gets enabled, the integration shape is: `aim`
exposes tools (done), CCC holds curated policy about when to call them
("Pattern A" in CCC's own `docs/developers/rag.md`) - not `aim` becoming a
CCC content source. See ADR-0008.

## ECA integration (`aim_eca`, not enabled)

**Deprioritized 2026-09-12, not deleted.** A real project,
`eca_tool` (drupal.org), bridges ECA directly to Tool API - once mature it
would let an ECA model call `aim_remember`/`aim_recall` (see the "Tool
API + MCP exposure" section above) as a plain action with the same
scope/subject flexibility, making `FactWrite`/`FactQuery` below largely
redundant (`FactState` would not be replaced - it's an ECA *condition*,
a plugin type Tool API doesn't have). `eca_tool` is dev-only today (no
tagged release, same composer shape as the other pre-1.0 pieces in this
stack), and Nik is talking to Jürgen Haas (`eca` maintainer, well
regarded) about it directly - revisit this section once that lands rather
than investing further in `aim_eca`'s bespoke plugins now.

Submodule, zero dependency from `aim` core. `eca`/`aim_eca` are
composer-present but not enabled - don't enable without being asked.
**ECA has no action plugin type of its own** - it reuses Drupal core's
`#[Action]`/`plugin.manager.action` wholesale; `eca`'s `ActionBase`/
`ConfigurableActionBase` just add token support via a `final __construct()`
(no constructor DI in a plugin extending it - use a service locator, as
`AccountResolverTrait` does).

Three plugins, `Fact<Verb>`/`aim_fact_<verb>`: `FactWrite` (action, writes
a fact from an ECA model's token-supplied values - same write path as
`aim:extract`, doesn't decide what's worth remembering, calls
`runGuardrails()` and `enqueueForConsolidation()` same as every other write
path), `FactQuery` (action, same vector query as `recall()`), `FactState`
(condition, direct `loadByProperties()` lookup on `scope`/`subject`/
`state`, no vector query). Not yet exercised through a real ECA model -
verified by `phpcs`/`php -l` only.

**Design boundary:** don't wire an ECA model to write a fact for something
already free from the current request/entity context (acting user's roles,
the node being viewed) - that's a stale duplicate, not memory. This action
is for values that would otherwise be lost once the triggering event
passes.

`Drupal\eca\EcaState` (core's State API under an `eca` key/value
collection) is not a substitute for `aim_fact` - flat/global, no
governance, no retrieval, not exportable. Reaching for it instead of
`FactWrite` is the same mistake as writing a fact for something already
available from context.

## MCP OAuth

Built 2026-09-13 ([ADR-0013](adr/0013-mcp-tool-exposure.md)'s OAuth
addendum has full detail). Closes the gap `aim_tool`/`mcp_server_tool_bridge`
left open: a remote MCP client with no Drupal session (Claude.ai/Claude
Desktop connector, or any other headless caller) can now authenticate to
`aim_remember`/`aim_recall` via OAuth2 instead of needing a logged-in
browser session. Existing cookie/session access is untouched - `oauth2`
was appended to `mcp_server.handle`'s `_auth` route option, not swapped
in.

**Dependency chain**, real package names (not drupal.org's usual pattern
throughout): `drupal/simple_oauth` (the OAuth2 authorization server) +
`e0ipso/simple_oauth_21` (OAuth 2.1 submodules: PKCE, RFC 9728 discovery
metadata, dynamic client registration - **Packagist-only under the
`e0ipso/` vendor namespace, not a drupal.org project** - `drupal/
simple_oauth_21` does not exist) + `drupal/mcp_server_oauth-mcp_server_oauth`
(self-doubled Composer name, same drupal.org packaging bug as
`mcp_server_tool_bridge`, see this file's Enabled-modules gotcha).

**`aim_tool_oauth` submodule** (`modules/aim_tool_oauth/`), new, optional,
`suggest` not `require` in `aim`'s `composer.json` - `mcp_server_oauth`
needed no `aim`-specific PHP (it gates any `mcp_tool_config` entity
generically off its own third-party settings), so this submodule exists
only to make the OAuth setup reproducible on a fresh install rather than
a manual one-off: `config/install` ships two `oauth2_scope` entities
(`aim:remember`, `aim:recall`, grant types `authorization_code` +
`refresh_token`), and `hook_install()`/`hook_uninstall()` set/clear the
`mcp_server_oauth` third-party settings (`authentication_mode: required`,
matching `scopes`) directly on `aim_tool`'s existing `aim_remember`/
`aim_recall` `mcp_tool_config` entities - this can't ship as a second
`config/install` file for those entities, that would collide with
`aim_tool`'s own copy of the same config name.

**Gotchas:**

- An `oauth2_scope` with no `granularity_id` set (the entity default, and
  what both `aim:remember`/`aim:recall` had at first) crashes
  `Oauth2ScopeProvider::getPermissions()` - it unconditionally
  `assert()`s `$scope->getGranularity()` is non-null, which fails outright
  the moment a real client (tested live: Claude.ai's connector) completes
  the OAuth flow. Surfaces client-side as a generic "AIM returned an
  error when connecting," not anything scope- or permission-shaped - check
  `drush watchdog:show` for a burst of
  `AssertionError: assert($granularity instanceof ScopeGranularityInterface)`
  at the same timestamp as the failed callback before assuming it's
  about which account authorized. Fix: set the `permission` granularity
  plugin on the scope, pointed at the real permission it should map to
  (`granularity_id: permission`, `granularity_configuration: {permission:
  'store aim memory'}` for `aim:remember`, `'read aim memory'` for
  `aim:recall`) - not a workaround, the correct binding between the OAuth
  scope and the Drupal permission `aim_tool` already checks. See
  ADR-0013's OAuth addendum.
- The OAuth admin form's "Required scopes" selector only offers scopes
  some enabled `mcp_tool_config` already carries in its own third-party
  settings - on a site with none configured yet, the selector renders
  empty with no free-text fallback, so the very first scope cannot be
  introduced through the UI. Seed it via `drush config:set
  <config_name> third_party_settings.mcp_server_oauth.scopes.0
  '<scope>'` (or code, as `aim_tool_oauth_install()` does) before the UI
  has anything to offer.
- Enabling a module whose `config/install` matches an already-existing
  config name throws `PreExistingConfigException` outright - Drupal does
  not silently skip it. Hit this after hand-creating the `oauth2_scope`
  entities to check their shape, then trying to enable `aim_tool_oauth`;
  fix was deleting the hand-made entities first.
- `simple-oauth:generate-keys` needs a path outside the docroot - used
  `/var/www/html/keys` (i.e. this site shell's own `keys/` at repo root,
  sibling to `web/`), which a pre-existing `/keys/*` `.gitignore` entry
  already covered. `simple_oauth.settings` `public_key`/`private_key`
  point at the generated pair.
- DDEV's local hostname is not reachable from a cloud-hosted MCP client
  (Claude.ai's connector runs in Anthropic's infrastructure, not this
  machine), and DDEV's self-signed cert would fail a real client's HTTPS
  check regardless - `ddev share --provider=cloudflared` (real CA-signed
  tunnel) is the fix. **Tested end to end 2026-09-13/14** against a real
  Claude.ai connector, both `/.well-known/oauth-protected-resource` and
  `/.well-known/oauth-authorization-server`, dynamic client registration,
  and the full OAuth consent flow, with two real bugs found and fixed
  along the way (this file's `registration_endpoint`/granularity gotchas
  above).
- `ddev share`'s quick tunnel gets a **new random `trycloudflare.com`
  hostname every restart** - the Claude.ai connector's URL needs
  re-entering each time. A named tunnel (persistent hostname) needs a
  Cloudflare account with the target domain added as a zone there -
  `cloudflared tunnel login`'s zone picker is a hard gate, required even
  if DNS itself would stay self-hosted (i.e. even the "just add one
  manual CNAME on my own DNS" path still needs a Cloudflare zone to
  create the named tunnel object in the first place). **Not available on
  this project's domain (`niklp.com`)** - its DNS is self-hosted on Nik's
  own Linode VPS and staying there, and Cloudflare's free plan has no
  CNAME-only/partial zone option (confirmed via Cloudflare's own docs -
  that's Business/Enterprise-plan only), so full nameserver delegation is
  the only way to get a zone into a free Cloudflare account, ruled out.
  **Solved 2026-09-14 with Tailscale Funnel instead - no Cloudflare
  account/zone involved at all.** Gives a stable public URL under the
  tailnet's own domain (`https://amaria.snake-amberjack.ts.net/` at time
  of writing - hostname won't match on a different machine/tailnet),
  persists across restarts (tailscaled's own serve/funnel config, not a
  foreground process to babysit like `ddev share`), and needed zero DNS
  changes since it lives under Tailscale's domain, not `niklp.com`.
  Confirmed via Tailscale's own docs first: Funnel has no custom-domain
  support at all (`ts.net` only), which is *why* it sidesteps the whole
  Cloudflare-zone problem - not a workaround, a genuinely different
  tradeoff (persistent hostname, zero domain cost, but not a
  `niklp.com`-branded URL). One-time gotcha: Funnel must be enabled
  per-tailnet first - `tailscale funnel --bg <port>` prints an
  enablement link (`https://login.tailscale.com/f/funnel?node=<id>`) and
  hangs indefinitely until that's clicked through in a browser, not a
  stuck process.

  **Real mistake made and fixed, same session: what Funnel should
  actually point at.** First pointed Funnel at DDEV's per-project
  "direct access" port (`32768`, what `ddev describe`'s `web:80 ->
  127.0.0.1:NNNNN` line and `ddev share` both use) on the assumption it
  was a stable per-project value worth pinning via `.ddev/config.yaml`'s
  `router_http_port`. Both halves of that were wrong: that port is
  Docker's own ephemeral container-publish assignment - confirmed it
  changed on every `ddev restart` across this session (`32768` ->
  `32773` -> `32781`), nothing DDEV exposes a pin for. And
  `router_http_port` isn't a per-project setting at all - it's the
  *global* port `ddev-router` listens on for every project's normal
  Host-based routing (default `80`, shared across the whole DDEV
  installation), so setting it to `32768` didn't pin anything, it
  **broke the router's real port 80** for every project until reverted
  (caught via `docker ps --filter name=ddev-router` showing the `80`
  binding gone, and `/mcp` starting to 404 with ddev-router's own "no
  route found" page instead of reaching Drupal). The actually-stable
  target: `ddev-router`'s real port `80` (confirmed unchanged across
  every restart this session, since it's shared infrastructure, not
  per-project) plus `.ddev/config.yaml`'s `additional_fqdns` (a
  first-class DDEV mechanism for teaching the router to route a real
  external hostname to this project, previously unused, `[]` by
  default) set to the Funnel hostname - `tailscale funnel --bg 80` then
  forwards the incoming `Host: amaria.snake-amberjack.ts.net` header
  as-is, and the router matches it via `additional_fqdns` instead of
  needing a Host rewrite. Verified genuinely stable, not just
  plausible: re-ran `ddev restart` twice after this fix with zero
  changes to Funnel's own config, `/mcp` kept resolving correctly both
  times.

  Re-verified the full OAuth chain (discovery metadata,
  `registration_endpoint`, unauthenticated `/mcp` returning 401) through
  the new hostname - all held up unchanged, confirming the earlier fixes
  are tunnel-independent. `ddev share`'s cloudflared quick tunnel is no
  longer running - this is a straight replacement, not a fallback. Self-
  hosted-via-the-Linode-VPS and ngrok's paid tier remain real alternatives
  if a `niklp.com`-branded URL is ever wanted instead - not pursued, see
  TODO.md.

  **Real bug hit and fixed 2026-09-14 (later session), third one in this
  chain: every OAuth discovery URL came back `http://`, not `https://`.**
  Surfaced client-side via a real Claude.ai connector registration attempt
  as "Couldn't register with Drupal AIM's sign-in service" (opaque `ofid_*`
  reference, no scope/permission detail like the earlier granularity bug -
  confirmed instead by curling `/.well-known/oauth-authorization-server`
  directly and finding `registration_endpoint` (and every other endpoint)
  schemed `http://` while `issuer`/`resource` stayed `https://`). Root
  cause: the DDEV router image had moved to
  `ddev/ddev-traefik-router` (Traefik, replacing the older nginx-proxy
  router this file's earlier Funnel section was written against) sometime
  between the 2026-09-13/14 build session and this one - Traefik sets
  `X-Forwarded-Proto` per-entrypoint, not per-original-client-scheme, and
  Funnel's target (`http://127.0.0.1:80`, the plain-HTTP entrypoint) meant
  every request looked like plain HTTP to Drupal from the router inward,
  regardless of Funnel having terminated real TLS on the public side.
  Confirmed by comparing against `https://aim.ddev.site` (TLS terminated
  at the router's 443 entrypoint) generating correct `https://` URLs the
  whole time - only the Funnel path was affected. Fix: repoint Funnel at
  the router's HTTPS entrypoint instead of its HTTP one -
  `tailscale funnel --bg https+insecure://127.0.0.1:443` (the
  `https+insecure` scheme accepts the router's self-signed/mkcert cert;
  `tailscale funnel reset` first if an old plain-HTTP target is still
  configured). No Drupal-side change needed - `reverse_proxy`/
  `reverse_proxy_addresses` in `settings.php` stay unset, same as always.
  Verified via a real `POST /oauth/register` through the Funnel URL
  returning a genuine `client_id`, not just the discovery metadata
  looking right. **Caveat:** this Funnel target survives `ddev restart`
  (port 443 is as stable as port 80 was) but is process/session state in
  `tailscaled`, not `.ddev/config.yaml` - a host reboot or `tailscale`
  service restart will drop it back to no funnel configured at all, not
  silently back to the broken `http://127.0.0.1:80` target. If a connector
  registration ever fails again, check `tailscale funnel status` for the
  proxy target scheme/port before re-diagnosing the OAuth chain itself.

## Admin UI

`/admin/content/aim-facts` (View `views.view.aim_facts`, gated on
`administer aim memory`) - table of every fact, "still live" shown for an
empty `expires`. `/admin/config/system/queue-ui` for the manual
consolidation trigger. **Gotcha:** a `menu.type: 'default tab'` View
display at a path that isn't an existing tab set's root silently registers
its route at the WRONG path (resolves into whatever tab set already claims
that root, e.g. `/admin/content`) - use `menu.type: normal` for a plain
admin menu link instead.

**`uid` ("Extracted by") column added 2026-09-15.** Noticed while
investigating a batch of `scope=user` facts that all showed
`subject_uid=admin`: the View already surfaced `subject_uid` (who the fact
is *about*) but never `uid` (`aim_fact`'s real `EntityOwnerTrait` field,
who/what wrote it - defaults to the current user at save time, same as any
core entity's "Authored by"). For `scope=site`, `subject` is unused
entirely, so `uid` is the only "who said this" signal available at all.
Added via the View entity's own `->save()` (a `drush scr` one-off script),
not hand-typed, per this file's "never hand-type dependencies/cache_metadata"
gotcha above - worth it here too: the recompute also dropped a stale
`options` module dependency the file had carried since `scope`/`subject`
moved off `list_string` (see "Scope as bundles"), never cleaned up until
now.

**Entity add/edit forms - built 2026-09-15, replaces the unbuilt
custom-`FormBase` ingress-form plan below entirely.** `AimFact`'s
`#[ContentEntityType]` attribute gained `handlers.form.default`
(`ContentEntityForm::class`, no overrides needed), `handlers.view_builder`
(`EntityViewBuilder::class` - required for the canonical route to
register at all; `DefaultHtmlRouteProvider::getCanonicalRoute()` checks
`hasViewBuilderClass()`), `handlers.route_provider.html`
(`DefaultHtmlRouteProvider::class`), and four `links`: `add-page`
(`/admin/content/aim-facts/add`), `add-form`
(`/admin/content/aim-facts/add/{scope}`), `canonical`
(`/admin/content/aim-facts/{aim_fact}`), `edit-form`
(`/admin/content/aim-facts/{aim_fact}/edit`). The `{scope}` parameter name
in `add-form` is not arbitrary - core's `EntityController::addPage()`
builds each bundle's add link using the entity type's bundle key
(`scope` here, since `aim_fact` has no `bundle_entity_type`) as the route
parameter name, confirmed by reading
`DefaultHtmlRouteProvider::getAddFormRoute()` and `EntityController::addPage()`
before choosing this over the earlier custom-form plan's own path
scheme. Access was originally gated by `administer aim memory` alone (the
default `EntityAccessControlHandler` granting every operation to a holder
of the entity type's `admin_permission`) - superseded by per-scope
permissions below, `administer aim memory` now works purely as the
existing bypass every check already `orIf`s against. Verified live: all
four routes resolve
(`entity.aim_fact.add_page`/`add_form`/`canonical`/`edit_form`), and the
add-form correctly runs through `remember()`'s underlying save path
(guardrails + consolidation enqueue both fire, see "Guardrails" above) -
not a hand-rolled `FormBase` with its own `$entity->save()` that would
have silently skipped both, the exact risk the superseded plan flagged.

**Per-scope view/create permissions and access handler, built 2026-09-15.**
Closes the gap the entity forms above left open: with only
`administer aim memory` gating anything, any user allowed to create facts
at all could create/view every scope, including `user`. No generic
"auto per-bundle permission" mechanism exists in this Drupal core version
for a code-only bundle - checked the real precedent first
(`node`'s `NodePermissions`, `annotations`' own `AnnotationsPermissions`),
both built on `BundlePermissionHandlerTrait::generatePermissions()`, which
hard-requires a real config-entity bundle (`getConfigDependencyKey()`/
`getConfigDependencyName()` on each one, to auto-clean the permission if
the bundle is later deleted). `aim_fact`'s four scopes are code-defined
bundles from `hook_entity_bundle_info()` (see "Scope as bundles" above),
not config entities, so that trait doesn't apply as-is - written instead
as a small equivalent, `AimPermissions` (`src/AimPermissions.php`,
`permission_callbacks` in `aim.permissions.yml`, `AutowireTrait` +
`ContainerInjectionInterface` same as `NodePermissions`'s current shape),
looping `entity_type.bundle.info`'s `getBundleInfo('aim_fact')` directly
and emitting `view {scope} aim facts` / `create {scope} aim facts` with no
dependency tracking - nothing to clean up, these bundles can't be deleted
via the UI.

Permissions alone did nothing without an access handler change -
confirmed by reading `EntityAccessControlHandler::checkAccess()`/
`checkCreateAccess()` directly, both only ever check the flat
`admin_permission` and stop, regardless of what other permissions exist.
`AimFactAccessControlHandler` (`src/AimFactAccessControlHandler.php`,
wired via `handlers.access` on `AimFact`'s `#[ContentEntityType]`
attribute) overrides `checkAccess()` for `view` and `checkCreateAccess()`
to check the new per-scope permission `orIf` `administer aim memory` as
bypass - `update`/`delete` deliberately left on `administer aim memory`
only, not asked to be scoped. Real payoff verified live, not just
inferred: the entity-form bundle-picker's add-page route already requires
`_entity_create_access: aim_fact:{scope}` per bundle
(`DefaultHtmlRouteProvider::getAddFormRoute()`, since `aim_fact` has no
`bundle_entity_type` the bundle key itself - `scope` - is used as the
route parameter directly) - confirmed via `drush php:eval` that a test
account with only `create site aim facts` gets `ALLOW` on
`createAccess('site', ...)` and `DENY` on `createAccess('user', ...)`, and
correspondingly `view`/`create` on real `aim_fact` entities of each
scope, with zero changes needed to `EntityController::addPage()` itself -
it already filters bundles by `createAccess()` per bundle.

## Admin settings

Built 2026-09-14. `/admin/config/aim/settings` (`AimSettingsForm`, a plain
`ConfigFormBase`, gated on `administer aim memory`) - two collapsible
`details` groups: "Consolidation thresholds" (`auto_threshold`,
`ambiguous_threshold` - open by default) and "Prompts" (`extraction_prompt`,
`consolidation_prompt` - collapsed by default, long text). Backed by
`aim.settings` config, `config/schema/aim.schema.yml` +
`config/install/aim.settings.yml` (the shipped defaults - same values
`AimMemoryManager::DEFAULT_AUTO_THRESHOLD`/`DEFAULT_AMBIGUOUS_THRESHOLD`
and the old hardcoded prompt heredocs used to hold). `AimMemoryManager`
gained a `ConfigFactoryInterface` constructor argument to read it (a
`drush cr` is needed on any site that already had the container built
before this landed - see this file's existing "stale container cache"
Chatbot gotcha, same failure shape).

**Route/menu shape**, reused instead of custom-built: `aim.admin_config`
(`/admin/config/aim`) uses core's own
`\Drupal\system\Controller\SystemController::systemAdminMenuBlockPage` as
its controller - the exact same reusable "list my child menu links as
blocks" controller core itself stacks a dozen `#[Route]` attributes onto
for `/admin/config/development`, `/admin/config/media`, etc. (confirmed by
reading `SystemController.php`, not assumed). This is why `aim.links.menu.yml`
only needed two links (`aim.admin_config` parented to `system.admin_config`,
`aim.settings` parented to `aim.admin_config`) and zero custom PHP for the
category page itself - only the actual settings form needed a real
controller.

**Prompts are config, not `ai_agents`.** `extractFacts()`/`classifyPair()`
build their `ChatInput` directly (one-shot structured-output calls, no
tool-calling loop) rather than going through `ai_agents`' `AgentRunner` the
way `aim_chatbot`'s agent does - so their prompts live as plain
`aim.settings` string fields, not an `ai_agent` config entity's
`system_prompt`. Each prompt is `strtr()`'d against a placeholder token at
call time: `{text}` for `extraction_prompt`, `{kept_text}`/
`{candidate_text}` for `consolidation_prompt`. The requested *output shape*
(the `facts`/`scope`/`subject`/`text` schema, the `decision`/`merged_text`
schema) stays hardcoded in `AimMemoryManager` as a `StructuredOutputSchema`,
deliberately not admin-editable like the instruction prose is, since the
schema is parsed by PHP downstream and isn't safe to hand to a text field.
`AimSettingsForm::validateForm()` rejects a save that drops a required
placeholder, so a `strtr()` no-op replacement can't happen silently.

Deliberately narrow scope: `GUARDRAIL_SET_ID`/`CONSOLIDATE_QUEUE_ID` (still
class constants on `AimMemoryManager`) were left out of this form - they're
structural wiring (which guardrail set, which queue), not values a site
admin tunes, unlike the numeric thresholds and prompt text.

## Default content export

`vendor/bin/dr content:export aim_fact <id>` (not
`web/core/scripts/drupal` - its autoload path assumes `vendor/` inside the
docroot, wrong for this project's layout). Pure read of entity field data -
never touches `search_api`/the vector collection table; reindex after
import (`drush search-api:index aim_vector_index`) is required regardless,
a genuine from-scratch recompute. Don't use `--with-dependencies` to carry
`uid` through - the exporter includes the **pre-hashed password** on any
exported user account, wrong for a git-committed module. Anonymous
authorship on re-import is accepted, not a problem to solve.

## Ideas raised, not designed

- **Fact verification as a user-facing feature.** The draft-to-trusted
  review step could double as a mobile "here's what I remember about you,
  confirm or correct" aide-memoire, not just an admin moderation queue.
- **Fact-to-fact relations.** `related` (built, see
  [ADR-0005](adr/0005-consolidation-algorithm.md)) only records
  consolidation's supersede edge. An *authored* graph (a human or
  extraction step deliberately linking facts) is still unbuilt, and so is
  any multi-hop traversal at retrieval time - `recall()` is one flat
  vector query with no traversal, doing exactly what it's built to do.
  Confirmed 2026-09-11 against the 50-fact benchmark that this is a real
  gap, not a theoretical one: a 3-hop query half-answered and a 4-hop
  query returned nothing relevant. Best-guess design (typed relation
  entity vs. reusing `related`, bounded BFS, hop/fan-out limits) and the
  risks it would carry (latency, storage, authoring cost, guardrails on
  authored edges, bad-seed amplification) are written up in
  [ADR-0012](adr/0012-fact-relation-graph.md) - a proposal and estimate,
  not an accepted design. It also compounds with the chatbot's unverified
  abstention behavior below: a caller could get a confidently-worded
  wrong answer built from a coincidental keyword match, not just an
  honest "don't know."
- **Scheduled TTL / staleness-driven review**, distinct from consolidation's
  `expires`. A real scheduled job (Queue API + dedicated crontab, not
  `hook_cron`) that acts on fact age is still unbuilt. "Force into review
  status" on staleness is really [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
  Content Moderation gate arriving early - don't build a smaller ad hoc
  `review_status` field that Content Moderation would just replace later.
- **Per-fact volatility hint for staleness thresholds.** Raised in another
  session 2026-09-14: let a fact declare its own expected shelf-life at
  write time (e.g. `stable`/`seasonal`/`volatile`) so the still-unbuilt
  scheduled staleness review above isn't one-size-fits-all - a `stable`
  fact ("Nik's employer") and a `volatile` one ("Nik's current mood")
  shouldn't age out on the same clock. Would live as a field alongside
  `asserted` (see bi-temporal validity below), populated by the writer
  (extraction model, `aim:remember` caller, or ECA's `FactWrite`) rather
  than inferred. Not designed: the actual threshold-per-tier mapping,
  whether a missing hint defaults to the most conservative tier or the
  least, and whether consolidation's own similarity thresholds should
  also vary by tier.
- **Manual review queue for contradicting facts, not auto-resolve.** Raised
  in the same 2026-09-14 session as the volatility-hint idea above: when a
  new fact semantically conflicts with an existing one (same subject,
  overlapping content, different claim), don't silently pick a winner -
  flag both and let a human decide "still true / no longer true / both
  true at different times." Proposed as a Content Moderation state machine
  on facts (`unverified` -> `confirmed`/`superseded`/`conflicting`), with
  `conflicting` facts kept, not deleted, so the contradiction stays
  visible rather than the older fact quietly vanishing - consistent with
  how `related`/`expires` already avoid hard deletes on supersede.
  Workload kept proportional by design: sample-review a percentage of new
  facts, or force review only when contradiction-detection actually
  fires, not a gate on every single write.

  Real tension with current design, not yet reconciled: consolidation's
  ambiguous band ([ADR-0005](adr/0005-consolidation-algorithm.md),
  thresholds above) already auto-resolves this case today via
  `classifyPair()`'s LLM verdict, setting `expires` on the older fact with
  no human step - this idea would replace that auto-resolve with a human
  decision specifically for contradictions, presumably leaving
  true-duplicate auto-merging (below the auto-threshold) alone. Also
  overlaps [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
  still-deferred Content Moderation gate, but is narrower than that ADR's
  current "nothing LLM-extracted is auto-trusted" blanket framing - a
  dedicated `conflicting` state and proportional (sampled/triggered)
  review are more specific than what ADR-0002 currently commits to.
  Reconcile both overlaps when ADR-0002 actually gets built, not before.
- **Pre-extraction summarization as a dedup lever** (an AI pass summarizes
  a conversation before extraction runs), complementary to consolidation,
  not a substitute - only catches duplication *within* one session.
  Already the pattern the `aim-discovery` Skill uses per-topic. Any
  intermediate markdown must stay scratch/ephemeral, never a committed
  field/table - persisting it would reopen the no-transcript-recording
  constraint under Extraction above.
- **EU AI Act disclosure obligation** (Article 50 transparency) once this
  moves past PoC into anything user-facing. `drupal/ai_disclosure` is an
  unevaluated candidate module. Revisit before any public-facing launch.
- **Sub-scope visibility/audience control within `scope: site`.** The
  chatbot's `scope: site` lock stops it leaking other scopes, but nothing
  stops it surfacing *any* site fact to *any* visitor - no "public" vs.
  "internal" notion within a scope yet. Not urgent while the chatbot's
  recall trigger stays narrow; a real gap the moment it loosens or a
  second, less-curated consumer shows up.
- **`revision_graph`** (drupal.org, mature/zero-config Git-style revision
  visualizer) - not useful today (`aim_fact` isn't revisionable, and it's
  node-specific). Real future fit once Content Moderation lands: a
  ready-made admin UI for the `related`/`expires` supersede graph instead
  of custom-building one.
- **Supersede-reason field.** A short field (e.g. `related_reason`)
  alongside `related`/`expires`, recording *why* consolidation superseded a
  fact (duplicate/merge/conflict/manual), not just that it did - the
  concrete, checkable "provenance on invalidation" parity target from
  ADR-0010's 2026-09-10 widened appraisal. Feasible as one field populated
  at `consolidateFact()`'s existing call site. A full separate "event fact"
  bundle/entity type for this was considered and rejected for now:
  `aim_fact` is single-bundle today, and ADR-0002's deferred Content
  Moderation revisioning would likely give a proper audit trail for free
  once built, making a bespoke event type duplicate work.
- **Bi-temporal fact validity - BUILT 2026-09-11, deliberately partial.**
  `aim_fact` gained an `asserted` timestamp (valid-time start only, defaults
  to `created`). Full bi-temporal (Zep-style: independent valid-time *and*
  transaction-time, each with a start and an end) was considered and
  rejected - the harder half (valid-time end, independent of when the
  system learned a fact stopped being true) is rarer, harder to elicit, and
  already approximated well enough by `expires` getting set when
  consolidation finds a contradiction. Revisit only if a real case shows
  that approximation failing.
- **Source-boundary policy per site archetype - design landed
  2026-09-14, not built.** Ties to ADR-0010's "speckit-for-Drupal" idea
  (its open question 8): each discovery-skill archetype (commerce,
  support, ...) should declare its own allowed-source boundaries as
  config, likely as its own Guardrail set (the same mechanism
  `aim_write_guardrails` is already one instance of), not a single global
  policy. Folded into a fuller proposal covering the whole starter-kit
  shape (discovery-Skill variant + Guardrail set + starter categories +
  optional Recipe) in
  [ADR-0014](adr/0014-usecase-archetype-starter-kits.md) - a proposal and
  estimate, not an accepted design.
- **Extraction-input guardrailing, distinct from candidate-fact
  guardrailing.** Guardrails today only filters the *output* of extraction
  (candidate fact text, via `runGuardrails()`). Nothing filters the *input*
  to an extraction call - a source document engineered to manipulate the
  extracting model into asserting a false-but-textually-clean fact would
  pass every existing check. Matters once ingesting raw source material
  (see the media ingestion idea below) becomes real; plain user-typed text
  carries a much smaller version of this risk today.
- **Taxonomy field - BUILT 2026-09-11, curation half only.** `aim_fact`
  gained a `category` field (entity_reference, unlimited cardinality,
  vocabulary `aim_category`) - the plain admin-curation use case from the
  original idea. The routing half (which discovery-skill archetype/
  source-policy/guardrail set governs a fact) stays a separate, later
  concern, deliberately not folded into this field - see "Source-boundary
  policy per site archetype" below, still undesigned.
- **Fast-path lookup for typed facts, bypassing `recall()`'s vector
  search.** `state` (boolean, built) and `category` (taxonomy, built) are
  both exact-match facts (scope+subject), not fuzzy semantic ones - but
  nothing queries them that way today. A boolean check still pays
  `recall()`'s full embed-query-then-vector-search cost (33-540ms
  depending on embeddings provider, see "Benchmarking"), the wrong tool for
  an exact match. Missing piece: a second retrieval method (e.g.
  `AimMemoryManager::getState($scope, $subject)`) doing a direct indexed
  `WHERE` query against `aim_fact`, optionally behind a Cache API layer
  (keyed scope+subject, invalidated on save) for a genuine page-load hot
  path (e.g. "should this user see the marketing banner"). Originally
  motivated by a 2026-09-09 question about a "warm state cache for
  booleans." Not designed, no ADR yet - see TODO.md.
- **Graduated-detail retrieval (OpenViking-style L0/L1/L2).** Store a short
  one-line abstract plus fuller detail tiers, fetch only the level a query
  needs, to cut retrieved-context token cost. Relevant to the
  token-efficiency parity target in ADR-0010's widened appraisal if
  retrieval volume ever makes that a real cost. Source: an unverified
  third-party summary, not independently confirmed - treat as a candidate
  mechanism, not a validated one.
- **Hybrid keyword+vector search.** `recall()` is vector-only today - a
  query for an exact name/ID that happens to embed far from its stored
  phrasing can miss, the class of error pure cosine similarity is worst
  at. mem0's April 2026 rewrite (see
  [ADR-0012](adr/0012-fact-relation-graph.md)) stores a lemmatized-text
  field alongside every embedding and fuses BM25 keyword scoring with
  vector similarity at query time - cited there as one input to
  `mem0ai/mem0` @ `a488e19` (`feat(oss): port v3 pipeline with hybrid
  search, entity extraction, and additive scoring`, PR #4805). Not
  designed here: would need confirming whether `ai_vdb_provider_mariadb`
  can carry a parallel text index alongside the `VECTOR` column, or
  whether MariaDB's own full-text index type could serve the keyword half.
- **Tool API + MCP exposure - BUILT 2026-09-12.** The CLI adapter
  (`aim:remember`/`aim:recall`) needs a drush/DDEV shell, and
  `aim_chatbot`'s tools are locked to `scope: site` - neither is what a
  generic Tool/MCP-speaking agent expects. `aim_tool` module
  (`modules/aim_tool/`) ships `#[Tool]` plugins `aim_remember`/
  `aim_recall` wrapping `AimMemoryManager` directly, not locked to
  `scope: site`, gated on two split permissions (`store aim memory` /
  `read aim memory`, not the admin UI's single `administer aim memory`),
  tested live including the permission split. `mcp_server_tool_bridge`
  exposes both over MCP via config-only `mcp_tool_config` entities -
  confirmed live via `plugin.manager.mcp_server.tool`. `aim_remember` also
  takes an optional `facts` list for saving several facts in one call/one
  bootstrap (mirrors `aim:remember --file`'s reasoning: an MCP call is its
  own HTTP request too). Real caveat: `mcp_server_tool_bridge`'s real
  Composer package is published under a self-doubled name due to a
  drupal.org packaging bug (see this file's Enabled-modules gotcha above).
  The bridge's schema converter not describing nested List/Map inputs
  (`facts` generating bare `{"type": "array"}`, no `items`) was real on
  `1.0.0-beta1` but is fixed on `1.x-dev` - see this file's Enabled-modules
  gotcha, 2026-09-13. Full detail, the working composer incantation, and
  the cross-project evidence from `annopm`/Annotations that shaped this
  design in
  [ADR-0013](adr/0013-mcp-tool-exposure.md)'s build addendum. External MCP
  client authentication (this bullet's old "still open" item) is now
  built - see "MCP OAuth" below and ADR-0013's 2026-09-13 addendum.
- **Media/source ingestion for re-analysis.** Agreed shape if this is ever
  built (2026-09-10): reference existing Media entities rather than `aim`
  owning its own copy, private file scheme (not public) for anything
  sensitive, given this system is treated as potentially business-critical.
  Still gated behind the separate, still-open "does aim ever store source
  material at all" question under Extraction above - this only settles the
  *shape*, not whether it happens.
- **Read-only markdown/Obsidian export - not a storage swap.** Raised
  2026-09-14, prompted by a question about whether `aim` should look into
  file-based storage the way an agent's own memory (e.g. this session's
  `~/.claude/.../memory/*.md` files) does. The right precedent already
  exists one repo over: `annotations_export` (`dotdev`'s `annotations`
  suite) doesn't store annotations as files - entities stay canonical -
  it ships `drush ann:ex --format=obsidian` as a one-way, read-only
  projection to markdown/an Obsidian vault, for human browsing and
  external consumption. The equivalent for `aim` (an `aim_export`
  submodule, or a drush command on `aim` core) would be additive only -
  no conflict with ADR-0001's storage decision, since the database stays
  the source of truth and nothing reads the export back in. `related`/
  `expires` would map naturally onto Obsidian `[[wikilinks]]`, the same
  way `annotations_export`'s `--ref-depth` does for entity-reference
  fields. Real uses: dovetails with "fact verification as a user-facing
  feature" above (a human-readable "here's what's remembered" view), and
  portability into another agent's own file-based memory convention. Not
  designed: filtering flags (scope/subject/category), and whether it
  warrants its own submodule or stays a single drush command.
- **Scope-aware fact-ingress form - conditional fields, not a wizard.**
  **Superseded 2026-09-15** by the plain entity add/edit forms built the
  same day - see "Admin UI"'s "Entity add/edit forms" entry above. That
  form gets every bundle's fields from the standard entity form (no
  `#states`-driven conditional fields, no default-subject prefill for
  `scope=user`), so the concrete handoff below is kept as a real, still-
  unbuilt enhancement on top of what exists now (a `hook_form_alter()`
  against `aim_fact_user_edit_form`/`aim_fact_user_add_form` etc., not a
  bespoke `FormBase` as originally planned - that approach's own real risk
  (bypassing `remember()`'s guardrail/enqueue calls) is moot now that both
  run via entity hooks regardless of which form saves the entity, see
  "Guardrails"). Original framing, raised 2026-09-15 prompted by noticing
  a batch of `scope=user` facts all resolved to one real account
  (`admin`) with no UI to pick a different one - see "Admin UI"'s `uid`
  column note above, added the same day, for the investigation that
  surfaced this - kept below unedited except for this note.

  **Route/menu and Form class below are stale** - both describe the
  original custom-`FormBase` plan, and now conflict with what actually
  exists: `/admin/content/aim-facts/add` is the real
  `entity.aim_fact.add_page` route (see "Admin UI" above), permission is
  still `administer aim memory` (that part held up), and each bundle gets
  its own `ContentEntityForm`-backed route
  (`entity.aim_fact.add_form`/`edit_form`, parametrized by `{scope}`) -
  not one shared `FormBase`. A `hook_form_alter()` targeting a specific
  bundle's form ID would already be scoped to that one bundle, so the
  `#states`-driven single-form design below (written for one shared form
  across all four scopes) would need rethinking, not direct reuse, before
  building - listed here for the underlying UX gap it identifies (no
  default-subject prefill, no case-ID hint, etc.), not as a ready spec.

  **Fields**, `#states`-driven off a `scope` select (default `site`,
  options from `allowedScopes()`) - **written for the superseded
  single-shared-form plan, see the note above:**
  - `scope=user`: an `entity_autocomplete` (`target_type: user`),
    required, **default value pre-filled to the current user** - a
    visible default the person can change, better UX here than the
    invisible current-user fallback `AimRemember` does API-side (see the
    default-subject-centralization note below - doing it visibly in this
    form's default value sidesteps needing that fix first, though it's
    still worth doing for the other callers).
  - `scope=role`: plain textfield, matching today's actual behavior -
    `remember()` never validates a role name against real `user_role`
    entities for `scope=role`, so a select-from-real-roles widget would be
    a behavior upgrade, not parity; fine as a later nice-to-have, not v1.
  - `scope=case`: optional textfield, described as "existing case ID -
    leave blank to mint a new one" (mirrors `remember()`'s own minting,
    see "Case IDs are minted server-side").
  - `scope=site`: no extra field.
  - Always shown: `text` (required textarea), `source` (optional
    textfield), `state` (optional tri-state boolean select, matching
    `remember()`'s `?bool $state`), `category` (optional tags-style
    `entity_autocomplete` against `aim_category`, comma-separated names
    through the same `resolveCategoryTerms()` that already skips a
    no-match name), `asserted` (optional textfield, same
    `strtotime()`-parseable freeform text `aim:remember --asserted`
    already accepts).

  **Explicitly out of scope for v1:** any "link this fact to another
  fact" field - raised in the same 2026-09-15 discussion (would this need
  a new field, or can it reuse `related`?) and answered there: `related`
  is a plain untyped `entity_reference` to `aim_fact` today (unlimited
  cardinality, no edge-type/reason of its own), reserved for
  consolidation's mechanical supersede edge - reusing it for an authored
  "this fact's reason is that fact" link would make the two kinds of edge
  indistinguishable in the same flat list. That's the separate,
  still-undesigned question [ADR-0012](adr/0012-fact-relation-graph.md)
  and the "Fact-to-fact relations" idea below already cover (typed
  relation entity vs. reusing `related`, an edge-type/reason value, or
  both) - don't let it grow inside this form's first cut.

  Building this would also expose a real gap found in passing:
  `AimRemember`'s Tool plugin already defaults `subject` to the calling
  account for `scope=user` when none is given
  (`modules/aim_tool/src/Plugin/tool/Tool/AimRemember.php`), but
  `AimMemoryManager::remember()` and `drush aim:remember` do not - only
  the MCP path gets that convenience today. A form needing the same
  default would be the second real occurrence of that logic, worth
  centralizing on `remember()` itself before a third one appears,
  independent of whether scopes ever become a plugin type (next).
- **Scopes as a plugin type - considered 2026-09-15, not adopted.** Raised
  in the same discussion as the ingress form above, prompted by wanting
  `aim` to support pluggable per-site archetypes (ties to
  [ADR-0014](adr/0014-usecase-archetype-starter-kits.md)). Sketch: an
  `#[AimScope]` attribute plugin type, same pattern as `#[Tool]`/
  `#[FunctionCall]` already in this codebase, one class per scope owning
  its own default-subject resolution, guardrail set, and ingress-form
  widget spec, discovered instead of the `if ($scope === 'user')` branches
  currently scattered across `AimMemoryManager`, `AimRemember`,
  `AimCommands`, and `aim_eca`. Real benefit: a site or contrib archetype
  adds a fifth scope with real attached behavior in one class, not just a
  bundle label via `hook_entity_bundle_info_alter()` (today's
  extensibility point - a bundle only, no behavior). Real cost: scope
  stays a Drupal bundle underneath either way (the entity system needs
  that), so a plugin type would sit alongside the bundle system, not
  replace it - some duplication unless one derives from the other - and
  today's actual per-scope behavior is thin (one `if` for subject
  defaulting), so building the plugin type now would mostly be ceremony.
  Decision: don't build it yet - build the ingress form's conditional
  fields and the `remember()` default-subject fix above as plain code
  first, the same shape scope-specific logic already takes. Once that
  logic is duplicated a third time (the ingress form's widget-per-scope
  would be the second, a future chat surface or archetype the third),
  that's the trigger to extract an `#[AimScope]` plugin type - by then the
  real interface will be known instead of guessed now.

## Dev process and rules

### Git

- **Never auto-commit.** Create and edit files freely; stop after writing.
  Don't `git add`/stage or commit unless explicitly asked in the moment.
- This module is its own git repo, nested inside the `aim` site shell
  (site shell has no git tracking of the module). Don't run `git init`
  unprompted even so.

### Schema/config changes during early development

- No migration scripts or `hook_update_N()`/install hooks needed while
  there's no real data to preserve - just make the change and reinstall
  (`drush pmu` / `drush en`). Revisit once real data exists that
  reinstalling would destroy; update hooks become required, not optional.

### Code style (Drupal/PHP/JS/CSS)

- **No em dash character** (or `&mdash;`) anywhere in code, comments,
  docblocks, strings, or YAML. Use a hyphen, parentheses, or a colon.
- **No banner/divider comment blocks** (`// --- Helpers ---`) - a single
  plain `// Helpers.` line instead.
- **`Html::escape()`**, not `htmlspecialchars()`, for escaping in Drupal PHP.
- **American English spelling** in code, comments, and docs.
- No `/** */` block comments inside method bodies - use `//` line comments.
  Exception: inline `/** @var Type $var */` type narrowing.
- Docblock short description is one line; wrap the rest after a blank `*`
  line. `@return` description on the line after `@return`. Every
  constructor param needs a `@param`, including on an existing
  promoted-property constructor.

### Linting - run before calling PHP/JS/CSS work done

```bash
# phpcs
ddev exec "cd /var/www/html && vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/aim --extensions=php,module,inc,install,test,profile,theme"

# phpstan (once a phpstan.neon exists for this module)
ddev exec "cd /var/www/html && vendor/bin/phpstan analyse web/modules/custom/aim --memory-limit=512M"
```

Run JS/CSS/spelling tools via core's pinned toolchain
(`web/core/node_modules/.bin/<tool>`), not `npx` - `npx` can drift from
whatever version CI actually pins. `corepack enable && cd web/core && yarn
install --immutable` installs the exact pinned versions first.

Fix small findings (typos, style violations, a genuine new dictionary
word) inline in the same pass rather than queuing them for later review.

`vendor/bin/phpcs` only works through `ddev exec` (or inside the
container) - the host has no `php` on `PATH`.

### Drupal gotchas

- The `administrator` role has **every** permission implicitly, including
  dynamically registered ones. Never diagnose it as missing a permission -
  look at other roles when a permission check unexpectedly fails.

### Docs

"Update docs" means CLAUDE.md and README.md together (and a DEVELOPING.md
if one exists for technical/architecture detail). Keep user-facing content
in README.md, technical/developer detail in DEVELOPING.md, operating rules
here. Don't create a DEVELOPING.md speculatively.
