# CLAUDE.md — AIM

Drupal-native AI agent memory infrastructure: entities + `VECTOR` fields
for storage, Queue API for async extraction/consolidation, `drupal/ai`
Guardrails on every write. Distinct venture, not a feature of or
dependency on any sibling suite. Status: working PoC.

See [README.md](README.md) for the pitch and requirements,
[DEVELOPING.md](DEVELOPING.md) for commands/API/runbooks, and
[adr/](adr/0000-index.md) for decision records (start with
[ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md) for the
founding rationale). [TODO.md](TODO.md) is the living backlog - don't
duplicate it here.

## Scope

**Scope stays broad.** Work so far follows one path (conversational input
turned into extracted facts) as a convenient first exercise, not a
decision to drop the other three categories in
[ADR-0001](adr/0001-storage-and-scope-model.md)'s scope model - role
memory, site memory, and general per-user memory are equally in scope.

It is assumed this module isn't used for classified information - a
non-sovereign (non-local) AI agent can expose data written through it.
Built with the expectation of a local reasoning model in production use.

## Environment

DDEV, `drupal11` type, PHP 8.4, MariaDB 11.8, docroot `web/`.

**Enabled:** `aim`, `aim_scope_user`, `aim_scope_role`, `aim_scope_site`,
`aim_scope_case`, `aim_chatbot`, `aim_tool`, `aim_tool_oauth`, `tool`,
`mcp_server`, `mcp_server_tool_bridge`, `mcp_server_oauth`, `simple_oauth`
+ `simple_oauth_21`/`simple_oauth_server_metadata`/
`simple_oauth_client_registration`/`simple_oauth_pkce`/`consumers`
(OAuth stack, see [DEVELOPING.md](DEVELOPING.md)'s "MCP OAuth"),
`ai_agents`, `ai_assistant_api`, `ai_chatbot`, `ai_search`,
`ai_provider_anthropic`/`ai_provider_amazeeio`/`ai_provider_ollama`,
`ai_vdb_provider_mariadb`, `search_api`, `views`, `queue_ui`,
`serialization` (core - required by `mcp_server_tool_bridge`'s schema
generation).
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
   in-database mode only. ([ADR-0001](adr/0001-storage-and-scope-model.md))
2. **Scope model** - four bundles (user/role/site/case), composable at
   retrieval. ([ADR-0001](adr/0001-storage-and-scope-model.md))
3. **Governance** - permissions/roles + Content Moderation draft-to-trusted
   gate; nothing LLM-extracted is auto-trusted. **Deferred for PoC** - see
   below. ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md))
4. **Processing** - Queue API + a dedicated crontab entry, never
   `hook_cron`. ([ADR-0003](adr/0003-async-processing-dedicated-crontab.md))
5. **Sovereignty** - extraction/consolidation must support Ollama; hosted
   providers are additional options, not replacements.
   ([ADR-0004](adr/0004-sovereignty-and-poc-build-order.md))
6. **Build order** - prove schema/logic with interactive Claude Code +
   local embeddings before any unattended provider.
   ([ADR-0004](adr/0004-sovereignty-and-poc-build-order.md))
7. **Guardrails mandatory** - every candidate fact runs through
   `drupal/ai`'s Guardrails before it's written anywhere. Live today, not
   part of the governance deferral above.
   ([ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md))
8. **Generative/planning surface** (spec -> Recipe -> built site) is in
   scope; no unattended `drush recipe apply` on an AI-generated recipe.
   ([ADR-0009](adr/0009-recipe-apply-safety-gate.md))

**PoC deviation from decision 3 (temporary, not abandoned):** no Content
Moderation / draft-to-trusted gate - every fact is live the moment it's
saved. Re-introduce before any non-PoC data goes in.

No Drupal module competes with `aim`'s actual scope (governed,
Guardrail-checked, extracted-and-consolidated, vector-searchable memory) -
checked directly against drupal.org. The one real overlap, CCC, is
handled per [ADR-0008](adr/0008-chatbot-integration-mechanism.md).

## Repository layout

```text
aim/                    ← root module (always required), ships zero scopes
├── modules/
│   ├── aim_scope_user/  ← ships the user aim_scope + its access plugin
│   ├── aim_scope_role/  ← ships the role aim_scope, zero PHP
│   ├── aim_scope_site/  ← ships the site aim_scope, zero PHP
│   ├── aim_scope_case/  ← ships the case aim_scope, zero PHP
│   ├── aim_chatbot/     ← ai_agents FunctionCall tools, locked scope=site
│   ├── aim_tool/        ← Tool API + MCP exposure, any scope
│   └── aim_tool_oauth/  ← OAuth2 scopes for remote MCP callers
├── recipes/             ← Drupal Recipes, one dir each (aim_demo_library)
└── adr/                 ← decision records, see 0000-index.md
```

