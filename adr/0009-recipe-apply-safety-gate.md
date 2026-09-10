# ADR-0009: No unattended `drush recipe apply` on an AI-generated recipe

**Status:** Accepted (design only - this surface is not yet built)
**Date:** 2026-09-08

## Context

`aim`'s scope includes a second product surface beyond retrospective
memory: the same extraction/consolidation pipeline, run *before* a site
exists, turning a discovery conversation into a **Drupal Recipe** (spec →
buildable site) - content types, fields, taxonomy, seed content,
scaffolded directly from the conversation. This is a fundamentally
higher-stakes operation than writing a memory fact: a Recipe apply
mutates live site structure, not one row in one entity type, and a bad
apply is far more disruptive to undo.

## Decision

**No `drush recipe apply` on an AI-generated recipe without a
dry-run/human-review step first.** This is a hard rule, not a
default-on-but-overridable setting - the same "treat every LLM-proposed
write as untrusted" posture ADR-0002 already applies to memory facts,
but with a stricter gate given the higher blast radius.

"AI-generated recipe" here is deliberately **not** the same thing as a
Drupal core Recipe applied via `drush recipe apply` in the ordinary,
human-initiated sense (which is how `aim` itself ships its own
`config/install` equivalent conceptually, though `aim` uses plain
`config/install`, not a Recipe, specifically to avoid conflating the two
meanings of "recipe" in this project - see CLAUDE.md's "Config now ships
in config/install" section). This decision is about a Recipe an LLM
*generated* from a conversation, applied *unattended*.

## Consequences

- This surface is not built. A real Tool API implementation of recipe
  generation/application already exists in the ecosystem -
  `mcp_tools_recipes` (submodule of `mcp_tools`, 352 installs) ships
  `CreateRecipe`, `ValidateRecipe`, `ApplyRecipe`, `GetRecipe`,
  `ListRecipes`, `GetAppliedRecipes` as real Tool API plugins. Not
  installed, not vetted against this ADR's dry-run/review requirement -
  beta-only releases, no official security-advisory coverage yet, and a
  fairly heavy dependency chain (pathauto, metatag, webform among 13
  deps). Check whether it already implements a dry-run pattern that
  satisfies this decision before building one from scratch, when this
  surface actually gets built - don't assume a from-scratch gate is
  needed.
- The discovery-conversation half of this surface has a real precedent
  already built for the *other* product surface: `.claude/skills/
  aim-discovery/` (the "grill") is a structured interview Skill that
  distills a conversation into a summary and runs it through `aim:extract`
  - explicitly scoped to only populate memory, not generate a Recipe.
  The same structured-conversation mechanism is a plausible front end for
  spec-gathering toward this surface too, but that hasn't been attempted.
