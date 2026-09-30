# ADR-0029: Context-carrying turns - mention tokens, an API-first console, and one review pipeline

**Status:** Accepted 2026-09-30 - design only, nothing built.
**Date:** 2026-09-30

## Context

A single utterance often touches several scopes: "bring John into the
pub project and note on node 25 we have a problem" is a `user` reference,
a `case` reference, and an `entity` fact. Current behavior, checked
against the code:

- A fact belongs to exactly one scope. `AimMemoryManager::remember()`
  takes one `scope`, one `subject`, and optionally one
  `target_type`/`target_id`. Nothing splits a compound statement.
- `extractFacts()` returns a list of `{scope, subject, text}` triples, so
  one input can yield several single-scope facts. Its schema has no
  `target_type`/`target_id`, so `scope=entity` facts cannot be
  extracted. It also cannot resolve "John" to an account or "the pub
  project" to a case ID (ADR-0011 already forbids it guessing accounts).
- Nothing tracks an "active case". Case IDs are opaque minted strings
  (`AimScopeCase::defaultSubject()`), with no name or alias lookup.
- "Bring John into the pub project" is a membership relation. A fact
  string can only approximate it, and case access control is unbuilt.
- Nothing links the facts that came from one utterance. `source` is a
  free string tag by convention, not a queryable link.

The root problem is that reference resolution (which user, which node,
which case) is being asked of an LLM, which is the wrong tool. A human
picking from autocomplete, or an agent calling a lookup tool, resolves
references reliably.

**Correction folded in with this ADR.** The earlier "no
conversation/transcript recording" constraint was a misconstrual. The
intent was only that the site does not record **audio**; text chat is
inherently recorded by its carrier. The constraint was removed from
DEVELOPING.md on 2026-09-30. Whether to store turn text is decided below
on its merits.

## Decision

Five pieces. Pieces 1-2 are the core; 3-5 build on them.

### 1. Mention tokens: references are resolved before extraction

Text carries typed tokens for anything already resolved:
`bring @[user:12] into @[case:case-a1b2c3d4], and note on @[node:25]
we have a problem`. The display form (`@john`) is a client rendering concern; the
stored and transmitted form is the token.

- **Syntax (decided 2026-09-30):** `@[<type>:<id>]`. `@[` opens a token,
  so it cannot collide with Markdown links or `[1]`-style citations.
  `type` is `user`, `case`, or an entity type ID (`node`,
  `taxonomy_term`, ...). This is aim's own syntax, not Drupal's Token
  API: core tokens (`[node:title]`) name a property of a contextual
  object and carry no entity ID or label, so they cannot express a
  reference. Role and site need no token (a role is chosen as a scope,
  the site is implicit).
- **No escape syntax.** The parser honors only a token matching the
  grammar that is also in the turn's resolved set; anything else is
  plain text.
- **Extractor contract change:** input is tokenized text plus a token
  set. Output facts reference tokens from that set in `subject` and
  `target_type`/`target_id`. The extractor is never asked to invent an
  ID, only to decompose text and classify each fact's scope.
- **Post-validation:** a proposed fact whose subject or target is not in
  the turn's token set is dropped before it reaches review. A
  hallucinated reference cannot be written.
- **Stored verbatim in `text`.** Tokens stay in the fact text in
  canonical form. Structured `subject`/`target_*` still carry the primary
  reference; tokens in text carry secondary ones (John mentioned inside a
  node fact).