Ships its own `composer.json` (separate from this site's root one) -
`aim` is headed for drupal.org as an independent project, not just this
site's build, so it declares its own Drupal package dependencies rather
than relying on the site shell's manifest.

| Module | Purpose | Docs |
| --- | --- | --- |
| `aim` | Core - `aim_fact`/`aim_scope` entities, `AimMemoryManager`, Guardrails wiring, vector search, admin UI, `aim:remember`/`aim:recall`/`aim:extract`/`aim:consolidate`/`aim:benchmark` Drush commands. Ships zero scope instances itself (ADR-0026). | this file |
| `aim_scope_user` | Ships the `user` `aim_scope`, its `AimScopeUser` access plugin (role-visibility, ADR-0025), and the `requires_account` ThirdPartySetting (ADR-0007) | [CLAUDE.md](modules/aim_scope_user/CLAUDE.md) |
| `aim_scope_role` | Ships the `role` `aim_scope`. Zero PHP. | [README.md](modules/aim_scope_role/README.md) |
| `aim_scope_site` | Ships the `site` `aim_scope` - `aim_chatbot`'s hardcoded scope. Zero PHP. | [README.md](modules/aim_scope_site/README.md) |
| `aim_scope_case` | Ships the `case` `aim_scope`. Zero PHP; access control still unbuilt. | [README.md](modules/aim_scope_case/README.md) |
| `aim_chatbot` | `#[FunctionCall]` tools for an `ai_agents` chat assistant, hardcoded `scope: site` | [CLAUDE.md](modules/aim_chatbot/CLAUDE.md) |
| `aim_tool` | `#[Tool]` plugins (any scope, permission-gated) exposed over Tool API and, via `mcp_server_tool_bridge`, MCP | [CLAUDE.md](modules/aim_tool/CLAUDE.md) |
| `aim_tool_oauth` | OAuth2 scopes + third-party settings so a remote MCP client with no Drupal session can authenticate | [CLAUDE.md](modules/aim_tool_oauth/CLAUDE.md) |

A site that wants today's default behavior enables all four scope
submodules plus `aim_chatbot`/`aim_tool` as needed - no bundling recipe
yet (ADR-0026 piece 6, unbuilt).

`recipes/` holds hand-written Recipes (not AI-generated, so outside
[ADR-0009](adr/0009-recipe-apply-safety-gate.md)'s gate); see
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
  [ADR-0007](adr/0007-user-scope-requires-real-account.md); renamed from
  `subject_uid` 2026-09-28, `aim_update_10001()`, to stop colliding in
  spirit with the unrelated `uid` field below),
  `text` (Guardrails-validated), `source`, `state` (tri-state boolean),
  `category` (taxonomy, vocabulary `aim_category`), `asserted` (valid-time
  start), `superseded_by`/`expires` (consolidation's supersede edge), `uid`
  (who wrote it), `trusted` (boolean, the draft-to-trusted gate - defaults
  to `aim.settings:default_trusted`, `recall()` excludes untrusted facts
  unless asked otherwise, see
  [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
  addendum).
- **`aim_scope`** config entity, `id`/`label` only, defined in core `aim`
  but shipped by the four `aim_scope_{user,role,site,case}` submodules
  (ADR-0026), not core `aim` itself - a site installs only the scopes it
  wants. Scope-level config differences are ThirdPartySettings the owning
  submodule reads (`requires_account`, `aim_scope_user`-owned,
  ADR-0007's "a scope=user fact must reference a real account", read
  generically via `AimMemoryManager::scopeRequiresAccount()`); scope
  behavior differences are `AimScopeAccessInterface` plugins (ADR-0025).
  No `field_ui_base_route` - deliberately, see
  [DEVELOPING.md](DEVELOPING.md) for why.
- Vector search: server `aim_vector`, index `aim_vector_index`, collection
  table `aim_fact_vectors` (MariaDB HNSW `VECTOR INDEX`; renamed from
  `aim_facts` 2026-09-28 - too easy to confuse with the `aim_fact` entity
  table in a raw SQL query). Retired facts (`expires`
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

`administer aim memory` (flat admin bypass, `restrict access: true`).
Per-scope, dynamically generated via `BundlePermissionHandlerTrait`:
`view {scope} aim facts` / `create {scope} aim facts`. Tool API/MCP:
`store aim memory` / `read aim memory` (`aim_tool`). A
`user_scope_role_visibility` matrix (`/admin/config/aim/user-scope-access`)
additionally grants `scope=user` visibility by viewer-role/subject-role
pairing, ORed against `view user aim facts` - see
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
  A workaround lives there only with an upstream issue in TODO.md and is
  deleted when the provider ships the fix; generic features go upstream
  first; aim ships values under the provider's own config key names, never
  its own. Rule and per-override table in
  [ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md).
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
```

Run JS/CSS/spelling tools via core's pinned toolchain
(`web/core/node_modules/.bin/<tool>`), not `npx` - `npx` can drift from
whatever version CI pins. `corepack enable && cd web/core && yarn install
--immutable` installs the exact pinned versions first.

`vendor/bin/phpcs` only works through `ddev exec` (or inside the
container) - the host has no `php` on `PATH`. Fix small findings (typos,
style violations, a genuine new dictionary word) inline rather than
queuing them.

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
