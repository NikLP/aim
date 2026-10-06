# ADR-0024: Annotations integration - facts scoped to annotation targets, review as promotion

**Status:** Proposed - exploratory, not built. Blocked on ADR-0002's
deferred draft-to-trusted gate and a write path that doesn't exist yet on
the Annotations side. Written to capture the idea and the real blockers
before any schema/code work starts, not to lock in a mechanism.
**Date:** 2026-09-27

## Context

Raised in a separate conversation comparing `aim` to Annotations (Nik's
other Drupal module, `drupal/annotations` ^2.0@alpha, present in this site
at `web/modules/contrib/annotations`, own git repo, not a dependency of
`aim`'s own `composer.json`). Framing: Annotations is the human-curated
layer of site knowledge, `aim` is the machine-observed layer.

**A factual correction to that conversation, checked directly against
the module's code and docs rather than assumed.** The claim was that an
"`ai_context` provider patch" already gets annotations into agent
prompts. No such patch exists, and none is needed. Annotations ships two
submodules that are entirely independent of `drupal/ai_context` (CCC):

- `annotations_context` - assembles a structured payload and exposes it
  as a markdown/HTML preview, a JSON API (`/api/annotations/{target_id}`),
  and its own MCP endpoint (`/api/annotations/mcp`, Streamable HTTP,
  2025-03-26 spec).
- `annotations_tool` - Tool API plugins (`annotations_read`,
  `annotations_list_targets`) for function-calling agents, gated on
  `view annotations context` and the annotation type's `in_ai_context`
  third-party setting.

Neither depends on or patches `ai_context`. This is a stronger argument
for fit than the original claim, not a weaker one: Annotations
independently reached the same conclusion [ADR-0008](0008-chatbot-integration-mechanism.md)
already documents for `aim` - Tool API primary, not a CCC content-source
plugin - and that ADR's own consequences section already flags this kind
of independent convergence as corroborating, not coincidental.

**Confirmed correct in the original conversation:** `annotation_target`
granularity is bundle/field/role-level (`id = {entity_type}__{bundle}`,
e.g. `node__article`), never a specific node instance. This integration
is naturally structural (site/role-scoped knowledge), not a route to
per-content-instance "content notes" - that would need `aim` facts
referencing specific nodes directly, unrelated to this ADR.

**Confirmed gap, from `annotations_tool`'s own CLAUDE.md (as of
2026-09-27):** there was no write path. `AnnotationStorageService`
exposed only `getForTarget`/`getLatestForTarget`/`getEntitiesForTarget`/
`getEntityMapForTarget`/`hasAnnotationData`/`deleteForTarget`/
`countForType`/`deleteForType` - no `save()`. That module's own docs
flagged a write tool as "worth doing if an actual agent-authoring use
case shows up, not a gap to fill preemptively" - this ADR is that use
case. **Update 2026-09-28: done, per Nik** - the write path was built.
Re-verify its exact shape against `annotations_tool`'s current CLAUDE.md
before wiring the bridge to it, rather than assuming the interface
guessed at above.

**Confirmed gap, from `aim`'s own decision 3:** [ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s
draft-to-trusted gate is deferred for the PoC. Every `aim_fact` is live
the instant it's saved. There is no draft tier on `aim`'s side to promote
*from* yet either. **Update 2026-09-28:** the design for this is now
resolved (ADR-0002's addendum) as a single `trusted` boolean field, not
Content Moderation - much cheaper than this ADR originally assumed, but
still not built. Promotion itself stays fully programmatic either way -
it never depended on which mechanism `aim_fact` used for its own gate.

## Decision (best-guess proposal - not committed)

Three separable pieces:

**1. Dependency direction and packaging.** A thin bridge submodule,
`aim_annotations`, living under `aim` (`aim` depends toward Annotations,
never the reverse). This matches the thin-shim convention
[ADR-0023](resolved/0023-hnsw-tuning-and-thin-provider-shim.md) already sets for
`AimMariaDBProvider`, and keeps Annotations - the funded, closer-to-stable
project - free of any dependency on `aim`'s experimental stack. `aim`
stays independently usable without Annotations installed.

**2. What a fact points at.** `aim_fact` gets linked by `annotation_target`
id (e.g. `node__article`), not by referencing a specific `annotation`
content entity. A fact can then exist before anyone has written an
annotation for that target, and the two modules meet at the target rather
than `aim` reaching into Annotations' content entities directly.

**3. Review becomes promotion.** A reviewer looking at a draft `aim_fact`
linked to a target gets a "promote to annotation" action: it
creates/updates the `annotation` entity for that target through a new
write path on the Annotations side (shaped like `tool_belt`'s
`EntitySave`, the precedent `annotations_tool`'s own CLAUDE.md already
names for a future write tool), then marks the source `aim_fact` as
consumed/superseded. Annotations becomes the trusted tier; `aim`'s review
queue becomes the drafts tier. This is meant to replace or absorb
ADR-0002's in-place draft-to-trusted gate for facts that have a target to
promote into, not run alongside it as a second, separate gate.

## Consequences / risks

- **Nothing to build this on top of yet.** Two prerequisites gate this
  entirely: ADR-0002's gate (something for a human to review before
  promotion) and a `save()` write path on `AnnotationStorageService`
  (something to promote into). Building the bridge before either exists
  produces a bridge with nothing connected at either end.
