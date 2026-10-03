# ADR-0040: Memory-driven site building: a discovery interview that emits a portable Webform

**Status:** Proposed 2026-10-03 - speculative, design only, not built.
Explicitly a gimmick-grade exploration of "a site built from its own
memory", recorded so the reasoning isn't redone. Not on the critical path.
**Date:** 2026-10-03

## Context

CLAUDE.md decision 8 puts the generative/planning surface (spec -> Recipe
-> built site) in scope, and the `aim-discovery` skill ("the grill")
already interviews a site owner and writes the answers into site memory.
This ADR asks the smaller, concrete version of that idea: can the same
interview style produce a *buildable artifact* - here a Drupal Webform -
rather than only facts?

A chat with Claude (2026-10-03, with web search; claims below are from that
search and unverified by us) found:

- **`drupal/ai_webform_generator`**: Webforms from plain-English prompts,
  OpenAI-only, paid key.
- **`drupal/ai_webform`** (not covered by the security advisory policy):
  generates Webform element YAML from a description, generates field help
  text, and lets a front-end user pre-populate a form by describing their
  request. Works with any `drupal/ai` provider (OpenAI, Anthropic, Ollama).
- Neither does true conversational "chat to fields"; both are
  prompt-to-YAML. No AI form-generation module from Jacob Rockowitz
  (Webform's maintainer) was found, though he writes about Drupal AI and
  Webform (a Bluefly.io Town Hall, 2026-07-23, per the same search).
- Webform stores everything as config YAML (`webform.webform.[id].yml`),
  which is what makes the output portable between sites.

## Idea

A "webform builder" interview skill, sibling to `aim-discovery`:

1. **Interview** the user: purpose, fields, required vs optional,
   conditionals, validation, confirmation and email needs.
2. **Emit** either (a) a tight prompt for `ai_webform` to turn into element
   YAML inside Drupal, or (b) the complete `webform.webform.*.yml` itself
   (elements, settings, handlers), imported via config single import or
   `drush config:import --partial`.
3. **Remember**: the interview's answers (audience, purpose, policy) land
   in site memory as ordinary facts, so later builds and the chatbot share
   one source of truth about what the site is for.

## Analysis

- **Option (b) beats (a) to start.** Claude can write Webform YAML directly;
  `ai_webform` only earns its place if generation must happen inside the
  Drupal UI with the site's own tokens and provider settings. It is also an
  extra dependency with no security-advisory coverage. Prototype (b) first.
- **`ai_webform` covers elements only** (types, required flags, token
  defaults, submit button). Handlers, emails, confirmation and access rules
  are separate config, so portability of the *whole* form depends on the
  interview capturing those too.
- **Output quality tracks prompt quality.** The skill should speak Webform's
  vocabulary (`textfield`, `webform_email_confirm`, `#states`) rather than
  free prose.
- **Where memory actually adds value** is not the YAML generation (any
  prompt does that) but the recall side: facts like "audience is library
  patrons", tone, and policy ("never collect date of birth") constrain what
  the generated form may ask. That is a real, cheap use of site memory.
  "Whole site assembled from memory" beyond that remains a gimmick until a
  concrete use case says otherwise.
- **Gate applies.** Anything generated and then applied to a live site goes
  through [ADR-0035](0035-standing-constraints-action-gate.md): dry-run and
  human review before any config import by an LLM-driven caller.

## Decision (proposed)

Do nothing in the module. If pursued, build only the skill (interview ->
Webform YAML file for human review and manual import), recalling site memory
for constraints and writing interview answers back as facts. Add no
dependency on `ai_webform` or `ai_webform_generator` unless the in-Drupal
generation path proves necessary.

## Consequences

- No code, schema or dependency change to `aim` now.
- Reuses the existing `aim-discovery` skill shape and `aim-memory` skill.
- Revisit if a recipe-based path (Recipes carrying Webform config, see
  `recipes/` and ADR-0035) becomes the preferred build target.

## Open questions

- Verify the module claims above directly on drupal.org before relying on
  them (the search summary is secondhand).
- Is one interview skill per artifact type (webform, view, content type)
  worth it, or one generic "spec to Recipe" interview? Leaning generic.
- Should interview answers be `scope: site` facts only, or also a
  `scope: case` fact per build for provenance?
