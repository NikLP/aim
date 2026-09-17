# AIM Tool - Developer Reference

Mechanism detail and gotchas for `aim_tool`. Pitch/requirements in
[README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md), root
module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

---

## Tool API + MCP exposure

`aim_tool` ships `#[Tool]` plugins `aim_remember`/`aim_recall` wrapping
`AimMemoryManager` directly (any scope, gated on `store aim memory`/
`read aim memory`, not the admin UI's single `administer aim memory`).
`mcp_server_tool_bridge` exposes both over MCP via config-only
`mcp_tool_config` entities (`config/optional`, installs automatically
once the bridge module is enabled). `aim_remember` also takes an
optional `facts` list for saving several facts in one call/bootstrap.

Requires `drupal/mcp_server_tool_bridge:^1.0.0-beta2` +
`drupal/mcp_server:^2.0.0-beta3` together - `mcp_server_tool_bridge`'s
nested List/Map input schema support (needed for `aim_remember`'s `facts`
input) shipped in `beta2`, which itself requires `mcp_server ^2.0.0-beta3`,
one version ahead of `mcp_server`'s own `^2.0@beta` constraint. Also
requires core's `serialization` module (schema generation throws
`LogicException` without it).

`mcp_server_tool_bridge` and `mcp_server_oauth`'s Composer package names
were previously self-doubled on drupal.org (`drupal/x-x`, an empty
metapackage stub) - fixed upstream; both now resolve under their plain
names.

## MCP OAuth

A remote MCP client with no Drupal session authenticates separately - see
[aim_tool_oauth's DEVELOPING.md](../aim_tool_oauth/DEVELOPING.md).
