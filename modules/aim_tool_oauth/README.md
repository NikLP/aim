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

Decision record: [ADR-0013](../../adr/0013-mcp-tool-exposure.md)'s OAuth
addendum.
