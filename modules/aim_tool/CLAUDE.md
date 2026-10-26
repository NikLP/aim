# CLAUDE.md — AIM Tool

Tool API tools (`#[ToolMethod]` services) exposing AIM memory (any scope, permission-gated), and
via `mcp_server_tool_bridge`, MCP. Part of the [aim](../../CLAUDE.md)
project - see the root module's CLAUDE.md for environment, architecture
decisions, and the code-style/git rules shared across all of `aim`'s
submodules; this file only covers what is specific to `aim_tool`.

## Permissions

`remember aim facts` / `recall aim facts` (`aim_tool.permissions.yml`, not
`restrict access`: chat members hold them, and neither grants anything the
per-scope permissions don't), separate from the flat `administer aim`
the admin UI uses. `AimRemember` additionally checks, per entry, the
real per-scope `create {scope} aim facts` permission via
`AimMemoryManager::checkCreateAccess()` - the flat permission only gates
use of the tool at all, not which scopes it can write.

Each tool declares its flat permission (`permission:` on `#[ToolMethod]`):
Tool API checks it before any input is processed, and the MCP bridge
hides a tool from `tools/list` for a caller without it. A typo'd name
denies every
ordinary account but not admins, so test as a non-admin. `AimRemember`
declares `destructive: FALSE` (it only adds facts): since tool 1.0.0-beta12
an undeclared Write tool counts as destructive, which MCP clients read as
"confirm before every call".

## What lives here

- `src/Tool/AimRemember.php` / `AimRecall.php` (`#[ToolMethod]` services
  `aim_tool.remember`/`aim_tool.recall`; see
  [DEVELOPING.md](DEVELOPING.md)), backed by `aim.memory_manager`
  directly. The one recall and remember for chat, MCP, Drush and Tool API
  ([ADR-0062](../../adr/0062-chat-on-the-shared-tools.md)); not locked to
  any scope - the caller is a real account, so scope/subject are
  caller-supplied (same shape as `drush aim:remember`, per
  [ADR-0006](../../adr/resolved/0006-agent-native-write-path.md)).
  `AimRemember` takes one shape: `facts`, a list of one or more entries
  (`text`, `scope` required; `subject`, `expires`, `scope_fields`
  optional), plus an optional `source_text` (the words the facts came
  from) for the grounded check, one call for the whole list
  ([ADR-0037](../../adr/resolved/0037-transient-source-passages-for-grounding.md)).
  Neither `source` nor the trust channel is an input: both follow the Tool
  API invoker (`AimRemember::CALLERS`; chat gets `chat`/`chat:aim_remember`),
  handed over by `src/EventSubscriber/AimRememberInvokerSubscriber.php`
  until Tool API passes it to the method. An
  entry's `scope_fields` (ADR-0028 piece 4) gets its properties from the
  installed scopes' types (`getBaseFieldDefinitions()`) in
  `AimToolHooks::toolInfoAlter()`, and is dropped from the schema when none
  declares any; Tool API's input refiners can't reach a list entry, so the
  per-call refiner that used to type it went with the single-fact form.
- `src/Tool/AimBrief.php` (`aim_tool.brief`) - `aim_brief`, no search
  text: the newest trusted facts per installed scope the caller can view
  (own user facts, own roles' role facts), via
  `AimRecallService::recent()`, results as `AimRecall::toResult()`. Gated
  on `recall aim facts`. See [DEVELOPING.md](DEVELOPING.md#aim_brief).
- `src/Tool/AimLiteral.php` (`#[ToolMethod]` service `aim_tool.literal`) -
  `aim_literal`, a thin wrapper over
  the `literals.lookup` service with one `query` input, described as "the
  person's question as they asked it" (what the chat gate gives the
  finder). With `literals_finder` enabled every query goes to the finder,
  keys included (it resolved every bare key exactly, 2026-10-25, and keeps
  its ambiguity and gist checks: results over Jev tokens); without it a
  key-shaped query is tried as a key, then a search lists candidates. The
  caller never picks a mode, so a site without the finder advertises no
  input that would fail. Gated on `view literals` (or `view
  restricted literals`), not `recall aim facts`: literals check access per
  value, so a signed-in member can use it in chat. The lookup tool
  an aim site uses; `literals_tool`'s `literals:lookup` wraps the same
  service for sites without aim. Only the attribute (ID, descriptions) and
  the permission live here.
- MCP exposure (`mcp_server_tool_bridge.mcp_tool_config.*.yml`) ships in
  `aim_tool_oauth/config/install`, not here: `aim_tool` alone is Tool API
  only, with no MCP packages needed. Those configs carry no
  `description`: one set there overrides the plugin's in MCP.
- `src/AimScopeGuide.php` (`aim_tool.scope_guide`) - the one source of the
  "which scope when" wording, limited to installed scopes. Read by
  `src/Hook/AimToolHooks.php` (`hook_tool_info_alter`, rewriting both
  tools' `scope` descriptions; plugin caches cleared on `aim_scope`
  changes) and by `aim_tool_oauth`'s MCP server instructions. Keep
  README.md's "Which scope when" table in step with it.

`AimRecall` always drops matches past `aim.settings:recall_max_distance`
(an off-topic query returns "No relevant facts found." instead of the
nearest unrelated facts, [ADR-0019](../../adr/resolved/0019-recall-abstention-distance-cutoff.md));
there is no per-call `max_distance`, so a remote caller can't switch the
cutoff off. `limit` is capped at 25. `AimRemember`'s reply says when a
fact waits for review or has an expiry, so a caller doesn't report a held
fact as known.

`AimRecall` results carry `date_kind` (`true_since`/`recorded`) beside
`date`. An MCP client caches the output schema on connect: after an output
change, reconnect it. History: [ADR-0013](../../adr/resolved/0013-mcp-tool-exposure.md)
kept chat's tools separate; [ADR-0062](../../adr/0062-chat-on-the-shared-tools.md)
merged them.
