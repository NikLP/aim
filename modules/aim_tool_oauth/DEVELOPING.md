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
   `aim_tool_oauth`:

   ```bash
   drush en tool mcp_server mcp_server_tool_bridge consumers simple_oauth simple_oauth_21 simple_oauth_server_metadata simple_oauth_client_registration simple_oauth_pkce mcp_server_oauth aim_tool_oauth -y
   ```

2. Generate a key pair outside the docroot:
   `vendor/bin/drush simple-oauth:generate-keys <path>` (this site uses
   `keys/` at repo root, sibling to `web/`, already gitignored; the keys
   survive a site reinstall, so after one only the config needs setting).
   `simple_oauth.settings`'s `public_key`/`private_key` must point at it:
   `drush cset simple_oauth.settings public_key <path>/public.key -y`, and
   the same for `private_key`.
3. Clear `registration_endpoint`:
   `drush cset simple_oauth_server_metadata.settings registration_endpoint '' -y`.
   `simple_oauth_server_metadata`'s install hook stores it as an absolute
   URL built from the host it ran on, so enabling it from Drush stores
   `https://<ddev host>/oauth/register` and discovery hands remote clients
   an unreachable registration URL. Empty means the route is found from
   each request's host (hit 2026-10-23 on a fresh install).
4. Confirm `aim_tool_oauth`'s shipped config took: two `oauth2_scope`
   entities (`aim:remember`, `aim:recall`) with `granularity_id:
   permission` set, pointed at `remember aim facts`/`recall aim facts`. A
   scope with no granularity crashes `Oauth2ScopeProvider::
   getPermissions()` the moment a real client completes the flow -
   surfaces client-side as a generic "couldn't connect", not a
   scope/permission error. Check `drush watchdog:show` for
   `AssertionError: assert($granularity instanceof
   ScopeGranularityInterface)` if this regresses.
5. Expose the site over real HTTPS to the client - DDEV's local hostname
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
6. Register and connect a real client: confirm
   `/.well-known/oauth-protected-resource` and
   `/.well-known/oauth-authorization-server` both resolve, every endpoint
   (including `registration_endpoint`) comes back `https://`, and a real
   `POST /oauth/register` returns a genuine `client_id`.

## Role scopes for non-admin users

`aim:recall` and `aim:remember` are permission-granularity: each puts one
permission on the token and carries no role. Admins hide the consequences
because they hold every permission. A non-admin MCP user gets:

- **Connect fails** (403 on initialize): no aim scope carries
  `access mcp server`.
- **`aim_remember` is refused** ("No permission to create {scope}-scope
  facts"): the token lacks `create {scope} aim facts`.
- **`aim_recall` returns nothing for `scope=user`**: the role-visibility
  matrix (`AimScopeUser`) reads `$account->getRoles()`, and the token's
  roles are only what its scopes carry.
- **A reviewer's writes still follow the `mcp` trust rule**: a writer
  with a trust permission starts facts trusted (ADR-0037's 2026-10-22
  addendum), but the token lacks it. A role-granularity scope (below)
  carrying a reviewer role fixes that too.

Fix: add a **role-granularity** scope per role that should use aim over
MCP, at `/admin/config/people/simple_oauth/oauth2_scope`:
`granularity_id: role`, `granularity_configuration: {role: <role id>}`,
grant types `authorization_code` and `refresh_token`. The token then
carries that real role, so its permissions apply and the matrix works.

The role itself must hold `access mcp server`, `recall aim facts`/
`remember aim facts`, `create {scope} aim facts` for the scopes it writes,
and **`grant simple_oauth codes`**: without the last, the consent screen
fails with "The 'grant simple_oauth codes' permission is required" and no
code is issued. A user outside the role and lacking those permissions
cannot connect (403 on initialize) even if the consent step passes.

Verified live 2026-10-15 (OAuth 2.1 PKCE script, throwaway roles/users/
scopes, all deleted afterwards): a staff user connected, recalled own and a
peer's user-scope facts (shared-role fallback) and remembered; a manager
saw staff facts through the matrix (and none with the matrix emptied); a
role scope without `aim:recall` was refused (-32603); aim scopes alone
could not connect (403); a user with no role permissions got a token but
403 on connect.

- **No role scope ships with the module.** It ships no roles and role ids
  are site-specific. The `aim_demo_library` recipe defines no custom roles
  either.
- **Clients request two scopes**: the role scope plus the matching aim
  scope (`aim:recall` or `aim:remember`). Tools still require their aim
  scope, so a role scope alone is rejected, and the permissions add up.
- **Scopes narrow, never escalate**: a token's effective permissions are
  the scope's permissions AND the user's own (tested live 2026-10-15: a
  permission scope for `access mcp server` did not connect a user whose
  roles lack it). Hence a role needs the permissions itself, and the role
  scope is what carries them onto the token.
- **Breadth**: a role scope grants everything the role can do. Exposure is
  bounded to the MCP tools exposed.
- **Rejected alternative**: having `AimScopeUser` look up the real user's
  roles instead of the token's. Scopes would then stop limiting role
  visibility.
- A blocked call reaches the client as JSON-RPC -32603 "Internal server
  error" (logged `insufficient_scope`), not a 403.
- Per-tool scope enforcement needs `mcp_server_oauth` with upstream fix
  #3604047 (this site pins `1.x-dev#fb92204`; swap for the first release
  containing it).

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
