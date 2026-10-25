# AIM Tool OAuth

Requires an OAuth2 access token with `aim`-specific scopes for remote MCP
callers of [aim_tool](../aim_tool/README.md)'s `aim_remember`/
`aim_recall`. Closes the gap `aim_tool`/`mcp_server_tool_bridge` leave
open for a client with no Drupal session (Claude.ai/Claude Desktop
connector, or any headless caller). Part of [AIM](../../README.md).

Existing cookie/session access to `aim_tool` is untouched - `oauth2` is
appended to `mcp_server.handle`'s `_auth`, not swapped in.

## Requirements

- `aim_tool`
- `drupal/mcp_server_tool_bridge:^1.0.0-beta2` + `drupal/mcp_server:^2.0.0-beta3`
  + core's `serialization`. Composer cannot require these per submodule, so
  they are only `suggest`ed by the `aim` package: run
  `composer require drupal/mcp_server_tool_bridge drupal/mcp_server` (plus the
  OAuth packages below) **before** `drush en aim_tool_oauth`. This module
  ships the `mcp_tool_config` entities that expose `aim_recall`,
  `aim_remember` and `aim_literal` over MCP, so MCP is on exactly when it
  is enabled. It also adds aim's usage notes and scope guide to the MCP
  server instructions a client receives on connecting (after any text the
  site sets in `mcp_server.settings`). See [aim_tool's DEVELOPING.md](../aim_tool/DEVELOPING.md) for
  the version-constraint reasoning.
- `drupal/simple_oauth` + `e0ipso/simple_oauth_21` (OAuth 2.1: PKCE, RFC
  9728 discovery metadata, dynamic client registration - **Packagist-only
  under `e0ipso/`, not a drupal.org project**) + `drupal/mcp_server_oauth`
  (`^1.0@alpha`)
- A key pair for `simple_oauth` (`simple-oauth:generate-keys`)
- Real HTTPS reachable by the client (a cloud connector can't reach a
  local DDEV hostname/self-signed cert)

## Getting started

Full setup runbook (dependency chain, composer gotcha, key generation,
HTTPS exposure, client registration) is in
[DEVELOPING.md](DEVELOPING.md#setup) - follow it in order, it's fiddly.

Non-admin users need a role-granularity scope (one per role, added by the
site) alongside `aim:recall`/`aim:remember`, and that role needs `access mcp server` and `grant simple_oauth codes`; see
[DEVELOPING.md](DEVELOPING.md#role-scopes-for-non-admin-users).

Decision record: [ADR-0013](../../adr/resolved/0013-mcp-tool-exposure.md)'s OAuth
addendum.
