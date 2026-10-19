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
- `drupal/tool` (`>=1.0.0-beta10`)
- Nothing else. MCP exposure is opt-in via
  [aim_tool_oauth](../aim_tool_oauth/README.md), which carries the MCP
  packages (see its Requirements).

## Getting started

1. Enable `aim` and `aim_tool`.
2. Grant `remember aim facts`/`recall aim facts` to the roles that should be
   able to call these tools (separate from `administer aim`, the
   admin UI's blanket permission).
3. For MCP (remote agents such as the Claude.ai connector), install
   [aim_tool_oauth](../aim_tool_oauth/README.md): it needs the MCP and
   OAuth packages first, then `drush en aim_tool_oauth`.

Full detail: [DEVELOPING.md](DEVELOPING.md). Decision record:
[ADR-0013](../../adr/resolved/0013-mcp-tool-exposure.md).
