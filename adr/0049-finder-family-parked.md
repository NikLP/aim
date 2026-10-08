# ADR-0049: The finder family - `@` picker, meaning navigator, tool picker (parked)

**Status:** Parked 2026-10-08 - design only, nothing built, none of it
needed now. Merges the former ADR-0049 (everything finder widget),
ADR-0050 (meaning navigator) and ADR-0051 (tool picker) into one record;
the old numbers 0050 and 0051 are retired. The browser-side idea
(WebMCP) may supersede much of the widget half; revisit before building
any of it. Builds on
[ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md)
(the finder, literals as first provider), [ADR-0048](../../literals/adr/0048-token-literal-access-and-entity-targets.md)
(per-account access on resolve) and [ADR-0053](0053-gist-shared-vocabulary.md)
(the gist).
**Date:** 2026-10-05 (widget, navigator), 2026-10-06 (tool picker)

## The shared idea

Site structure is a tree: entity types, bundles, entities, fields, tokens,
tools, literals. A person can walk it by hand (the widget). A model can walk
it for them by choosing, at each node, one child from an enumerated list by
its gist (the navigator, with the tool picker as its first and best-formed
provider). One tree contract, `children()`, serves both. Nothing here is
built; `literals_finder` already does the single-level version (access
filter, outcome cache, a Decision API choice from a menu).

Rules that hold for every part:

- **The model only picks from an enumerated list.** It returns an ID from
  the menu it was given, never a value, a path it composed or free text.
  An unknown ID or "none" ends the walk. A hallucinated answer cannot reach
  the caller; values come from the leaf's provider exactly as stored.
- **Access before the model, not after.** Children are filtered by the
  asking account's access before the menu is built; a name the account
  cannot see must not reach the model or the logs. Audit lines record IDs,
  depth and decision, never question text.
- **Bounded cost.** One model call per level, an outcome cache (as in
  `literals_finder`), pruning of wide levels, a depth cap and a per-question
  call budget ([model-call-budget.md](model-call-budget.md)). A walk that
  exceeds a limit returns "not found", not a partial guess.
- **A measured pass bar first.** A gold set of (question, expected leaf)
  pairs and a measured error rate before anything relies on it; a
  plausible chooser is not a right one.
- **Decision API, Jev.** No new model plumbing ([ADR-0021](0021-jev-typed-decision-provider.md);
  its hosted-deviation addendum applies: synthetic data only on hosted).

## A. The `@` picker (deterministic widget, no AI)

A standalone module `finder`, no dependency on `drupal/ai`, `aim` or
`literals`.

- **Widget.** A JS behavior on a textarea, CKEditor 5 or contenteditable;
  a trigger (`@` or a key combo) opens a debounced, keyboard-first picker,
  results grouped by provider (so no cross-provider ranking). Progressive
  enhancement.
- **One endpoint.** `/finder/suggest?q=&scope=&context=` returns typed
  groups (label, group, opaque id, optional preview), with per-provider
  limit and timeout.
- **Providers are a plugin type.** `search()`, `children()` (next path
  segment), `resolve()` (what to insert) and an access check for the asking
  account inside the provider. First providers: entity (via
  `SelectionPluginManager`), token (`token.tree_builder`), literal (the
  search service, now `literals.search`), route/menu if a use appears.
- **Traversal** is first-class: tokens are already paths
  (`[node:author:mail]`), entities drill type, entity, field, value, and
  prefix chips (`@user:`, `@node:`, `@tok:`) scope the search.
- **Insert modes.** The plain value, a token string left for render, an
  entity link or embed, or the bare ID. A literal's value is returned
  exactly as stored.
- **Context** (entity being edited, current user) is passed explicitly;
  providers never read the session behind the caller's back.
- **Risks.** Access leaks in the per-viewer suggest endpoint (cache keys
  must vary by account), per-keystroke fan-out, scope creep (it is Linkit
  plus a mention plugin; wrap Linkit or core if they already cover a
  provider), and `@` colliding with email addresses (a key combo may be the
  safer default).
- **Open.** Module name and home; whether traversal into field values is in
  the first build (leaning: tokens first); prior art not yet read (Linkit,
  `EntityAutocomplete`, the CKEditor 5 mention plugin, the Token module's
  tree browser).

Note: the autocomplete route that once sat in `literals` was removed
2026-10-08 (no consumer); the Token module's browser is the editor picker
for tokens.

## B. The meaning navigator (a model walks the tree)

