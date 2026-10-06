# ADR-0047: Literal candidates (approve, don't bulk-create) and tracked gists

**Status:** Proposed 2026-10-05 - design only, nothing built. Extends
[ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md) and
[ADR-0046](0046-gist-shared-vocabulary.md); where this ADR conflicts with
ADR-0040 Addendum 3's "copy the gist from the annotation", this one wins.
**Date:** 2026-10-05

## Context

Some useful literals can be inferred from the system rather than typed in:
the login URL, the site name, the site mail. Drupal already exposes these
as tokens (`[site:login-url]`, `[site:name]`), and the `token` literal kind
resolves them at read time. Others cannot be inferred (a phone number has
no `system.phone`) and stay hand-made custom literals.

Two temptations, both rejected:

- **Pre-create a literal for every useful token.** `Token::getInfo()` lists
  hundreds. Re-creating the token system as literals adds nothing, and
  every literal costs an embedding, a menu entry in the finder's tier 1,
  and a row the chooser must weigh. Literals are an area to keep small
  (ADR-0040's finder is cheap only while pools are small).
- **Copy the gist once at creation** (ADR-0040 Addendum 3). A copied gist
  silently drifts from the thing it describes. For config-backed literals,
  tracking the source is the point.

Checked 2026-10-05 against the contrib index and the installed code of
`site_settings`, `token_custom` and `custom_token`: all three store keyed
site-wide values and expose them as tokens, and none can find a value from
a question, gate it by viewer, or carry a gist. Zero matches for
embedding/vector code in them, and none for "find by description" in
`.info.yml` files across contrib. Storage of plain site-wide text is
therefore already well served; what is unique to `literals` is **find by
question, audience-gated resolve, typed kinds, revisions, and (later) a
tracked gist**. So `literals` is positioned as a find-and-gate layer, most
literals being small pointers (`token`/`entity` kind) at values that live
elsewhere, with its own `text` kind kept only as the fallback for values
with no other home (a phone number nobody stores). No change to ADR-0040's
standalone-store decision, but the store is the minority case.

## Decision

1. **Candidates are suggestions, not entities.** A candidate is a
   (source, suggested key, suggested gist) triple held by a provider. Nothing
   is stored until a person approves it. Flow: pick a candidate, edit the
   proposed key and gist, save. Only then does a `literal` exist, so the
   literal count tracks what people actually use.
2. **A candidate provider is a small plugin** (`LiteralCandidate`, discovered
   like `LiteralKind`). Core `literals` ships two:
   - a **curated list** of a few site-level tokens (site name, URL, login
     URL, mail, slogan), declared in YAML, not as config entities;
   - a **generic token-source provider** over `Token::getInfo()`, grouped
     by token type and filtered to site-level types (`site`,
     `current-date`; not `current-user`, see 7), so a person can propose
     any token themselves.

   Not one provider per store module. A small **enricher** interface lets a
   module supply a human label and description as the gist seed, instead:
   - `site_settings`: enumerate its config types (id, label, group), not
     `getInfo()` (which loads every setting and gives generic
     descriptions). Multi-valued settings give indexed tokens, so the
     candidate is the type and the index is chosen at approval.
   - `token_custom`: enumerate its entities; its `description` field is the
     best seed of the three. It resolves to formatted HTML, so the literal
     must say whether it wants plain text (see the context-shaped output
     seam below).
   - `custom_token`: no enricher. It has no descriptions, depends on
     `webform`, and keeps values per environment in State.
   Neither store checks viewer access on resolve as far as read, so
   `LiteralAudience` stays the gate; a literal wraps their tokens and does
   not expose them directly.
3. **A gist-suggestion interface.** Given a candidate, a gist provider
   returns a suggested gist or nothing. The default provider uses the
   token's own description. That is accurate but weak for matching: it
   describes the token ("the URL of the login page"), while the finder
   matches on how people ask ("how do I sign in?"). It is only a prefill;
   the edit step is where the gist gets good. A small local model can
   rewrite it into question phrasing, a frontier model is not needed.
4. **Gist tracking, with a local override.** A literal gets a nullable
   gist source: a reference to the thing the gist came from (target plus
   annotation ID). The gist used for matching is resolved from that source
   at index time. Editing the gist on the literal sets a local override,
   which wins and stops tracking for that field. The form shows which
   state it is in ("tracking annotation X" or "local"). Two sources of
   truth, and the literal always records which it is using.
5. **Re-embed on source change.** When a tracked annotation changes, the
   bridge queues the literal onto `literals_finder`'s existing embed queue
   (`LiteralEmbedWorker`), so the stored gist embedding and model ID stay
   in step with the text.
6. **Annotations support is an optional bridge module**, `literals_annotations`.
   Core `literals` knows only the gist-provider interface and the nullable
   source field; it has no Annotations dependency (ADR-0040: neither module
   requires the other). The bridge supplies the Annotations gist provider,
   the tracking reference, and the re-queue on change. It asks Annotations
   "is there a gist for this target?" and does not assume which targets
   exist, so it keeps working as Annotations coverage grows from views and
   other tracked entities toward all config (likely an Annotations-in-core
   question, outside this repo). With no answer it falls back to decision 3.
   It waits on the Annotations `gist` field (ADR-0046).
7. **Audience stays authoritative.** `current-user` tokens resolve per
   viewer and are excluded from the picker by default. Any approved literal
   goes through the existing `LiteralAudience` rules; a candidate never
   widens who can see a value.

## Literals as tokens, and literals in facts

Exposing approved literals as tokens (`[literal:key]`, ADR-0040's form) is
a thin resolve-by-key provider over the collection, not a second token
system. It resolves to the plain string first. Output shaped by context is
deferred: a URL literal becoming a link inside CKEditor, a text literal
staying text. The kind plugin already knows its own shape, so a later
`render(context)` method on `LiteralKindInterface` is the natural seam.

The provider resolves by key only: it does not enumerate literals for
discovery (that is the finder's job), which keeps it from becoming a second
token system. A `token` kind literal whose value contains `[literal:other]`
can resolve in a cycle, so resolution carries a **loop guard**: a per-request
stack of keys being resolved and a small fixed depth limit. A key already on
the stack, or a depth over the limit, resolves to a visible gap (as for a
dangling key) and logs a warning with the key, never the value. The same
check runs in `TokenKind::validate()` at save time where a static cycle can
be detected, so the common mistake fails early and the runtime guard covers
the rest.

**Facts do not reference literals by default.** A question reaches a
literal through the finder: `aim_recall` already calls `LiteralFinder` when
the service exists (progressive enhancement, nothing stored in `aim`), and
a literal's gist is itself the label-only fact. That is the path being
built, and it covers the common case.

**Inline placeholders in fact text are allowed but niche.** Where a fact
genuinely needs a live value mid-sentence, `[literal:key]` in its text is
acceptable, with these rules: the stored and embedded text keeps the
placeholder (resolve at read, never at write or embed); resolve as the
viewing account so `LiteralAudience` applies; extraction and consolidation
must keep placeholders as written, and a candidate fact naming an unknown
key fails validation (as `TokenKind::validate()` does for unknown tokens);
a deleted or unpublished literal shows a visible gap, not an empty string.
No `aim` change is needed until a real fact needs it.

**A pointer field on `aim_fact`** (a fact carrying a reference to a
literal, value attached at recall; ADR-0039's label-only idea) is
deferred. It would cost a plugin-declared base field and cut across
ADR-0040's "`aim` stores nothing about literals". Build it only if a real
fact needs the value as part of its meaning and the finder path does not
cover it.

## Build order

1. Candidate and gist-suggestion plugin types in core `literals`, the
   nullable gist-source field, the curated list, the token picker, and the
   approve-edit-save form.
2. The `[literal:key]` token provider (plain-string resolve).
3. `literals_annotations` once Annotations ships `gist`.

Steps 1 and 2 need no AI and no Annotations.

## Alternatives rejected

- **Bulk-create literals from tokens.** Slow finder, noisy menu, duplicates
  the token system.
- **Copy the gist at approval and never track.** Simpler, but a config-
  backed literal's match text rots as its annotation is edited, which
  defeats annotating the config.
- **Always reference the annotation, no override.** Removes the person's
  ability to tune a gist for matching, and couples every literal to a
  module that may not be installed.
- **Frontier-model gist generation by default.** Unneeded; a prefill plus a
  human edit, or a small local model, is enough, and sovereignty
  ([ADR-0004](resolved/0004-sovereignty-and-poc-build-order.md)) argues
  against a hosted call per candidate.

## Consequences

- A literal's gist now has provenance (tracked source or local override),
  which the form, the finder's re-embed path and any audit must handle.
- The literal count stays small by construction; suggestion has no runtime
  cost until approval.
- Annotation edits can change what the finder matches. That is intended,
  but means a gist change should show in the finder's evals (TESTS.md,
  "Literals") the same as a hand edit.
- Core `literals` gains two plugin types and one nullable field; the
  Annotations coupling is confined to the bridge.

## Open questions

- Does the `text` kind stay in core `literals` as the fallback for values
  with no other home, or move to a submodule so core is pointer kinds
  only? Leaning: stay, so a site needs no second module to start.
- Can Annotations attach to config entities such as `system.site`, and by
  what target ID? The bridge's coverage depends on it.
- Placeholder syntax: `[literal:key]` (ADR-0040) or a shorter `@key` form
  for use outside the token system. The latter needs an input filter.
- Should re-embedding on an annotation change be immediate or batched, given
  the embed queue already exists and a hosted embedder costs per call?
- When a tracked annotation is deleted, does the literal keep the last
  resolved gist as a local override, or flag itself for review?
