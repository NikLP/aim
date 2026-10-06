# ADR-0040: Literals - a `literal` field type with an optional gist, found by vector shortlist and a Jev choice

**Status:** Proposed 2026-10-03, revised 2026-10-04 and 2026-10-05 - Phase 1
(entity, types, kinds, audience access, list UI) built; the finder, tokens and
tool are not. Generalizes [ADR-0039](0039-token-scope-live-config-values.md)
(also unbuilt) and, if accepted, replaces the built `entity` scope
([ADR-0027](resolved/0027-entity-scope.md)). Settle the amend-or-supersede
question on 0039 before any code (see Open questions). **The
seven addenda at the end (2026-10-04 and 2026-10-05) narrow the direction
(the second reverses the first on the value's home, the third on
Annotations as the host, the fourth simplifies the finder and drops alias
machinery, the fifth removes the `aim` mirror and `aim_scope_literal` and
adds the build plan, the sixth fixes terms and replaces the vector index
with a stored-embedding gate, the seventh replaces pools with types, kinds
and an audience); where they conflict with pieces 1 to 12
above, the later addendum wins.**
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
case, a `config_pages` token. The idea generalizes: **the value is exact,
the address is probabilistic.** A person (or agent) describes what they
want in words; the system works out which exact value that means, then
returns it through an access-checked resolve. The value is never embedded,
paraphrased or consolidated.

Two things were settled in discussion on 2026-10-04 and shape this
revision:

1. **The match comes from a typed decision model, not from the vector
   score.** Vector search only builds a shortlist. A decision model (Jev,
   [ADR-0021](0021-jev-typed-decision-provider.md)) picks from it, or says
   "none" or "ambiguous". Jev cannot generate text, so it can choose among
   IDs it is given but never invent a value, and it cannot write
   descriptions (a chat model does that, with a human approving).
2. **The semantic part is optional, and the field type is the right home
   for it.** A literal is first a plain key and value. The gist (the
   natural-language description of what the value is) augments it, and
   with the gist on, any entity carrying a literal field can be searched by
   meaning. That works without `aim`: no facts, extraction, consolidation
   or queue, only an index of gists and a chooser.

**Why vector search alone is not enough.** Even a well-written gist
competes with its neighbours (reservations phone, main switchboard, fax),
and top-k crowding and the distance cutoff (`recall_max_distance`) can both
drop the right literal. A vector score cannot say "these two are
equivalent". Literals are a small, closed, human-vetted set, which is the
easy case for a classifier: choosing one option from a short menu.

**Framing against Annotations**
([ADR-0024](0024-annotations-integration-target-scoped-promotion.md)),
which is the same mapping read in opposite directions:

- Annotations describes a place and asks what goes there. It improves
  data on the way **in**, by giving the producer context.
- Literals describes a value and asks where it lives. It improves data on
  the way **out**, by verifying before serving.

Shorter: **Annotations is to fields what literals are to values.** Good
annotation context is a firm foundation for good gists (piece 10).

