# AIM: staff console

[Site Agent](https://www.drupal.org/project/site_agent)'s chat as the staff
console for an [AIM](../../README.md) site's memory, through
[aim_site_agent](../../modules/aim_site_agent/README.md). A layer: apply it
on top of an instance recipe ([`aim_demo_library`](../aim_demo_library/README.md)
or [`aim_personal`](../aim_personal/README.md)), not on its own.

## What it applies

- `aim_site_agent`, and with it `site_agent` and its dependencies (`node`,
  `media`, `tool_belt_content`).
- The Memory toolset (recall and brief run without asking, every save
  waits for approval on a card listing its facts).
- Site Agent's own Editor and Site builder toolsets, disabled: the chat is
  for memory only.
- Site Agent settings: named `Memory`, does not open after login, no
  greeting, its tool-call audit log kept 3 days.

The model is left on Site Agent's default, which is the site's Chat default
at `/admin/config/ai/settings`. It grants no permissions: an administrator
has them all; any other staff role needs "Use the Memory toolset" (`use
memory toolset`) and the aim permissions it should have (recall, remember,
the scopes' view and create, and a scope's trust permission if its saves
should start trusted, ADR-0065).

## Requirements

- An AIM instance already applied, with its AI defaults set.
- `composer require drupal/site_agent` (aim's composer.json only suggests
  it).
- The Site Agent patch in this site's `composer.json` (`patches/site_agent-person-approved-invoker.patch`,
  until upstream ships it): without it, a save approved on the card still
  runs the grounded check
  ([ADR-0067](../../adr/0067-person-approved-calls-skip-grounding.md)). A
  recipe can't add a patch.

## Apply

From the site root, adjusting `custom` to wherever the module lives:

```bash
ddev drush recipe /var/www/html/web/modules/custom/aim/recipes/aim_console
```

Not strict, so it applies over a Site Agent that is already set up: config
that exists is kept, and the settings and toolset actions above apply
anyway.
