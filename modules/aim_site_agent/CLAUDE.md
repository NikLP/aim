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
  its prompt, and the reads (`aim_brief`, `aim_recall`) pre-approved with
  `aim_remember` on `always`. No `aim_literal`: recall returns exact
  values itself, and Site Agent's model never chose the second tool
  ([ADR-0061](../../adr/0061-how-aim-uses-literals.md)'s addendum; the
  tool keeps its classification for a site that adds it). Optional
  config, so a site that already has a `memory` toolset keeps its own: a
  changed prompt or entry list reaches an existing site only by hand
  (`drush cset` or the toolset form).
- `src/Hook/AimSiteAgentHooks.php` +
  `templates/site-agent-card--aim-remember.html.twig` - the approval card
  of an `aim_remember` call renders with this suggestion of
  `site_agent_card`: each fact's text as a heading and one line of scope,
  subject (or the account a user fact is about), scope fields and expiry
  (flagged when already past or not a date, read by `aim.remember` as the
  save will read it), with `source_text` in place of the tool description written for the
  model. See [DEVELOPING.md](DEVELOPING.md).

What stays in `aim_tool`: `AimRemember::CALLERS` maps the `site_agent`
invoker to the `site_agent:aim_remember` source by ID, with no dependency,
and its test is `aim_tool`'s `AimToolPermissionsTest`; and
`AimRemember::PERSON_APPROVED` skips the grounded check for a call a person
approved on the card ([ADR-0067](../../adr/0067-person-approved-calls-skip-grounding.md),
needs this site's Site Agent patch until upstream ships it); see
[aim_tool's CLAUDE.md](../aim_tool/CLAUDE.md) for why it is not an alter
hook.
