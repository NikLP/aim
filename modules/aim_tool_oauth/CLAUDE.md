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
  `remember aim facts`/`recall aim facts` permissions. A scope with no
  granularity crashes `Oauth2ScopeProvider::getPermissions()` the moment
  a real client completes the flow.
- **No role scope ships here, by design.** The two shipped scopes are
  permission-granularity, so a non-admin token lacks `access mcp server`,
  `create {scope} aim facts` and the user's roles (the user-scope matrix
  reads them). Sites add one role-granularity scope per role; clients
  request it together with the aim scope. The role also needs
  `grant simple_oauth codes`, or the consent screen fails. Retest scripts
  live in `scripts/`. See
  [DEVELOPING.md](DEVELOPING.md#role-scopes-for-non-admin-users).
- `aim_tool_oauth.install` - `hook_install()`/`hook_uninstall()` set/unset
  `mcp_server_oauth` third-party settings (`authentication_mode:
  required`, `scopes`) on `aim_tool`'s own `mcp_tool_config` entities.
  This mutates config `aim_tool` owns because `mcp_server_oauth` gates
  through third-party settings, not a config file this module could ship
  without a filename collision - see the install file's own docblock.
  Both `oauth2_scope` entities ship `dependencies.enforced.module:
  [aim_tool_oauth]`, so `ConfigManager::uninstall()` deletes them on its
  own; no explicit deletion needed in `hook_uninstall()`.
- `src/Hook/AimToolOauthHooks.php` - `hook_mcp_server_instructions_alter`
  appends aim's usage notes and `aim_tool`'s `AimScopeGuide` to the MCP
  server instructions, after any site-set
  `mcp_server.settings:server_instructions`. A hook, not shipped config:
  that setting is `mcp_server`'s and per site, so shipping it would
  collide, and a rebuild lost it when it lived only in the database. The
  demo pre-flight checks it arrives at the handshake.

## Root `composer.json` workaround

`mcp_server_oauth`'s own `composer.json` requires
`drupal/simple_oauth_client_registration` and
`drupal/simple_oauth_server_metadata` as if they were separate
drupal.org packages - they're Drupal module names bundled *inside*
`e0ipso/simple_oauth_21`, not separate Composer packages. The site's root
`composer.json` (not this module's) carries a `"provide"` block naming
both - see [DEVELOPING.md](DEVELOPING.md) for the exact block. This
module's own dependency declaration just names `mcp_server_oauth` plain.
