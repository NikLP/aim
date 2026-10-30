# ADR-0029: Context-carrying turns - mention tokens, an API-first console, and one review pipeline

**Status:** Parked 2026-10-30 (accepted 2026-09-30) - design only,
nothing built. Trimmed 2026-10-30 to what is still unbuilt: mention
tokens (piece 1), the API-first console (piece 2) and `batch_id`
(piece 3). Pieces 4 and 5 were delivered or moved elsewhere (notes in
place). Revisit when a console or another reference-carrying client is
wanted.
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
- **No `aim_turn` entity.** (Turn text is stored after all, in `ai_log`,
  since [ADR-0060](resolved/0060-adopt-ai-logging-for-model-calls.md).) With on-the-fly
  validation (piece 4), the proposed facts, their tokens, and the
  `batch_id` are enough once reviewed. Stored raw text would matter only
  for re-extraction with a better model or auditing bad extractions.

### 4. Review pipeline: delivered elsewhere

Removed 2026-10-30. A turn's facts start untrusted and are promoted by a
human or a decision model through the `trusted` flag and per-channel
starting trust ([ADR-0002](resolved/0002-governance-deferred-guardrails-mandatory.md)'s
addendum, [ADR-0037](resolved/0037-transient-source-passages-for-grounding.md)'s
addenda); the console uses that, with `batch_id` as the per-turn handle.

### 5. Case naming and access: moved

Removed 2026-10-30. Case names were built 2026-10-29 (the case plugin
takes a name as the case ID); the subject constraint is in TODO.md "Case
scope". Case-scope view access and membership are in BACKLOG.md "Case
scope access control"; that work must land before console case chips.

## Open questions

1. **Console packaging:** decided 2026-09-30 - own submodule
   (`aim_console`) over a stable API contract.
2. **`aim_chatbot` future:** deferred. Not a priority against the
   console; revisit once the console API exists.
3. **Case-name lookup:** case names exist (2026-10-29); a lookup for chips is still to design. Token grammar is settled.
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
- Console writes need their own trust channel (ADR-0037's addenda), most
  likely starting untrusted.
- Storing tokens in fact text couples text to entity lifetimes; the
  placeholder-on-delete rule is the cost of that.
- Case access control and membership remain open and block console case chips.

## Related

[ADR-0001](resolved/0001-storage-and-scope-model.md),
[ADR-0002](resolved/0002-governance-deferred-guardrails-mandatory.md),
[ADR-0006](resolved/0006-agent-native-write-path.md),
[ADR-0008](resolved/0008-chatbot-integration-mechanism.md),
[ADR-0011](resolved/0011-extraction-explicit-subject-uid.md),
[ADR-0012](resolved/0012-fact-relation-graph.md) (fact-to-fact links, distinct from
`batch_id`),
[ADR-0016](0016-document-ingestion-ui.md),
[ADR-0021](0021-jev-typed-decision-provider.md),
[ADR-0024](0024-annotations-integration-target-scoped-promotion.md),
[ADR-0027](resolved/0027-entity-scope.md),
[ADR-0028](resolved/0028-scope-type-plugin.md),
[ADR-0030](resolved/0030-fact-groups.md) (a durable, cross-scope `group`,
distinct from per-turn `batch_id`; overlaps piece 5's case titles).
