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

**Enabled:** `aim`, `aim_chatbot`, `ai_agents`, `ai_assistant_api`,
`ai_chatbot`, `ai_search`, `ai_provider_anthropic`, `ai_provider_amazeeio`,
`ai_provider_ollama`, `ai_vdb_provider_mariadb`, `search_api`, `views`,
`queue_ui`.
**Composer-present but not enabled:** `eca`/`aim_eca` (don't enable without
being asked), `ai_context` (CCC).

**PoC deviations from [ADR-0001](adr/0001-storage-and-scope-model.md)/
[ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md) (temporary,
not abandoned):** one flat `scope` list field instead of four bundles; no
Content Moderation / draft-to-trusted gate - every fact is live the moment
it's saved. Guardrails itself is *not* part of this deviation - see below,
it's live today. Re-introduce both before any non-PoC data goes in.

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
`anthropic`/`claude-sonnet-5` (`ai.settings`); `search_api.server.aim_vector`
`backend_config.embeddings_engine` is `ollama__nomic-embed-text:latest`
(768-dim, switched from `amazeeio__mistral-embed`/1024-dim on 2026-09-10 -
see "Local Ollama" below) - Anthropic has no embeddings API at all, don't
try to point `embeddings_engine` at it. `AimCommands`' `--provider`/
`--model` options default to `NULL` and resolve the site-wide default via
`AimMemoryManager::getDefaultChatProvider()` rather than a hardcoded PHP
default, so they follow whatever the site default is set to (chat stays on
`anthropic` - the swap only touched embeddings).

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

## Guardrails

Live today, not part of the governance deferral
([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)). Set
`aim_write_guardrails` (`config/install`): `aim_max_length`
(`input_length_limit`, 2000 chars), `aim_no_markup` (`regexp_guardrail`,
blocks `<script>`-shaped markup), `stop_threshold: 1.0`. Neither plugin
calls `->chat()` - zero LLM cost.

Wired in via `AimMemoryManager::runGuardrails()` (public), called from
`remember()`, `createFactsFromCandidates()`, `aim_eca`'s `FactWrite`, and
consolidation's UPDATE path. A stop throws `\InvalidArgumentException`
(same exception every caller already catches for scope validation).
`createFactsFromCandidates()` catches per-candidate so one rejected fact
doesn't abort a batch. If the guardrail set is ever removed from a site,
checking is silently skipped rather than blocking every write.

## Extraction

`drush aim:extract <file> [--provider] [--model] [--source] [--index]`
sends the file to a chat provider with a structured-JSON-schema request,
creates one `AimFact` per returned item (scope/subject classified by the
model). The source file itself is never stored - only the extracted facts
persist. **Standing constraint, not a PoC shortcut:** no conversation or
transcript recording - the `source` field is a short provenance pointer,
never the raw dialogue. Don't add a field/table storing full transcripts
without this being explicitly revisited first.

`--subject-uid` (uid or username) attaches every model-classified
`scope=user` candidate to that one real account; without it every
`scope=user` candidate is skipped (with a warning), unconditionally -
extraction never attempts to match the model's own freeform subject text
against the accounts table. See
[ADR-0007](adr/0007-user-scope-requires-real-account.md) for why user
scope requires a real account at all, and
[ADR-0011](adr/0011-extraction-explicit-subject-uid.md) for why the match
can't be model-guessed.

**Skill:** `.claude/skills/aim-discovery/` ("the grill") - a structured
discovery interview that distills each topic into a summary and runs it
through `aim:extract`, mostly `scope: site`. Doesn't generate a Recipe
(that's [ADR-0009](adr/0009-recipe-apply-safety-gate.md)'s territory).

## CLI agent adapter

For a caller (Claude Code, or any agent) that already decided what's worth
remembering - no extraction LLM round-trip. See
[ADR-0006](adr/0006-agent-native-write-path.md) for why this exists
alongside `extract()`.

- `drush aim:remember <text> [--scope] [--subject] [--source] [--state]` -
  validates scope, creates the fact directly, prints its ID.
- `drush aim:recall <text> [--scope] [--subject] [--subject-uid] [--limit]
  [--format]` - real semantic query against `aim_vector_index`.
  `--format=json` for a parsing caller.

Both live on `AimCommands`, backed by `AimMemoryManager` (also used by
`aim_eca` and the chatbot).

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
defaults:** `AimMemoryManager::DEFAULT_AUTO_THRESHOLD = 0.09`,
`DEFAULT_AMBIGUOUS_THRESHOLD = 0.45` - recalibrated 2026-09-10 against
`ollama__nomic-embed-text:latest` (previously 0.05/0.20, tuned for
`mistral-embed`, went stale the moment `embeddings_engine` switched - see
AI dependency map above).

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

