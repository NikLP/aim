# ADR-0014: Use-case archetype starter kits via Recipes

**Status:** Proposed - exploratory best-guess estimate, not an accepted
design and not built. Written to scope the idea and flag open questions
before any Skill/config work starts, not to lock in a mechanism.
**Date:** 2026-09-14

## Context

`aim`'s use cases are deliberately broad (CLAUDE.md's "Scope stays broad"
- user/role/site/case memory are all equally in scope), but nothing today
helps a new adopter pick a starting point. ADR-0010 named this gap and
left it open rather than designed:

> **Open question 8: Speckit-for-Drupal's actual question sets** - no
> design work done on what a spec-gathering Skill asks, in what order,
> per site archetype (commerce, brochure, LMS, ...). Real scope of work,
> not a byproduct of the storage/extraction plumbing.

`.claude/skills/aim-discovery/` ("the grill") already proves the
mechanism end to end - a structured interview distilled per-topic and run
through `aim:extract`, mostly `scope: site` - but it is one generic
interview, not an archetype-specific one. CLAUDE.md's "Ideas raised, not
designed" list separately carries a narrower slice of the same gap
("Source-boundary policy per site archetype": each archetype should
declare its own allowed-source boundaries, likely as its own Guardrail
set). Both are the same unanswered question seen from different angles -
this ADR is the first attempt at an actual shape, folding both in rather
than tracking them as two separate loose ends.

[ADR-0009](0009-recipe-apply-safety-gate.md) already settled the *safety*
question for applying an AI-generated Recipe (dry-run/review gate, no
unattended `drush recipe apply`). It says nothing about what recipes get
offered or how discovery differs per use case - that's this ADR's
territory, and it assumes ADR-0009's gate applies unchanged to any Recipe
a starter kit ships.

## Decision (best-guess proposal - not committed)

Package a small number of named **archetypes** - candidates: personal/
user assistant memory, internal team wiki (site memory), support-desk
case tracking, commerce catalog assistant - each as a bundle of:

1. **A discovery-Skill variant.** Not a rewrite of `aim-discovery` per
   archetype - a parameterized question set (or a thin per-archetype
   Skill that wraps the same grill mechanism with a different topic list)
   tailored to what that use case actually needs to learn.
2. **A dedicated Guardrail set.** Closes the "source-boundary policy per
   site archetype" idea directly - reuses the exact mechanism
   `aim_write_guardrails` already is, just a second (third, fourth...)
   named set instead of one global policy. A commerce archetype's
   allowed-source boundary is not a support-desk archetype's.
3. **A starter `aim_category` term set.** Seeded terms for that domain
   (admin-curated per CLAUDE.md's existing stance - a starter kit
   *proposes* terms, it doesn't change categories from curated to
   auto-created).
4. **An optional Recipe**, gated by ADR-0009, only for archetypes that
   need real site structure beyond `aim_fact` itself (e.g. a case-
   tracking archetype provisioning a supporting content type). Not every
   archetype needs one - a personal-assistant archetype may need nothing
   beyond (1)-(3).

`mcp_tools_recipes` (composer-present, beta, uninstalled - per project
memory) already implements Recipe-as-Tool-API-plugin; worth checking
before building a bespoke Recipe-invocation path for (4) rather than
assuming one needs to be written from scratch.

## Consequences / risks

- **Real authoring investment per archetype**, not a byproduct of
  existing plumbing - ADR-0010 said this explicitly about open question
  8 and it doesn't get cheaper for being named here. Each archetype's
  question set needs actual per-vertical design, the same way GitHub's
  Spec Kit needed real work per project type, not just a template
  swap.
- **Guardrail-set fragmentation.** Multiple named Guardrail sets is more
  governance surface than one - and sits ahead of
  [ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s still-
  deferred Content Moderation gate, which will eventually want to reason
  about all of them uniformly. Don't let per-archetype sets drift
  independently once that gate lands; reconcile then, not before.
- **Answers part of ADR-0010's still-open question 1** ("what's the
  first concrete feature this unlocks, sellable on its own") by turning
  one generic PoC into a menu of pitchable, demoable slices - a real
  secondary benefit, not the primary motivation for building this.

## Open questions

- **How many archetypes to start with**, and which - no evidence yet for
  which vertical is worth building first. Candidate approach: build one
  (the one closest to existing PoC content, likely personal/user
  assistant memory) end to end before committing to a second.
- **Separate Skills vs. one parameterized Skill.** Whether each archetype
  is its own `.claude/skills/aim-discovery-<archetype>/` directory or one
  Skill that takes an archetype argument and varies its question set
  internally - not decided, affects how much duplication vs. indirection
  the mechanism carries.
- **Whether a starter kit is itself a Recipe.** Packaging (2)+(3) as
  config entities means a Recipe could plausibly provision the whole
  kit - Guardrail set, category terms, and all - in one
  `drush recipe apply`, not just the archetype-specific content-type case
  under (4). Not evaluated against ADR-0009's gate for this narrower,
  lower-risk case (provisioning config, not generating it from an AI
  pass).

None of the above is validated against real code or real data - the whole
"Decision" section is a best guess to be replaced by an actual design once
this gets prioritized, not a spec to build against as-is.