- **Bridge maintenance cost.** `aim_annotations` tracks both modules'
  schemas at once. A change to `aim_scope`'s config-entity shape or to
  `annotation_target`'s id format breaks the bridge, not either core
  module - the same fragility ADR-0023 already accepts for
  `AimMariaDBProvider`, now duplicated across two independent projects
  instead of one upstream dependency.
- **Staleness detection is a separate, unbuilt mechanism.** If a fact is
  linked to a target and that target changes (a new annotation revision,
  a bundle's fields changing), the fact should be flagged for review.
  Likely modeled on `annotations_audit`'s existing waypoint/
  accumulated-changes drift detection rather than invented fresh, but not
  designed here.
- **Guardrails apply twice.** Promoting a fact into an annotation is a
  second write with its own trust implications - decision 7's
  Guardrails-mandatory stance (live today, not part of the governance
  deferral) should run again at promotion time, not only at fact-write
  time, since the text is about to become site-official documentation
  consumed by `annotations_context`'s MCP/Tool API/JSON surfaces.
- **Scope discipline.** The originating conversation also raised
  RAG-plus-memory correction capture, source-revision staleness for
  general RAG content, and job-market framing. Those are real but
  separate ideas, deliberately not captured here - this ADR is only the
  Annotations-specific integration.

## Open questions

- Whether target-linked facts need a new `aim_scope` bundle (e.g.
  `scope: annotation`) or just a field on the existing `site`/`role`
  scopes holding a target id string. A new bundle adds another
  `view {scope} aim facts`/`create {scope} aim facts` permission pair for
  what may be pure linkage, not a new audience - worth resisting unless a
  real access-control need shows up.
- Who can trigger promotion: reuse Annotations' existing
  `edit {type} annotations` permission, or a new permission scoped to the
  bridge module.
- Manual admin action (a button on the draft-fact review UI) vs. a Drush
  command or queue worker that can batch-promote. Manual first is
  consistent with [ADR-0004](resolved/0004-sovereignty-and-poc-build-order.md)'s
  build order (prove the mechanism interactively before automating it).
- Whether [ADR-0012](0012-fact-relation-graph.md)'s entity-linking/
  co-mention idea (cited there from `mem0ai/mem0`) has any bearing on
  target-linked facts too - out of scope here, flagged only as a
  "see also."

None of the above is validated against real code paths on either module -
this captures what's known and where the real blockers are, not a spec to
build against as-is.

## Addendum 2026-10-04: a second consumer, literal gists

[ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md) (Literals) reads the same mapping the other way: Annotations describes a field and asks what goes there, Literals describes a value and asks where it lives. An annotation's `value` can serve as a literal's gist (singleton hosts such as `config_pages` pages), and Annotations' target discovery can supply the entity/bundle/field picker. That is a separate optional bridge from the one proposed above, with the same review-as-promotion shape; the two may share work. Annotations attaches at bundle and field granularity, never an entity instance, which limits the gist use to singletons. Nothing here changes this ADR's status or blockers.

## Addendum 2026-10-05: Tool API notes from Matt Glaman's capability-once post

[Define the capability once, call it anywhere](https://mglaman.dev/blog/define-capability-once-call-it-anywhere) bears on this ADR's Tool API surface (`annotations_tool`, the proposed promotion write tool). Points to apply when that write tool is designed, all **unverified** against released tags and the issue queue: set `operation: ToolOperation::Write` and judge `destructive` honestly; keep inputs and outputs typed so an agent can chain them; mutate through a staging store rather than saving directly (the `canvas_tools` approach, which fits review-as-promotion); and expect `tool:info` and MCP schemas to flatten inputs (no enums, no required permissions) while Tool API issue #3582943 is open. The same findings for `aim_tool` are in [TODO.md](../TODO.md) under "aim_tool: schema discoverability and typed output". Nothing here changes this ADR's status or blockers.
