# AIM: personal memory

One person's long-term memory on a site of its own, reached from Claude or
another MCP client over OAuth. There is no chat widget and no public
registration. Why it is a recipe on its own site:
[ADR-0066](../../adr/0066-one-instance-one-recipe.md).

## What it applies

- The modules for [AIM](../../README.md)'s tools over MCP and OAuth:
  `aim_tool`, `aim_tool_oauth` and the `mcp_server` and `simple_oauth`
  stacks.
- `server_instructions`: a statement of whose memory this is, read by every
  client when it connects, using the `owner_name` input.
- Only the `user` and `case` scopes stay on, with the user scope's
  description rewritten for one owner. `site`, `role` and `entity` are
  disabled.
- `user.settings:register` set to `admin_only`, as the site is public.
- Each tool's OAuth scope. `aim_tool_oauth_install()` normally sets these,
  but in a recipe it runs before the tool configs are imported and finds
  none.
- `index_directly` on and `recall_max_distance` 0.6, as in
  `aim_demo_library`. The cutoff is for `nomic-embed-text`, and has not been
  tuned on personal data.

It grants no permissions and creates no role, because the owner signs in as
uid 1 (see "Be upfront about").

## Requirements

- A fresh Drupal 11 site on MariaDB 11.7 or newer, separate from any other
  AIM instance. A recipe sets up an instance but doesn't separate its data.
- `ai_vdb_provider_mariadb`, `ai_search` and `search_api` **not** enabled
  yet, as for [`aim_demo_library`](../aim_demo_library/README.md).
- Composer packages for the OAuth stack, including the site-level `provide`
  block in `aim_tool_oauth`'s
  [DEVELOPING.md](../../modules/aim_tool_oauth/DEVELOPING.md).
- An embeddings provider. Facts can't be saved without one while
  `index_directly` is on.

## Apply

From the site root, adjusting `custom` to wherever the module lives:

```bash
ddev drush recipe /var/www/html/web/modules/custom/aim/recipes/aim_personal --input=aim_personal.owner_name="Your Name"
```

Then the parts a recipe can't do, which are site-specific:

1. Set the default embeddings model at `/admin/config/ai/settings`, and the
   Chat default if consolidation will run.
2. Generate OAuth keys outside the docroot and point
   `simple_oauth.settings` at them.
3. Expose the site over real HTTPS and confirm the discovery URLs. Steps 2
   and 3 are in `aim_tool_oauth`'s
   [DEVELOPING.md](../../modules/aim_tool_oauth/DEVELOPING.md) "Setup".
4. Add the site's `/mcp` URL as a connector in your MCP client, and sign in
   as uid 1 when it asks.

## Be upfront about

- **A token uid 1 authorizes keeps every permission.** `simple_oauth` lets
  uid 1 skip the narrowing a token's scopes normally apply. That is what
  makes this recipe simple, and it also means a leaked token is a
  super-user token. An account with its own role and a role-granularity
  scope would be narrower, but needs testing against a real connector,
  which may not request that scope.
- **Extraction still knows five scopes.** `extraction_prompt` is unchanged,
  so `aim:extract` and the Ingest form can propose `site` or `role`, which
  are off here. Not tested; the MCP tools only offer the enabled scopes.

## What has been tested

`AimPersonalRecipeTest` applies the recipe to a fresh test site and checks
the settings above and that a caller with no token gets a 401 challenge.
No model call, real OAuth sign-in or connector has been run against it.
