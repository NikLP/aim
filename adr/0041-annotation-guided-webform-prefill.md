# ADR-0041: Annotation-guided Webform pre-fill from recalled facts and literals

**Status:** Proposed 2026-10-04 - design only, nothing built. Replaces the
2026-10-03 draft of this number ("a discovery interview that emits a
portable Webform"), dropped as the wrong tree: generating forms is a
prompt-to-YAML task any model does without memory.
**Date:** 2026-10-04

## Context

Filling in a form is a recall problem. A visitor's name, address and
preferences are already known to the site, and the form should offer them
rather than ask again.

Two pieces already exist or are designed, and meet at the form:

- **Annotations describes the hole.** An annotation on a webform element
  (`annotations_webform`, target `webform_submission__{id}`, per element)
  says what the field wants. With the annotation type's `in_ai_context`
  setting on, an agent can read it through `annotations_tool`
  (`annotations_read`) or `/api/annotations/{target_id}`. This is the
  "in" direction in [ADR-0040](../../literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md)'s
  framing.
- **`aim` supplies the value.** General facts and, if ADR-0040 is built,
  literals. Facts are atomic and form-agnostic: "lives at 12 Mill Lane,
  Leeds" fills every form that asks for an address. No fact describes a
  form or a field; that knowledge stays in annotations.

Checked 2026-10-04: `annotations_webform` has no token or interpolation
support and needs none for this. `ai_webform` is dropped (obsolete, and
its generation and front-end fill do nothing the agent below does not).

## Decision (proposed)

An agent with two tool sets fills the form, per element:

1. Read the element's annotation (what the field wants).
2. Build a recall query from the field label plus that text, and recall as
   the **viewing account** (so `scope: user` visibility rules apply).
3. Take the best match, split it to the field's shape if needed (a street,
   city and postcode fact feeding three fields), and propose the value.
   Exact values (phone numbers, IDs) come from literals via ADR-0040's
   shortlist-then-choice lookup rather than from fact text.
4. If nothing scores within `recall_max_distance`, or the match is
   ambiguous, leave the field blank.
5. The visitor reviews every proposed value and submits. Nothing is saved
   back to memory without explicit confirmation.

Site and role facts act as policy: "never collect date of birth" makes the
agent skip that field. A `scope: case` fact supplies the case ID when a
form is filled in a case context.

No change to `aim` core. The consumer is an `aim_chatbot`-style
`ai_agents` assistant (or MCP client) holding `aim_recall` plus
`annotations_read`; the open piece is how it writes values into the
rendered form.

## Consequences

- Reuses annotations, recall and (later) literals unchanged; adds no
  dependency to `aim` (the agent lives in a bridge such as ADR-0024's
  `aim_annotations`, or a site-level assistant).
- **Annotation quality sets fill quality.** Vague annotations give wrong
  guesses; this is the cost of putting the schema in prose.
- **The mapping is LLM-judged and non-deterministic.** Human review before
  submit is mandatory, not optional.
- **Facts are free text.** Splitting one fact across several fields is real
  work for the agent. Literals (ADR-0040) are the more reliable source for
  structured values.
- **Privacy.** Recalled facts and the visitor's text go to the configured
  provider; decision 5 (local models) applies. Recall must never run as a
  broader account than the viewer.
- **`annotations_webform` limit:** element annotations work for top-level
  elements only, not inside fieldsets, containers or wizard pages, which
  excludes many real forms until fixed there.
- Writing the answers back (a submitted form as a source of candidate
  facts) is possible through extraction but out of scope here.

## Open questions

- How does the agent write values into a rendered form: a front-end
  widget, a Webform pre-population endpoint, or the chatbot telling the
  visitor what to enter? Needs a look at Webform's own APIs.
- Is the loop worth an LLM at all for forms whose elements map cleanly to a
  fixed fact category? A deterministic Webform token default
  (`[aim:...]`) might cover those cheaply.
- Depends on ADR-0024's bridge decisions (fact-to-target linkage) only if
  facts are ever scoped to annotation targets; plain per-user recall needs
  no linkage.
