# CLAUDE.md — AIM

Drupal-native AI agent memory infrastructure: entities + `VECTOR` fields
for storage, Queue API for async extraction/consolidation, `drupal/ai`
Guardrails on every write. Distinct venture, not a feature of or
dependency on any sibling suite. Status: working PoC.

See [README.md](README.md) for the pitch and requirements,
[DEVELOPING.md](DEVELOPING.md) for commands/API/runbooks, and
[adr/](adr/0000-index.md) for decision records (start with
[ADR-0010](adr/resolved/0010-drupal-native-agent-memory-rationale.md) for the
founding rationale). [TODO.md](TODO.md) is the active list and [BACKLOG.md](BACKLOG.md) holds parked and wait-for-trigger items - don't
duplicate it here.

## Scope

**Scope stays broad.** Work so far follows one path (conversational input
turned into extracted facts) as a convenient first exercise, not a
decision to drop the other three categories in
[ADR-0001](adr/resolved/0001-storage-and-scope-model.md)'s scope model - role
memory, site memory, and general per-user memory are equally in scope.

It is assumed this module isn't used for classified information - a
non-sovereign (non-local) AI agent can expose data written through it.
Built with the expectation of a local reasoning model in production use.

## Environment

DDEV, `drupal11` type, PHP 8.4, MariaDB 11.8, docroot `web/`.

**Enabled:** `aim`, `aim_chatbot`, `aim_tool`,
`aim_tool_oauth`, `aim_benchmark`, `tool`, `mcp_server`, `mcp_server_tool_bridge`,
`mcp_server_oauth`, `simple_oauth`
+ `simple_oauth_21`/`simple_oauth_server_metadata`/
`simple_oauth_client_registration`/`simple_oauth_pkce`/`consumers`
(OAuth stack, see [DEVELOPING.md](DEVELOPING.md)'s "MCP OAuth"),
`ai_agents`, `ai_assistant_api`, `ai_chatbot`, `ai_search`, `ai_logging`,
`ai_provider_anthropic`/`ai_provider_amazeeio`/`ai_provider_ollama`,
`ai_vdb_provider_mariadb`, `search_api`, `views`, `queue_ui`,
`serialization` (core - required by `mcp_server_tool_bridge`'s schema
generation).
**Hard dependency:** `literals` (`drupal/literals` on drupal.org, working
copy in `web/modules/contrib/literals/`, the exact-value store; `recall()` replaces `[literal:key]` tokens through
it). `literals_finder` and `literals_tool` are optional: the assistant's
lookup tool is `aim_tool`'s `aim_literal` (a wrapper over the
`literals.lookup` service), through `tool_ai_connector`. See
[DEVELOPING.md](DEVELOPING.md)'s "Exact values from `literals`". Memory is
aim's job; literals is the value store behind it, not a second recall path.
**Composer-present but not enabled:** `eca` (no `aim` code uses it -
`aim_eca` was removed, see [DEVELOPING.md](DEVELOPING.md)), `ai_context`
(CCC).

**Trust this file's module lists as intent, verify before relying on
them.** `core.extension` can record a module as installed while its
files are absent from the codebase (a previously-hit real incident) -
check `ddev drush pm:list` and the module's actual directory before
assuming a `drush en`/`drush cr` failure is about what you just changed.

## Architecture decisions

Binding until superseded. Full context in [adr/](adr/0000-index.md):

1. **Storage** - entities + `VECTOR` fields via `ai_vdb_provider_mariadb`,
   in-database mode only. ([ADR-0001](adr/resolved/0001-storage-and-scope-model.md))
2. **Scope model** - scopes are `aim_fact` bundles, composable at
   retrieval ([ADR-0001](adr/resolved/0001-storage-and-scope-model.md)):
   config on core plugins, five shipped by `aim`
   ([ADR-0063](adr/0063-scopes-as-config-on-core-plugins.md)).
