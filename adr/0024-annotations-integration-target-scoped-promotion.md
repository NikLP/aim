# ADR-0024: Annotations bridge: facts scoped to annotation targets, review as promotion, and the two other consumers (form pre-fill, briefs)

**Status:** Proposed - design only, the bridge module is unbuilt. Both
blockers listed on 2026-09-27 are now cleared (re-checked 2026-10-11):
the `trusted` flag and its Trust/Untrust actions are built
([ADR-0002](resolved/0002-governance-deferred-guardrails-mandatory.md) addendum), and
Annotations has a write path (see Context; confirmed by Nik 2026-10-30,
though this site's `contrib/annotations` copy still has no
`annotations_tool`). Absorbs the former ADR-0041
(Webform pre-fill) and ADR-0042 (fact-compiled briefs). The WebMCP
direction may supersede part of this.
**Date:** 2026-09-27

## Context

Annotations (Nik's other Drupal module, `drupal/annotations` 2.0.0-alpha,
own git repo, not a dependency of `aim`) is the human-curated layer of
site knowledge; `aim` is the machine-observed layer. Checked against its
code and docs, not assumed:

- **No `ai_context` (CCC) patch is involved.** `annotations_context`
  assembles a payload and exposes it as markdown/HTML, a JSON API
  (`/api/annotations/{target_id}`) and its own MCP endpoint;
  `annotations_tool` provides Tool API plugins for function-calling
  agents. Annotations independently reached the conclusion of
  [ADR-0008](resolved/0008-chatbot-integration-mechanism.md): Tool API, not a CCC
  content-source plugin.
- **`annotation_target` granularity is bundle/field/role** (`id =
  {entity_type}__{bundle}`, e.g. `node__article`), never one node
  instance. The bridge is structural (site- and role-scoped knowledge);
  facts about one instance use `scope: entity`
  ([ADR-0027](resolved/0027-entity-scope.md), built).