- **Two renderings.** Embedding and display use a rendered form (token
  replaced by the entity's current label), so semantic search for "John"
  can hit and a renamed entity stays correct. A token whose entity was
  deleted degrades to a placeholder ("removed user"), never a broken
  token.
- **Same mechanism for chips and inline mentions** (see piece 2): a chip
  is a token pinned to the composer, an inline `@` mention is a token in
  the text. The backend treats both identically.

### 2. Context chips and an API-first console

A site-facing console ("talking to the Drupalbrain"), with an on-page
form or controller holding the widgets.

- **Context chips:** zero or more attached context items in the
  composer: a case, users (entity-reference autocomplete), an entity. The
  "about this node" case is a chip pre-filled from the current route.
- **Inline `@` mentions:** typing `@` opens autocomplete for users,
  cases, and entities, same as comment mentions elsewhere. Resolves to
  the same tokens.
- **Turn payload:** `{text, context: [...]}`, where context entries are
  resolved tokens. The backend runs extraction inside that context.
- **API-first:** the console is the first client of a JSON API
  (`/aim/api/turn`, `/aim/api/lookup?type=user&q=`, a proposed-facts
  response), not Drupal forms with logic in them. A mobile app becomes a
  second client of the same contract. MCP and Claude Code callers use the
  same token syntax, resolving references through lookup tools.
- **Packaging:** a new submodule (`aim_console`), not core `aim`.
- **`aim_chatbot`:** deferred (open question 2). It could later become a
  client of this API, but nothing here depends on it.

### 3. Provenance: a `batch_id` on the fact, no turn entity

Add a `batch_id` field to `aim_fact` (string or UUID), shared by every
fact produced from one turn. It supports "what else came out of that
utterance" and undoing or reviewing a whole turn. It does not affect
retrieval.

- `source` stays a short provenance tag; it is not made queryable.
- **No `aim_turn` entity, no stored turn text.** With on-the-fly
  validation (piece 4), the proposed facts, their tokens, and the
  `batch_id` are enough once reviewed. Stored raw text would matter only
  for re-extraction with a better model or auditing bad extractions.
- **Why not store it now:** the text has already reached the provider
  because AI is not yet sovereign, so local storage adds exposure risk,
  not new leakage. Revisit when a sovereign model is in use (ADR-0004).

### 4. One review pipeline with pluggable reviewers

Extraction produces proposed facts (`trusted=false`, shared `batch_id`)
shown for confirmation before they count.

- **Reviewers, all acting on the same object:** a human ("verify these
  facts", the gamified aide-memoire screen from the fact-verification
  idea), a machine reviewer (Jev or Laya as a decision provider, ADR-0021),
  or none (auto-trust, today's PoC behavior).
- **Human-only mode is required**, not optional, since Jev/Laya are not
  yet spiked.
- **This builds the ADR-0002 draft-to-trusted gate** (the lightweight
  `trusted` flag, ADR-0002's 2026-09-28 addendum). Review is what promotes
  a fact, matching ADR-0024's review-as-promotion.

### 5. Case naming and membership are separate follow-ups

Two gaps this ADR exposes but does not solve:

- **Case names:** cases are opaque IDs. A `title` on the case scope (or a
  lookup convention) is needed for the case chip's autocomplete to show
  anything useful. Small, but a prerequisite for piece 2.
- **Case scope access control (required, not optional):** who may view
  a case-scoped fact is undesigned; `AimScopeCase::checkViewAccess()`
  stays neutral. Console case chips and lookups must not expose cases the
  viewer shouldn't see, so this must be built before case chips ship.
  It needs its own ADR, and it depends on membership.
- **Case membership:** "bring John into the pub project" is structured
  data, not free text, if it drives access control. The membership model
  (who belongs to a case, and how that is stored) is the first thing the
  access-control ADR must decide.

## Open questions

1. **Console packaging:** decided 2026-09-30 - own submodule
   (`aim_console`) over a stable API contract.
2. **`aim_chatbot` future:** deferred. Not a priority against the
   console; revisit once the console API exists.
3. **Case-name lookup:** deferred (piece 5). Token grammar is settled.
4. **`batch_id` type:** decided 2026-09-30 - plain UUID string field; a
   small entity stays possible later.

## Consequences

- Extraction gets safer: it can no longer invent references, and
  `scope=entity` facts become extractable for the first time.
- Every write path needing references (form, MCP, CLI) shares one token
  contract.
- Adds one base field (`batch_id`), an update hook, and a schema/API
  change to `extractFacts()` (a break for existing callers, mitigated by
  an empty token set meaning today's behavior).
- The review pipeline finally exercises the `trusted` flag end to end, so
  `default_trusted` will be false for console-originated facts.
- Storing tokens in fact text couples text to entity lifetimes; the
  placeholder-on-delete rule is the cost of that.
- Case naming and membership remain open and block parts of piece 2.

## Related

[ADR-0001](0001-storage-and-scope-model.md),
[ADR-0002](0002-governance-deferred-guardrails-mandatory.md),
[ADR-0006](0006-agent-native-write-path.md),
[ADR-0008](0008-chatbot-integration-mechanism.md),
[ADR-0011](0011-extraction-explicit-subject-uid.md),
[ADR-0012](0012-fact-relation-graph.md) (fact-to-fact links, distinct from
`batch_id`),
[ADR-0016](0016-document-ingestion-ui.md),
[ADR-0021](0021-jev-typed-decision-provider.md),
[ADR-0024](0024-annotations-integration-target-scoped-promotion.md),
[ADR-0027](0027-entity-scope.md),
[ADR-0028](0028-scope-type-plugin.md),
[ADR-0030](0030-fact-groups.md) (a durable, cross-scope `group`,
distinct from per-turn `batch_id`; overlaps piece 5's case titles).
