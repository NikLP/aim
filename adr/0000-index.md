# ADR index

Architecture Decision Records for the `aim` module.

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-storage-and-scope-model.md) | Storage and scope model | Accepted; scope-model deviation resolved 2026-09-15 |
| [0002](0002-governance-deferred-guardrails-mandatory.md) | Governance deferred for PoC; Guardrails mandatory from day one | Accepted; draft-to-trusted resolved as a lightweight flag (not Content Moderation) 2026-09-28, not yet built |
| [0003](0003-async-processing-dedicated-crontab.md) | Async processing via Queue API and a dedicated crontab, never `hook_cron` | Accepted; enqueue mechanism updated 2026-09-26 |
| [0004](0004-sovereignty-and-poc-build-order.md) | Sovereignty option and zero-API-key PoC build order | Accepted |
| [0005](0005-consolidation-algorithm.md) | Consolidation algorithm: ADD/UPDATE/DELETE/NOOP with soft supersede | Accepted |
| [0006](0006-agent-native-write-path.md) | Agent-native write path bypassing extraction's LLM call | Accepted |
| [0007](0007-user-scope-requires-real-account.md) | User-scope facts must reference a real Drupal account | Accepted, extraction mechanism superseded by 0011 |
| [0008](0008-chatbot-integration-mechanism.md) | Chatbot integration: `ai_agents` tools, not a CCC content source | Accepted |
| [0009](0009-recipe-apply-safety-gate.md) | No unattended `drush recipe apply` on an AI-generated recipe | Accepted (design only, not yet built) |
| [0010](0010-drupal-native-agent-memory-rationale.md) | Original rationale, market appraisal, and risk analysis (folded in from repo root 2026-09-10) | Accepted as founding rationale, superseded by 0001-0009 for binding decisions |
| [0011](0011-extraction-explicit-subject-uid.md) | Extraction never guesses scope=user account matches; requires explicit `--subject-uid` | Accepted |
| [0012](0012-fact-relation-graph.md) | Fact-to-fact relation graph and multi-hop retrieval | Proposed - exploratory estimate, not built |
| [0013](0013-mcp-tool-exposure.md) | MCP tool exposure via Tool API (`tool`/`mcp_server`/`mcp_server_tool_bridge`) | Accepted - built and OAuth-authenticated, 2026-09-12/13 |
| [0014](0014-usecase-archetype-starter-kits.md) | Use-case archetype starter kits via Recipes | Proposed - exploratory estimate, not built |
| [0015](0015-immediate-consolidation-considered-deferred.md) | Immediate post-write consolidation via kernel.terminate | Proposed - designed and prototyped, deliberately deferred |
| [0016](0016-document-ingestion-ui.md) | Document ingestion via a Drupal form: three modes, one shared core | Mode 1 accepted (build now); Mode 2 blocked on ADR-0002; Mode 3 out of scope |
| [0017](0017-query-embedding-cache.md) | Query-embedding cache via a drupal/ai event subscriber | Accepted - built and verified 2026-09-27 |
| [0018](0018-index-subject-uid-with-btree.md) | Index `subject_uid` as a Search API attribute, with a BTREE index on its column | Accepted - built and verified 2026-09-26 |
| [0019](0019-recall-abstention-distance-cutoff.md) | Recall abstention via a distance cutoff, and what bounds recall quality | Accepted - chatbot cutoff built 2026-09-19; follow-ups not built |
| [0020](0020-verbatim-facts-consolidation-opt-out.md) | Verbatim facts: an explicit opt-out from consolidation | Proposed - analyzed, not built; build/no-build undecided |
| [0021](0021-jev-typed-decision-provider.md) | Jev (TypeSafe AI) as a typed-decision provider: spike, not adoption; addendum on Laya, an open-source alternative | Deferred - Laya spikes first (self-hosted), post-DrupalCon; Jev queued behind it |
| [0022](0022-exclude-retired-facts-from-vector-index.md) | Retired facts stay out of the vector index (Search API processor) | Accepted - built and rolled out 2026-09-26 |
| [0023](0023-hnsw-tuning-and-thin-provider-shim.md) | HNSW tuning (M=16, ef_search=100), and keeping the provider shim thin | Accepted - built and verified 2026-09-26 |
| [0024](0024-annotations-integration-target-scoped-promotion.md) | Annotations integration: facts scoped to annotation targets, review as promotion | Proposed - blocker 2 (Annotations write path) done 2026-09-28; blocker 1 (trust gate) designed, not built; still needs `scope: entity` + its ADR-0025 plugin |
| [0025](0025-scope-access-plugin-type.md) | Scope-specific view access as a plugin type, not hardcoded bundle branches | Proposed - exploratory, seam only, no second dedicated plugin built yet |