Deliberately bypasses `remember()`'s guardrail check and the consolidation
queue: synthetic text needs neither, and queuing thousands of facts for
LLM-mediated consolidation would turn a latency benchmark into an
uncontrolled reasoning-call bill the moment `aim_consolidate`'s crontab next
runs. Cost is predictable - one embedding-API call per generated fact, at
`reindex()` time, nothing else. Every generated fact is tagged
`source=<run tag>` so `aim:benchmark-cleanup` (or `--cleanup` on the same
invocation) can remove exactly that run's data.

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
- Abstention correctness is unverified: `AimRecall::execute()` (the
  `aim_chatbot:recall` tool) only special-cases the zero-rows case ("No
  relevant facts found.") - there's no similarity-score threshold, so any
  non-empty result set, even one where the best match is a poor one, still
  gets formatted as "Relevant facts:" and handed to the model. A visitor's
  off-topic question could get a confidently-worded answer built from
  irrelevant facts instead of an honest "don't know." Flagged from the
  2026-09-10 competitive review, not yet reproduced with a real query. Fix
  candidate: a minimum-score cutoff before formatting output, not just the
  existing empty check.

**CCC (`ai_context`), not enabled on this site.** No Guardrails-equivalent
- its governance is Content Moderation for its own curated
`ai_context_item` entities, a different concern from filtering untrusted
LLM output. If/when CCC gets enabled, the integration shape is: `aim`
exposes tools (done), CCC holds curated policy about when to call them
("Pattern A" in CCC's own `docs/developers/rag.md`) - not `aim` becoming a
CCC content source. See ADR-0008.

## ECA integration (`aim_eca`, not enabled)

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

## Admin UI

`/admin/content/aim-facts` (View `views.view.aim_facts`, gated on
`administer aim memory`) - table of every fact, "still live" shown for an
empty `expires`. `/admin/config/system/queue-ui` for the manual
consolidation trigger. **Gotcha:** a `menu.type: 'default tab'` View
display at a path that isn't an existing tab set's root silently registers
its route at the WRONG path (resolves into whatever tab set already claims
that root, e.g. `/admin/content`) - use `menu.type: normal` for a plain
admin menu link instead.

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

- **Typed/categorical facts via taxonomy**, not a generic value_type/value
  pair. A true on/off flag is the existing `state` boolean field; a small
  curated set of values (contact preference, a "traits" tag) wants a
  taxonomy term reference instead, avoiding the free-text drift `subject`
  had before [ADR-0007](adr/0007-user-scope-requires-real-account.md). One
  `entity_reference` field (`category`) spanning several vocabularies, one
  `aim_fact` row per tag if more than one applies. Don't build
  speculatively - wait for a real recurring category worth curating.
- **Fact verification as a user-facing feature.** The draft-to-trusted
  review step could double as a mobile "here's what I remember about you,
  confirm or correct" aide-memoire, not just an admin moderation queue.
- **Fact-to-fact relations.** `related` (built, see
  [ADR-0005](adr/0005-consolidation-algorithm.md)) only records
  consolidation's supersede edge. An *authored* graph (a human or
  extraction step deliberately linking facts) is still unbuilt. A graph
  *view* is a separate presentational layer Drupal has nothing built-in
  for - don't build until there's a real reason to browse facts as a graph
  rather than query them.
- **Scheduled TTL / staleness-driven review**, distinct from consolidation's
  `expires`. A real scheduled job (Queue API + dedicated crontab, not
  `hook_cron`) that acts on fact age is still unbuilt. "Force into review
  status" on staleness is really [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
  Content Moderation gate arriving early - don't build a smaller ad hoc
  `review_status` field that Content Moderation would just replace later.
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
- **Bi-temporal fact validity.** `aim_fact` has `created`/`expires`, but
  neither is independently assertable as "when this became true in
  reality" versus "when the system learned it." A fact reported late (a
  user says today "I moved three months ago") has no field for the
  real-world date separate from today's write timestamp. Zep-style
  two-axis timestamps are the model to borrow from if this becomes a real
  problem; not designed.
- **Source-boundary policy per site archetype.** Ties to ADR-0010's
  still-undesigned "speckit-for-Drupal" idea (its open question 8): each
  discovery-skill archetype (commerce, support, ...) should declare its own
  allowed-source boundaries as config, likely as its own Guardrail set (the
  same mechanism `aim_write_guardrails` is already one instance of), not a
  single global policy.
- **Extraction-input guardrailing, distinct from candidate-fact
  guardrailing.** Guardrails today only filters the *output* of extraction
  (candidate fact text, via `runGuardrails()`). Nothing filters the *input*
  to an extraction call - a source document engineered to manipulate the
  extracting model into asserting a false-but-textually-clean fact would
  pass every existing check. Matters once ingesting raw source material
  (see the media ingestion idea below) becomes real; plain user-typed text
  carries a much smaller version of this risk today.
- **Taxonomy field, revisited.** The original caution above ("wait for a
  real recurring category") assumed the field would be admin-curation
  metadata only. It's more concretely motivated if it also does routing
  work - which discovery-skill archetype/source-policy/guardrail set
  governs a fact - rather than just being a browsable tag. Worth building
  once the archetype/info-pack design (previous bullet) is real, not
  before.
- **Graduated-detail retrieval (OpenViking-style L0/L1/L2).** Store a short
  one-line abstract plus fuller detail tiers, fetch only the level a query
  needs, to cut retrieved-context token cost. Relevant to the
  token-efficiency parity target in ADR-0010's widened appraisal if
  retrieval volume ever makes that a real cost. Source: an unverified
  third-party summary, not independently confirmed - treat as a candidate
  mechanism, not a validated one.
- **Media/source ingestion for re-analysis.** Agreed shape if this is ever
  built (2026-09-10): reference existing Media entities rather than `aim`
  owning its own copy, private file scheme (not public) for anything
  sensitive, given this system is treated as potentially business-critical.
  Still gated behind the separate, still-open "does aim ever store source
  material at all" question under Extraction above - this only settles the
  *shape*, not whether it happens.

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
