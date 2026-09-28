# AIM Tool OAuth - Developer Reference

Full setup runbook and gotchas for `aim_tool_oauth`. Pitch/requirements
in [README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md),
root module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

---

## Mechanism

Closes the gap `aim_tool`/`mcp_server_tool_bridge` leave open: a remote
MCP client with no Drupal session (Claude.ai/Claude Desktop connector, or
any headless caller) authenticates via OAuth2 instead of a logged-in
browser session. Existing cookie/session access is untouched - `oauth2`
is appended to `mcp_server.handle`'s `_auth`, not swapped in. Full detail
in [ADR-0013](../../adr/resolved/0013-mcp-tool-exposure.md)'s OAuth addendum.

**Dependency chain:** `drupal/simple_oauth` (OAuth2 authorization server)
+ `e0ipso/simple_oauth_21` (OAuth 2.1: PKCE, RFC 9728 discovery metadata,
dynamic client registration - **Packagist-only under `e0ipso/`, not a
drupal.org project**; `drupal/simple_oauth_21` does not exist) +
`drupal/mcp_server_oauth` (`^1.0@alpha`).

**Composer gotcha:** `mcp_server_oauth`'s own `composer.json` requires
`drupal/simple_oauth_client_registration` and
`drupal/simple_oauth_server_metadata` as if they were separate
drupal.org packages - they're Drupal module names bundled *inside*
`e0ipso/simple_oauth_21`, not separate Composer packages at all. A plain
`composer require drupal/mcp_server_oauth` fails until the site's own
`composer.json` adds a `"provide"` block naming both (version matching
whatever `e0ipso/simple_oauth_21` resolves to):

```json
"provide": {
    "drupal/simple_oauth_client_registration": "1.13.0",
    "drupal/simple_oauth_server_metadata": "1.13.0"
}
```

Root `composer.json` only - a site-level workaround for an upstream bug,
not something `aim`'s own `composer.json` should carry (it just
`suggest`s `mcp_server_oauth` plain).

## Setup

1. Enable the OAuth stack (`simple_oauth`, `simple_oauth_21`,
   `simple_oauth_server_metadata`, `simple_oauth_client_registration`,
   `simple_oauth_pkce`, `consumers`, `mcp_server_oauth`), then
   `aim_tool_oauth`.
2. Generate a key pair outside the docroot:
   `vendor/bin/drush simple-oauth:generate-keys <path>` (this site uses
   `keys/` at repo root, sibling to `web/`, already gitignored).
   `simple_oauth.settings`'s `public_key`/`private_key` must point at it.
3. Confirm `aim_tool_oauth`'s shipped config took: two `oauth2_scope`
   entities (`aim:remember`, `aim:recall`) with `granularity_id:
   permission` set, pointed at `store aim memory`/`read aim memory`. A
   scope with no granularity crashes `Oauth2ScopeProvider::
   getPermissions()` the moment a real client completes the flow -
   surfaces client-side as a generic "couldn't connect", not a
   scope/permission error. Check `drush watchdog:show` for
   `AssertionError: assert($granularity instanceof
   ScopeGranularityInterface)` if this regresses.
4. Expose the site over real HTTPS to the client - DDEV's local hostname
   and self-signed cert can't be reached/trusted by a cloud-hosted
   connector. This site uses Tailscale Funnel: `tailscale funnel --bg
   https+insecure://127.0.0.1:443` (must target the router's **HTTPS**
   entrypoint - the HTTP one produces `http://` discovery URLs even
   though Funnel terminated real TLS on the public side, since Traefik
   sets `X-Forwarded-Proto` per-entrypoint) plus `.ddev/config.yaml`'s
   `additional_fqdns` set to the Funnel hostname. `ddev-router`'s port 80
   and 443 are shared global infrastructure, not per-project - don't
   point Funnel at DDEV's per-project ephemeral direct-access port
   (`ddev describe`'s `web:80 -> 127.0.0.1:NNNNN`, reassigned on every
   restart), and don't set `router_http_port` expecting it to pin a
   per-project value.
5. Register and connect a real client: confirm
   `/.well-known/oauth-protected-resource` and
   `/.well-known/oauth-authorization-server` both resolve, every endpoint
   (including `registration_endpoint`) comes back `https://`, and a real
   `POST /oauth/register` returns a genuine `client_id`.

## Gotchas

- The OAuth admin form's "Required scopes" selector only offers scopes an
  already-enabled `mcp_tool_config` carries - empty with no free-text
  fallback on a fresh site. `aim_tool_oauth_install()` seeds this in code.
- Enabling a module whose `config/install` matches an existing config
  name throws `PreExistingConfigException` - don't hand-create
  `oauth2_scope` entities to inspect their shape before enabling
  `aim_tool_oauth`.
- The Funnel target is `tailscaled` process/session state, not
  `.ddev/config.yaml` - a host reboot or `tailscale` service restart
  drops it entirely. Check `tailscale funnel status` before
  re-diagnosing the OAuth chain if a connector registration fails.
