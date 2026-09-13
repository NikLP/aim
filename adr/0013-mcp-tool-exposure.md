# ADR-0013: MCP tool exposure via Tool API (`tool`/`mcp_server`/`mcp_server_tool_bridge`)

**Status:** Built and MCP-exposed, 2026-09-12. `aim_tool` module
(`AimRemember`/`AimRecall`) is live, tested, permission-split, and
discoverable by `mcp_server` over the bridge. Only the client
authentication question (who is allowed to call the `/mcp` route at all)
remains open - see the "Built 2026-09-12" addendum at the end for the
full path, including a real drupal.org packaging bug that briefly looked
like a dead end.
**Date:** 2026-09-12

## Context

2026-09-11/12 discussion (see CLAUDE.md's Chatbot section and ADR-0006)
landed on a framing for what's worth building next: Drupal site owners
need a concrete reason to adopt `aim`, and separately, agents should find
`aim` technically attractive to call. The second half has a specific
current gap - the only agent-facing surfaces today are `drush
aim:remember`/`aim:recall` (ADR-0006's CLI adapter, needs a DDEV/drush
shell) and `aim_chatbot`'s two `#[FunctionCall]` tools (hardcoded to
`scope: site`, locked because an anonymous chat visitor has no real
account to resolve `scope: user` against - see ADR-0007). Neither is what
a generic MCP-speaking agent (Claude Desktop, Cursor, another Claude Code
session) expects to find.

Drupal's contrib MCP landscape was checked directly against drupal.org
(via the same standard this file already holds itself to elsewhere - not
assumed from general knowledge). Two independent lineages exist:

- **Lineage 2 (considered, not chosen):** the `mcp` project itself
  ("Model Context Protocol") - monolithic, ships its own JSON-RPC method
  plugins plus a bundled `#[Mcp]` plugin type with built-ins
  (`AiFunctionCalling` bridges `drupal/ai` FunctionCall plugins in;
  `AiAgentCalling` exposes `ai_agents` agents directly; `DrushCaller`
  exposes drush commands; `JsonApi`/`Content`/`General` cover other
  surfaces). Real, security-covered, 353 installs - but its dev branch's
  last commit is 2026-03-19, six months stale next to the alternative
  below.
- **Lineage 1 (chosen path):** three smaller, currently-active projects
  used together:
  - `tool` ("Tool API") - the actual foundation, a generic `#[Tool]`
    plugin type. beta7, released 2026-09-03. 672 installs,
    security-covered. Itself requires `ai` and `ai_agents`, both already
    enabled on this site. Hub of the ecosystem - 24 dependent projects.
  - `mcp_server` - the MCP JSON-RPC protocol server itself
    (`initialize`, `tools/list`, `tools/call`, etc.). 2.0.0-beta2,
    released 2026-09-02. 408 installs, security-covered. Zero
    dependencies of its own.
  - `mcp_server_tool_bridge` - the adapter: registers any `tool`-API
    plugin as an `mcp_server` tool. 1.0.0-beta1, released 2026-07-29.
    188 installs, security-covered.

  All three were touched within two weeks of this ADR's date - the
  actively-developed line, versus lineage 2's six-month-stale one.

