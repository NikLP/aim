# AIM Tool - Developer Reference

Mechanism detail and gotchas for `aim_tool`. Pitch/requirements in
[README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md), root
module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

---

## Tool API + MCP exposure

`aim_tool` ships `#[Tool]` plugins `aim_remember`/`aim_recall` wrapping
`AimMemoryManager` directly (any scope, gated on `remember aim facts`/
`recall aim facts`, not the admin UI's single `administer aim`).
`mcp_server_tool_bridge` exposes both over MCP via config-only
`mcp_tool_config` entities, shipped by `aim_tool_oauth` (`config/install`),
so MCP is opt-in: enable that module to turn it on. `aim_remember` also takes an
optional `facts` list for saving several facts in one call/bootstrap, and an
optional `expires` (`YYYY-MM-DD`, also per `facts` entry): the fact is
retired after that day by `drush aim:expire` or `hook_cron` (ADR-0057).

Requires `drupal/mcp_server_tool_bridge:^1.0.0-beta2` +
`drupal/mcp_server:^2.0.0-beta3` together - `mcp_server_tool_bridge`'s
nested List/Map input schema support (needed for `aim_remember`'s `facts`
input) shipped in `beta2`, which itself requires `mcp_server ^2.0.0-beta3`,
one version ahead of `mcp_server`'s own `^2.0@beta` constraint. Also
requires core's `serialization` module (schema generation throws
`LogicException` without it).

Over MCP the Tool API plugin IDs are listed with a `tool_api__` prefix
(`tool_api__aim_recall`, `tool_api__aim_remember`; observed 2026-09-27 with
`mcp_server_tool_bridge` 1.0.0-beta3, `mcp_server` 2.0.0-beta5, `mcp/sdk`
0.8.1), so a client that cached the old bare names needs to refresh its
tool list. `aim_recall` always applies the site's `recall_max_distance`
(no per-call `max_distance` input, removed 2026-10-25: a caller passing 2
turned the cutoff off; `drush aim:recall --max-distance` stays for
tuning). `limit` is capped at `AimRecall::MAX_LIMIT` (25) by a `Range`
constraint, which the MCP schema advertises as `minimum`/`maximum` and the
server enforces, and clamped in code too. An empty result reads "No
relevant facts found."

**Descriptions have one source each.** The scope inputs' descriptions are
rewritten at discovery by `AimToolHooks::toolInfoAlter()` from
`AimScopeGuide` (`aim_tool.scope_guide`): recall gets a one-line list,
remember the full guide, both limited to the installed scopes, site first.
`AimToolHooks::scopeChanged()` clears the plugin caches on any
`aim_scope` insert, update or delete, so a scope added from the admin form
appears without a rebuild. The MCP one-liner per tool is the plugin's own
description: the `mcp_tool_config` entities ship no `description`, which
would override it ([McpToolConfigDeriver](../../../../contrib/mcp_server_tool_bridge/src/Plugin/Derivative/McpToolConfigDeriver.php)
falls back to the tool definition only when it is empty). The MCP server
instructions come from `aim_tool_oauth`, reading the same guide.

`aim_remember`'s reply adds "Waiting for staff review: recall will not
return it until it is approved." for an untrusted fact and "It retires
after YYYY-MM-DD." when `expires` is set, per fact in a batch too (the same
idea as `aim_chatbot`'s remember reply).

`mcp_server_tool_bridge` and `mcp_server_oauth`'s Composer package names
were previously self-doubled on drupal.org (`drupal/x-x`, an empty
metapackage stub) - fixed upstream; both now resolve under their plain
names.

## MCP OAuth

A remote MCP client with no Drupal session authenticates separately - see
[aim_tool_oauth's DEVELOPING.md](../aim_tool_oauth/DEVELOPING.md).
