# ADR-0050: The meaning navigator - Jev walks the site's structure by gist, choosing at each node

**Status:** Proposed 2026-10-05 - design only, nothing built; blocked on
annotations carrying gists (see Dependencies). Generalizes the choose-
from-a-list step of [ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md)'s
finder. Shares the tree with [ADR-0049](0049-everything-finder-widget.md)
but is a separate decision: that one is a deterministic widget, this one
calls a model.
**Date:** 2026-10-05

## Context

[ADR-0049](0049-everything-finder-widget.md) lets a person walk the
site's structure by hand: entity types, bundles, entities, fields,
tokens, literals. If each node also carries a semantic description (a
**gist**, [ADR-0053](0053-gist-shared-vocabulary.md), supplied by
Annotations), the same walk can be done on a person's behalf: at each
node, show the model the children's gists and let it choose one, and
repeat until a leaf. The answer is "find the thing that means this",
without the asker knowing the structure.

This is the pattern `literals_finder` already uses for one flat level
(access filter, outcome cache, a Decision API choice from an enumerated
menu), applied level by level. It is a known retrieval shape (a tree of
summaries navigated by a model, as opposed to a flat vector index); this
ADR does not claim novelty, only that the site's own structure plus
annotations is a ready-made tree.

## Decision (proposed)

### 1. A navigator is the ADR-0049 tree plus a chooser

The tree is the one the finder's providers expose through `children()`.
The navigator does not define its own structure. At each node it:

1. Takes the children (from the providers).
2. **Filters by the asking account's access** (see 5) so the model never
   sees a name the account cannot see.
3. Prunes wide levels (see 4).
4. Builds a menu of (opaque id, label, gist) and asks the Decision API to
   choose **one id or none**.
5. Recurses into the chosen child, or stops at a leaf and returns its
   value.

### 2. The model only picks from an enumerated list

As with literals: the chooser returns an ID from the menu it was given,
never a value, a path it composed, or free text. An unknown ID, or
"none", ends the walk with no result. The value comes from the leaf's
provider, exactly as stored. A hallucinated answer cannot reach the
caller.

### 3. Gists are the only thing the model sees

Each menu line is a label plus a gist. A node with no annotation falls
back to its label alone (weaker, and the navigator should say so in its
result: how many levels were chosen on labels only). Annotation coverage
is the quality ceiling; the navigator does not invent gists at run time.

### 4. Bounded cost

One model call per level, so latency scales with depth. Three limits:

- **Outcome cache** reused from `literals_finder`: the same question at
  the same node for the same audience returns the stored choice.
- **Prune wide levels** with embeddings or plain search before asking, so
  the model picks among a short list (on the order of 8), not hundreds.
- **Depth cap** and a per-question call budget
  ([model-call-budget.md](model-call-budget.md) is the house rule).

A walk that exceeds a limit returns "not found", not a partial guess.

### 5. Access before the model, not after

Children are filtered by the asking account's access before the menu is
built. A name the account cannot see must not reach the model (or the
logs): "the model chose X, then we hid X" has already leaked X. Audit
lines record IDs, depth and decision, never the question text (aim's
logging rule).

### 6. Where it plugs in

A mode of the finder (`mode=ask`), and a Tool API / MCP tool for agents,
alongside `literals:lookup`. The widget can offer it as an explicit "ask
in words" entry, never as the default for typing (it costs model calls
and is slower than autocomplete).

### 7. Provider is Jev, through the Decision API

No new model plumbing: the chooser is a Decision API activity like the
finder's. Local or hosted follows the site's setting
([ADR-0021](0021-jev-typed-decision-provider.md), and its addendum on
the temporary hosted deviation applies: synthetic data only on hosted).

## Dependencies and blockers

- **Annotations carry gists.** Per [ADR-0024](0024-annotations-integration-target-scoped-promotion.md)
  and [ADR-0047](../../literals/adr/0047-literal-candidates-and-tracked-gists.md), the
  bridge's write path and the trust gate are not fully built. Until
  annotations supply gists, only literals have them, and the navigable
  tree is label-only.
- **ADR-0049's `children()` contract.** The navigator cannot exist before
  providers expose a tree.
- **A measured pass bar.** Per the decision-model evaluation findings
  (laya/tev1 measured on aim decision points), a model that is
  plausible is not a model that is right. The navigator needs a gold set
  of (question, expected leaf) pairs and a measured error rate before it
  is trusted, and a wrong choice at a high level misleads every level
  below it.

## Risks

