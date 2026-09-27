# CLAUDE.md — AIM Tool

Tool API plugins exposing AIM memory (any scope, permission-gated), and
via `mcp_server_tool_bridge`, MCP. Part of the [aim](../../CLAUDE.md)
project - see the root module's CLAUDE.md for environment, architecture
decisions, and the code-style/git rules shared across all of `aim`'s
submodules; this file only covers what is specific to `aim_tool`.

## Permissions

`store aim memory` / `read aim memory` (`aim_tool.permissions.yml`, both
`restrict access: true`), separate from the flat `administer aim memory`
the admin UI uses. `AimRemember`'s tool plugin additionally checks the
real per-scope `create {scope} aim facts` permission via
`AimMemoryManager::checkCreateAccess()` - the flat permission only gates
use of the tool at all, not which scopes it can write.

## What lives here

- `src/Plugin/tool/Tool/AimRemember.php` / `AimRecall.php` - the two Tool
  API plugins, backed by `aim.memory_manager` directly. Not locked to
  `scope: site` like `aim_chatbot`'s equivalents - a Tool API caller is a
  real, authenticated account, so scope/subject are caller-supplied
  (same shape as `drush aim:remember`, per
  [ADR-0006](../../adr/0006-agent-native-write-path.md)).
- `config/optional/mcp_server_tool_bridge.mcp_tool_config.*.yml` - MCP
  exposure config, installs automatically once `mcp_server_tool_bridge`
  is enabled.

`AimRecall` drops matches past `aim.settings:recall_max_distance` by
default (an off-topic query returns "No relevant facts found." instead of
the nearest unrelated facts), and takes an optional `max_distance` input
to override it ([ADR-0019](../../adr/0019-recall-abstention-distance-cutoff.md)).

See [ADR-0013](../../adr/0013-mcp-tool-exposure.md) for why this is a
separate plugin pair from `aim_chatbot`'s rather than a shared one.
