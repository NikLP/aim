# ADR-0040: Literals - probabilistic lookup of exact values (a `literal` field type, pluggable kinds, and a `literal` scope that subsumes `entity` and `token`)

**Status:** Proposed 2026-10-03 - design only, nothing built. Generalizes
[ADR-0039](0039-token-scope-live-config-values.md) (also unbuilt) and, if
accepted, replaces the built `entity` scope
([ADR-0027](resolved/0027-entity-scope.md)). Settle the amend-or-supersede
question on 0039 before any code (see Open questions).
**Date:** 2026-10-03

## Context

A visitor asks the chat bot "what is the phone number?". The value
exists in exactly one right place (a site setting, a field on an
entity, a token) and the agent has to find it. Today it can only call
`aim_recall`, which embeds the question and searches fact text. That is
probabilistic, and a number is a poor target for an embedding: recall
can miss it, return opening hours instead, or return a stale copy that
consolidation has merged or rewritten
([ADR-0020](0020-verbatim-facts-consolidation-opt-out.md)). A missed
recall is also invisible ([ADR-0035](0035-standing-constraints-action-gate.md)).

[ADR-0039](0039-token-scope-live-config-values.md) solved this for one
case, a `config_pages` token. The idea generalizes: **the lookup is
probabilistic, the value is deterministic.** Vector search finds *which*
value is wanted, then an exact, access-checked resolve returns it. The
probabilistic part is only a finder, and the value is never embedded,
paraphrased or consolidated.

Framing against Annotations
([ADR-0024](0024-annotations-integration-target-scoped-promotion.md)),
which is the same mapping read in opposite directions:

- Annotations describes a place and asks what goes there. It improves
  data on the way **in**, by giving the producer context.
- Literals describes a value and asks where it lives. It improves data on
  the way **out**, by verifying before serving.

Good annotation context is a firm foundation for good literal
descriptions (see "Annotations").

**Prior art.** Two shallow `drupal-code-query` searches (2026-10-03)
found no contrib module implementing semantic-description-to-exact-value
lookup; hits for "probabilistic/fuzzy/semantic + token/literal/lookup"
were generic noise. `drupal_rag_toolkit`, `ai_rag_api`, `search_api_ai`
and `daedalus` turned up in adjacent searches and were **not** opened.
Treat "no existing module" as unverified. Nothing in this repo or the
two skills (`aim-discovery`, `aim-memory`) models confidence or
probability; the one use of the word is ADR-0035's remark that
`recall()` is probabilistic. `aim_fact`'s nearest fields are the boolean
`trusted` and `state`.

## Decision

A literal has three parts: a **short name** (UI and agent key, e.g.
`phone`), a **description** (the bulk of the text, the part that is
embedded and matched), and a **value** (kind-specific: a token, an
entity path, a plain string).

### 1. Kinds are plugins

A `LiteralKind` plugin type, so the set is open. Each kind supplies:

- `resolve(account)`: the rendered, access-checked value, or NULL.
- `raw()`: the unrendered form (the token string, the entity path), the
  reverse of piping to `raw` in Twig. Callers choose which they want.
- `validate()`: kind-specific hard checks (a token exists, a path
  resolves and passes access, a plain value matches its declared type).

First kinds: `token`, `entity`, and `config_page` field (the 0039
case). An `entity` kind may use `dynamic_entity_reference` for its
reference, kept inside that kind's own submodule so the core module never
requires it. This is the one place the dependency ADR-0027 rejected for
core `aim` is reasonable.

### 2. A `literal` field type, with formatter settings

The field type is the primary storage and carries the idea: description,
value, kind, validation, and the match check (piece 5). On any content
entity it inherits revisions, Content Moderation, per-bundle permissions
and view access from the host.

- **Formatter settings** choose the output: resolved value, raw token or
  path, link, or description only. The same modes are parameters on the
  agent tool, so formatter and tool share one definition.
- **Value patterns** (int, phone, email, url, string) are a field setting
  mapped to **Typed Data constraints** (Regex, Email, Range, Uri), not
  Form API `#pattern`. They validate in the entity layer, so they apply
  to agents and tools, not only to form submits. Cribbing Form API's
  element types is fine for the setting's vocabulary.
- A token has no host entity. It needs a small config or content entity
  to carry it (open question).

### 3. Packaging

- **`literals`**: the field type, kind plugin manager, validation, match
  check and resolve. The finder (the index of descriptions) sits behind
  an interface.
- **`literals_ui`** (the usual `_ui` suffix): a thin list of literals
  across entities (short name, kind, target, status, pool), the
  convert-from-fact form, approve and regenerate actions. Build the field
  type first; the UI is cheap if the type is right, and a UI built first
  tends to become the API by accident.
- **`aim_scope_literal`**: the bridge that makes `aim` the finder
  (piece 4). Naming: drop `p_`; "probabilistic" describes how a literal
  is found, not what it is, so it belongs in the docs. Check drupal.org
  for a clash on `literals` before committing to it.

