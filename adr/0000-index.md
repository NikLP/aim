# ADR index

Architecture Decision Records for the `aim` module.

## Active

Still carrying work, proposed, or deferred.

| ADR | Title | Status |
| --- | --- | --- |
| [0002](0002-governance-deferred-guardrails-mandatory.md) | Governance deferred for PoC; Guardrails mandatory from day one | Accepted; draft-to-trusted resolved as a lightweight flag (not Content Moderation) 2026-09-28, built 2026-09-28; the `trusted` override on `remember()` is still ungated (TODO.md) |
| [0008](0008-chatbot-integration-mechanism.md) | Chatbot integration: `ai_agents` tools, not a CCC content source | Accepted |
| [0012](0012-fact-relation-graph.md) | Fact-to-fact relation graph and multi-hop retrieval | Proposed - exploratory estimate, not built |
| [0014](0014-usecase-archetype-starter-kits.md) | Use-case archetype starter kits via Recipes | Proposed - exploratory estimate, not built |
| [0016](0016-document-ingestion-ui.md) | Document ingestion via a Drupal form: three modes, one shared core | Mode 1 accepted (build now); Mode 2 blocked on ADR-0002; Mode 3 out of scope |
| [0019](0019-recall-abstention-distance-cutoff.md) | Recall abstention via a distance cutoff, and what bounds recall quality | Accepted - chatbot cutoff built 2026-09-19; follow-ups not built |
| [0020](0020-verbatim-facts-consolidation-opt-out.md) | Verbatim facts: an explicit opt-out from consolidation | Proposed - analyzed, not built; build/no-build undecided |
| [0021](0021-jev-typed-decision-provider.md) | Jev (TypeSafe AI) as a typed-decision provider: spike, not adoption; addendum on Laya, an open-source alternative | Deferred, updated 2026-10-02 - Decision API now in core `drupal/ai` 1.6, Ollama serves decision models (tev1); no spike scheduled |
| [0024](0024-annotations-integration-target-scoped-promotion.md) | Annotations integration: facts scoped to annotation targets, review as promotion | Proposed - blocker 2 (Annotations write path) done 2026-09-28; blocker 1 (trust gate) designed, not built; `scope: entity` + its ADR-0025 plugin now built ([ADR-0027](resolved/0027-entity-scope.md), 2026-09-29); the bridge module's own write path is still unbuilt |
| [0029](0029-context-carrying-turns.md) | Context-carrying turns: mention tokens, an API-first console with context chips, `batch_id` provenance, one review pipeline | Accepted 2026-09-30 - design only, not built |
| [0030](0030-fact-groups.md) | Fact groups: a scope-agnostic `group` field shipped as an `aim_group` submodule, plus a generic `recall()` filter seam | Accepted 2026-09-30 - design only, not built |
| [0031](0031-cron-fallback-for-queue-processing.md) | Opt-in `hook_cron` fallback for queue processing, alongside the dedicated crontab | Proposed 2026-09-30 - design only, not built; amends 0003 |
| [0032](0032-dated-category-listing-for-quick-notes.md) | No `event` scope for diary-style notes: category + `asserted` date, a non-vector list method, and tid-based capture in the Tool API | Proposed 2026-10-01 - design only, not built |
| [0033](0033-plausibility-gate-processing-modes.md) | Plausibility gate on new facts: untrusted until scored, fail closed, and queued vs inline vs post-response processing | Proposed 2026-10-01, addendum 2026-10-02 - design only, not built; measured on the dev laptop (laya:en, then tev1:4b) |
| [0034](0034-split-chat-assistants-read-only-public.md) | Split chat assistants: read-only public bot, staff bot with `remember`, gated by tool permission not block visibility | Proposed 2026-10-01 - design only, not built |
| [0035](0035-standing-constraints-action-gate.md) | Standing constraints: structured "don't build X until Y" facts enforced by a deterministic action-layer gate, semantic recall advisory only | Proposed 2026-10-01 - design only, not built |
| [0036](0036-memory-algorithm-appraisal.md) | Memory algorithm appraisal: what to borrow from the literature (recency/importance re-rank, RRF, decay), and what aim does badly | Proposed 2026-10-02 - research only, nothing built |
| [0037](0037-transient-source-passages-for-grounding.md) | Transient source passages (side table + pointer, deleted after the check) so a decision model can check a candidate against what was actually said | Proposed 2026-10-02 - design only, not built; blocked on the grounded evaluation set |
| [0038](0038-local-decision-models-parked.md) | Local decision models: measured, parked, and how to bring one back (Laya, `tev1`, memory-capped services) | Parked |
| [0039](0039-token-scope-live-config-values.md) | `scope: token`: label-only facts whose value resolves live from a `config_pages` token at recall, access delegated to the config page, never consolidated | Proposed 2026-10-03 - design only, not built |
| [0040](0040-literals-probabilistic-lookup-of-exact-values.md) | Literals: a `literal` field type (key, value, optional gist), found by vector shortlist then a Jev choice; standalone or as an `aim` scope that subsumes `entity` and `token` | Proposed 2026-10-03, revised 2026-10-04 - design only, not built; generalizes 0039, would replace built 0027 |
| [0041](0041-annotation-guided-webform-prefill.md) | Annotation-guided Webform pre-fill: annotations describe each field, an agent fills it from recalled facts and literals as the viewing account | Proposed 2026-10-04 - design only, not built; replaces the 2026-10-03 interview-to-Webform draft |
| [0042](0042-fact-compiled-briefs.md) | Fact-compiled briefs: a readable per-subject document compiled from trusted facts, following the `annotations_docs` pattern (draft, edit, lock, publish) | Proposed 2026-10-04 - design only, not built; prompted by the "agents need documentation" critique |
| [0043](0043-content-truth-drift-audit.md) | Content-versus-truth drift audit: live facts as an independent reference, a typed decision flags published content that contradicts them, human resolves | Proposed 2026-10-04 - design only, not built; needs a content index and a measured false-positive rate |