- **Compounding error.** A wrong pick near the root sends the whole walk
  down the wrong branch. Mitigations to evaluate: return the top-k leaves
  rather than one, allow backtracking one level, and surface the path
  taken so a person can see and correct it.
- **Latency.** Depth times a model call. Acceptable for "ask" and agents,
  not for typing.
- **Gist drift.** A stale annotation misleads the model. ADR-0047's
  tracked-gist work (reference, don't copy) is the mitigation.

## Open questions

- Top-k with a person confirming versus one automatic pick: which is the
  default in the widget?
- Should the walk start from a fixed root (entity types) or from a
  cheap vector shortlist across all gists, descending only to confirm?
  (A hybrid may beat a pure top-down walk; measure both.)
- Does a leaf return its value, or only its location, when the asking
  account may resolve it differently (ADR-0048)?

## Addendum 1 (2026-10-05): cost, where gists live, hybrid as default

**Cost of the walk.** The deterministic walk (ADR-0049) is cheap: structure
comes from Drupal's cached definitions (entity type manager, field
definitions, `Token::getInfo()`), and a step's gists are one
`loadMultiple` by annotation target, cacheable per target. No model call.
Only this ADR's chooser costs a model call per level.

**Gists belong on structural nodes, not content nodes.** Entity types,
bundles, fields, tokens, tools and literals are few, and their gists come
from annotations (or the literal's own gist). Content nodes (thousands)
are not given navigation gists: Search API with the vector backend
already finds content by meaning, and the embedded text there is the
gist. The navigator duplicates nothing Search API does.

**Revised default: hybrid.** A shortlist first (Search API or an
embedding search over gists), then the chooser picks among the shortlist
(on the order of 8). Full top-down descent is kept for pools that are huge
or where access boundaries follow the tree. This resolves the open
question above in favor of the hybrid; measure both against the gold set
before fixing it.

## Addendum 2 (2026-10-05): use cases

All are "a description in words, no knowledge of where it lives":

- **Finding:** exact values ("the page where members sign in" returns
  `[site:login-url]`), content discovery, config and settings locations.
- **Agents:** tool picking by gist at each level instead of loading every
  schema ([ADR-0053](0053-gist-shared-vocabulary.md)); Webform pre-fill
  per field ([ADR-0041](0041-annotation-guided-webform-prefill.md));
  reading structure ("which fields of a case are sensitive") without
  scraping config.
- **Authoring:** the ADR-0049 picker's "ask in words" entry; token
  insertion ("the author's email" returns `[node:author:mail]`);
  chatbot answers that relay exact stored values.
- **Governance (speculative):** finding where a fact is repeated, feeding
  [ADR-0043](0043-content-truth-drift-audit.md); onboarding ("where is
  X").

Strongest first candidates: tool picking and form pre-fill, which have
designs and small, well-labeled trees.

## Addendum 3 (2026-10-05): hypothesis - a weaker chat model in front

**Hypothesis, untested.** With the navigator doing the finding, memory
supplying conventions and past decisions, and exact values returned from
the site, the chat model can be weaker: it only turns the visitor's words
into a question and phrases the result. Hard steps become narrow ones
("which of these 8 fits"), and hallucination has less room because values
are relayed, not recalled.

Not removed, only moved:

- The chat model must still decide to call the navigator (a weak model may
  answer from habit), form a usable question (a wrong query compounds down
  the tree), and hold a multi-turn thread.
- Jev is still a model; the capability is specialized and typed, and the
  cost moves into navigator calls.
- Memory is for intent and conventions. Structure itself is read live
  through tools (`annotations_read`, config readers), since memory goes
  stale.

Scope: likely adequate for lookups, "where is X", and explanations
grounded in recalled facts and annotations. Not adequate for planning or
altering structure, which stays on the propose, validate, approve path
([ADR-0035](0035-standing-constraints-action-gate.md), decision 8: no
unattended apply).

**To test:** the same assistant (this site's chat persona and
`aim_chatbot` tools) on a small model with the navigator available,
against a gold set of site questions. Measure how often it calls the
tool, how often its query reaches the right leaf, and the end-to-end
answer error rate, against the current stronger model. Per the decision
model evaluation findings, a plausible answer is not evidence; the gold
set is. Until measured, nothing here is a claim.

## Addendum 4 (2026-10-05, moved 2026-10-06): tools as the first provider

Tool picking is worked out in its own record:
[ADR-0051](0051-tool-picker.md) (Jev ranks tools by description, grouped
by module, no vectors; `tool_find` returns top-k ids, not schemas).