3. **Governance** - permissions/roles + a draft-to-trusted gate, built as
   the `trusted` flag rather than Content Moderation; `recall()` skips
   untrusted facts. A new fact starts trusted when its writer could trust
   it by hand (`administer aim`, `review aim facts` or the scope's trust
   permission); otherwise its starting trust comes from
   `aim.settings:trust_policy` (per entry channel, then per scope, then
   `default_trusted`). The grounded check, in enforce mode, demotes or
   promotes it per the policy (a reviewer's start can be demoted, only an
   explicit `trusted` cannot), and `hold` is never promoted. The chat
   channel ships as `hold`. ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
   addendum)
4. **Processing** - Queue API + a dedicated crontab entry, never
   `hook_cron`. ([ADR-0003](adr/resolved/0003-async-processing-dedicated-crontab.md))
5. **Sovereignty** - extraction/consolidation must support Ollama; hosted
   providers are additional options, not replacements.
   ([ADR-0004](adr/resolved/0004-sovereignty-and-poc-build-order.md))
   **Temporary deviation (2026-10-02):** this site's consolidation and
   verifier run on hosted Jev (synthetic/demo data only); the chat path and
   `DecisionBackend` stay so a local decision model can be switched back
   in per activity. ([ADR-0021](adr/0021-jev-typed-decision-provider.md),
   decision 2)
6. **Build order** - prove schema/logic with interactive Claude Code +
   local embeddings before any unattended provider.
   ([ADR-0004](adr/resolved/0004-sovereignty-and-poc-build-order.md))
7. **Guardrails mandatory** - every candidate fact runs through
   `drupal/ai`'s Guardrails before it's written anywhere.
   ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md))
8. **Generative/planning surface** (spec -> Recipe -> built site) is in
   scope; no recipe-apply tool is exposed to an LLM-driven caller without
   a dry-run and human review.
   ([ADR-0035](adr/0035-standing-constraints-action-gate.md); retired
   [ADR-0009](adr/resolved/0009-recipe-apply-safety-gate.md))

No Drupal module competes with `aim`'s actual scope (governed,
Guardrail-checked, extracted-and-consolidated, vector-searchable memory) -
checked directly against drupal.org. The one real overlap, CCC, is
handled per [ADR-0008](adr/0008-chatbot-integration-mechanism.md).

## Repository layout

```text
aim/                    ← root module (always required), ships the five scopes
├── modules/
│   ├── aim_chatbot/     ← ai_agents chat agent on aim_tool's tools
│   ├── aim_tool/        ← Tool API + MCP exposure, any scope
│   ├── aim_tool_oauth/  ← OAuth2 scopes for remote MCP callers
│   └── aim_benchmark/   ← synthetic fact generation, aim:benchmark commands
├── recipes/             ← Drupal Recipes, one dir each (aim_demo_library)
└── adr/                 ← decision records, see 0000-index.md
```

