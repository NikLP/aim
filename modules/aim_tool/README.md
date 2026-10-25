# AIM Tool

Exposes AIM memory as Tool API plugins (`aim_remember`/`aim_recall`), and
through them, over MCP via `mcp_server_tool_bridge`. Any scope, gated by
permission rather than hardcoded like `aim_chatbot`. Part of
[AIM](../../README.md).

Over MCP the tools are listed as `tool_api__aim_remember` and
`tool_api__aim_recall` (the bridge prefixes the Tool API plugin ID; observed
with `mcp_server_tool_bridge` 1.0.0-beta3). `aim_recall` drops facts past
`aim.settings:recall_max_distance` (not settable per call, so a remote
model can't turn the cutoff off) and returns at most 25 facts (`limit`,
default 10). `aim_remember`'s reply says when a fact waits for review or
has an end date.

## Which scope when

Every fact has a scope, which says who it is true for. The same wording
goes to the model, in the tools' scope inputs and in the MCP server
instructions, listing only the scopes installed on the site.

| Scope | Use it for | Subject |
| --- | --- | --- |
| `site` | Something true about the organization for everyone who asks: opening hours, a fees policy | None |
| `role` | Guidance for everyone holding one site role: "editors must add alt text to every image" | The role machine name, e.g. `content_editor` |
| `user` | Something about one person, by default the one talking: how they like answers formatted | Their account (uid). Never about someone else without confirming who |
| `case` | One piece of ongoing work with a start and an end: a site redesign | The case ID. Leave it empty on the first save and a new one comes back; reuse it to add more |
| `entity` (if installed) | One specific piece of content: a page, a product | None; the content's type and ID go in `scope_fields` |

When no scope clearly fits, or the role or case is unclear, the model is
told to ask before saving. Recall searches every scope the caller can see
unless given one, and finds role and case facts by topic, without the role
name or case ID.

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
