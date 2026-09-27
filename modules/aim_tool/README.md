# AIM Tool

Exposes AIM memory as Tool API plugins (`aim_remember`/`aim_recall`), and
through them, over MCP via `mcp_server_tool_bridge`. Any scope, gated by
permission rather than hardcoded like `aim_chatbot`. Part of
[AIM](../../README.md).

Over MCP the tools are listed as `tool_api__aim_remember` and
`tool_api__aim_recall` (the bridge prefixes the Tool API plugin ID; observed
with `mcp_server_tool_bridge` 1.0.0-beta3). `aim_recall` drops facts past
`aim.settings:recall_max_distance` by default and takes an optional
`max_distance` (2 disables the cutoff).

## Requirements

- `aim` (this module's parent)
- `drupal/tool` (`>=1.0.0-beta8`)
- Optional, for MCP exposure: `drupal/mcp_server_tool_bridge:^1.0.0-beta2`
  + `drupal/mcp_server:^2.0.0-beta3` + core's `serialization` module - see
  [DEVELOPING.md](DEVELOPING.md) for the version-constraint reasoning.

## Getting started

1. Enable `aim` and `aim_tool`.
2. Grant `store aim memory`/`read aim memory` to the roles that should be
   able to call these tools (separate from `administer aim memory`, the
   admin UI's blanket permission).
3. For MCP exposure, also enable `mcp_server_tool_bridge` - its config
   entities for `aim_remember`/`aim_recall` install automatically.
4. For a remote MCP client with no Drupal session, add
   [aim_tool_oauth](../aim_tool_oauth/README.md).

Full detail: [DEVELOPING.md](DEVELOPING.md). Decision record:
[ADR-0013](../../adr/0013-mcp-tool-exposure.md).