- **The write path exists in the development copy, not in this site's.**
  `annotations_tool` there ships `annotations_write` (`ToolOperation::Write`;
  `target_id`, `field_name`, `type_id`, `value`, optional `langcode`;
  upserts one annotation's `value` via `AnnotationStorageService::writeValue()`)
  and `annotations_read` (renamed from the old `GetAnnotations`, 2026-09-28).
  Authorization is at the storage layer: the account needs `edit {type}
  annotations`, and a denial returns a typed access failure. This site's
  `contrib/annotations` copy (2.0.0-alpha4) is older: read and list tools
  only, no write. Re-verify the shape against the version installed when
  the bridge is built.
- **Annotations has a draft-and-publish pattern aim can copy.**
  `annotations_docs` generates one AI draft per (target, type) as an
  unpublished node, per role and language, with a lock against
  regenerate and a manual publish step.
- **Known limit:** `annotations_webform` shows an element's annotation only
  for top-level elements, not inside fieldsets, containers or wizard
  pages.

## Decision (best-guess proposal, not committed)

**1. Packaging.** A thin bridge submodule, `aim_annotations`, under `aim`:
`aim` depends toward Annotations, never the reverse, and stays usable
without it. Same thin-shim convention as
[ADR-0023](resolved/0023-hnsw-tuning-and-thin-provider-shim.md).

**2. What a fact points at.** A fact is linked by `annotation_target` id,
not by a specific `annotation` entity, so it can exist before anyone writes
the annotation. `scope: entity` does not fit (it names an instance, a
target names a bundle or field), so this is either a field on the `site`/
`role` scopes or a new scope; resist a new `view/create {scope} aim facts`
permission pair unless a real access need shows up.

**3. Review becomes promotion.** A reviewer looking at an untrusted fact
linked to a target gets a "promote to annotation" action. It writes the
annotation through `annotations_write` (a staging store rather than a
direct save is worth checking; Tool API notes from Matt Glaman's
"Define the capability once, call it anywhere", all unverified against
released tags: set `operation: Write` and judge `destructive` honestly,
keep inputs and outputs typed, expect enums and permissions to flatten in
`tool:info` and MCP schemas while Tool API issue #3582943 is open), then
retires the source fact with `superseded_by_reason` naming the annotation.
Annotations becomes the trusted tier; the review queue is the drafts tier.
Guardrails run again at promotion, since the text becomes site-official
documentation served by `annotations_context`.

**4. Consumers (formerly ADR-0041 and ADR-0042).** Two features sit on the
same pair of modules and need no change to `aim` core.

*Webform pre-fill.* An agent holding `aim_recall` plus `annotations_read`
fills a form per element: read the element's annotation (what the field
wants); recall as the **viewing account** from the field label plus that
text; take the best match under `recall_max_distance` (split it to the
field's shape if needed; exact values such as phone numbers come from
literals, [ADR-0040](../../../contrib/literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md));
leave the field blank if nothing matches or the match is ambiguous; the
visitor reviews every value before submit, and nothing is saved back
without confirmation. Site and role facts act as policy ("never collect
date of birth"); a `scope: case` fact supplies the case ID. Costs:
annotation quality sets fill quality, the mapping is LLM-judged so human
review is mandatory, facts are free text so splitting is real work, and
recalled facts go to the configured provider. `ai_webform` is dropped
(obsolete); `annotations_webform` has no token support and needs none.

*Fact-compiled briefs.* A readable brief per scope subject (a user, a case,
an entity target) compiled from that subject's live, trusted, unsuperseded
facts and injected whole into an agent's context, for the cases top-k recall
misses (unknown unknowns) and for human review of a pile of atomic facts
(Liao, "Agents don't need memory, they need documentation", 2026-10-04: one
author's anecdote, no benchmarks). Built by `aim` itself following
`annotations_docs` as a pattern, not using it: an AI draft stored
unpublished, optional per-role filtering, a manual publish step, a config
entity for the system prompt. Not copied: direct editing of the brief,
since an edit that bypasses the facts forks them. Facts stay the source of
truth; the body is read-only and regenerated when facts change; each brief
records the fact IDs it came from. Correction is by feedback: a reviewer
marks up a sandbox copy and an agent turns it into fact changes through the
normal Guardrails and trust path. Injection is size-bounded and falls back
to recall. One brief per user is an LLM call each, so likely only for case,
entity and site subjects.

## Consequences / risks

- **Nothing built on either side yet** beyond the prerequisites; the bridge
  tracks two modules' schemas at once (a change to `aim_scope` config or
  to the `annotation_target` id format breaks the bridge, not either
  module).
- **Staleness is a separate unbuilt mechanism:** if a target changes, its
  linked facts should be flagged, likely modeled on `annotations_audit`'s
  drift detection. See also
  [ADR-0043](0043-content-truth-drift-audit.md), which checks published
  content against facts and needs no Annotations dependency.
- **Gist.** [ADR-0049](0049-finder-family-parked.md) section D (body in annopm
  ADR-028) defines a shared "gist" field; an annotation's gist can prefill a
  literal's gist ([ADR-0040](../../../contrib/literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md)).
  Annotations attaches at bundle and field granularity, so this is limited
  to singleton hosts.
- **Privacy:** recall must never run as a broader account than the viewer.
- **Scope discipline:** RAG-plus-correction capture and source-revision
  staleness for general RAG content are separate ideas, not captured here.

## Open questions

- Who can trigger promotion: reuse `edit {type} annotations` or add a bridge
  permission. Manual button first, per
  [ADR-0004](resolved/0004-sovereignty-and-poc-build-order.md), batch later.
- How the pre-fill agent writes values into a rendered form (front-end
  widget, Webform pre-population endpoint, or telling the visitor), and
  whether forms that map cleanly to a fixed fact category are better served
  by a deterministic token default (`[aim:...]`) than an LLM.
- Brief feedback loop shape (draft node, diff view, comments), whether the
  resulting fact changes pass the grounded check
  ([ADR-0037](resolved/0037-transient-source-passages-for-grounding.md)) or
  wait for review, the regeneration trigger, and storage (a node type or an
  `aim`-owned entity; no node dependency in core `aim`).
- Whether [ADR-0012](resolved/0012-fact-relation-graph.md)'s entity linking
  bears on target-linked facts: out of scope, a "see also".
- A possible injection point (2026-10-15, from the `agent_access` trial):
  Tool Belt's `entity_metadata` MCP tool describes an entity type's bundles
  and fields. Annotations target entity type + bundle + field, so notes
  about what a field means could ride on that tool's output. Not built,
  and the tool is not enabled on this site; instance-level notes would
  still go through `scope=entity` facts.
