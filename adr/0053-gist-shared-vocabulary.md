# ADR-0053: "Gist" as a separate searchable field, shared by annotations and literals

**Date:** 2026-10-04
**Status:** Proposed - design only, nothing built. Revised 2026-10-04: the first draft proposed renaming the annotation `value` field to `gist`; discussion replaced that with adding `gist` as a second field. The body is duplicated from ADR-028 in the `annopm` repo (inter-repo links are not reliable); keep the two bodies in step. The addenda differ: annopm's covers annotations, this one covers literals. The annotations changes are dotdev-repo work, not aim work. **The addendum at the end narrows the body where they conflict; for literal mechanics, [ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md) and its addenda still govern.**

## Context

Two modules need a word for one concept.

- **Annotations** (the separate dotdev suite) attaches guidance to a field (a target). Its main text field is named `value`, which is vague and collides with Drupal's own `value` column property on `string_long` and `text_long`.
- **Literals** (designed in [ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md) and its four addenda, not built) is a keyed pointer to an exact value the system knows but does not expose as such: "phone number" -> 1111, "contact page" -> a URL. A literal has a key, a **value** (the exact thing returned) and a **gist** (a natural-language description, the only part matched). The ADR's design: shortlist or full menu by gist, a Decision API chooser picks the key, an access-checked resolve returns the value. Values are never embedded, paraphrased or sent to the chooser.

Annotations are already used to find things by what they say. ADR-013 in the annopm repo (`adr/ADR-013-migration-inference-context-export.md`; migration-time field matching: for each incoming source field, pick the destination field whose annotation text fits) is a find-a-field-by-its-annotation operation, currently framed only as a bulk export for an external caller to rank.

## Decision

1. **Add `gist` to annotations as a new, optional field alongside the existing main text field.** The main field keeps holding the full guidance (the verbatim side). The gist is a short natural-language description of what the field is for, used to find the field. It may be authored, or derived by an LLM from the guidance (`annotations_ai_draft` already drafts guidance, so drafting a gist belongs there), and it is moderated like any other annotation content. When it is empty, finders fall back to the main text, so nothing breaks and no one has to author two fields.
2. **Literals use `gist` from day one**, with `value` kept for the exact returned thing, where the word is precise.
3. **Define the gist by what it is:** a short natural-language description of its record's referent, used as the address. The referent differs, the role does not.

| Module | Full content (verbatim side) | Gist describes | Found by gist, returns |
| --- | --- | --- | --- |
| Annotations | The guidance text | A field (target) | The field (target) |
| Literals | The exact value | A value | The value |

