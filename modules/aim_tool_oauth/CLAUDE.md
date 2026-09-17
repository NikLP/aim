# CLAUDE.md — AIM Tool OAuth

OAuth2 scopes + third-party settings so a remote MCP client with no
Drupal session can authenticate to `aim_tool`. Part of the
[aim](../../CLAUDE.md) project - see the root module's CLAUDE.md for
environment, architecture decisions, and the code-style/git rules shared
across all of `aim`'s submodules; this file only covers what is specific
to `aim_tool_oauth`.

## What lives here

- `config/install/simple_oauth.oauth2_scope.aim_remember.yml` /
  `aim_recall.yml` - two `oauth2_scope` entities with
  `granularity_id: permission`, pointed at `aim_tool`'s
  `store aim memory`/`read aim memory` permissions. A scope with no
  granularity crashes `Oauth2ScopeProvider::getPermissions()` the moment
  a real client completes the flow.
- `aim_tool_oauth.install` - `hook_install()`/`hook_uninstall()` set/unset
  `mcp_server_oauth` third-party settings (`authentication_mode:
  required`, `scopes`) on `aim_tool`'s own `mcp_tool_config` entities.
  This mutates config `aim_tool` owns because `mcp_server_oauth` gates
  through third-party settings, not a config file this module could ship
  without a filename collision - see the install file's own docblock.
  Both `oauth2_scope` entities ship `dependencies.enforced.module:
  [aim_tool_oauth]`, so `ConfigManager::uninstall()` deletes them on its
  own; no explicit deletion needed in `hook_uninstall()`.

## Root `composer.json` workaround

`mcp_server_oauth`'s own `composer.json` requires
`drupal/simple_oauth_client_registration` and
`drupal/simple_oauth_server_metadata` as if they were separate
drupal.org packages - they're Drupal module names bundled *inside*
`e0ipso/simple_oauth_21`, not separate Composer packages. The site's root
`composer.json` (not this module's) carries a `"provide"` block naming
both - see [DEVELOPING.md](DEVELOPING.md) for the exact block. This
module's own dependency declaration just names `mcp_server_oauth` plain.
