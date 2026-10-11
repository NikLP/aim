# ADR-0053: "Gist" as a separate searchable field, shared by annotations and literals

**Date:** 2026-10-04
**Status:** Proposed - design only, nothing built. Revised 2026-10-04: the first draft proposed renaming the annotation `value` field to `gist`; discussion replaced that with adding `gist` as a second field. Trimmed 2026-10-11: the body lives in ADR-028 in the `annopm` repo and is summarized here. The addenda differ: annopm's covers annotations, this one covers literals. The annotations changes are dotdev-repo work, not aim work. **The addendum at the end narrows the body where they conflict; for literal mechanics, [ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md) and its addenda still govern.**

## Summary

Annotations and literals both need a word for a short natural-language
description of a record's referent, used as its address: the **gist**.
Annotations add `gist` as an optional second field beside their main
guidance text (empty falls back to the main text); literals use it from day
one, with `value` kept for the exact returned thing. Gist describes a field
in annotations and a value in literals. Operations are named by direction:
annotations read by target and find by question; literals find by question
and get by key. The finder (candidates as id + gist in, an access-filtered
choice out) is meant to be shared once annotations gains find-by-gist, in a
small third module or upstream in `drupal/ai`; literals builds it first
behind an interface, because extracting before a second consumer exists means
guessing the interface. The full body (provenance in fuzzy-trace theory and
ReadAgent, the annotations-side changes, editor explanation, alternatives) is
in annopm ADR-028, not duplicated here.

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