A fourth project, `mcp_tools` (+ its `mcp_tools_ai` submodule), bundles
many pre-built `tool`-API plugins for other subsystems (search_api,
JSON:API, webform, translate, Recipes - see
`project_aim_mcp_tools_recipes` in the assistant's own memory, relevant
to decision 8/ADR-0009) and a bridge that derives any `tool`-API plugin
into a `drupal/ai` FunctionCall automatically, so an `ai_agents` agent
(including `aim_chatbot`'s own) could call it too. Real and potentially
useful later, but the parent `mcp_tools` project is flagged **not
security-covered** on drupal.org, and almost all of its bundled tools are
for subsystems `aim` doesn't touch. Not part of the dependency set below.

Permission model verified independently for lineage 1, not assumed by
analogy to lineage 2: `mcp_server` gates its own route on an `access mcp
server` permission; `mcp_server_tool_bridge` has `administer mcp tool
configurations`; `tool` has `administer tool` for its explorer UI. An MCP
caller authenticates as a real Drupal account through Drupal's normal
auth stack in front of that route - this is the fact the Decision below
depends on.

## Cross-project evidence (annopm/Annotations, added 2026-09-12)

A related suite in this same environment (`~/websites/annopm`, the
Annotations suite) has already lived through this exact design question
for its own AI-facing surface, independently of `aim` - real, executed
decisions, not analogous guesswork, worth checking before re-deriving any
of it from scratch (its own ADR-010, ADR-016).

- **Tool API plugins are the durable artifact; MCP/FunctionCall exposure
  are consumers on top, not separate things to build and maintain.**
  `annotations_context` originally shipped a hand-rolled MCP JSON-RPC
  controller (`ContextMcpController`) alongside two Tool API plugins
  (`GetAnnotations`/`ListAnnotationTargets`) that duplicated what it
  exposed. Once both existed, the hand-rolled controller was deleted
  outright (2026-08-22, "everything likely to now route thru Tool API")
  rather than migrated - `mcp_server_tool_bridge` re-exposes the same Tool
  API plugins over MCP for free. This directly confirms this ADR's own
  framing of `tool` as "the actual foundation... hub of the ecosystem":
  build `AimRemember`/`AimRecall` as `#[Tool]` plugins first: MCP exposure
  (and, if `mcp_tools_ai` is ever added, FunctionCall exposure) follow as
  thin config on top, not as separate hand-rolled work.
- **The MCP-bridge config entities don't need their own module.**
  `mcp_server_tool_bridge`'s `mcp_tool_config.*` entities (pointing an
  existing Tool API plugin ID at an MCP tool) are pure site configuration
  - annopm explicitly considered and dropped a dedicated `annotations_mcp`
  module for exactly this, shipping the config entities in
  `config/install` of the module that already owns the Tool plugins
  instead ("no different from a view or a role"). Bears on this ADR's own
  "module name" open question below: the new module's job is hosting the
  Tool plugins, with the MCP-facing config entities living inside it, not
  a second `aim_mcp` module for MCP specifically.
- **Auth: `mcp_server` core really does ship nothing, confirmed by a
  second independent read of its source/docs, not just this ADR's own.**
  annopm's investigation quotes `mcp_server`'s own
  `references/auth/index.md` directly: "Core ships zero auth policy... a
  site that wants a scheme (OAuth2, API keys, mTLS, role-based) writes its
  own subscriber." In practice this means: out of the box, only a caller
  already holding a Drupal session (a logged-in browser) can reach the
  route at all - a headless/external caller needs a site-supplied
  `AuthenticationProviderInterface` service registered on that route via a
  `RouteSubscriber`, which is the standard Drupal extension point for
  proving identity before the `access mcp server` permission check runs;
  nothing in `mcp_server` itself provides this for a non-session caller.
  annopm's first plan was to write exactly that subscriber by hand
  (replicating an existing custom Bearer-token comparison). That plan was
  superseded 2026-09-07: real review of the installed package found
  `mcp_server` has an OAuth2 companion project that does the same job,
  adopted instead of hand-rolled auth code. **Recommendation for `aim`:**
  default to evaluating that same OAuth2 companion project first, rather
  than researching Basic auth/API-key modules from a blank page - not yet
  confirmed to fit `aim`'s case identically, but a real, working precedent
  beats undirected research.

## Decision (built 2026-09-12, both halves)

