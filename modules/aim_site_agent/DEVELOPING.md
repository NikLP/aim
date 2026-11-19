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

## The `aim_remember` result card

`aim_remember`'s classification names no `changes` (Site Agent's
`changes` needs an `entity_type` input, which the tool has none of), so
Site Agent's `EntityWriteCollector` records every content entity saved
while the call runs. That includes the `ai_log` rows `ai_logging` writes
for the grounding and time checks (`ai_log` is not an internal entity
type), and the items card calls an entity with no published state
"Published". `hook_preprocess_site_agent_items_result_card()` fixes both
for an `aim_remember` record: only `aim_fact` items stay, each with status
"Trusted" or "Waiting for review", the heading counts facts, and the
draft rows, versions and Publish are dropped (a fact has nothing to
publish). The View links (a fact's page is its edit form) stay only for
an account with `administer aim`. Site Agent has no hook for either yet;
upstream ask in TODO.md.

Tests: `tests/src/Unit/AimSiteAgentCardTest.php` (suggestion, the facts
and their detail line, other cards left alone, the result card).

## The memory section of the system prompt

`MemorySection` (tagged `site_agent.prompt_section`) adds `## Memory:
live values` (`MemorySection::HEADING`), a list of values that can't be
written once: today, `- Today: Wednesday 2026-11-18 (site timezone)`, from
`AimRememberService::today()`, the date saves check `expires` and the
time check against. **Values only, no guidance**: how to use them is in
the toolset prompt, which names the section by its heading, so a site
rewords it there. A new live value is one more list line here; keep the
heading stable, or update the toolset prompt with it.

Site Agent builds the prompt before every model call, so the values are
never stale in its own chat, unlike `aim_brief`'s "Today is" line, which
arrives only if the model calls it and then stays in the history (the
toolset prompt says an earlier date may be from a previous day). A tool
definition can't carry them: Tool API caches definitions.

- **Only with aim's tools.** The section is empty unless a toolset in the
  thread holds a tool whose definition's provider is `aim_tool`, so a
  thread with no memory tools gets nothing.
- **Remote clients get it too**, in their instructions, read once when
  they connect: a session that outlives midnight keeps the old date
  until it reconnects (a past `expires` is still refused on save, naming
  today). MCP has no message to resend instructions.
- **Date only, no time of day**, so the prompt changes once a day.
- **Nothing about the person** (roles, where their saves start trusted):
  each call would send it to the model; the brief is the place, if any.

Tests: `tests/src/Unit/MemorySectionTest.php`.