Reading by target (annotations' normal lookup) is the case where the referent is already in hand, so the gist is not needed to find it.

4. **Annotations gain a find-by-gist operation**, used first for migration inference (ADR-013): a source field or question goes in, the best-matching destination field comes out. It uses the same tiered finder as literals (exact key, outcome cache, margin shortcut, Decision API chooser over key plus gist, view access applied before any candidate reaches the chooser). ADR-013's bulk export stays the cheap first step for callers who rank for themselves; find-by-gist is the richer step that reuses the finder instead of building matching twice. The `in_ai_context` flag already scopes which fields are candidates.
5. **Name operations by direction, never "get gist".** Annotations: read by target, find by question. Literals: find by question, get by key.
6. **The bridge is one move.** A field has an annotation gist; a literal is found by a gist. Taking the gist of the field you are on and using it as the query that finds a literal is [ADR-0041](0041-annotation-guided-webform-prefill.md)'s annotation-guided webform prefill. A literal's default gist may also be copied from the annotation on its target field (ADR-0040 Addendum 3).

### Factoring out the common parts

The two entities share little: bundles, access models and key schemes differ. What they share is the field definition, an interface, and above all the **finder**.

- `GistInterface` (`getGist()`, with the fallback behaviour documented).
- A base-field-definition helper or trait for `gist`.
- The finder service contract and the Decision API chooser: candidates as (id, gist) in, an access-filtered ranked choice out.

Home: ADR-0040 settled that neither module requires the other, so the shared pieces go in a small third module both depend on (name it so it does not collide with GitHub gists, for example `gist_finder`). ADR-0040 also notes the finder interface is worth contributing upstream to `drupal/ai`, which could become its eventual home.

**Timing:** build literals first with the finder behind an interface, then extract it when annotations find-by-gist is built. Two consumers justify the extraction; extracting before the second exists means guessing the interface.

## In plain terms: what this changes for editors, and how to explain it

An annotation answers two different questions about a field. Today one text box tries to answer both. The change gives each question its own box.

| Question the editor has | Box to use | What it holds | Example, for a field that holds an office phone number |
| --- | --- | --- | --- |
| "Where does this value go?" (I have a piece of content and need the right field) | **Gist** | What the field is for: what kind of thing belongs in it. Used to find the field. | "The main telephone number visitors should ring to reach the office." |
| "How do I fill this field in well?" (I am in the field and need to curate it) | **Guidance** (today's `value` field) | The rules for writing it: format, tone, what to avoid, who approves it. Read once you are in the field. | "Include the country code, no spaces or brackets. Use the switchboard, not a direct line. Check with Reception before changing." |

One is **find the place**, the other is **do the work well once you are there**. The gist is a label for matching, so it stays short and says what the field *is*. The guidance is instructions, so it can run long and says how to *treat* it.

**Authoring rules, for the docs and the editing form:**

- **Gist:** one or two plain sentences, describing the contents, not the rules. No formatting advice. If you could not tell from it what to put in the field, it is too vague.
- **Guidance:** everything else an editor needs once they have found the field. Do not repeat the gist.
- **Gist empty?** Nothing breaks. Finders fall back to the guidance. Fill the gist in when you want the field to be findable reliably, which is mostly for migration and AI-assisted placement.

**Editing-form labels (suggested):** gist as "What this field is for"; guidance as "How to fill it in". The form should show the two as separate, visibly different boxes, so editors do not paste instructions into the gist.

**Where an editor meets each one:**

- In the field's context (the overlay, the help beside the widget): **guidance**, as today.
- When choosing where something goes (migration matching, "which field takes this?", AI-assisted placement, a prefill that has to pick a target): **gist**, behind the scenes. The editor sees the match and its reason, not the gist as such.

**Open: how well the "find the place" half works.** This is the part that has not been tried. Matching a piece of content to a field by gist depends on gists being specific and on the field set being small enough for the chooser to separate (the same "Decision API reliability at 50, 200 and 500 options" question ADR-0040 leaves open for literals). A throwaway test belongs before this is promised in docs: take a few dozen real fields, write their gists, feed in sample values, and measure how often the right field comes back.

**A consequence for naming.** Putting the two meanings next to each other makes the case for renaming today's `value` to **`guidance`**: it says exactly what the box is for, in plain human-friendly language that suits a human-friendly module. That rename is still optional and carries the breaking-change cost measured below, but the plain-speak docs above read much better with it. Recommend doing it, as a separate release from adding `gist`.

**A note on AI-derived gists.** Guidance says how to treat a field, not necessarily what it is, so a gist derived only from guidance can be lossy. Derive it from the field label, field type, bundle and the guidance together, and have an editor approve it (see the moderation point in Decision 1).

## Provenance

The word is not a coinage and is not a Drupal convention.

- **Fuzzy-trace theory** (Reyna and Brainerd, psychology of memory) holds two parallel representations of an event: *verbatim* traces (precise detail) and *gist* traces (fuzzy bottom-line meaning). Gist lasts longer and is more accessible; detail decays faster. This is the split literals relies on, and the one the two-field annotation design now matches: full guidance (verbatim) plus a gist (fuzzy, searchable). aim already works this way for facts ([ADR-0020](0020-verbatim-facts-consolidation-opt-out.md), [ADR-0046](0046-core-cutoff-time-axis-and-shared-gist-finder.md) (memory kinds, formerly ADR-0045)). Overview: <https://en.wikipedia.org/wiki/Fuzzy-trace_theory>; <https://pmc.ncbi.nlm.nih.gov/articles/PMC4671075/>.
- **ReadAgent, "gist memory"** (Lee et al., Google DeepMind, ICML 2024, <https://huggingface.co/papers/2402.09727>) is the closest prior art and the one to cite. The document is split into pages, an LLM writes a short gist per page, the agent reads the gists, chooses which pages matter, and looks up the verbatim original. That is gist-as-address with exact content behind it, and it is the same two-field shape as the annotation design (full text plus a gist generated from it). It is explicitly human-inspired, from the same lineage. Implementation walkthrough: <https://inference-docs.cerebras.ai/cookbook/agents/gist-memory>.
- **Gist tokens** (Mu, Li and Goodman, Stanford, NeurIPS 2023, <https://arxiv.org/pdf/2304.08467>) is a different sense: a model learns to compress a prompt into a few cacheable tokens. It is a model-internals technique with no schema or field, so it does not conflict.
- **Drupal ecosystem:** a case-insensitive whole-word code search across core and contrib returned 298 matches in 191 files. Every hit inspected (webform, token, smart_trim, schemadotorg and `node_modules` copies) was a gist.github.com link. No module uses "gist" as a field, property, plugin or concept.
- **Wider web:** a search for "gist" as a schema-level field on stored records (a described, searchable companion to an exact value) found nothing. Related material exists under other names ("description", "summary", column descriptions in text-to-SQL schema linking). This proves absence of evidence only.

One difference worth stating in the docs: ReadAgent's gists are LLM-generated. Here a gist is authored or approved, or LLM-derived and then moderated. That ties into the moderation and guardrails story.

## Scope of the change (annotations)

Adding the field is small compared with the rename the first draft proposed.

- **Schema:** one new optional base field on the annotation entity (alongside `$fields['value']` at `src/Entity/Annotation.php:125`), via an update hook (the module uses update hooks per annopm ADR-023). No data migration, no existing key changes, and the existing 101 recipe content files, the Tool API keys and the third-party alter hooks are untouched.
- **Code:** the editing UI (`annotations_ui`), `AnnotationStorageService` (translation handling and the write primitive), `annotations_tool` Read/WriteAnnotations schemas, the context payload (a new optional `gist` key beside `value`), and `annotations_ai_draft` (derive a gist from guidance). Sync payloads (ADR-023) gain an optional key.
- **Finder:** find-by-gist and the shared extraction, as above.
- **Docs:** CLAUDE.md, README.md and DEVELOPING.md for annotations, annotations_context and annotations_tool, and the shared vocabulary definition in all of them.

### If the main field is later renamed

Renaming `value` to something clearer is now an independent, optional step (candidates: `guidance`, or a neutral `text`/`body`, since annotation types are fieldable and not all hold guidance). If done, the measured scope (2026-10-04, `web/modules/contrib/annotations`) is:

- one base field plus its data-table column (and the revision table if revisionable, not checked); Drupal cannot rename a base field natively, so an update hook adds the new field, copies the data and drops the old one;
- about 10 PHP files reading the field or the `['value']` key in the assembled-annotation array (`AnnotationStorageService`, `ContextAssembler`, `ContextRenderer`, `ContextHtmlRenderer`, `AnnotationsOverlayHooks`, `AnnotationAiContextBuilder`, `ObsidianVaultWriter`, `AnnotationsCommands`, the `annotations_tool` plugins);
- two views, about 19 references;
- 101 recipe content YAMLs, to be rewritten against that key only (`status` and others also use `value:`);
- heavy test coverage (27 hits in `AnnotationStorageServiceTest` alone), and about 19 markdown files;
- a **breaking** change to the documented `'value'` array key in the alter-hook examples and Tool API output;
- do not blind-sed: `->value` and `['value']` on other fields are Drupal's own properties and stay.

## Alternatives considered

- **Rename `value` to `gist` (the first draft).** Rejected. It forces the annotation gist to be both payload and address, leaves "gist implies short" unresolved for long guidance, loses the verbatim-versus-gist contrast, and carries the full breaking-migration cost.
- **Keep `value` and add nothing.** Rejected: leaves no shared vocabulary and no good field for find-by-gist.
- **Make the gist an ordinary configurable field per annotation type.** Rejected: finders need one known field, so a base field is simpler.
- **Literals depends on annotations, or the reverse.** Rejected by ADR-0040 ("A standalone `literal` content entity").

## Consequences

- One word, one meaning across both modules, with a published lineage to cite.
- Gist is metadata (the address) in both modules; the payload is the guidance (annotations) or the exact value (literals).
- A second field to author on annotations, softened by being optional, falling back to the main text and being derivable by AI. The risk is drift between the guidance and its gist; moderation and the derive-on-save option are the mitigations.
- Embedding or indexing cost for gists is small (short text).
- A third module to maintain, deferred until the second consumer exists.
- Risk (from ADR-0040): four resolver kinds for a literal's value (string, token, entity-field path, route) change what "value" means per kind. Hold v1 to plain string and token until the finder is proven. This is a literals concern and does not affect the gist decision.

## Open questions

- Should the gist be derived automatically on save when empty, or only on request from the editing UI? What stops a stale derived gist after the guidance changes?
- Does the finder embed gists (vector) or send the whole menu to the chooser, per ADR-0040 (the whole menu goes to the chooser, no vector step)? Annotation corpora may be larger than literal pools.
- Is the annotation entity revisionable (matters only if the optional rename goes ahead)?
- Does any LGD pilot or recipe-distributed content (ADR-023 sync) need a compatibility shim for the new optional key?
- Name and home of the shared module, versus contributing the finder interface to `drupal/ai`.
- Search drupal.org project names once for "gist" (the code index covers code, not project names).

## Addendum 2026-10-04: what the settled gist design means for literals

The annotations side of the discussion is in annopm ADR-028's addendum. This is the part that applies to literals. It refines the vocabulary only; the finder, tiers, privacy rules and entity design in ADR-0040 and its addenda are unchanged.

### 1. The lane, as it applies here

A literal's `gist` is an optional plain text field on the `literal` entity, next to `value`. It is authored, short, and stored on the literal row. It is not derived by default, not a separate entity or annotation type, and not a config value. This matches ADR-0040's anatomy (value, gist, name, key, pool); nothing there changes. One difference from annotations worth keeping straight: here the gist is the only matched part and the value is the payload, so a literal with no gist cannot be found semantically (it is still reachable by key, tier 0). On annotations the gist is optional and falls back to the main text; here an empty gist means "not findable by question".

### 2. The gist is a delta on the stack's context

The stack already knows the pool, the key, the name and, for pointer kinds, the target entity and field. The gist says only what those do not: "Telephone number" is enough where the pool is `literal_contact`, with "main switchboard line" added only if it is not already clear. The gist never repeats the context.

### 3. Context is assembled at read time and at write time

- **Read (the chooser):** each candidate is presented as key, pool context and gist. This is already the chooser's input in ADR-0040 ("key plus gist per literal"); the addition is that the pool and name context are assembled next to the gist, not baked into it. **Values are still never sent to the chooser.**
- **Write (authoring):** the editing form, and the write path of any tool, show the same assembled context beside the gist field, so an author writes only the delta. The write-time match check (ADR-0040 piece 5) is the one step that sees the value; assembling context for authoring must not add the value to anything sent to a hosted model beyond what that step already does.

### 4. Annotations bridge, now with a shared word

Where a literal points at an entity field, its default gist may be copied from the annotation gist on that field (ADR-0040's optional Annotations bridge), a direct gist-to-gist copy. Neither module requires the other. Because the annotation gist describes the field and the literal gist describes the value held there, an inherited gist may need a one-line tweak ("main switchboard line" for the value, versus "telephone number" for the field); treat inheritance as a prefill, not a live link.

### 5. Derived gists: optional, suggestion only

Suggesting a gist (from the pool, key, name and, for pointer kinds, the target field's own gist or label) is a plausible opt-in, with a human approving it. It could reuse the proposer review queue ADR-0040 already designs for proposed edits and alias proposals, but that reuse is not decided. Not part of the base design.

### 6. Config versus database: no extra cost for literals

ADR-0040 already settled that literal values are content (pools are config, literal rows are content), so editing a phone number never needs a config import. The gist is a field on that same row and follows it, so it adds no new storage trade-off here. The config-versus-database cost discussed in annopm ADR-028 applies to annotations, where help text would otherwise live in field config.

### 7. Factoring the finder

Build `LiteralChooser` and the tiered finder behind an interface now. Extract the shared interface and finder into a small module only when annotations gains its find-by-gist operation, so there are two real consumers. Possible eventual home: a contribution to `drupal/ai`, as ADR-0040 already suggests for the finder interface.

### Open questions added

- Pointer-kind literals: prefill the gist from the target field's annotation gist only at creation, or offer a refresh when that gist changes?
- Should the chooser's assembled context include the pool name, or is the pool boundary enough to carry that meaning?