- New submodule, named `aim_tool` (settled 2026-09-12 - "to keep cadence
  with the ecosystem," matching `tool`/`mcp_server`/`mcp_server_tool_bridge`'s
  own naming, singular over the `aim_tools` this ADR floated first),
  alongside `aim_chatbot`, depending on `aim:aim` + `tool:tool` +
  `mcp_server_tool_bridge:mcp_server_tool_bridge` (which itself pulls in
  `mcp_server`). The MCP-exposing `mcp_tool_config.*` config entities ship
  in this same module's `config/install`, not a second module - they are
  pure configuration, not code, per the cross-project evidence above.
- It defines native `#[Tool]` plugins wrapping `AimMemoryManager::
  remember()` and `recall()` directly. These are new plugins, not derived
  from `aim_chatbot`'s existing `#[FunctionCall]` tools, and don't replace
  them - `aim_chatbot` keeps its current anonymous-visitor-safe,
  `scope: site`-locked tools exactly as they are.
- The new tools are deliberately **not** locked to `scope: site`. An MCP
  caller authenticates as a real Drupal account (unlike an anonymous chat
  visitor - the exact condition ADR-0007 requires for `scope: user`), so
  the tool can accept `scope`/`subject`/`subject-uid` parameters and
  resolve against the calling account, the same shape ADR-0006 already
  established for the drush CLI adapter - this is that same agent-native
  write path, over MCP transport instead of a shell.
- Guardrails still run unconditionally: the new plugins call
  `AimMemoryManager::runGuardrails()`, same as every other write path
  (`remember()`, `createFactsFromCandidates()`, `aim_eca`'s `FactWrite`).
  No exemption for this entry point.
- `mcp_tools_ai`'s auto-derivation (Tool API plugin -> `drupal/ai`
  FunctionCall) is a possible later layer, not decided here. If added, it
  would let `aim_chatbot`'s own agent call the same tool definitions -
  but that path is an internal agent call, not an authenticated MCP
  caller, so ADR-0007's anonymous-visitor reasoning would still apply
  there and needs its own review before wiring it up.

## Consequences / risks

- **Auth is the load-bearing unresolved piece.** `mcp_server`'s `access
  mcp server` permission exists, but what actually sits in front of it -
  a real login flow, an API-key module, something else - hasn't been
  chosen or verified. Nothing here should write real memory until that's
  settled.
- **Composer surface.** Three new dependencies, all pre-1.0 (`tool` and
  `mcp_server_tool_bridge` are still beta) - a real maintenance trade a
  PoC absorbs more easily than a production site would.
- **Scope discipline.** This only adds a second, more permissive entry
  point for authenticated callers - it does not loosen `aim_chatbot`'s
  existing anonymous-visitor lock. Keep the two paths separate in code,
  not just by intent.
- **Guardrails/permission drift.** Every existing write path is already
  audited (see CLAUDE.md's Guardrails section). A new entry point widens
  that surface - confirm `runGuardrails()` is actually reached from the
  new Tool plugin with a real test, not assumed by symmetry with the
  others.

## Open questions

- **Exact MCP client authentication mechanism - the one thing left before
  this is safe to point a real external client at.** `mcp_server`'s
  `access mcp server` permission is real and gates the route, but nothing
  yet supplies a way for a non-session (headless) caller to prove who they
  are and receive it - see the auth finding in "Cross-project evidence"
  above. `mcp_server`'s OAuth2 companion project is the leading candidate
  per the annopm precedent, but not yet evaluated against this site. Until
  this is settled, only a caller with an existing Drupal session (a
  logged-in browser holding `store`/`read aim memory`) can actually reach
  `aim_remember`/`aim_recall` over MCP - fine for continued local testing,
  not yet a real external-agent story.
- Whether `mcp_tools_ai`'s auto-derivation is worth adding later, and
  whether it should replace or sit alongside `aim_chatbot`'s existing
  FunctionCall tools.
- ~~Module name~~ - decided 2026-09-12: `aim_tool`, built.

## Addendum (built 2026-09-12): Tool API plugins and MCP exposure, both shipped

**What's built and tested:** `aim_tool` module
(`web/modules/custom/aim/modules/aim_tool/`), two `#[Tool]` plugins in
`src/Plugin/tool/Tool/`: `AimRemember` (`aim_remember`, operation `Write`)
and `AimRecall` (`aim_recall`, operation `Read`), both wrapping
`AimMemoryManager::remember()`/`recall()` exactly as this ADR's Decision
specified - `scope`/`subject` caller-supplied (not locked to `scope:
site`), `subject` defaults to the calling account for `scope: user` when
omitted. Gated on two new permissions, not the single blanket
`administer aim memory` the admin UI uses: `store aim memory`
(`AimRemember`) and `read aim memory` (`AimRecall`), defined in
`aim_tool.permissions.yml` - split deliberately so a site can grant write
without read or vice versa, verified live with two throwaway roles each
holding only one permission (writer role: could remember, could not
recall; reader role: the reverse). `ToolBase`'s constructor is `final`
with no room for extra constructor-injected services, so `AimMemoryManager`
is fetched via `\Drupal::service('aim.memory_manager')` inside
`doExecute()` instead - the same service-locator pattern `aim_eca`'s
plugins already use for the identical reason (CLAUDE.md's ECA integration
section). `phpcs` clean.