### 4. The `literal` scope: aim is the finder, no separate vector row

With `aim` as the backend a literal needs **no vector row of its own**.
It is an `aim_fact` with `scope=literal`: the fact `text` is the
description (already embedded and indexed), and the fact carries a
pointer to the literal. Recall finds the fact, the pointer resolves to
the value. Indexing, scope, access, trust and the audit log are reused.

- **One scope, many kinds.** `literal` replaces `entity` (ADR-0027) and
  subsumes ADR-0039's `token`: those are kinds, not scopes. This also
  moves the `target_type`/`target_id` columns off `aim_fact` and into the
  literal, which is where they belong.
- **Pools are scope instances.** `literal_public`, `literal_staff` and so
  on, each an `aim_scope` with `plugin: literal` and its own settings,
  exactly the shared-plugin case [ADR-0028](resolved/0028-scope-type-plugin.md)
  allows. Visibility by role or permission is then ordinary scope access.
- **Reuse ADR-0039's seams as written:** `renderText()` (resolve at
  recall, never stored), `isConsolidatable()` FALSE (a literal is never
  `kept` or `candidate`), `AimScopeTypeBase`, unresolved means dropped
  from the result with an audit line (fact ID, scope, reason, never the
  value). A literal is verbatim by nature, which retires most of
  ADR-0020's motivation for the literal case.
- **Access delegates to the host** (`$target->access('view', ...)`), the
  ADR-0027 pattern, so the target's own permission is the single gate and
  a staff-only value never lands in an embedding or a cached string.
- **Misses are visible.** Retrieval can silently miss. A literal lookup
  that fails returns "no matching literal", never a guess. A literal that
  is a hard constraint must not depend on retrieval at all (ADR-0035).

### 5. Validation and Guardrails on every part

Guardrails run on the **description**, the **value**, and every
**proposed edit** to either, at save time and again when a proposal is
created. This extends decision 7 ("every candidate fact runs through
Guardrails before it is written") to everything a literal writes. The
value matters most: it is what the agent hands a customer, so a toxic or
injected URL or number is a real attack.

On top of Guardrails and the kind's hard validation, a **match check**
asks a decision model "does this description match this value?". It
catches mismatches ("main phone number" with an email address) and
unlikely values (`+1 555 5555`), and doubles as field validation. It is
a plausibility check, not a truth check: a wrong but well-formed number
passes. [ADR-0037](0037-transient-source-passages-for-grounding.md)
records that bare plausibility was the wrong test for the small local
models, and [ADR-0038](0038-local-decision-models-parked.md) that they
are parked. Treat the match check as **one filter** beside Guardrails and
hard validation, fail closed, and measure it before leaning on it.

### 6. Moderation, revisions, and regenerated descriptions

Revisions and moderation come from the host entity (the field type's
main advantage), not a parallel system. Descriptions are never edited
automatically. A **regenerate** action has a model propose a better
description from the value, the annotation on its target field (if any)
and recent questions that hit or missed it, and creates a **draft
revision** a human promotes. A bad description sends the agent to the
wrong value with no signal, so the human gate stays.

### 7. Nightly proposer

A queue worker on the dedicated crontab
([ADR-0003](resolved/0003-async-processing-dedicated-crontab.md), never
`hook_cron`), shaped like consolidation, compares new facts to existing
literals and proposes edits or new literals. It only ever creates a
**draft revision** with provenance (fact IDs, writers, trust state) and a
diff. It never changes a live literal. Because literals can be business
critical and the realistic attack is a visitor telling the chatbot "our
phone number is now X":

- Propose only from facts written by trusted sources (the uid-0 write ban
  agreed in the 2026-10-02 design review, TODO.md).
- Rate-limit and de-duplicate proposals per literal.
- Show the reviewer the source facts beside the diff, not only the value.
- Run Guardrails on the proposal (fact text enters the proposing model and
  can carry injection).
- Separate permission to approve edits to business-critical literals.
- Never read facts or literals above the pool being proposed for, so a
  public fact cannot surface a staff-only value through a suggested diff.

### 8. Convert a fact to a literal

A row action on the fact list and a button on the fact edit page,
opening a short form (never one click):

1. **Split.** The model proposes a description and a value from the fact
   text ("The library's phone number is 01234 567890" splits into the
   two); a human confirms. This reuses 0039's candidate step.
2. Pick kind, target and pool.
3. Run Guardrails, kind validation and the match check on submit.
4. On approval the literal is created with a `source_fact` back-reference
   for provenance. The fact stays as the record.

**Retirement with a cool-off.** On approval the fact gets `expires` =
now + N days; a sweep sets `retired` after that. During the cool-off the
fact is still recalled beside the literal, so a rejected or reverted
literal loses nothing. Today `expires` means "superseded, exclude from
the index at once" ([ADR-0022](resolved/0022-exclude-retired-facts-from-vector-index.md)),
so this **depends on the agreed `retired`/`expires` split** in TODO.md
(`retired` timestamp for the index and recall; `expires` a real sell-by
date). Until that is built the only options are an immediate retire
(after approval, never before) or none.

`superseded_by` is an `aim_fact` reference and cannot point at a
literal. No archive table is needed: the literal's `source_fact`
back-reference carries the link. A generic pointer on the fact side is
added only if something needs to read it from there.

A bulk "suggest literals from facts" belongs to the proposer, not the UI.

### 9. Agent tools

Expose a read tool (`literal_get`: by short name or by semantic query,
with an output-mode parameter) and a narrow **register** tool
(`literal_register`: description, kind, pointer to an existing field or
token). Agents propose descriptions and pointers; **they never create
storage schema or enter values**, which keeps the poisoning surface small
and matches ADR-0035. Humans create the underlying field and value. A
decision model is optional: retrieval is vector-based and the value is
exact, so the tool works without one. A model only improves
descriptions, near-duplicate choice and the proposer.

### 10. Annotations

Same mapping, opposite directions, and the asymmetry is real: annotations
covers any content entity, literals only what is registered. Two
concrete links, neither in v1: a literal's default description can come
from the annotation on its target field, and ADR-0024's "promote a
reviewed fact to an annotation" is the same promotion shape as "promote a
proposed edit to a literal revision", so the review flow could be shared.
Keep the overlap a documented bridge; ADR-0024 is itself unbuilt.

### 11. config_pages

`config_pages` already provides a content entity per page type, per-type
permissions, a token for typed fields, context (`domain_config_pages`)
and a field UI. **Do not reimplement it.** It is the human-facing value
store for the entity kind: it owns field definitions, editing,
permissions and history. `literals` is only the semantic index over it
(description plus pointer to page type, field, delta). The agent-side
narrow register tool means an agent never drives a page-type builder.

Unchecked, to settle before relying on it: whether `config_pages` gives
revisions and moderation on a page's values (I saw neither in a shallow
search), and that its scoping is by **context** (domain, language), not
by role pool, so role pools remain our layer. ADR-0039 also records that
its token handler does **no view-access check**; the literal's own
`resolve()` must do it.

