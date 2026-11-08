# ADR-0066: One instance, one recipe, one plugin

**Status:** Proposed. `recipes/aim_personal` is drafted and tested on a fresh
test site, but has not been used on a real site. No plugin is built.
**Date:** 2026-11-07

## In plain terms

Before: one site held everything. Using memory personally in Claude would
mean sharing a store with the demo library, and nothing said what a given
AIM site is for.

After: each use of AIM is its own site, set up by its own recipe, which also
tells connecting models what the site is for. If a use needs skills, it gets
its own Claude plugin.

Gains: personal facts and library facts can't mix, and a site's purpose is
written down in one place. Costs: every instance is another site to run, with
its own models, OAuth keys and public URL.

## Context

Scopes separate who may see a fact, not which body of knowledge it belongs
to. Recall covers every scope the caller can see, so an owner who is also the
library's administrator would get both sets of facts in one answer.

[ADR-0014](resolved/0014-usecase-archetype-starter-kits.md) proposed a catalog
of archetypes, each with an interview variant and its own Guardrail set, and
was retired. This record keeps its one surviving idea, a recipe per use, and
none of the catalog.

Claude plugins take a fixed connector URL (checked 2026-10-26 against the
plugin docs), so one plugin cannot point at "your site". The MCP server
instructions already tell a model when to use each tool, so a plugin that only
repeats them adds little.

## Decision

1. **An instance is a recipe applied to a site of its own.** The recipe sets
   the modules, enabled scopes, scope descriptions, trust permissions and
   purpose. Separate data comes from the separate site, not the recipe.
2. **A recipe states the purpose in `mcp_server.settings:server_instructions`.**
   `aim_tool_oauth` adds its guidance after that setting, so every client reads
   both when it connects.
3. **A recipe gets written when someone wants that instance.** There is no
   catalog, and no variant of the grill or Guardrail set per use.
4. **`aim_personal` is the first new one:** `user` and `case` scopes only, no
   chat widget, closed registration, and the owner signs in as uid 1 so that
   no role or grant is needed.
5. **A plugin belongs to one instance and carries what a connector can't:**
   skills. A personal instance may need none. The library's would carry the
   `/library` persona; a chat version of the grill could ship with either. This
   drops the wait for the grill-to-recipe skill (TODO.md).

## Consequences

- A recipe must set each tool's OAuth scope itself:
  `aim_tool_oauth_install()` runs before the recipe imports the tool configs,
  and finds none. Without those actions the test shows the settings are
  missing. Any future recipe installing `aim_tool_oauth` needs the same
  actions.
- A token uid 1 authorizes keeps every permission (`Oauth2AccessPolicy`). That
  is acceptable for one owner while the site holds only their own notes. A
  narrower owner role needs testing against a real connector, which may not
  request a role scope.
- `extraction_prompt` still names all the scopes, so `aim:extract` and the
  Ingest form could propose one that's disabled. Not tested.
- A persona skill is not the guarded assistant: over MCP it has no finder-first
  gate (`AimGateProcessor`), and its rules are advice to the model. Permissions
  still hold, since the tools check them on the server.

## Not decided

- Whether a drupal.org release ships a skills-only plugin, with the connector
  added separately, once a per-site one has proven useful.
- Where a second site runs. This laptop has limited memory, and a personal
  instance can share its Ollama but not its DDEV project.