**MCP exposure is live - the blocker was a drupal.org packaging bug, not
missing code.** `composer require drupal/mcp_server_tool_bridge:^1.0@beta`
fails outright - `composer show drupal/mcp_server_tool_bridge --all`
returns only `dev-1.x`/`1.x-dev`, `type: metapackage`, empty `source`/
`dist`, no real release. That's what this ADR first read as "no
installable code exists" - wrong. Cloning
`https://git.drupalcode.org/project/mcp_server_tool_bridge.git` (branch
`1.x`) directly shows a complete, real module (`McpToolConfig` entity,
`ToolApi` plugin, `McpToolConfigDeriver`), whose own `composer.json`
names itself plainly as `drupal/mcp_server_tool_bridge`. The plain name
is simply broken on `packages.drupal.org`'s Composer facade for this
project - the actual installable package is published under a
self-doubled name, `drupal/mcp_server_tool_bridge-mcp_server_tool_bridge`
(confirmed for real: `composer show` on that exact string returns the
genuine `1.0.0-beta1` release with a populated `dist`), which is what
drupal.org's own project page gives as the require command. A real
packaging quirk, not a typo to fix in this ADR - `composer require
"drupal/mcp_server_tool_bridge-mcp_server_tool_bridge:^1.0@beta"` is the
correct, working incantation. (Cosmetic side effect: composer/installers
uses that same string for the install directory, so it lands at
`web/modules/contrib/mcp_server_tool_bridge-mcp_server_tool_bridge/` -
ugly, harmless, Drupal's module discovery doesn't care about directory
names.)

With the real package installed and `mcp_server` + `mcp_server_tool_bridge`
enabled, two `mcp_tool_config` config entities (`aim_remember`/
`aim_recall`, `config/install` in `aim_tool`) point at the two Tool API
plugin IDs. Confirmed live: `plugin.manager.mcp_server.tool` lists both as
`tool_api__aim_remember`/`tool_api__aim_recall` - `mcp_server` genuinely
sees them as MCP tools now.

**Permission split, added same day:** `AimRemember`/`AimRecall` no longer
share `administer aim memory` - `aim_tool.permissions.yml` defines
`store aim memory` and `read aim memory` separately, so a site can grant
write without read or vice versa. Verified live with two throwaway roles,
each holding exactly one permission.

