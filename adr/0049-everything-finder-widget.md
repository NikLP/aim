# ADR-0049: The everything finder - an inline "@" picker over pluggable providers

**Status:** Proposed 2026-10-05 - design only, nothing built. Splits the
autocomplete idea out of `literals`; builds on
[ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md) (literals
stay the first provider) and [ADR-0048](0048-token-literal-access-and-entity-targets.md)
(per-account access on resolve). Sibling of
[ADR-0050](0050-meaning-navigator.md), which is deliberately separate.
**Date:** 2026-10-05

## Context

`literals_search` already gives plain autocomplete over literal names,
keys and gists (no AI, no scoring, a person picks). The same interaction
is wanted for much more than literals: while writing in any text field,
press a key combo or type `@` and find a node, an entity value, a token,
a literal, by typing or by walking a path.

The seam is generic. A widget, one suggest endpoint, and sources of
candidates. Nothing in it needs a model, and nothing in it is specific to
literals, so it should not live inside the `literals` module.

Prior art to check before building the widget half (not yet read for this
ADR): Linkit (link autocomplete, the nearest "find anything" in contrib),
core's `EntityAutocomplete` and `SelectionPluginManager` (labels, bundles,
access), CKEditor 5's mention plugin, and the `token` module's tree
browser. Reuse where they fit; the new part is the provider seam and the
uniform insert contract.

## Decision (proposed)

### 1. A standalone module, `finder`, with no AI dependency

`finder` ships the widget, the suggest endpoint and the provider plugin
type. It does not require `drupal/ai`, `aim` or `literals`. Jev and the
Decision API are never called from it (that is ADR-0050).

### 2. The widget

A JS behavior that attaches to a textarea, a CKEditor 5 instance or a
contenteditable. A trigger (`@`, or a configurable key combo) opens a
picker; typing narrows it. Debounced, one request per pause, results
grouped by provider. Keyboard-first (arrows, Enter, Escape), usable
without a mouse. Progressive enhancement: with no JS the field is
unchanged ([literals' progressive-enhancement note](../../literals/adr/progressive-enhancement.md)
applies).

### 3. One suggest endpoint

`/finder/suggest?q=...&scope=...&context=...` returns typed groups:
each candidate has a label, a group, an opaque id, and an optional
preview. Per-provider limit and timeout, so one slow provider cannot
stall the picker. Groups are shown as sections, which avoids ranking
across providers entirely.

### 4. Providers are a plugin type

A `FinderProvider` plugin has:

- `search(string $query, array $context): array` - candidates for the
  typed text;
- `children(string $id, array $context): array` - the next segment of a
  path, for traversal (see 5);
- `resolve(string $id, array $context): FinderInsert` - what to insert;
- an access check run **for the asking account**, inside the provider,
  never as a filter over results afterward (an unpublished title in a
  suggestion is already a leak).

First providers:

| Provider | Source |
| --- | --- |
| entity | any entity type through `SelectionPluginManager` (bundles, labels, access already handled) |
| token | `token.tree_builder`, segment by segment |
| literal | the existing `literals_search` service |
| route/menu | menu links and routes, if a use appears |

Providers live in the module that owns the source, or in `finder` when
the source is core. A site installs only what it wants.

### 5. Traversal

Path walking is a first-class interaction, not a flat list:

- **Tokens** are already paths: `[node:author:mail]`. After `[` or an
  `@tok:` chip, each step offers the next segment.
- **Entities** drill the same way: type, then entity, then field, then
  value (`@node:` then a title, then `:field_x`).
- **Prefix chips** (`@user:`, `@node:`, `@tok:`) scope the search to one
  provider and make the path explicit.

`children()` is what the widget calls on each step. A provider with no
natural hierarchy returns nothing.

### 6. Insert modes

`resolve()` returns what to put in the field, and the provider or the
target field picks the mode:

- the plain value (a phone number, a URL);
- a token string (`[site:login-url]`), left for the token system to
  resolve at render;
- an entity link or embed;
- the bare ID.

The value of a literal is returned exactly as stored, never paraphrased
(ADR-0040's invariant carries over to every provider that resolves a
stored value).

### 7. Context passes through

Tokens and entity values can resolve differently by context (the current
node, the current user). The widget sends a small context (entity type
and ID being edited, if any) and providers use it; they do not read the
session behind the caller's back (ADR-0048).

## Risks

- **Access leaks.** The per-viewer suggest endpoint is the sensitive
  part. Every provider runs the real entity or literal access check as
  the asking account. Cache keys must vary by account (or by the cache
  contexts the provider declares).
- **Fan-out cost.** Every keystroke can hit every provider. Debounce,
  per-provider limits and timeouts, and a minimum query length for broad
  providers.
- **Scope creep.** This is Linkit plus a mention plugin. The first build
  should be the endpoint, the provider plugin type, two providers
  (entity, literal) and a textarea widget; CKEditor 5 and path traversal
  follow once the contract is proven.
- **Duplication.** If Linkit or core already covers a provider cleanly,
  wrap it rather than rewrite it.

## Relationship to literals

`literals_search` is ported as the first provider with no behavior
change. `literals_finder` (ask in words, Decision API) is not part of
this ADR and stays where it is; ADR-0050 is the place a meaning-based
mode lives.

## Open questions

- Module name and home: `finder` as its own project, or inside the
  `aim` tree like the literals modules (not decided).
- Is traversal into field values needed in the first build, or a second
  step? (Leaning: path traversal for tokens first, since the tree already
  exists.)
- Trigger default: `@` can collide with email addresses and handles in
  free text; a key combo may be the safer default.