## Resolved

Built and verified (or a standing decision with nothing left to build),
with any leftover items moved to [TODO.md](../TODO.md). A resolved ADR
remains binding, it just has nothing left to build.

| ADR | Title | Status |
| --- | --- | --- |
| [0001](resolved/0001-storage-and-scope-model.md) | Storage and scope model | Accepted; scope-model deviation resolved 2026-09-15 |
| [0003](resolved/0003-async-processing-dedicated-crontab.md) | Async processing via Queue API and a dedicated crontab, never `hook_cron` | Accepted; enqueue mechanism updated 2026-09-26 |
| [0004](resolved/0004-sovereignty-and-poc-build-order.md) | Sovereignty option and zero-API-key PoC build order | Accepted - standing principle |
| [0005](resolved/0005-consolidation-algorithm.md) | Consolidation algorithm: ADD/UPDATE/DELETE/NOOP with soft supersede | Accepted - built |
| [0006](resolved/0006-agent-native-write-path.md) | Agent-native write path bypassing extraction's LLM call | Accepted - built |
| [0007](resolved/0007-user-scope-requires-real-account.md) | User-scope facts must reference a real Drupal account | Accepted - built; extraction mechanism superseded by 0011 |
| [0009](resolved/0009-recipe-apply-safety-gate.md) | No unattended `drush recipe apply` on an AI-generated recipe | Retired 2026-10-02 - subsumed by 0035's action gate; never built |
| [0010](resolved/0010-drupal-native-agent-memory-rationale.md) | Original rationale, market appraisal, and risk analysis (folded in from repo root 2026-09-10) | Founding rationale; binding decisions superseded by 0001-0009; open questions live in TODO.md |
| [0011](resolved/0011-extraction-explicit-subject-uid.md) | Extraction never guesses scope=user account matches; requires explicit `--subject-uid` | Accepted - built |
| [0013](resolved/0013-mcp-tool-exposure.md) | MCP tool exposure via Tool API (`tool`/`mcp_server`/`mcp_server_tool_bridge`) | Accepted - built and OAuth-authenticated, 2026-09-12/13 |
| [0015](resolved/0015-immediate-consolidation-considered-deferred.md) | Immediate post-write consolidation via kernel.terminate | Deferred - closed on this site by `index_directly`; design kept for hosted embeddings |
| [0017](resolved/0017-query-embedding-cache.md) | Query-embedding cache via a drupal/ai event subscriber | Accepted - built and verified 2026-09-27 |
| [0018](resolved/0018-index-subject-uid-with-btree.md) | Index `subject_uid` as a Search API attribute, with a BTREE index on its column | Accepted - built and verified 2026-09-26 |
| [0022](resolved/0022-exclude-retired-facts-from-vector-index.md) | Retired facts stay out of the vector index (Search API processor) | Accepted - built and rolled out 2026-09-26 |
| [0023](resolved/0023-hnsw-tuning-and-thin-provider-shim.md) | HNSW tuning (M=16, ef_search=100), and keeping the provider shim thin | Accepted - built and verified 2026-09-26 |
| [0025](resolved/0025-scope-access-plugin-type.md) | Scope-specific view access as a plugin type, not hardcoded bundle branches | Accepted - built and verified 2026-09-28; widened by 0028 |
| [0026](resolved/0026-pluggable-scope-submodules.md) | Pluggable scope submodules: ThirdPartySettings for config, ADR-0025's plugin type for behavior | Built (pieces 1-5); piece 6, the bundling recipe, tracked in TODO.md |
| [0027](resolved/0027-entity-scope.md) | `scope: entity`: facts about an arbitrary Drupal entity, view access mirroring that entity's own | Built and verified 2026-09-29; remaining polish tracked in TODO.md |
| [0028](resolved/0028-scope-type-plugin.md) | Widen the scope-access plugin into a scope-type plugin: fields, settings, and behavior in one seam | Built and verified 2026-09-29 (pieces 0-4) |