**Batch input, added same day - real capability, incomplete schema.**
`aim_remember` now accepts an optional `facts` list (`ListInputDefinition`
of `MapInputDefinition` items: text/scope/subject/source per entry)
alongside its single-fact inputs, mirroring `aim:remember --file`'s own
motivation: an MCP `tools/call` is its own HTTP request, so an agent asked
to remember several things would otherwise pay one Drupal bootstrap per
fact regardless of transport. Same per-entry resilience as the drush
version (one bad entry reported and skipped, not aborting the batch) -
required this file's own `text` property inside the map to be `required:
FALSE`, since `ToolBase::access()`/`getExecutableValues()` enforces
declared-required fields *before* `doExecute()` ever runs, which would
otherwise reject the whole batch over one missing entry rather than
letting the per-entry code handle it. Verified live via
`plugin.manager.tool`, single-fact and batch paths both, including the
same missing-text/bad-scope per-entry cases as the drush version.

Real gap found while confirming an MCP client could actually discover
this: `mcp_server_tool_bridge`'s `McpToolConfigDeriver::
convertInputDefinitionToSchema()` maps Tool API's `list`/`map` data types
to JSON Schema's `array`/`object` type keywords only - it never recurses
into a `ListInputDefinition`'s `item_definition` or a `MapInputDefinition`'s
`property_definitions`, confirmed by inspecting `tool_api__aim_remember`'s
actual generated `inputSchema` live: `facts` comes through as `{"type":
"array"}` with no `items` schema at all. Not a bug in `aim_tool` - a real,
confirmed limitation of `mcp_server_tool_bridge` 1.0.0-beta1's schema
converter for any Tool API plugin with nested List/Map inputs, not just
this one. Worked around, not fixed: `facts`'s own description spells the
per-entry shape out in prose (`{text (required), scope (optional,
defaults to site), subject (optional), source (optional)}`), since that
description is the one thing that does reach an MCP client - a real JSON
Schema `items` key would be more reliable and isn't available yet. Worth
a drupal.org issue against `mcp_server_tool_bridge` if this becomes a
recurring problem for other nested-input tools, not filed here.

**Addendum, 2026-09-13: fixed upstream, no issue needed.** Checked before
filing the drupal.org issue floated above: `mcp_server_tool_bridge`'s
`1.x` branch had already deleted `convertInputDefinitionToSchema()`
entirely (commit `569be5d`, issue #3613896, merged 2026-09-11, as a side
effect of a broader refactor to delegate schema generation to `drupal/
tool`'s `ToolDefinitionSerializer`/`ContextDefinitionNormalizer`, which
does recurse into `getItemDefinition()`/`getPropertyDefinitions()`) - just
not yet in a tagged release. Moved this project's Composer constraint
from `1.0.0-beta1` to `1.x-dev` rather than filing a duplicate fix; see
CLAUDE.md's Enabled-modules gotcha. Confirmed live: `tool_api__aim_remember`'s
`facts` property now carries real `items.properties` for text/scope/
subject/source. Picked up a new hard dependency on core's `serialization`
module in the process (enabled). Re-pin to a real tag once
`mcp_server_tool_bridge` cuts one past beta1; the prose-shape workaround
in `facts`'s description can stay (harmless belt-and-suspenders) or be
trimmed once the dependency is stable on a tagged release.

**Unrelated discovery made while enabling `aim_tool`, fixed in passing:**
`drush en tool aim_tool` initially failed with "The module ai_agents does
not exist" - `core.extension` config had `ai_agents` recorded as
installed, but the module had no files anywhere in the codebase and no
entry in `composer.json`/`composer.lock` at all (not something this
session's `tool`/`mcp_server_tool_bridge` composer work touched). Predates
this ADR's work and would have blocked any `drush en`, not just this one -
also means `aim_chatbot`'s real dependency on `ai_agents` had been broken
on disk for some unknown period despite CLAUDE.md's "Enabled" list
claiming otherwise. Fixed by `composer require drupal/ai_agents`
(resolved cleanly to 1.3.5); CLAUDE.md's module lists should be treated as
descriptive of intent, not proof of current on-disk state, until this
kind of drift is checked for directly.
