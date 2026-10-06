# ADR-0051: The tool picker - Jev chooses tools by description, not by vector

**Status:** Proposed 2026-10-06 - design only, nothing built. First
provider for [ADR-0050](0050-meaning-navigator.md)'s navigator; answers
the open question in [ADR-0046](0046-core-cutoff-time-axis-and-shared-gist-finder.md)
("is the tool picker needed beyond Tool API's own selection, or only when
the tool list is large?"). Supersedes the vector shortlist that
ADR-0046 and ADR-0050 Addendum 1 assumed, for this provider only.
**Date:** 2026-10-06

## Context

The plan is to expose every aim capability as a tool (Tool API, and MCP
via `mcp_server_tool_bridge`). Handing a model every tool schema on every
call wastes context and degrades its choice as the list grows.

Checked 2026-10-05 against the installed code: `tool`'s `tool:search` is
keyword matching in a Drush command, not callable by an agent;
`mcp_server` and the bridge list every tool unranked; `ai_agents` passes a
fixed list. No semantic tool picker was found in installed contrib or the
code index (drupal.org itself not searched).

A tool's description is already a gist ([ADR-0046](0046-gist-shared-vocabulary.md)),
so this does not wait on Annotations carrying gists, the main blocker for
the rest of ADR-0050.

## Decision (proposed)

### 1. A "tools" provider on the ADR-0049 tree

Children are Tool API plugins: label = plugin label, gist = the tool's
description. They are grouped one level up by providing module, with the
module's `.info.yml` description as the group gist (nothing generated at
run time). The tree is two levels: group, then tool.

### 2. Jev ranks; no vectors

After the access filter (4), build a menu of (opaque id, label, one-line
gist) and ask the Decision API to choose up to k ids:

- **Up to ~40 visible tools:** one call over the whole menu.
- **More:** level 1 chooses up to two groups from the group menu; level 2
  chooses tool ids from those groups' menus. Two calls, each short.

Jev returns ids from the menu only ([ADR-0050](0050-meaning-navigator.md)
decision 2). An unknown id or "none" gives an empty result.

### 3. `tool_find`: a two-step agent interface

A Tool API / MCP tool, beside `literals:lookup`. Input: a description in
words. Output: up to k matches (id, label, gist), never full schemas. The
caller then loads the schema of the one it picks and calls it. It must
stay visible to every agent, since it is the entry point.

### 4. Access before the model

Menus are built only from tools the asking account can run, using Tool
API's own checks. A hidden tool's name never reaches the model or the
logs. Audit lines record ids, depth and decision, never the question text.

### 5. Bounded cost

- **Outcome cache** keyed on (question, audience, tool-set hash); the hash
  changes on cache rebuild.
- Depth is fixed at two; one call budget per find
  ([model-call-budget.md](model-call-budget.md)).
- A find that exceeds a limit returns no result, not a partial guess.

### 6. Return top-k, don't auto-pick

A miss means the agent never sees the right tool, so v1 returns k
candidates and the caller's own model makes the final choice from a short
list.

## Why not vectors

Every find is 1-2 model calls instead of one embedding lookup: slower and
dearer per call. In exchange there is no index, no embedding refresh and
no second ranking system to evaluate. Tool counts are small and stable
enough that a menu fits. A vector shortlist (ADR-0050 Addendum 1's hybrid)
remains the fallback if counts grow past what two levels handle or
latency matters; the access filter and `tool_find` interface are
unchanged either way.

## Dependencies

- [ADR-0049](0049-everything-finder-widget.md)'s `children()` contract, or
  a minimal tool-only equivalent until it exists.
- A Decision API activity for the chooser ([ADR-0021](0021-jev-typed-decision-provider.md);
  its hosted-deviation addendum applies: synthetic data only on hosted).
- **A measured pass bar.** A gold set of (task description, expected tool)
  pairs and a measured miss rate before agents rely on it, per the
  decision-model evaluation findings. A plausible chooser is not a right
  one.

## Risks

- **Group-level miss** hides the right tool; mitigated by picking two
  groups at level 1 and measuring.
- **Weak descriptions.** Quality is capped by tool and module descriptions;
  improving them is part of the work.
- **Latency** of up to two model calls per find; acceptable for agents,
  and the cache absorbs repeats.

## Open questions

- Is k fixed (~5) or caller-supplied?
- Should read-only and write tools be separate menus, so an agent can ask
  for "something that changes data" explicitly?
- Does grouping by module fit tools from the Tool API's own operations
  (explain, read, transform, trigger, write) better?
