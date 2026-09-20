# ADR index

Architecture Decision Records for the `aim` module.

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-storage-and-scope-model.md) | Storage and scope model | Accepted; scope-model deviation resolved 2026-09-15 |
| [0002](0002-governance-deferred-guardrails-mandatory.md) | Governance deferred for PoC; Guardrails mandatory from day one | Accepted |
| [0003](0003-async-processing-dedicated-crontab.md) | Async processing via Queue API and a dedicated crontab, never `hook_cron` | Accepted |
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
| [0017](0017-query-embedding-cache.md) | Query-embedding cache via a drupal/ai event subscriber | Proposed - designed, not built |
| [0018](0018-index-subject-uid-with-btree.md) | Index `subject_uid` as a Search API attribute, with a BTREE index on its column | Proposed - analyzed, not built; empty-value handling still open |
| [0019](0019-recall-abstention-distance-cutoff.md) | Recall abstention via a distance cutoff, and what bounds recall quality | Accepted - chatbot cutoff built 2026-09-19; follow-ups not built |
