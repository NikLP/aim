# CLAUDE.md — AIM Site Agent

Glue between `aim_tool`'s tools and the `site_agent` chat. Part of the
[aim](../../CLAUDE.md) project - see the root module's CLAUDE.md for
environment, architecture decisions, and the code-style/git rules shared
across all of `aim`'s submodules; this file only covers what is specific
to `aim_site_agent`.

## What lives here

- `aim_site_agent.site_agent_tools.yml` - Site Agent's classification of
  each aim tool (`aim_remember` is `content_write`, the rest `read`). Site
  Agent reads every enabled module's file, so it need not sit beside the
  tools. An unclassified tool counts as a destructive config write.
- `config/optional/site_agent.toolset.memory.yml` - the Memory toolset:
  its prompt, and the reads pre-approved with `aim_remember` on
  `always`. Optional config, so a site that already has a `memory`
  toolset keeps its own.
- `src/Hook/AimSiteAgentHooks.php` +
  `templates/site-agent-card--aim-remember.html.twig` - the approval card
  of an `aim_remember` call renders with this suggestion of
  `site_agent_card`: each fact's text as a heading and one line of scope,
  subject (or the account a user fact is about), scope fields and expiry,
  with `source_text` in place of the tool description written for the
  model. See [DEVELOPING.md](DEVELOPING.md).

What stays in `aim_tool`: `AimRemember::CALLERS` maps the `site_agent`
invoker to the `site_agent:aim_remember` source by ID, with no dependency,
and its test is `aim_tool`'s `AimToolPermissionsTest`; see
[aim_tool's CLAUDE.md](../aim_tool/CLAUDE.md) for why it is not an alter
hook.