At each node: take the children from the providers, filter by access,
prune wide levels, build a menu of (opaque id, label, gist), ask for one id
or none, recurse or return the leaf. Gists are the only thing the model
sees; a node without one falls back to its label (weaker, and the result
says how many levels were chosen on labels only). Surfaced as a finder
`mode=ask` and a Tool API / MCP tool beside `literals:lookup`, and as an
explicit "ask in words" entry in the widget, never the default for typing
(model calls, slower than autocomplete).

- **Blocked** on annotations carrying gists (ADR-0024, ADR-0047: the bridge
  write path and trust gate are not fully built); until then only literals
  have gists and the tree is label-only. Also on `children()`.
- **Cost.** The deterministic walk is cheap (cached definitions, one
  `loadMultiple` per step); only the chooser costs a model call per level.
- **Gists belong on structural nodes** (entity types, bundles, fields,
  tokens, tools, literals), not on content nodes: Search API with the
  vector backend already finds content by meaning.
- **Default is a hybrid:** a shortlist first (Search API or an embedding
  search over gists), then the chooser picks among about eight; full
  top-down descent for huge pools or where access follows the tree. Measure
  both against the gold set.
- **Risks.** Compounding error (a wrong pick near the root misleads every
  level below; evaluate top-k leaves, one-level backtracking and showing the
  path taken), latency (depth times a call; fine for "ask" and agents, not
  typing), gist drift (ADR-0047's tracked gists).
- **Open.** Top-k with a person confirming versus one automatic pick; fixed
  root versus vector shortlist; whether a leaf returns its value or only
  its location when the asking account may resolve it differently.
- **Use cases** (all "a description in words, no knowledge of where it
  lives"): finding exact values and config locations; agents (tool picking,
  Webform pre-fill per field in [ADR-0041](0041-annotation-guided-webform-prefill.md),
  reading structure); authoring (token insertion, chatbot answers that
  relay stored values); speculative governance (finding where a fact is
  repeated, for [ADR-0043](0043-content-truth-drift-audit.md)). Strongest
  first candidates: tool picking and form pre-fill, which have designs and
  small, well-labeled trees.
- **Hypothesis, untested: a weaker chat model in front.** With the
  navigator finding, memory supplying conventions and exact values returned
  from the site, the chat model only turns words into a question and
  phrases the result. Not removed, only moved: it must still decide to call
  the navigator, form a usable question and hold a thread, and structure
  itself is read live through tools since memory goes stale. Likely
  adequate for lookups and grounded explanations, not for planning or
  altering structure ([ADR-0035](0035-standing-constraints-action-gate.md),
  decision 8). To test: the same assistant on a small model with the
  navigator, against a gold set, measuring tool-call rate, right-leaf rate
  and end-to-end error against the stronger model.

## C. The tool picker (the navigator's first provider)

Plan: expose every aim capability as a tool (Tool API, and MCP via
`mcp_server_tool_bridge`); handing a model every schema wastes context and
degrades its choice as the list grows. Checked 2026-10-05: `tool:search` is
a keyword Drush command not callable by an agent, `mcp_server` and the
bridge list every tool unranked, `ai_agents` passes a fixed list; no
semantic picker was found in installed contrib. A tool's description is
already a gist, so this does not wait on Annotations.

- **A "tools" provider.** Children are Tool API plugins (label, description
  as gist), grouped one level up by providing module with the `.info.yml`
  description as group gist. Two levels: group, then tool.
- **Jev ranks, no vectors.** Up to about 40 visible tools: one call over the
  whole menu. More: level 1 chooses up to two groups, level 2 chooses tool
  ids from those groups.
- **`tool_find`**, a two-step agent interface: a description in words in,
  up to k matches (id, label, gist) out, never full schemas; the caller then
  loads the schema of the one it picks. It must stay visible to every agent.
- **Access** by Tool API's own checks, before the model. **Cache** keyed on
  (question, audience, tool-set hash). Depth fixed at two.
- **Return top-k, don't auto-pick:** a miss means the agent never sees the
  right tool, so the caller's own model makes the final choice from a short
  list.
- **Why not vectors:** one or two model calls instead of an embedding
  lookup is slower and dearer per call, but there is no index, no refresh
  and no second ranking system to evaluate, and tool counts are small and
  stable. A vector shortlist remains the fallback.
- **Risks.** A group-level miss hides the right tool (pick two groups,
  measure); descriptions cap quality; up to two calls of latency (the cache
  absorbs repeats).
- **Open.** Fixed or caller-supplied k; separate menus for read-only and
  write tools; whether grouping by Tool API operation (explain, read,
  transform, trigger, write) fits better than by module.