**Prior art.** Two shallow `drupal-code-query` searches (2026-10-03)
found no contrib module implementing semantic-description-to-exact-value
lookup; web searches on 2026-10-03 found no CMS or headless CMS with a
field type that carries a confidence or distribution. `drupal_rag_toolkit`,
`ai_rag_api`, `search_api_ai` and `daedalus` turned up in adjacent
searches and were **not** opened. Treat "no existing module" as
unverified. The nearest research is **attribute-level uncertainty** in
probabilistic databases (for example
[Orion](https://orion.cs.purdue.edu/docs/model.pdf)), where a cell holds a
distribution and the uncertainty is in the value. Here it is inverted: the
value is exact and the uncertainty is in how it is addressed. Nothing in
this repo or the two skills (`aim-discovery`, `aim-memory`) models
confidence; `aim_fact`'s nearest fields are the boolean `trusted` and
`state`.

## Decision

A literal has up to four parts: a **key** (short name, the UI and agent
handle, e.g. `phone`), a **value**, an optional **gist** (the text
describing what the value is, and the part that is embedded and matched),
and optional **aliases** (approved alternate phrasings, piece 8). The
judgement lives in the gist's wording, in prose; there is no numeric
confidence field on the literal.

### 1. A `literal` field type (the primary storage)

On any content entity the field inherits revisions, Content Moderation,
per-bundle permissions and view access from the host, and works with
Views, Token and Layout Builder like any field.

- **Basic mode (gist off):** key and value only. A tidy typed
  setting: the value is validated and exposed through the Token API
  (`[node:field_x:phone]`-style tokens, which need the contrib Token
  module for custom field types), so a plain site gets a labelled
  settings value with no AI involved.
- **Semantic mode (gist on):** a field setting adds the gist (and
  aliases). The gist is what a Search API index embeds. Turning it on
  never changes the value or its tokens. The gist is resolved in order:
  the item's own gist property (instance-specific), then an annotation on
  the field's target if one is wired in (piece 10), then the field label.
  The index embeds whichever resolves, so a literal works with or without
  Annotations and the finder sees the same thing either way.
- **Value patterns** (int, phone, email, url, string) are a field setting
  mapped to **Typed Data constraints** (Regex, Email, Range, Uri), not
  Form API `#pattern`. They validate in the entity layer, so they apply
  to agents and tools, not only to form submits.
- **Formatter settings** choose the output: resolved value, key and value,
  link, or gist only. The same modes are parameters on the agent tool, so
  formatter and tool share one definition.
- **Live widget probe.** The field's settings form and edit widget carry a
  "ask a question" box that runs the real lookup (pieces 3 and 4) and shows
  which literal it resolves to and why. Writers see a bad gist before a
  visitor does.
- **Value kinds (optional, v2).** A literal's value may instead be a
  live pointer (a token, or an entity-field path) resolved at read time,
  via a small `LiteralKind` plugin with `resolve(account)`, `raw()` and
  `validate()`. The plain string is the default kind. This is where
  ADR-0039's `config_pages` token case lives; it may use
  `dynamic_entity_reference` inside that kind's own submodule so the core
  module never requires it.
  An entity-field kind is **static**: entity type, ID, and a Typed Data
  path to the value (`field_num.0.value`, the path core already defines,
  rather than an invented bracket grammar). A **route kind** points at an
  internal route (route name and parameters) or a path, and resolves to a
  URL after a route access check; `raw()` is the path. Together with a
  plain URL string this covers "latest news" as a designated pointer to a
  news page, with no query involved. Authoring uses existing
  pickers, not a custom one: core `entity_autocomplete` for the entity and
  the Token module's token browser for tokens. `cshs` is built around
  taxonomy term hierarchies and is not a fit.
- **Computed ("latest X") literals are out of v1.** "The most recent news
  item" is a different thing from a designated value: its identity is a
  rule, not a human-vetted single value, so there is no fixed value for
  the match check to vet and no single gist that stays true. It behaves
  like a frequent query whose useful answer is a pointer (an entity or a
  page) more than a value. If built, it is its own kind: a declarative
  entity query (type, bundle, conditions, sort, limit 1) run with access
  checks on every candidate and the list cache tags of what it queries,
  human-authored, returning the entity reference alongside the value.
  Views is rejected as the mechanism (too heavy for one scoped, checked
  lookup).
- **A host is always a content entity.** `config_pages` page types are
  content entities, so a literal field on one gives a labelled settings
  page with gists, with no reimplementation (piece 11). A token with no
  natural host needs a small config or content entity to carry it (open
  question).

### 2. Packaging

- **`literals`**: standalone. The field type, kind plugin manager,
  validation, the gist index (a Search API index over the gist property,
  embeddings via `ai_search`), the `LiteralFinder` and `LiteralChooser`
  services, and the match check. Requires `search_api` and `ai_search`
  (and a vector DB provider). A decision provider is **optional**: without
  one the finder returns the top hit only when it clears a margin over the
  second, otherwise "ambiguous" with the candidates, never a guess.
- **`literals_ui`** (the usual `_ui` suffix): a thin list of literals
  across entities (key, host, status, pool), the convert-from-fact form,
  the review queue and approve actions. Build the field type first; a UI
  built first tends to become the API by accident.
- **`aim_scope_literal`**: the optional bridge for sites that already run
  `aim` (piece 6). It is an alternative finder backend, not the only one.
- Drop the `p_` prefix idea: "probabilistic" describes how a literal is
  found, not what it is, so it belongs in the docs. Keep "gist" for the
  semantic text. Check drupal.org for a clash on `literals` before
  committing to the name.

### 3. The finder: vector shortlist, then a Jev choice

Two services behind interfaces, so backends swap.

1. **`LiteralFinder::shortlist($query, $account)`** returns up to k
   candidate literals. The standalone backend queries the Search API
   gist index. **It drops every candidate the caller cannot view before
   anything else sees it** (`$host->access('view', $account)`), so
   neither the chooser nor the agent ever reads a description or value
   above the caller's access, and the tie list in piece 4 cannot leak.
   A small pool skips the vector step entirely and shortlists everything
   (k = pool size); the vector step is a pre-filter for large pools only.
2. **`LiteralChooser::choose($query, $candidates)`** asks the decision
   model one **Choice** question: options are the candidate IDs plus
   `none`, state is the query and each candidate's key and gist. Jev's
   Choice type returns per-option probabilities and a confidence
   ([ADR-0021](0021-jev-typed-decision-provider.md)), which is the
   ambiguity signal natively. The result is one of: `match` (one option
   clearly ahead), `ambiguous` (two or more close), or `none`.

The vector layer sets a ceiling: the right literal must be in the
shortlist, so use a generous k and measure recall@k per site. The
chooser's accuracy is separate and also measured (piece 5). The distance
cutoff stays as a coarse sanity floor, not the selector.

### 4. What the tool returns, and misses are visible

`literal_get` (piece 9) returns exactly one of:

- `match`: the key, the resolved value (access-checked at resolve), and
  the mode requested.
- `ambiguous`: the tied candidates (key, gist, access-checked value).
  There is no conversation inside the tool, so it does **not** try to
  disambiguate itself. The chat agent, which has the conversation, either
  shows both or asks the visitor; MCP and Drush callers get the same list.
- `none`: "no matching literal", never a guess.

A literal that is a hard constraint must not depend on retrieval at all
(ADR-0035). Every outcome writes an audit line (see Logging below).
Unresolved or unviewable values drop out with an audit line (literal ID,
reason, never the value or the query text).

### 5. Guardrails and write-time checks on every part

Guardrails run on the **gist**, the **value**, the **aliases** and every
**proposed edit** to any of them, at save time and again when a proposal is
created. This extends decision 7 ("every candidate fact runs through
Guardrails before it is written") to everything a literal writes. The value
matters most: it is what the agent hands a customer, so a toxic or injected
URL or number is a real attack.

Beyond Guardrails and the kind's hard validation, three typed decision
checks run at save time (Jev, fail closed, none on the query path):

- **One-intent check.** "Does this gist name more than one distinct value
  or question?" A gist like "phone number for reservations, open office
  hours" is rejected. Conflation is the failure that makes lookup
  unreliable, so it is blocked at write, not tolerated at read.
- **Neighbour check.** Embed the new gist, pull literals in the same pool
  within a distance threshold, and ask whether a visitor question could be
  answered by one and not the other. If the two cannot be told apart, the
  save is blocked or flagged for the reviewer.
- **Match check.** "Does this gist match this value?" It catches
  mismatches ("main phone number" with an email address) and unlikely
  values (`+1 555 5555`). It is a plausibility check, not a truth check: a
  wrong but well-formed number passes.
  [ADR-0037](0037-transient-source-passages-for-grounding.md) records that
  bare plausibility was the wrong test for the small local models, and
  [ADR-0038](0038-local-decision-models-parked.md) that they are parked.
  Treat it as **one filter** beside Guardrails and hard validation, and
  measure it before leaning on it.

**Hosted-model caution.** CLAUDE.md allows hosted Jev for consolidation
and the verifier on synthetic or demo data only. Descriptions and values
sent to the chooser and these checks are real business data on a live
site, so a production literal site needs a local decision model or a
data-handling decision first. Until then, demo data only.

### 6. The `aim_scope_literal` bridge

For a site already running `aim`, `literal` is a scope and `aim` can be
the finder: a literal's gist is mirrored into an `aim_fact` with
`scope=literal` (the fact `text` is the gist, already embedded and
indexed; the fact carries the host-field coordinates, entity type, id,
field and delta). Recall finds the fact, the chooser picks, the pointer
resolves to the value. Indexing, scope, access, trust and the audit log
are reused, and no second vector row is kept. A standalone site uses
the Search API backend instead; both sit behind `LiteralFinder`.

- **One scope, many kinds.** `literal` replaces `entity` (ADR-0027) and
  subsumes ADR-0039's `token`: those are kinds, not scopes. This also
  moves the `target_type`/`target_id` columns off `aim_fact` and onto the
  literal pointer, where they belong.
- **Pools are scope instances** (`literal_public`, `literal_staff`), each
  an `aim_scope` with `plugin: literal` and its own settings, the
  shared-plugin case [ADR-0028](resolved/0028-scope-type-plugin.md) allows.
  Standalone sites get pools from host bundle and permissions instead.
- **Reuse ADR-0039's seams as written:** `renderText()` (resolve at
  recall, never stored), `isConsolidatable()` FALSE (a literal is never
  `kept` or `candidate`), `AimScopeTypeBase`. A literal is verbatim by
  nature, which retires most of ADR-0020's motivation for the literal
  case.
- **Access delegates to the host** (`$target->access('view', ...)`), the
  ADR-0027 pattern, so the host's own permission is the single gate and a
  staff-only value never lands in an embedding or a cached string.
- **Host-field coordinates, not a registry entity.** The field type is the
  record, so a separate registry entity is no longer needed .

### 7. Moderation, revisions, and proposed edits

Revisions and moderation come from the host entity (the field type's main
advantage), not a parallel system. A gist is never edited automatically.
A **regenerate** action has a **chat model** (Jev cannot write text)
propose a better gist from the value, the annotation on its target field
(if any) and recent questions that hit or missed it, and creates a
**draft revision** a human promotes. A bad gist sends the agent to the
wrong value with no signal, so the human gate stays.

A nightly proposer (a queue worker on the dedicated crontab,
[ADR-0003](resolved/0003-async-processing-dedicated-crontab.md), never
`hook_cron`), shaped like consolidation, compares new facts to existing
literals and proposes edits or new literals. It only ever creates a
**draft revision** with provenance (fact IDs, writers, trust state) and a
diff, and never changes a live literal. Because literals can be business
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

### 8. Aliases from observed misses (the data story)

Aliases keep the gist short and readable and fix misses with real data,
not speculative keyword stuffing. The loop, in order:

1. A visitor asks something.
2. The lookup logs one outcome: `match`, `none` or `ambiguous`.
3. A review list shows the `none` and `ambiguous` questions.
4. A human points each at the literal it should have found.
5. A chat model proposes an alias phrasing; the human approves it.
6. The approved alias is stored on the literal and indexed with the gist,
   so that question now matches.

Limits: wrong matches stay invisible (step 2 only sees misses), so add a
thumbs-down or staff spot-check where that matters. And **query text is
personal data**: CLAUDE.md bars logging it except behind the opt-in
`log_query_text`, so this loop needs that setting on, scoped to the
lookup tool, with the usual retention discipline. Aliases are limited in
number per literal and one-intent each (the write-time checks apply).

### 9. Agent tools

Expose a read tool (`literal_get`: by key or by semantic query, with an
output-mode parameter, returning the three outcomes in piece 4) and a
narrow **register** tool (`literal_register`: gist, kind, pointer to an
existing field or token). Agents propose gists and pointers; **they never
create storage schema or enter values**, which keeps the poisoning surface
small and matches ADR-0035. Humans create the underlying field and value.
A decision model is optional for the tool to run (see piece 2's fallback)
and is what makes it reliable.

Build the logic as typed service methods (`LiteralFinder`/`LiteralChooser`)
returning an explicit value object, with the tool plugin a thin wrapper, so
it can adopt upstream's method-attribute tooling if that lands
([ADR-0044](0044-tool-api-method-attributes.md)).

### 10. Annotations

Same mapping, opposite directions, and the asymmetry is real: annotations
covers any content entity, literals only what carries a literal field.

**The gist is an annotation of the value's field.** An `annotation` is a
`(target_id, field_name, type)` row with a plain-text `value`
(`web/modules/contrib/annotations`, checked 2026-10-04). That is the same
job as a gist: text saying what a field's content is for. On a singleton
host such as a `config_pages` page, the annotation on the phone field
**is** the literal's gist, so the literal needs no gist text of its own.

**The bridge.** An optional submodule supplies the annotation as the
gist (piece 1's second step) and carries the review and regenerate flow
through annotation revisions. It lives in whichever package is the
optional dependent; Annotations is the same author's module, so
`annotations_literals` (or the `aim` side, per ADR-0024's bridge) is the
likely home. Not in v1. What it reuses from Annotations:

- **Target discovery as a picker only.** The `#[AnnotationsTarget]`
  plugins list which entity types, bundles and fields exist
  (`GenericTarget` derives one per fieldable type) and the target's
  `fields` map is an inclusion list. That supplies the entity, bundle and
  field picker for registering a literal, so `literals_ui` need not build
  its own. It does **not** make those entities literals: reading the
  value is still a literal kind (one generic `entity_field` kind covers
  fieldable entities), and non-fieldable targets (roles, views, menus,
  workflows) have no field value to read.
- Per-type edit and consume permissions, revisions, the editing UI, the
  in-context overlays, and `annotations_read` (Tool API) / MCP.

**The limit: granularity.** Annotations attach to a bundle and field,
never an entity instance (ADR-0024). That fits singletons (config pages,
site settings) and breaks for a field with a different value per node: a
"branch phone" annotation cannot say which branch. Per-instance literals
therefore keep their own gist property; the annotation is the fallback.

ADR-0024's "promote a reviewed fact to an annotation" is the same shape
as "promote a proposed edit to a literal revision", so the review flow
could be shared. ADR-0024 is itself unbuilt, and Annotations is
`^2.0@alpha` with no uniqueness enforcement on `(target, field, type)`;
keep the coupling in the optional bridge.

### 11. config_pages

`config_pages` already provides a content entity per page type, per-type
permissions, a token for typed fields, context (`domain_config_pages`)
and a field UI. **Do not reimplement it.** A `literal` field on a
`config_pages` page type is the settings page with a gist: `config_pages`
owns the editing, permissions and history, and the literal field adds the
semantic address. A site that wants only labelled settings uses basic mode
and never touches the index.

Unchecked, to settle before relying on it: whether `config_pages` gives
revisions and moderation on a page's values (I saw neither in a shallow
search), and that its scoping is by **context** (domain, language), not
by role pool, so role pools remain our layer. ADR-0039 also records that
its token handler does **no view-access check**; the literal's own
`resolve()` must do it.

### 12. Convert a fact to a literal

A row action on the fact list and a button on the fact edit page,
opening a short form (never one click):

1. **Split.** A chat model proposes a gist and a value from the fact text
   ("The library's phone number is 01234 567890" splits into the two); a
   human confirms. This reuses 0039's candidate step.
2. Pick the host entity and field (or create a `config_pages` field by a
   deterministic, human-initiated step).
3. Run Guardrails, kind validation and the write-time checks on submit.
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

`superseded_by` is an `aim_fact` reference and cannot point at a literal.
No archive table is needed: the literal's `source_fact` back-reference
carries the link. A bulk "suggest literals from facts" belongs to the
proposer, not the UI.

## Alternatives rejected

- **Vector score as the selector.** Cannot express ties, and top-k
  crowding and the cutoff make a miss possible even for a well-written
  gist. The vector layer is a shortlist only.
- **Keyword-stuffed gists.** Pulls a gist toward other intents, the
  opposite of the one-intent rule. Aliases from observed misses, human
  approved, do the job with data.
- **Rewriting the query with Jev.** Jev cannot generate text.
- **A separate registry entity.** Unnecessary once the field type is the
  record; host-field coordinates point at it.
- **A separate vector row per literal under `aim`.** Redundant once the
  fact is the row. Standalone sites use the Search API index instead,
  behind the same interface.
- **Value in the fact text** (the status quo and 0039's rejected option).
  Drifts, embeds badly, gets consolidated.
- **Form API for value patterns.** Validates on form submit only, so
  agents bypass it.
- **An archive table for `superseded_by`.** A back-reference from the
  literal does the job.
- **Reimplementing the `config_pages` UI.** Config pages already do the
  human half.
- **Auto-applying regenerated gists, aliases or nightly proposals.** Any
  can silently redirect the agent or poison a business-critical value.
- **Letting agents create literal storage or values.** Proposals only.

## Consequences

- One field type, usable alone as a tidy token-exposed settings value and
  with the gist as a semantic address book. The standalone `literals`
  module needs Search API and `ai_search`, not `aim`.
- One scope and one seam replace two (`entity`, `token`) for `aim` sites,
  and move the `target_type`/`target_id` columns off `aim_fact`. Migrating
  the built `entity` scope is its own piece (open question).
- The value is never embedded or consolidated; the finder and chooser are
  the only probabilistic parts. Stale copies are gone because the value
  lives once.
- Reliability is bounded by shortlist recall and the chooser's accuracy,
  both measured per site, not assumed. Misses surface as `none` or
  `ambiguous`, never a guess; a wrong confident match is invisible without
  a spot-check.
- The hosted decision model sees real descriptions and values. Demo data
  only until a local decision model or a data-handling decision exists.
- Moderation, the nightly proposer, conversion and the alias loop add a
  review queue someone must staff.
- The match check inherits the decision-model limits in ADR-0037/0038.
- New dependencies stay in optional submodules: `config_pages` and
  `dynamic_entity_reference` only in their kinds.

## Logging

Per CLAUDE.md: IDs, host entity type and ID, field, outcome (`match`,
`ambiguous`, `none`), shortlist size, chooser provider, model ID and
confidence. Never the gist, value or query text. The opt-in
`log_query_text` gates the alias loop (piece 8) only.

## Open questions

- **Name.** "Literal" grates; the field is a key and value with an
  optional gist. Settle the module and field type names, and check
  drupal.org for a clash on `literals`.
- **Chooser fallback without a decision model.** The margin threshold on
  the top two scores is the stated default; measure whether it is
  acceptable on its own for a small site.
- **Shortlist k and the "small pool shows everything" threshold**, and
  recall@k, per site.
- **Chooser model.** Whether hosted Jev is acceptable for any production
  site, or a local model must handle the choice; measure per
  ADR-0037/0038.
- **Token host.** A token has no host entity; which small entity carries
  it.
- **0039.** Amend it into the token kind of this ADR, or supersede it.
- **Migrating `entity` scope.** Built facts use `target_type`/`target_id`
  today; a `hook_update_N()` once real data exists (PoC rule: none needed
  yet).
- **Grouping.** An optional `group` (taxonomy or config entity) so
  "contact details" holds phone, email and address, field_group-style for
  presentation. Not v1.
- **Value types beyond strings** and per-kind formatter defaults.
- **`config_pages` revisions/moderation** and role-pool scoping, as above.
- **Mirror sync.** Under the `aim` bridge a gist is mirrored into a fact.
  Either specify the sync (gist edit re-indexes, delete retires, draft
  revisions and unviewable hosts stay out of the index) or drop the mirror
  and have `aim` recall query the literal index beside facts.
- **Computed literals.** Whether "latest X" belongs in this module at all
  or is better served by ordinary recall or a search over content, and
  how a value that changes with content is vetted (check the gist against
  the rule, re-check on a schedule, or skip the match check).
- **Annotations bridge.** Which package owns it (`annotations_literals`
  versus the `aim` side), whether annotation revisions support moderation
  (unchecked, and the draft-revision flow for regenerated gists depends
  on it), and whether per-instance literals need their own gist always.
  Confirm `config_pages` appears among the generic targets (assumed from
  the deriver, not checked).
- **Aliases storage.** A multi-value property on the field versus extra
  index rows; the rejection of per-literal vector rows
  applies to the `aim` backend only.

## Addendum 2026-10-04: no field type - semantic lookup over annotated fields

Direction after review. Not yet folded into the pieces above; where it
conflicts with pieces 1, 2 and 10, this wins.

**The `literal` field type is dropped.** Its semantic part is the gist,
and the gist is an annotation of the value's field (piece 10). Its plain
part, a key and value exposed as a token, is what `config_pages` already
does. Nothing else needed a type of its own, and a formatter is the wrong
place: formatters render a stored value per display and own neither data
nor an index.

**What remains is a lookup capability over fields that already exist:**

- A field opts in (a `lookupable` flag beside its annotation). It is
  opt-in because the finder only works where a field holds a short,
  discrete answer (phone, address, hours, price). On free text it turns
  into ordinary retrieval-augmented generation, a different problem.
- The annotation text is the gist and is what gets embedded. The pointer
  is the existing coordinates (entity type, bundle, field, and entity ID
  for singletons).
- Everything else in the Decision carries over unchanged: the vector
  shortlist, the Jev choice (piece 3), view-access filtering before the
  chooser, the three outcomes (piece 4), write-time checks (piece 5),
  the `aim_scope_literal` bridge (piece 6), proposed edits (piece 7), the
  alias loop (piece 8) and the agent tools (piece 9).
- Whether the flag is a dedicated annotation `type` row or a setting on
  the field config is open (see below).

**The value still needs a home, and that home is `config_pages`.**
Annotating existing fields finds values that already live somewhere. It
gives no place for a value that lives only in a fact ("the phone number
is 01234 567890") or in nothing yet. Core has no generic singleton store
with editing UI, permissions and tokens, and building one would
reimplement `config_pages` (piece 11). So `config_pages` is the host for
homeless values: an optional dependency of the lookup package, already
installed on this site. The flow that ties it together, human-gated and
deterministic (ADR-0035, ADR-0039's "add setting"): create the page type
and field, set the annotation (the gist) and the `lookupable` flag, and
write the value, together. A site whose values already live on fields it
owns needs no `config_pages` at all.

**Limits that stand:**

- **Granularity.** An annotation addresses a bundle and field, never an
  instance (piece 10, ADR-0024). It fits singletons and breaks where each
  node holds its own value (twenty branch phones). Those need an
  instance-level gist, which is ADR-0024's target-scoped facts, not this
  mechanism. Say so in the docs rather than hide it.
- **Annotations is alpha** (`^2.0@alpha`, no uniqueness on `(target,
  field, type)`); keep the coupling in an optional package.
- **Packaging.** The finder and chooser live in an optional lookup package
  on the Annotations side or in `aim_scope_literal`; the `literals` and
  `literals_ui` names in piece 2 go away or shrink to that package.
- **Name.** "Literal" no longer names a field type. Candidate names for
  the capability: "semantic lookup" or "answerable fields". Settle at the
  next revision.

**Open questions added:**

- Is the `lookupable` flag a dedicated annotation `type` row (the gist
  and the flag in one record, and the review flow comes with it) or a
  setting on the field config?
- Does Annotations' `(target_id, field_name, type)` row carry enough text
  to embed well, and does its revision support moderation (piece 7's
  draft flow depends on it)?
- Whether the opt-in flag should be enforced (a field cannot be
  `lookupable` without an annotation) or only warned.

## Addendum 2 2026-10-04: a `literal` entity type, built on Annotations

Reverses the first addendum on one point and supersedes its
`config_pages` host paragraph. The first addendum is right that a
`literal` **field** type is the wrong shape. It is wrong that
`config_pages` can host the values.

**Why `config_pages` cannot be the host.** Checked in the installed module
(2026-10-04): `ConfigPages` declares no revision keys or revision table,
no Content Moderation, access is per page type (no pool or bundle
concept), and its token handler does no view-access check (ADR-0039).
Revisions, moderation, per-bundle pools and a real view-access check are
the point of literals, and a field inherits all of these from its host,
so a literal field on `config_pages` gains none of them. The earlier
claim that `config_pages` "owns editing, permissions and history"
overstated it: it owns editing and per-page permissions only.

**The shape: a `literal` content entity, the fields tied together in one
place.** Key, value (with a Typed Data constraint) and gist are fields of
one revisionable, moderatable entity. A bundle is a pool, so per-bundle
permissions give the public versus staff split, and the entity exposes
the Token API. Draft revisions make the regenerate, proposer and alias
flows (pieces 7 and 8) work as written. Each literal is an instance with
its own gist, so the first addendum's granularity limit goes away: twenty
branch phones are twenty literals. `config_pages` shrinks to an optional
value kind (a literal can point at one of its tokens), not the host.

**The overlap with Annotations is real, and the answer is to build on it,
not beside it.** Checked in the installed module (2026-10-04),
`annotation` already is:

- a content entity on `EditorialContentEntityBase`: revisionable, with a
  published status that Content Moderation manages when installed,
  translatable, owner-aware, with a revision UI;
- bundled by an `annotation_type` config entity, with
  `permission_granularity: bundle`;
- **content, not config** ("never touched by config sync, editable on
  production"), which is exactly the property literal values need;
- already reachable by Tool API and MCP (`annotations_tool`) and with
  optional `annotations_workflows`, `annotations_context`,
  `annotations_ui` and `annotations_type_ui` submodules;
- able to be target-less (`target_id` defaults to empty and the code falls
  back to a "site" label), so a site-level annotation is a supported
  shape.

A second module with the same entity scaffolding, permission handler,
revision UI, list builders and Tool/MCP exposure would be a rehash. The
recommended shape is therefore: **literals ships an annotation type
(bundle) plus the parts Annotations does not have**, instead of a new
entity type:

- a `value` field on that bundle, and the key (Annotations' own field
  name or label can carry it);
- Typed Data validation of the value, and token exposure;
- the finder and chooser (pieces 3 and 4) and the write-time checks
  (piece 5);
- the `lookupable` flag, which is then simply "this annotation type";
- the optional `aim_scope_literal` bridge (piece 6).

"Literals standing on its own" then means: an optional package that
depends on Annotations, not on `aim`. `aim` sites add the bridge on top.

**Verified against the installed code (2026-10-04; Annotations is not
enabled on this site, so none of this was run, only read):**

1. **Bundles are fieldable.** `annotations_type_ui` sets
   `field_ui_base_route` on the `annotation` entity type, giving Manage
   fields, form display and display per bundle. It is an optional
   submodule ("can be uninstalled once types are defined"), so a literals
   package needs it, or an equivalent, enabled.
2. **Moderation is real.** `annotations_workflows` ships a Content
   Moderation workflow and attaches it to every annotation type
   automatically, including types added later. It requires
   `annotations_ui`, `content_moderation` and `workflows`.
3. **The annotation's own `value` is a revisionable, translatable
   `string_long`.** That is the gist. The exact value is a second field
   on the literal bundle (added through Field UI or shipped as config).
   The key is a bundle field too.
4. **No uniqueness is enforced** on `(target, field, type)`, and nothing
   else enforces one key per pool. Key uniqueness is ours to add.
5. **Not needed from Annotations:** the target machinery (a literal is a
   site-level, target-less annotation), overlay, context, audit, docs,
   explorer, export, profile and webform submodules. They are separate
   submodules, so they are not pulled in.

Remaining unchecked: Annotations is `^2.0@alpha`, and a literal bundle
sitting among ordinary annotation types may show up in the Annotations UI
and overlays unless filtered.

**Mapping.** Gist = the annotation `value`. Exact value, key and aliases
= fields on the `literal` bundle. Pool = a bundle (`literal_public`,
`literal_staff`), permissions per bundle. Draft, review and publish =
`annotations_workflows`. The finder indexes the gist and aliases of
published literals; the host entity is kept behind the finder interface
so it could be swapped for a standalone entity later without rewriting
the chooser or checks.

**Fallback.** If any of 1 to 3 fails, build a standalone `literal` entity
type (`EditorialContentEntityBase`, a bundle entity for pools, a
permission handler). In Drupal that scaffolding is modest; the genuine
duplication is the UI, permissions, Tool/MCP and workflow exposure, which
a standalone module would have to build again. Prefer the Annotations
route unless a check above forces the fallback.

**Naming.** With this shape "literal" names an annotation type, not a
module or field. The capability name ("semantic lookup", "answerable
values") is still open.

## Addendum 3 2026-10-04: standalone `literal` entity, with the UI cost counted

Supersedes Addendum 2's recommendation to host literals as an annotation
type. Annotations' alpha status is **not** a factor in this decision.
Addendum 2's verified findings stand and are the comparison baseline.

**The deciding concern is the UI, not the entity.** The entity,
bundle, access handler and permissions are about 450 lines of boilerplate
(a pattern `AimFact`/`AimScope` already use). The real cost of leaving
Annotations is the editing surface: another place where people edit
context and config, with forms to present and pools to keep apart. Annotations
already has that surface (an edit controller, in-context overlays, Tool
and MCP exposure), so the choice is honest: reuse a heavy surface built
for a different concept, or build a deliberately thin one.

**The thin UI, mostly free from core and Views config:**

| Need | Source |
| --- | --- |
| Add, edit, delete, per-bundle forms | Core `ContentEntityForm` at `/literal/add/{pool}`; Field UI controls which fields each pool shows (form modes) |
| Collection with a tab per pool | One Views page (shipped as config, as Annotations ships `views.view.annotations`) with a bundle filter; tabs hidden from users without that pool's permission |
| Revision history, draft and publish | Core `content_moderation` plus a small workflow config attached to every pool bundle (about 40 lines) |
| Review queue (drafts, proposed edits, alias proposals) | Views over moderation state |
| Pool management (create `literal_staff`) | Bundle config entity form, standard |
| Tokens (`[literal:key]`) | A small token provider |
| **Probe box** ("ask a question", shows the match and why) | The one custom form; also surfaced on the edit form |
| Convert a fact to a literal | Custom, `aim_scope_literal` only (piece 12) |

Only the probe box and the fact-to-literal form are custom screens. Treat
the probe box as the single piece of UI that is not boilerplate.

**Segregating pools.** A pool is a bundle, so segregation is per-bundle
permission (`view/edit/create {pool} literal`, generated with
`BundlePermissionHandlerTrait`) plus the access handler, never a UI
convention. A staff pool is invisible in the list, the Views tabs, the
probe box and the finder (view-access filtering happens before the
chooser, piece 3). Different pools can show different fields via form
displays without code.

**Where editing happens.** Values are **content, not config**: bundles
(pools) are config and go through `config/sync`; the literals themselves
are content rows, so editing a phone number on production never needs a
config import and never trips the drift rule in the site CLAUDE.md. Entry
points, in order of effort: the Content admin listing (free); the `aim`
console's "add setting" action (ADR-0039/0029) writing the same entity;
and the proposer's review queue. In-context editing on the live page is
not v1; it is the one thing Annotations gives that the thin UI does not.

**The criterion that would reverse this.** If the thin UI (Views list,
review queue, probe box) proves more than a few hundred lines of custom
code to make usable, or in-context editing turns out to matter, fall
back to Addendum 2's annotation-type host and take Annotations' surface
instead. Decide after a throwaway spike of the entity and the Views list,
before committing to either.

**Optional bridge.** With both modules installed, a literal's default gist
may come from the annotation on its target field (piece 10). Neither
module requires the other.

**Open questions added:**

- Is one Views collection with pool tabs good enough, or does a pool need
  its own form layout beyond what form modes give?
- Does the probe box belong on the collection page, the edit form or both?
- Whether the review queue for proposals shares a screen with the
  moderation queue or sits on its own.
- Token provider: core's entity tokens need the Token module; confirm
  whether to depend on it or hand-write `[literal:key]`. (Settled in
  Addendum 4: tokens are in v1, with the Token module as a soft
  dependency.)

## Addendum 4 2026-10-04: tiered finder, no vector DB for small pools, tokens in v1

Refines Addendum 3's standalone `literal` entity. Where it conflicts with
pieces 2, 3, 5 and 8 above, this wins.

### Anatomy of a literal

- **Value:** the exact thing returned. A plain string, or a live pointer
  of some kind (token, entity-field path, route). A token is one value
  kind, not the definition of a value.
- **Gist:** the semantic description, and the only part matched.
- **Name:** the human label (the entity label).
- **Key:** a machine name auto-generated from the name, editable, unique
  per pool. It is not semantic. It is the option ID handed to the Decision
  API, the tier 0 exact-lookup handle, and the stable identifier in audit
  lines (which cannot log text).
- **Pool:** the bundle. It controls who can view the literal.

### The Decision API is the chooser

`drupal/ai` already ships a Decision operation type
(`ai/src/OperationType/Decision`: `ChoiceQuestion::fromOptions()` returns a
`ChoiceAnswer` with per-option probabilities and a confidence). Checked in
the installed checkout, which reports `1.3.1-317` (not confirmed as the
`1.6.0-dev` line). `LiteralChooser` uses it directly, so there is nothing
to propose upstream for a "choice" operation. What is worth contributing
is the finder interface and the access-before-chooser contract (view
access is filtered before any candidate reaches the model).

### Tiered finder: the model is the last resort

| Tier | Handles | Cost |
| --- | --- | --- |
| 0. Exact key (or alias) match | Callers who know the key: tokens, `literal_get key=...`, templates | No model |
| 1. Outcome cache | Repeated questions | No model |
| 2. Top hit clears a margin over the second | The easy majority; also the no-decision-model fallback | One embedding (vector) or none (full-text) |
| 3. Chooser over the shortlist | The ambiguous middle | One model call |
| 3b. Staged choice (group, then literal) | Pools too big for one flat menu, no vector DB | Two model calls |

- **Small and medium pools skip the vector step entirely.** Filter the
  pool by the caller's view access, put the whole menu (key plus gist per
  literal) into one `ChoiceQuestion`, and cache the outcome. About 20
  tokens per gist puts 200 literals near 4k tokens, and the static menu is
  prompt-cacheable. Search API and `ai_search` become an optional scale
  feature (a shortlist pre-filter for large pools), not a requirement; the
  core dependency becomes `drupal/ai` alone. This also makes the standalone
  contribution lighter.
- **Unmeasured:** whether the Decision API's per-option probabilities stay
  reliable with hundreds of options. Measure before relying on it, and set
  the "show everything" threshold from the result.
- **Why the vector tier needs an embedding:** gists are embedded once at
  index time, but the query is a new string and must be embedded before it
  can be compared. That is the one model call in the vector path; tiers 0
  and 1 never make it. A full-text top hit with a margin is a model-free
  tier 2 alternative, less reliable on natural-language questions.

### Staged choice: a model-only answer to large pools

When one flat menu fails the measurement above, split the choice instead
of adding a vector DB (keeps the `drupal/ai`-only dependency). Not built;
use only past the measured flat-menu threshold.

- **Group, then literal (preferred).** Round 1 is a `ChoiceQuestion` over
  group descriptions (a pool, or a finer topic group such as "contact",
  "hours", "policies"). Round 2 is a `ChoiceQuestion` over that group's
  literals only (key plus gist, plus `none`). Menus stay small, so
  per-option probabilities should stay reliable and prefill stays cheap,
  and each group's menu is static and prompt-cacheable.
- **Access first.** Filter groups and literals by the caller's view access
  before round 1, so an inaccessible pool is never in any menu. Pools
  already are the access boundary, so a pool is the coarsest group.
- **Failure mode.** A wrong round 1 makes the right literal unreachable.
  Mitigate by letting round 1 return its top two groups (run round 2 on
  both, then take the better result), or by falling through to the flat
  menu or `none` when round 1's confidence is low. Never guess.
- **Cost.** Two sequential model calls on an uncached query, so the
  outcome cache (keyed as above) matters more.
- **Needs a grouping layer.** Pools alone may be too few or too coarse.
  An optional `group` on the literal (the open "Grouping" question, now
  load-bearing for this tier) with a gist of its own, authored and vetted
  like a literal gist. Group gists get the same one-intent discipline.
- **Alternative: chunked flat menu.** Split the menu into chunks, run one
  `ChoiceQuestion` per chunk (each with `none`, parallelizable), then a
  final choice among the winners. No grouping layer, but N+1 calls and
  each chunk's probabilities are relative to its own options, so only the
  final round is a real decision. Second choice to group-then-literal.
- Measure both against the flat menu in the evaluation below (add them as
  setups) before building either.

### Privacy: values never go to the chooser

The chooser needs only key and gist per candidate, so **values are never
sent to it**. This corrects the "Hosted-model caution" in piece 5, which
listed values as chooser input. Only the write-time match check needs the
value. A local decision model removes the per-call money cost and the
exposure of gists, and lets the match check run on values too; it does not
remove latency (a CPU-only host pays prefill time on every uncached call,
and a larger menu makes that worse), and ADR-0037/0038 found small local
models weak on plausibility-style decisions. Cost moves from API spend to
latency and RAM; caching and the tiers matter either way.

### Caching

- **Key:** normalized query, pool or permission set, and language. Never
  serve across permission sets.
- **Invalidation:** the literal's cache tags (any published edit, delete or
  pool change).
- **Misses:** cache `none` with a short TTL.
- **Exact versus semantic:** exact-string hits are rare in free chat. A
  nearest-cached-query hit repeats more but is itself probabilistic, so it
  needs a tight distance threshold and is a later optimization.
- **Where it pays:** public FAQ-style queries repeat heavily. Measure the
  hit rate from the audit log instead of assuming one.

### Aliases: a field and a view, not machinery

Piece 8's loop stays; its mechanism shrinks. An alias is a whole example
question (not a word synonym) attached to one literal and indexed or
matched beside the gist. It earns its place for organization-specific
vocabulary an embedding does not know ("the Hub", "the bursar") and for
pinning a query that wrongly matched a neighbouring literal. It is a
multi-value text field on the literal, a Views list of logged misses, and
the existing chat-model proposer with human approval. No bespoke alias
subsystem. Aliases also serve as the regression and gold set: each is a
known query-to-literal pair. Add them only after a logged miss; do not
pre-fill.

### Derived pointers ("dreaming")

A nightly pass can derive a pointer from trusted data instead of a human
typing it, and serve it as a static pointer at read time (cheap, vetted,
access-checked). It only ever creates a draft revision a human approves
(ADR-0035; the poisoning surface is the same as piece 7's proposer, and
only trusted-source facts may feed it).

- **Prefer stable identities.** "The returns policy" mapped to the node
  titled "Returns and refunds", "contact us" to a route, "opening hours"
  to a config-page field. These fail visibly: if the pointer stops
  resolving, or the access or published check fails, the lookup returns
  `none` with an audit line.
- **Role-holders go stale silently.** "The CEO" pointing at a user stays
  valid after the person changes. If supported, the pass must re-derive
  and diff on a schedule and propose a draft revision when the answer
  moves (this is the "re-check on a schedule" option in the computed
  literals open question). Prefer not to ship role-holder literals first.

### Languages: a conditional win

A multilingual embedding model can match a query in one language against
a gist in another with no translation; many local models are English-
centric, so verify with a second-language gold set before claiming it. On
the no-vector path the same question applies to the chooser model. The
value comes back in the visitor's language through entity translation if
the literal is a translatable entity.

### Tokens are in v1

The Token module is a **soft dependency** (very common, and not needed for
semantic lookup). Chained tokens, pool in the path:

| Token | Returns |
| --- | --- |
| `[literal:pool:key]` | Resolved value, sanitized for output, access-checked |
| `[literal:pool:key:raw]` | The same value unsanitized; never place in HTML unescaped |
| `:gist`, `:name`, `:key`, `:pool` | Metadata (admin and debug use) |
| `:url` | Resolved URL for route and entity kinds, after an access check |
| `:entity:...` | Chains into the target entity's tokens (entity-pointer kinds only) |
| `:updated` | Changed date, chainable into core date formats |
| `:pointer` | The unresolved pointer expression (`[user:uid:5:name]`), admin and debug |

`:pointer` is deliberately not `:raw`: Drupal's `:raw` convention means
"unsanitized", and overloading it would confuse. Piece 1's `raw()` on a
pointer kind is exposed as `:pointer`; a plain value's `raw()` is `:raw`.

Handler rules:

- **Access:** every resolve checks view access itself (ADR-0039 recorded
  that `config_pages`' handler does not). An inaccessible or missing
  literal returns nothing and the token stays unreplaced.
- **Cacheability:** add `user.permissions` (or the pool's access context)
  and the literal's cache tags to the bubbleable metadata. Without it a
  cached page leaks a staff value to another user.
- **Revisions:** only the published revision resolves. Drafts never
  render.
- **Language:** honor the `langcode` token option.
- **Pool is required:** keys are unique per pool, so a bare
  `[literal:key]` is ambiguous and is not offered.
- **Rejected:** a semantic token such as `[literal:ask:...]`. Token
  rendering sits on hot, cached page-render paths; a model call there is
  slow, nondeterministic and uncacheable. Semantic lookup stays in the
  tool.
- The token output is a human-author convenience for placing existing
  values; it plays no part in the lookup.

### Evaluation before commitment

Build a gold set of queries per literal (`aim_benchmark` already
generates facts) and compare three setups on recall@1, miss rate and
wrong-confident rate, plus the share of queries each tier absorbs:
(1) full-text with hand-written synonyms, (2) vector only, (3) vector or
full-menu plus chooser. The design only pays off if tiers 0 to 2 absorb
most traffic. Where the pool is small and synonyms are well kept, the
keyword baseline may win everywhere except unanticipated phrasing; that
is a legitimate outcome and the chooser stays optional.

**Open questions added:**

- Decision API reliability and latency at 50, 200 and 500 options, hosted
  and local.
- The "show everything" pool-size threshold, from the measurement above.
- Staged choice: does group-then-literal beat the flat menu past that
  threshold, how often does round 1 misroute, and are pools enough as
  groups or is a `group` field needed?
- Whether a semantic cache (nearest cached query) is worth the risk of a
  wrong hit.
- Whether role-holder derived pointers ship at all in v1.
- Whether the Token provider declares dynamic tokens per literal in
  `hook_token_info()` (fine for small pools) or only the generic chain.

## Addendum 5 2026-10-04: no mirror, no `aim_scope_literal`; the build plan

Removes piece 6's bridge and the "replaces the `entity` scope" claim, and
sets the order of work. Where it conflicts with pieces 6 and 12 and the
"Mirror sync" open question, this wins.

### The mirror is dropped

Piece 6 copied a literal's gist into an `aim_fact` with `scope=literal`.
That is a second copy of the gist with its own embedding, and it needs
sync code on every save, delete, draft and access change. Resaving the
fact when the gist changes is cheap (one embedding call, a row write and
a vector upsert; gists change rarely), and deriving an index from a source
of truth is how Search API works. But hand-rolling that is a small Search
API, and the cost that matters is the standing duplicate invariant, not
the timing. The stale window between a literal save and the fact resave is
bounded (the old gist is used briefly, never a wrong value, since values
are never in a fact), but it is avoidable by having one copy.

The earlier claim that the bridge avoids a second vector index only moved
the duplication from the index to the fact table. It is withdrawn.

### Scope means "what the fact is about"

An `aim_scope` says what a fact is about (user, case, entity). A literal is
an exact value with a fuzzy address, not a fact. So:

- **There is no `literal` scope and no `aim_scope_literal`.** A scope named
  `literal` would also collide with the pool bundles (`literal_public`,
  `literal_staff`) of the literal entity.
- **A pointer-only fact adds nothing to lookup.** It has no text and no
  embedding, so recall could not search it and would have to hand the
  question to the finder, which can be called directly.
- **`entity` scope stays** (ADR-0027). A fact about a literal ("the
  switchboard number changed on 3 Oct, the old one was X") is an ordinary
  `scope=entity` fact with `target_type=literal` and `target_id` set. The
  built `entity` scope is not retired, and the `target_type`/`target_id`
  migration in the Consequences and Open questions no longer applies.

### The gist lives on the literal

Standalone: the literal row holds name, key, value, gist and aliases, and
the finder reads them (full menu first, piece 3 and Addendum 4).

`aim` integration is a **finder consumer, not a store**: an `aim_chatbot`
and `aim_tool` tool calls `LiteralFinder` beside `aim_recall`, and the chat
agent chooses between them. Nothing is mirrored into `aim_fact`, and
`aim_vector` is not shared with the literal gist index (when a vector tier
exists at all, it is the literal module's own Search API index).

**Deferred option: an aim-stored gist backend.** With `aim` installed the
gist could live only as a `scope=entity` fact targeting the literal, with
no gist column on the literal. That is one copy, not a mirror, and the
finder returns the same candidates (key, gist, access-checked pointer)
whichever backend supplies them, so the interface already allows it. Not
built, because it costs:

- two storage modes to test and document (gist on the literal versus on a
  fact);
- the literal form writing another entity's text;
- draft gists, regenerate and moderation (piece 7) live on literal
  revisions, while a fact has only the `trusted` flag, so aim mode would
  lose them or need its own gate;
- deleting a literal must retire its fact (one-way lifecycle linkage, much
  smaller than a mirror);
- consolidation must skip these facts (the `isConsolidatable()` seam
  exists for this).

Decide after Phase 4's measurements show whether the full-menu path
suffices; build standalone first.

### Convert a fact to a literal stays, one-way

Piece 12 is unchanged and is the only `aim`-specific build: a form that
creates a literal with a `source_fact` back-reference. It needs the
`retired`/`expires` split in TODO.md for its cool-off.

### Build plan

No commits until asked. Each phase ends at a checkable result; lint per
CLAUDE.md.

0. **Decisions (no code).** This addendum. Settle ADR-0039 as amend or
   supersede. Check `literals` for a clash on drupal.org (the name is still
   open). Pick the module's home: it is a standalone project, so a sibling
   of `aim` under `web/modules/custom/`, not nested in the `aim` repo.
1. **Throwaway spike** (Addendum 3's gate). `literal` entity, pool bundle,
   Views list, moderation. Result: thin UI stays under a few hundred custom
   lines (go standalone) or fall back to Addendum 2's annotation-type host.
2. **Core, no model.** Revisionable `literal` entity with a pool bundle,
   per-bundle permissions (`BundlePermissionHandlerTrait`) and an access
   handler. Fields: name, key (unique per pool), value (plain string only),
   gist, aliases. Typed Data constraint on the value. Guardrails on gist,
   value and aliases at save. Tier 0 exact key or alias lookup.
   `[literal:pool:key]` tokens with access check and cache metadata. Tool
   API `literal_get` by key. Audit logging per CLAUDE.md. Result: a usable
   settings store with no AI.
3. **Finder and chooser.** `LiteralFinder` and `LiteralChooser` behind
   interfaces. Full-menu backend: filter by view access, key plus gist menu,
   one Decision API `ChoiceQuestion`. Outcomes `match`, `ambiguous`, `none`.
   Margin fallback without a decision model. Outcome cache keyed by
   permission set and tagged to the literal. Values never sent to the
   chooser. `literal_get` gains the by-question mode. Result: natural-language
   lookup over a small pool.
4. **Evaluation** (run in a separate thread). Gold queries per literal;
   compare keyword, vector and chooser setups; measure at 50, 200 and 500
   options, hosted and local. Result: the "show everything" threshold, and
   whether the vector or staged tiers are needed at all. Brings numbers back
   here before anything past Phase 3 is built.
5. **`aim` integration.** The finder tool in `aim_chatbot`/`aim_tool`, and
   convert-a-fact (piece 12).

Deferred, no phase yet: write-time one-intent, neighbour and match checks
(piece 5), the probe box, the alias loop and proposer (pieces 7 and 8),
value kinds beyond a plain string (token, entity-field, route), the vector
and staged tiers, the aim-stored gist backend above, the Annotations gist
bridge (piece 10), computed literals.

Production use of the chooser stays demo-data-only until a local decision
model or a data-handling decision exists (Addendum 4's privacy note).

## Addendum 6 2026-10-05: terms, a stored-embedding gate, chooser latency

Refines Addendums 4 and 5. Where it conflicts with them or with piece 2's
requirement of Search API and `ai_search`, this wins.

### Terms (used consistently from here on)

- **Finder:** the whole lookup. It filters candidates by view access, runs
  the cheap steps, and returns `match`, `ambiguous` or `none`.
- **Chooser:** only the model call inside the finder (the Decision API
  `ChoiceQuestion`).
- **Gate:** the cheap check inside the finder, before the chooser, that
  decides whether the question is close to any literal at all.

### The gate: embeddings stored on the literal row, compared in PHP

The gate's default is not a vector index.

- At save, the literal's gist (and aliases) is embedded and the vector is
  stored on the literal itself, with the embedding model's ID beside it. It
  is derived data on the same row, written in the literal's own save, so
  there is no second entity to keep in sync.
- At query time, embed the question (one embedding call), then compare it
  with the access-filtered pool's stored vectors in PHP (cosine).
- Nothing close: return `none` with no chooser call. One candidate clearly
  ahead by the margin: `match`, no chooser call. Otherwise the top few go to
  the chooser. This is Addendum 4's tier 3, without Search API.
- Requires only `drupal/ai`'s embedding operation. No `search_api`,
  `ai_search` or vector DB provider. A real vector index (Search API plus a
  provider) is needed only past a few thousand literals; the threshold is
  unmeasured.
- A vector whose model ID differs from the configured embedding model is
  ignored by the gate and re-embedded by a queue worker (the stale-vector
  rule).
- It is cheap to run and, unlike a Search API index, cheap to set up on a
  standalone site.

The same embed-and-compare step over cached past questions is the semantic
cache. It stays deferred (Addendum 4): needs a tight threshold, is keyed by
permission set, and "reservations phone" versus "main phone" is the failure
to guard against.

### Chooser latency: what is known

- **Measured here:** hosted Jev 0.41 s per call including the network, on
  small prompts (ADR-0021, ADR-0038). Local `tev1:4b` on this CPU-only laptop
  10-14 s per call (ADR-0038).
- **Supplied figures, unsourced and unverified:** typical single-pass
  classification on a warm GPU or vLLM endpoint, p50 15-80 ms, p99 120-250 ms,
  300-1,200+ decisions per second on one GPU, and 25x to 200x faster than a
  generative text judge. These describe short inputs. A menu of hundreds of
  gists is a long input with per-option output, so treat them as a lower
  bound until measured in Phase 4 on the actual model and hardware.

### Decided

- The module is named `literals` (Nik's decision 2026-10-05; the
  drupal.org clash check has not been run and is still needed before
  release) and lives as a
  sibling of `aim` under `web/modules/custom/`, not nested in the `aim` repo.
- [ADR-0039](0039-token-scope-live-config-values.md) is superseded by this
  ADR, not amended. What carried over is listed in its header.
- Pool sets (a literal in several pools) are skipped. If revisited: a
  bundle is one per entity, so it would need a separate many-to-many
  grouping, and access across sets (any versus most restrictive) must be
  stated. This is the "Grouping" open question.
- Bundles stay as pools, for access only.

**Open questions added:**

- Cosine compare time in PHP at 200, 2,000 and 10,000 vectors, to set the
  point where a real vector index is needed.
- Vector storage format (a blob or JSON on a field), and whether revisions
  each carry a vector or only the published one.

## Addendum 7 2026-10-05: types, kinds and audience replace pools (Phase 1 built)

Records what the Phase 1 build settled. Where it conflicts with pieces 1
to 12 or Addendums 1 to 6 (pools as bundles, per-pool permissions,
`[literal:pool:key]`, a `value_pattern` on the pool), this wins. The
module is built and live on the dev site; see its `HANDOFF-literals.md`
for the current state.

### Bundle is the type, not the pool

- The bundle is a `literal_type` config entity (fieldable, Field UI on
  its edit form, like `config_pages_type`). A type names a **kind** (a
  `LiteralKind` plugin: `text`, `token`, `entity`, `url`) and carries that
  kind's settings (text: `validate_as` of any text, whole number, phone,
  email or URL). A site defines its own types, for example "Phone number"
  (text, validated as phone) and "Page link" (url).
- A literal still holds exactly one value. A kind plugin validates it on
  save and `resolve($account)` reads it at read time, so the finder, the
  token handler and the tool all call one `$literal->resolve()` and never
  read `value` directly. Rejected: letting a literal hold several fields
  of mixed kinds (the `config_pages` model), because the finder would have
  to understand arbitrary field types.
- `entity` values are stored as `entity_type:id` and resolve to the
  entity's URL after a view-access check. `url` values are **internal
  paths only** (start with a slash, access checked for the viewer):
  external URLs cannot be access checked, so they stay out until there is
  a reason, then an "allow external" setting on the type. A `token` value
  must contain at least one known token.

### Access is one column: audience

- Pools are removed. Each literal has an `audience`: `anonymous`
  (everyone), `authenticated` (any signed-in account) or `restricted`
  (accounts with the `view restricted literals` permission, granted to
  roles on the normal permissions page). The default is `authenticated`,
  so a literal whose audience is forgotten is hidden, not published.
- `LiteralAudience::visibleTo($account)` returns the audience values the
  account can see. The same set answers a single access check and a list
  query (`audience IN (...)`), applied by the access handler and by a
  `hook_query_alter` for entity queries and Views. **The finder must
  filter candidates this way before the gate or chooser sees any gist.**
- The other permissions are flat: `administer literals`, `create`, `edit`
  and `delete literals`. Unpublished (draft) literals are visible only to
  those who can edit.
- Not built, and the likely first extension when a case needs it: per-type
  "restricted" permissions (so "staff only" and "contractors only"
  differ), and a scope-plugin interface (`checkViewAccess()` plus a
  query-condition method, aligned with aim's scope types) once a second
  scope type exists.

### Changes to the tokens and the key

- Keys are unique across all literals, so the token form is
  `[literal:key]`, not `[literal:pool:key]`. Everything else in
  Addendum 4's token rules (access check, cacheability, published revision
  only, soft Token dependency) stands.

### Progressive enhancement

The module needs no model: `user` and `views` only, and nothing calls an
AI service. Exact-key lookup (tokens, the tool, PHP) is a complete product;
the finder adds a chooser, then an embedding gate, then optionally a vector
index, each only when the step before stops being enough. See
`web/modules/custom/literals/adr/progressive-enhancement.md`.

**Open questions added:**

- Per-type restricted permissions versus a scope-plugin interface: build
  neither until a second case appears.
- Where the literals-only ADRs live once they move into the module (two
  files currently share the number 0046).

## Addendum 8 2026-10-05: pinned, merge a missed question into the gist

Pinned, not built, not scheduled. A different answer to piece 8's alias
loop and the "Aliases: a field and a view" section of Addendum 4: instead
of a side list of example questions, fold what a miss reveals into the gist
itself, the way consolidation folds a candidate fact into an existing one.

- **Trigger.** A question that returned `none` or `ambiguous` and that a
  person points at the literal it should have found. For example "how do I
  contact you" missing a literal whose gist is "main telephone number".
- **Proposal.** A model writes a refined gist that covers both ("main
  telephone contact number"). Same shape as aim's consolidation UPDATE:
  the model only judges and proposes, and the result is checked before it
  is used.
- **Verification.** A faithfulness check that the new gist still describes
  the same single value (the one-intent rule of piece 5), and that it does
  not drift toward a neighbouring literal. A distance check against the old
  gist and against neighbours, as `modelConfirmsMerge()` does for facts.
- **Approval.** A person approves, and the change lands as a draft revision
  under the existing moderation, so it can be reviewed and rolled back.
  The old gist stays in revision history.
- **Why it may beat aliases.** One description per literal, no second field
  to keep in step, no separate matching path, and the gate and chooser need
  no change. The risk is the same one aliases showed in a quick trial on the
  dev site (removed afterwards): widening a gist pulls the literal toward
  vague neighbours, so a vague "phone number" can start to pick one phone
  literal over a tie. The verifier's neighbour check is the guard, and the
  eval's near-neighbour pairs are the test.
- **Open.** Needs the opt-in query-text logging (question text is personal
  data) to see misses at all; whether repeated merges converge or bloat a
  gist; whether the proposer is the decision model or a chat model (aim
  splits these: a decision model judges, a chat model writes).

## Addendum 9 2026-10-06: aliases reconsidered, the gist convention

Reconsider piece 8 and Addendum 4's "Aliases: a field and a view" sections:
a separate alias field (and its UI, guardrails and review queue) is not
needed to get aliases.

- **Measured 2026-10-05, hosted Jev, 14 queries on the dev pool.** Arbitrary
  house names (not guessable from the gist) written into the gist after a
  plain-text `ALSO`, e.g. "Main telephone number, ALSO the Beacon Line": 9/9
  answerable hits, against 4/9 for the same gists without them, 0 wrong
  picks. Adding an instruction line explaining `ALSO` changed nothing; the
  model reads it as synonyms unprompted. One false positive in 5 no-match
  queries, where an alias overlapped an ordinary phrase ("the Open Doors").
  Natural synonyms (switchboard, reception) needed no alias at all.
- **Decided.** No alias field, no `ALSO` parser, no structured option
  rendering. TypeSafe's Choice does accept structured option descriptions
  (`{what, not_for, examples}`, field names free), and drupal/ai's
  `ChoiceQuestion` passes them through, but a send-time parse of the gist
  would add failure modes (typos, commas, case) for no measured gain. The
  gist stays one free-text field; `ALSO` is an optional author habit.
- **Gist length stays 255.** A gist is the answer to a one-shot question,
  not a document. Jev's limits (64k tokens per request, 32k for state plus
  the longest question; its docs advise concise descriptions and warn that
  lengthy descriptions of similar options confuse it) and the small local
  models' budgets (about 125 options in 512 tokens) both favor short.
- **Addendum 8 is the way to grow wording.** Folding a missed question into
  the gist, with a person's approval, replaces hand-kept aliases.
- **Open: groups.** Chopping a large pool (narrow before the chooser) and
  access control both want a grouping of literals. Audience is per row
  today; per-item access is more than needed. A group that carries the
  audience, and that a call can narrow by, would give both. Not designed.