Ships its own `composer.json` (separate from this site's root one) -
`aim` is headed for drupal.org as an independent project, not just this
site's build, so it declares its own Drupal package dependencies rather
than relying on the site shell's manifest.

| Module | Purpose | Docs |
| --- | --- | --- |
| `aim` | Core - `aim_fact`/`aim_scope` entities, the scopes and their plugins (ADR-0063), `AimMemoryManager`, Guardrails wiring, vector search, admin UI, `aim:remember`/`aim:recall`/`aim:extract`/`aim:consolidate`/`aim:expire` Drush commands. | this file |
| `aim_chatbot` | An `ai_agents` chat agent, assistant and block on `aim_tool`'s `aim_recall`/`aim_remember`; scope set by the visitor's permissions (ADR-0062) | [CLAUDE.md](modules/aim_chatbot/CLAUDE.md) |
| `aim_tool` | `#[ToolMethod]` tools (any scope, permission-gated) for chat (through `tool_ai_connector`), Tool API and, via `mcp_server_tool_bridge`, MCP | [CLAUDE.md](modules/aim_tool/CLAUDE.md) |
| `aim_tool_oauth` | OAuth2 scopes + third-party settings so a remote MCP client with no Drupal session can authenticate | [CLAUDE.md](modules/aim_tool_oauth/CLAUDE.md) |
| `aim_benchmark` | Synthetic fact generation (`AimBenchmarkGenerator`) and the `aim:benchmark`/`aim:benchmark-cleanup` Drush commands, split out so production sites don't ship dev-only load generation | [CLAUDE.md](modules/aim_benchmark/CLAUDE.md) |

`recipes/` holds hand-written Recipes (not AI-generated, so outside
[ADR-0035](adr/0035-standing-constraints-action-gate.md)'s gate); see
[DEVELOPING.md](DEVELOPING.md)'s "Recipes". Each has its own README.md.

Each submodule carries its own README.md/CLAUDE.md/DEVELOPING.md
alongside its `.info.yml`, same shape as this root set. This file (and
root README.md/DEVELOPING.md) covers the `aim` core module plus
environment, architecture decisions, and conventions shared across all
submodules - submodule-specific detail lives in their own docs, not
duplicated here.

## Data model

- **`aim_fact`** content entity. Bundle field `scope` (deliberately not
  named `type` - see [DEVELOPING.md](DEVELOPING.md)), bundles are real
  `aim_scope` config entities (`bundle_entity_type`), not code-defined.
  Fields: `subject`/`user` (an entity reference to a real Drupal account,
  required for `scope=user` per
  [ADR-0007](adr/resolved/0007-user-scope-requires-real-account.md); renamed from
  `subject_uid` 2026-09-28, `aim_update_10001()`, to stop colliding in
  spirit with the unrelated `uid` field below),
  `target_type`/`target_id` (plain strings naming an arbitrary referenced
  entity, required for `scope=entity` in practice though not enforced at
  write time yet - see
  [ADR-0027](adr/resolved/0027-entity-scope.md); not a real `entity_reference`
  since that field type needs one fixed target type, and not
  `drupal/dynamic_entity_reference` either, to keep the dependency out of
  core `aim` - see that ADR's Context; declared by the entity plugin's
  `AimScopeEntity::getBaseFieldDefinitions()` and merged onto `aim_fact`
  by `AimHooks::entityBaseFieldInfo()` on every site -
  [ADR-0028](adr/resolved/0028-scope-type-plugin.md) piece 1),
  `text` (Guardrails-validated), `source`,
  `category` (taxonomy, vocabulary `aim_category`), `asserted` (valid-time
  start), `retired`/`superseded_by`/`superseded_by_reason`, plus `expires` (a nullable end date; `aim:expire` retires the fact once it passes, ADR-0057) (consolidation's
  non-destructive supersede edge and its JSON provenance), `uid`
  (who wrote it), `grounding_score` (float, empty when unchecked: how
  strongly a decision model found the fact stated in the text it was
  written from, checked at write time; the text is not kept in the fact -
  [ADR-0037](adr/resolved/0037-transient-source-passages-for-grounding.md); see
  [DEVELOPING.md](DEVELOPING.md)'s "Grounded check"), `trusted` (boolean, the draft-to-trusted gate - starts per
  `aim.settings:trust_policy` (the add form: `default_trusted`), `recall()` excludes untrusted facts
  unless asked otherwise, see
  [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
  addendum).
- **`aim_scope`** config entity: `id`, `label`, `status`, `description`,
  `weight`, `plugin`, `settings`. `aim` ships five (site, role, user,
  case, entity) in `config/install`, like `literals` ships its literal
  types ([ADR-0063](adr/0063-scopes-as-config-on-core-plugins.md)). A
  disabled scope takes no new facts and is not offered; a scope with
  facts can't be disabled or deleted. `description` is the wording models
  read to choose a scope, `weight` the order everything lists scopes in
  (dragged on the scope list). `plugin` names the `AimScopeTypeInterface`
  plugin (`user`, `case`, `entity` in `src/Plugin/AimScopeType/`; null
  for site and role), which gets the scope's `settings` as its
  configuration; two scopes can share a plugin
  ([ADR-0028](adr/resolved/0028-scope-type-plugin.md) piece 3). The
  interface (ADR-0025/0028): `checkViewAccess()` (ORed against the flat
  permission), `resolveSubject()` (run on save by a presave hook; the
  case plugin mints or names case IDs under its `prefix` setting),
  `getBaseFieldDefinitions()` and `defaultSettings()`/
  `buildSettingsForm()` (rendered inline on the scope form; the user
  plugin's are `requires_account` and the role-visibility grid, the
  case plugin's the case ID `prefix`). No
  `field_ui_base_route` - deliberately, see
  [DEVELOPING.md](DEVELOPING.md) for why.
- Vector search: server `aim_vector`, index `aim_vector_index`, collection
  table `aim_fact_vectors` (MariaDB HNSW `VECTOR INDEX`; renamed from
  `aim_facts` 2026-09-28 - too easy to confuse with the `aim_fact` entity
  table in a raw SQL query). Retired facts (`retired`
  set) are excluded from the index by the `aim_exclude_retired` processor
  ([ADR-0022](adr/resolved/0022-exclude-retired-facts-from-vector-index.md)),
  `user` is an indexed attribute with a BTREE index
  ([ADR-0018](adr/resolved/0018-index-subject-uid-with-btree.md), named
  for the field's old `subject_uid` name), HNSW is tuned to
  `M=16` and `ef_search=100` ([ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md)),
  and the
  provider plugin is swapped for `AimMariaDBProvider` to work around four
  upstream bugs. Detail in [DEVELOPING.md](DEVELOPING.md).

## Permissions

`administer aim` (flat admin bypass, `restrict access: true`);
`review aim facts` (`restrict access`; trust/untrust bulk actions in every
scope, no text edits - the one reviewer permission, ADR-0037's addendum);
`ingest aim facts` (the Content > AIM > Ingest form at
`/admin/content/aim-facts/ingest`, `restrict access: true`; opens the form
only, each fact also needs its scope's `create {scope} aim facts`, see
[ADR-0016](adr/0016-document-ingestion-ui.md)).
Per-scope, dynamically generated via `BundlePermissionHandlerTrait`:
`view {scope} aim facts` / `create {scope} aim facts` /
`trust {scope} aim facts` (`restrict access`; gates the custom `trust`
entity access operation used only by the Trust/Untrust bulk actions, so
a scope's reviewer can flip the `trusted` flag without update access to
the fact's text; retire/unretire stay on `administer aim`).
The same three permissions (`administer aim`, `review aim facts`, the
scope's trust permission) start a writer's fact trusted and are the only
ones `remember()`'s explicit `trusted: TRUE` is honored for
(`AimTrustPolicy::canApprove()`). Chat, Tool API and MCP:
`remember aim facts` / `recall aim facts` (`aim_tool`; not `restrict
access`, since each only opens a tool whose results and writes are
checked per scope). The user scope's role-visibility grid (its own
settings, on its scope form) additionally grants `scope=user` visibility
by viewer-role/subject-role pairing, ORed against `view user aim facts` - see
[DEVELOPING.md](DEVELOPING.md)'s "User-scope role visibility".

The `administrator` role has **every** permission implicitly, including
dynamically registered ones - never diagnose it as missing a permission;
look at other roles when a permission check unexpectedly fails.

## Cross-cutting conventions

- **Never hand-type a config entity's `dependencies` or (Views)
  `cache_metadata` key in `config/install`** - both are computed by
  Drupal on `->save()` and silently diverge from a guess. Re-fetch
  `\Drupal::config($name)->getRawData()` after a real save (module/`uuid`/
  `_core` stripped) and use that.
- `drush config:status` compares against the config **sync** directory,
  not `config/install` - the wrong tool for checking whether live config
  matches what a module ships.
- No migration/`hook_update_N()` needed while there's no real data to
  preserve (PoC) - just change and reinstall (`drush pmu`/`drush en`).
  Revisit once real data exists that reinstalling would destroy.
- **`AimMariaDBProvider` stays a thin shim** over `ai_vdb_provider_mariadb`.
  A workaround lives there only with an upstream issue in TODO.md/BACKLOG.md and is
  deleted when the provider ships the fix; generic features go upstream
  first; aim ships values under the provider's own config key names, never
  its own. Rule and per-override table in
  [ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md).
- **Logging** goes through `logger.channel.aim`: warnings and errors
  always, info/debug only via `AimMemoryManager::logAudit()` /
  `logVerbose()` (gated by `aim.settings` `log_audit` / `log_verbose`).
  On this channel put IDs, scope, uid, subject, decision, score, provider
  and model ID only, not fact or recall query text (the one exception is
  the opt-in `log_query_text`). Model calls tagged `aim_*` are the
  exception to "no text": `ai_logging` records their prompts and replies in
  `ai_log` ([ADR-0060](adr/0060-adopt-ai-logging-for-model-calls.md);
  stealth mode, off, is the later opt-out); a new tag must be added to
  `ActivityBackendInterface::LOGGED_TAGS` and the site's
  `ai_logging.settings:prompt_logging_tags` (site-wide, aim doesn't write
  it). A new write path, action or decision point should add an audit line; see
  [DEVELOPING.md](DEVELOPING.md)'s "Logging".
- Hooks live in `src/Hook/AimHooks.php` (`#[Hook(...)]` attributes), not
  `aim.module` - core's OOP hook system, same shape as this codebase's
  sibling `annotations` module's `AnnotationsHooks`.

## Code style (PHP/JS/CSS)

- No em dash character (or `&mdash;`) anywhere - hyphen, parentheses, or
  a colon instead.
- No banner/divider comment blocks (`// --- Helpers ---`) - a single
  plain `// Helpers.` line.
- `Html::escape()`, not `htmlspecialchars()`, for escaping in Drupal PHP.
- American English spelling.
- No `/** */` block comments inside method bodies - use `//` line
  comments. Exception: inline `/** @var Type $var */` type narrowing.
- Docblock short description is one line; wrap the rest after a blank `*`
  line. `@return` description on the line after `@return`. Every
  constructor param needs a `@param`, including on an existing
  promoted-property constructor.

## Linting - run before calling PHP/JS/CSS work done

```bash
# phpcs
ddev exec "cd /var/www/html && vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/aim --extensions=php,module,inc,install,test,profile,theme"

# phpstan - pass -c explicitly, this command's cwd is the site root, not
# the module directory, so the module's own phpstan.neon (its
# ignoreErrors rules) is silently skipped without it
ddev exec "cd /var/www/html && vendor/bin/phpstan analyse web/modules/custom/aim --memory-limit=512M -c web/modules/custom/aim/phpstan.neon"

# cspell - run from the module dir so its .cspell.json (core's config +
# .cspell-project-words.txt) is picked up
ddev exec -d /var/www/html/web/modules/custom/aim "../../../core/node_modules/.bin/cspell --no-progress '**/*.{php,module,inc,install,yml,js,css,twig,md}'"
```

Run JS/CSS/spelling tools via core's pinned toolchain
(`web/core/node_modules/.bin/<tool>`), not `npx` - `npx` can drift from
whatever version CI pins. `ddev exec "cd web/core && yarn install
--immutable"` installs the exact pinned versions first (DDEV's
`corepack_enable` already provides the pinned yarn). A genuine new
project word goes in `.cspell-project-words.txt` (the file name
drupal.org's CI template expects), one lowercase word per line, sorted.

`vendor/bin/phpcs` only works through `ddev exec` (or inside the
container) - the host has no `php` on `PATH`. Fix small findings (typos,
style violations, a genuine new dictionary word) inline rather than
queueing them.

## Git

- **Never auto-commit.** Create and edit files freely; stop after
  writing. Don't `git add`/stage or commit unless explicitly asked in the
  moment.
- This module is its own git repo, nested inside the `aim` site shell
  (the site shell has no git tracking of the module). Don't run
  `git init` unprompted even so.

## Docs

"Update docs" means CLAUDE.md and README.md together, and DEVELOPING.md
for technical/developer detail. Keep user-facing content in README.md,
developer reference (commands, API, runbooks, gotchas) in DEVELOPING.md,
operating rules here. When something gets built, fold the *current state*
into the relevant doc and delete the narrative of how it got there - this
file is a reference for what's true now, not a session log. Chronology
belongs in `git log`/ADRs, not here.

Content specific to one submodule belongs in that submodule's own
README.md/CLAUDE.md/DEVELOPING.md, not here - these root docs cover the
`aim` core module plus whatever is genuinely shared (environment,
architecture decisions, code style, git rules). Don't duplicate a
submodule's detail up into the root docs "for visibility" - link to it
instead, the way the repository layout table above does.
