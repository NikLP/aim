# ADR-0044: Tool API method attributes - watch the upstream MR, shape new tools to adopt it

**Status:** Proposed 2026-10-04 - a watching brief, nothing built and no
change to `aim_tool` yet. Touches [ADR-0013](resolved/0013-mcp-tool-exposure.md)
(Tool API/MCP exposure) and [ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md)
"Tool, search and lookup modes" (the literal agent tools).
**Date:** 2026-10-04

## Context

[drupal/tool MR 162](https://git.drupalcode.org/project/tool/-/merge_requests/162)
("Define tools with attributes on typed methods") is a draft proposal for
exposing an existing service method as a tool without a plugin class.
Nik's read is that it is being treated upstream as an important piece of
tooling, so it is worth tracking even though it is unmerged.

Details below come from a summary of the MR page, not a read of the diff.
Verify against the MR before building on any of it.

What it proposes:

- `#[ToolMethod]` on a typed service method, with `#[ToolInput]`,
  `#[ToolEntityInput(op: ...)]`, `#[ToolOutput]` and `#[ToolProperty]`.
  Inputs come from parameters, outputs from the return type, descriptions
  from the docblock. No definition arrays.
- Access is never inferred. Each tool declares a `permission`, an entity
  `op` on its inputs, or a custom `access()`.
- Value objects publish only members marked `#[ToolProperty]`; an
  unmarked class fails discovery rather than leaking fields.
- Existing `#[Tool]` plugins keep working and can optionally drop their
  definition for a typed `__invoke()`.
- It is split into seven pieces meant to land separately, with typed
  `__invoke()` on existing plugins as the first piece with user value.
- Core services could only use the attributes once the attribute classes
  move to core (`Drupal\Core\Tool\Attribute\*`).
- Breaking edges: `ToolManager::__construct()` gains arguments, and
  `ToolBase::doExecute()` stops being abstract.

## Decision

Do not change `aim_tool` now. The API is unmerged and unstable, and
`aim_tool` is two plugins ([AimRecall](../modules/aim_tool/src/Plugin/tool/Tool/AimRecall.php),
[AimRemember](../modules/aim_tool/src/Plugin/tool/Tool/AimRemember.php), about
575 lines). Instead:

1. **New tools are designed as typed service methods first.** Put the
   logic on a service method with native types and docblock types
   (`list<T>`, shaped arrays), and keep the plugin a thin wrapper. The
   `LiteralFinder`/`LiteralChooser` services in ADR-0040 follow this.
2. **Outputs are explicit value objects**, not loose arrays. ADR-0040's
   three outcomes (`match`, `ambiguous`, `none`) become one small class
   with only the intended members, which is also what the MR's
   members-only rule wants.
3. **Registration inputs cannot carry a value.** `literal_register`
   accepts gist and pointer only (ADR-0040, "Tool, search and lookup modes"), so the signature
   itself enforces "agents never enter values".
4. **Access stays explicit and layered.** Even with `op: 'view'` on an
   entity input, the finder still drops unviewable candidates before the
   chooser sees them (ADR-0040, "The finder and the chooser"); the tool boundary check is a
   second layer, not a replacement.
5. **Revisit when a piece lands**, in this order of likely value: typed
   `__invoke()` on existing plugins (would trim `AimRecall`/`AimRemember`
   definitions), then service-method discovery.

## Consequences

- Little saving for aim today. Its tools take a `scope` argument and
  `remember()` has a `trusted` override, so permission and per-scope
  access still need a custom `access()`; the MR's biggest wins are
  thin wrappers, which aim's tools are not.
- `aim_chatbot`'s `FunctionCall` tools go through `ai_agents`, not Tool
  API, so this does not touch them.
- If the MR changes shape or stalls, nothing here is wasted: typed
  methods and value objects are good practice regardless.

## Open questions

- Does the attribute set land in core, and under what namespace? aim
  should not import attribute classes from contrib `tool` if they are
  about to move.
- Does `#[ToolEntityInput]` cover aim's per-scope permissions
  (`view {scope} aim facts`), or only entity access? Likely only the
  latter; check when it is reviewable.
- Do the attribute classes survive into the `mcp_server_tool_bridge`
  schema generation without changes?
