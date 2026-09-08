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
   writing a memory fact.

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

**Idea raised, not designed: extraction as a curated Skill.** The
elicitation side of this - the structured conversation that decides what's
worth extracting - could be built as a Claude Code Skill rather than ad hoc
prompting. This is the same territory as ADR-003's open question 8
("Speckit-for-Drupal's actual question sets") and its "second product
surface" (spec-gathering conversation → Drupal Recipe): a well-curated
elicitation Skill could plausibly be the front end for both the
retrospective memory-extraction case and the generative spec-gathering case,
sharing one structured-conversation mechanism. Nothing designed here yet -
what questions, in what order, per use case - just worth remembering this
connects to that open question rather than being a new idea from scratch.

## Ideas raised, not designed

Two more, not yet built, kept here so they don't evaporate between sessions:

- **Typed/boolean facts could skip the vector pipeline entirely.** A
  flag-shaped fact ("user prefers email over phone") doesn't need semantic
  similarity to retrieve, just an indexed lookup by scope+subject against
  `aim_fact`'s own table. A Drupal cache API layer (keyed by scope+subject,
  tagged so a save invalidates it) makes sense on top of that lookup for a
  hot path, but only once there's a typed-fact concept (a `value_type`/
  `value` field, or a separate bundle) to look up in the first place. Don't
  add this speculatively; wait for an actual hot-path check that needs it.
- **Fact verification as a user-facing feature, not just a governance
  gate.** The draft-to-trusted review step (decisions 3/7) could double as
  a mobile-friendly "here's what I remember about you, confirm or correct"
  aide-memoire, valuable to the person reviewing it independent of it also
  being the safety gate. When that review UI actually gets built, default
  to something a person would want to use, not an admin moderation queue.

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

`drupal/core-dev` (dev-only, `composer require --dev drupal/core-dev:^11.4`)
is what provides `drupal/coder` (phpcs) and phpstan/mglaman-phpstan-drupal -
not yet installed at time of writing, so the commands above will fail until
it is.

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