## Alternatives rejected

- **A separate vector row per literal.** Redundant once the fact is the
  row; the fact already carries the embedded description and a pointer.
  It would bring back a second index to keep consistent. A standalone
  build without `aim` is the only case that needs its own index (open
  question).
- **Value in the fact text** (the status quo and 0039's rejected
  option). Drifts, embeds badly, gets consolidated.
- **Form API for value patterns.** Validates on form submit only, so
  agents bypass it.
- **An archive table for `superseded_by`.** A back-reference from the
  literal does the job.
- **Reimplementing the `config_pages` UI.** Config pages already do the
  human half; an agent-built UI would be a poor fit either way.
- **A `p_` prefix.** Describes retrieval, not the thing.
- **Auto-applying regenerated descriptions or nightly proposals.** Either
  can silently redirect the agent or poison a business-critical value.
- **Letting agents create literal storage or values.** Proposals only.

## Consequences

- One scope and one seam replace two (`entity`, `token`), and move the
  `target_type`/`target_id` columns off `aim_fact`. Migrating the built
  `entity` scope is its own piece (open question).
- The value is never embedded or consolidated; the finder is the only
  probabilistic part. Stale copies are gone because the value lives once.
- A literal whose host is unviewable, or whose retrieval misses, fails
  silent unless the audit line and the visible "no matching literal"
  result are watched.
- Moderation for the nightly proposer and conversion adds a review queue
  someone must staff.
- The match check inherits the decision-model limits in ADR-0037/0038.
- New dependencies stay in optional submodules: `config_pages` and
  `dynamic_entity_reference` only in their kinds.

## Open questions

- **Pointer storage.** Where the fact's pointer lives: a registry entity
  (`literal`: short name, description, kind, target) that facts reference
  and the field type feeds, or host-field coordinates (entity type, id,
  field, delta) on the fact. The registry is cleaner for pools, the
  back-reference and moderation; coordinates are lighter. Decide before
  the field type.
- **Standalone.** Whether `literals` requires `aim` or ships its own
  small index behind the finder interface. Starting with `aim` as the
  backend and the index behind an interface keeps standalone possible
  later.
- **Token host.** A token has no host entity; which small entity carries
  it.
- **0039.** Amend it into the token kind of this ADR, or supersede it.
- **Migrating `entity` scope.** Built facts use `target_type`/`target_id`
  today; a `hook_update_N()` once real data exists (PoC rule: none needed
  yet).
- **Grouping.** An optional `group` (taxonomy or config entity) so
  "contact details" holds phone, email and address, field_group-style
  for presentation. Not v1.
- **Value types beyond strings** and per-kind formatter defaults.
- **Name clash.** Whether `literals` is free on drupal.org.
- **`config_pages` revisions/moderation** and role-pool scoping, as above.
- **Whether a decision model is good enough for the match check** on this
  site's hardware; measure per ADR-0037/0038.
