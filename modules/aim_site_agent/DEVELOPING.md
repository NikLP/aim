# AIM Site Agent - Developer Reference

Mechanism detail and gotchas for `aim_site_agent`. Pitch/requirements in
[README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md), root
module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

## The `aim_remember` approval card

Site Agent renders cards on the server (`site_agent_card`, built by
`CardBuilder` from the stored call), so the usual theme layer applies.
`AimSiteAgentHooks` adds the suggestion `site_agent_card__aim_remember`
for `tool_id` `aim_remember`, registers it in `hook_theme()` with
`base hook: site_agent_card` (so site_agent's own preprocess still runs),
and fills `facts` and `description` in `hook_preprocess_site_agent_card()`
from the record's arguments.

- **Keep the template's hooks for `chat.js`.** Approve and Reject work
  through `data-site-agent-card`, `data-record`, `data-hash`,
  `data-approve-url`, `data-reject-url`, `data-site-agent-card-actions`,
  `data-site-agent-decision` and `data-site-agent-reason`. Copy them from
  site_agent's `site-agent-card.html.twig` when it changes.
- **Looks only.** The decision is bound to the payload hash and the runner
  runs the stored payload, so the card cannot approve some facts and not
  others. Per-fact approval would need site_agent to re-hash an edited
  payload: an upstream feature, not something a template can do.
- **Rendered in the front-end theme.** The card comes back from
  `/site-agent/threads/{id}/turn`, not an admin route; a module template
  sidesteps which theme that is.
- The same template draws a decided card in a reopened chat ("Approved."
  or "Rejected." in place of the buttons).

Tests: `tests/src/Unit/AimSiteAgentCardTest.php` (suggestion, the facts
and their detail line, other cards left alone).
