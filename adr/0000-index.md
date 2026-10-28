# ADR index

Architecture Decision Records for the `aim` module.

## Clusters (reading map, 2026-10-08)

The Active table is one list; these are the threads running through it.

- **Literals and the finder** - [0040](../../../contrib/literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md)
  (in the `literals` repo: what literals is for; absorbs the former 0048
  and 0052), [0061](0061-how-aim-uses-literals.md) (how aim uses it, answer
  modes),
  [0046](0046-core-cutoff-time-axis-and-shared-gist-finder.md); parked
  extensions and the gist vocabulary in [0049](0049-finder-family-parked.md).
- **Annotations bridge** (prerequisites cleared 2026-10-11, bridge module
  unbuilt; the WebMCP direction may supersede part of it) -
  [0024](0024-annotations-integration-target-scoped-promotion.md) (with the
  former 0041 and 0042), [0043](0043-content-truth-drift-audit.md).
- **Write-path gate and decision models** -
  [0002](0002-governance-deferred-guardrails-mandatory.md),
  [0037](resolved/0037-transient-source-passages-for-grounding.md) (the grounded check, built 2026-10-11, with the gate semantics of the former 0033),
  [0056](0056-personal-data-write-gate.md) (personal-data gate, parked),
  [0021](0021-jev-typed-decision-provider.md) (decision models: hosted Jev live, local parked, with the former 0038),
  [0035](0035-standing-constraints-action-gate.md),
  [0036](0036-memory-algorithm-appraisal.md) (research).
- **Chat, tools and front ends** - [0008](0008-chatbot-integration-mechanism.md)
  (with the split-assistants addendum), [0044](resolved/0044-tool-api-method-attributes.md),
  [0016](0016-document-ingestion-ui.md), [0029](0029-context-carrying-turns.md),
  [0062](0062-chat-on-the-shared-tools.md) (chat on `aim_tool`'s tools).

## Active

Still carrying work, proposed, or deferred.

| ADR | Title | Status |
| --- | --- | --- |
| [0002](0002-governance-deferred-guardrails-mandatory.md) | Governance deferred for PoC; Guardrails mandatory from day one | Accepted; draft-to-trusted resolved as a lightweight flag (not Content Moderation) 2026-09-28, built 2026-09-28; the `trusted` override on `remember()` gated 2026-10-19, writer-based starting trust 2026-10-22 (ADR-0037 addendum) |
| [0008](0008-chatbot-integration-mechanism.md) | Chatbot integration: `ai_agents` tools, not a CCC content source; addendum 2026-10-08 holds the former 0034 (split public read-only and staff write assistants, gated by tool permission) | Accepted; addendum proposed, not built |
| [0016](0016-document-ingestion-ui.md) | Document ingestion via a Drupal form: three modes, one shared core | Mode 1 accepted (build now); Mode 2 blocked on ADR-0002; Mode 3 out of scope |
| [0020](0020-verbatim-facts-consolidation-opt-out.md) | Verbatim facts: an explicit opt-out from consolidation | Proposed - analyzed, not built; build/no-build undecided |
| [0021](0021-jev-typed-decision-provider.md) | Decision models: hosted Jev is the live path, local models measured and parked; absorbs the former 0033 (processing modes) and 0038 (local models, small-machine runbook) | Accepted as current state, rewritten 2026-10-11; pass bar, retention and per-model config open (TODO.md) |
| [0024](0024-annotations-integration-target-scoped-promotion.md) | Annotations bridge: facts scoped to annotation targets, review as promotion; absorbs the former 0041 (Webform pre-fill) and 0042 (fact-compiled briefs) | Proposed - design only; prerequisites cleared (the `trusted` flag built, Annotations write path exists in the dev copy, `scope: entity` built); bridge module unbuilt |
| [0029](0029-context-carrying-turns.md) | Context-carrying turns: mention tokens, an API-first console with context chips, `batch_id` provenance, one review pipeline | Accepted 2026-09-30 - design only, not built |
| [0030](0030-fact-groups.md) | Fact groups: a scope-agnostic `group` field shipped as an `aim_group` submodule, plus a generic `recall()` filter seam | Accepted 2026-09-30 - design only, not built |
| [0031](0031-cron-fallback-for-queue-processing.md) | Opt-in `hook_cron` fallback for queue processing, alongside the dedicated crontab | Proposed 2026-09-30 - design only, not built (ADR-0057's `hook_cron` expiry sweep is a separate, built thing); amends 0003 |
| [0032](0032-dated-category-listing-for-quick-notes.md) | No `event` scope for diary-style notes: category + `asserted` date, a non-vector list method, and tid-based capture in the Tool API | Proposed 2026-10-01 - design only, not built |
| [0035](0035-standing-constraints-action-gate.md) | Standing constraints: structured "don't build X until Y" facts enforced by a deterministic action-layer gate, semantic recall advisory only | Proposed 2026-10-01 - design only, not built |
| [0036](0036-memory-algorithm-appraisal.md) | Memory algorithm appraisal: what to borrow from the literature (recency/importance re-rank, RRF, decay), and what aim does badly | Proposed 2026-10-02 - research only, nothing built |
| [0040](../../../contrib/literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md) | Literals (in the `literals` repo, drupal.org): a standalone `literal` entity (name, key, value, gist, type, restricted flag) found by a decision-model chooser over the whole menu; values never reach the model; a find-and-gate layer for things, not a chat-cost trick; consumers use tokens, `literals.lookup` and the finder as a gate; absorbs the former 0048 (token literals resolve for the asking account) and 0052 (what literals is for) | Accepted, current state, merged 2026-10-22 (history in git); aim's side split into 0061 on 2026-10-23; supersedes 0039 |
| [0043](0043-content-truth-drift-audit.md) | Content-versus-truth drift audit: live facts as an independent reference, a typed decision flags published content that contradicts them, human resolves | Proposed 2026-10-04 - design only, not built; needs a content index and a measured false-positive rate |
| [0046](0046-core-cutoff-time-axis-and-shared-gist-finder.md) | Where core stops: time as a retrieval axis instead of a `kind`, sibling modules for other semantics, literals standalone; absorbs the former 0045 (memory kinds: `aim_fact` is semantic by design, defer a `kind` field) | Proposed 2026-10-04, updated 2026-10-08 - pieces 1-3 design only; 4 done, 5 became `literals_finder` + 0049 |
| [0049](0049-finder-family-parked.md) | The finder family (merged 2026-10-08 from 0049/0050/0051): an inline `@` picker over pluggable providers, a Jev-driven meaning navigator choosing by gist, and a tool picker; model only picks from an enumerated, access-filtered list ; absorbs the former 0053 (the gist vocabulary) | Parked 2026-10-08 - design only, nothing built; WebMCP may supersede the widget half |
| [0057](0057-dates-resolved-and-confirmed-before-write.md) | Dates resolved and confirmed before write: the chat assistant states the calendar date in its confirmation, fact text carries the date, an end date sets `expires` (plus the expiry sweep), extraction resolves against the document date, time-limited facts never UPDATE-merged into permanent ones | Proposed 2026-10-11 - design only |
| [0056](0056-personal-data-write-gate.md) | No personal data stored unless authorized: refuse names and contact details at write time (patterns plus a decision-model check), authorized by `user` scope or a permission, never by a model; grounded check inline, no stored passages | Parked 2026-10-11 (proposed 2026-10-10) - design only; revisit later |
| [0058](0058-htmx-4-for-admin-forms.md) | htmx 4 for self-saving admin controls: per-row trusted toggle and the role-visibility matrix as candidates | Proposed 2026-10-13 - feasibility study only, nothing built |
| [0060](0060-adopt-ai-logging-for-model-calls.md) | Model-call logging through `ai_logging`, prompts and replies included; aim adds run ID and duration; stealth mode a later option | Built 2026-10-14; stealth not built |
| [0061](0061-how-aim-uses-literals.md) | How aim uses literals: tokens replaced at recall as the viewer, `aim_literal` over `literals.lookup`, the finder as a gate ahead of the chat model (measured), no scope or mirror; convert-a-fact, finder as a recall source and answer modes designed | Built 2026-10-08 to 10-15 (sections 1-2); section 3 unbuilt, tracked in TODO.md; split out of 0040 2026-10-23 |
| [0059](0059-single-valued-category-and-ingress-labelling.md) | Single-valued, indexed `category` that recall can filter on, set on every write route (labelled on ingress); free tagging becomes a scope-module concern | Proposed 2026-10-14 - design only, nothing built |
| [0062](0062-chat-on-the-shared-tools.md) | Chat on the shared tools: one `aim_recall`/`aim_remember` for chat and MCP; scope gated by permissions; the tool picks its trust channel from its Tool API invoker and says which date recall returns; chat's evidence is the model's quote; changes ADR-0013 | Built 2026-10-25; upstream invoker parameter on tool#3583125 (split from tool#3583116 2026-10-28), stopgap subscriber live |
| [0063](0063-scopes-as-config-on-core-plugins.md) | Scopes as config on core plugins: plugins and the five scopes move into core `aim` (literals' pattern), `status`/`description`/`weight` on `aim_scope`, the matrix becomes the user scope's settings, the scope submodules go; supersedes ADR-0026 | Built 2026-10-26, live-verified |

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
| [0010](resolved/0010-drupal-native-agent-memory-rationale.md) | Original rationale, market appraisal, and risk analysis (folded in from repo root 2026-09-10) | Founding rationale; binding decisions superseded by 0001-0009; open questions live in BACKLOG.md |
| [0011](resolved/0011-extraction-explicit-subject-uid.md) | Extraction never guesses scope=user account matches; requires explicit `--subject-uid` | Accepted - built |
| [0012](resolved/0012-fact-relation-graph.md) | Fact-to-fact relation graph and multi-hop retrieval | Retired 2026-10-11 (parked 2026-10-08) - no consumer; revisit trigger in BACKLOG.md |
| [0013](resolved/0013-mcp-tool-exposure.md) | MCP tool exposure via Tool API (`tool`/`mcp_server`/`mcp_server_tool_bridge`) | Accepted - built and OAuth-authenticated, 2026-09-12/13 |
| [0014](resolved/0014-usecase-archetype-starter-kits.md) | Use-case archetype starter kits via Recipes | Retired 2026-10-11 (parked 2026-10-08) - two Recipes exist; revisit trigger in BACKLOG.md |
| [0015](resolved/0015-immediate-consolidation-considered-deferred.md) | Immediate post-write consolidation via kernel.terminate | Deferred - closed on this site by `index_directly`; design kept for hosted embeddings |
| [0017](resolved/0017-query-embedding-cache.md) | Query-embedding cache via a drupal/ai event subscriber | Accepted - built and verified 2026-09-27 |
| [0018](resolved/0018-index-subject-uid-with-btree.md) | Index `subject_uid` as a Search API attribute, with a BTREE index on its column | Accepted - built and verified 2026-09-26 |
| [0019](resolved/0019-recall-abstention-distance-cutoff.md) | Recall abstention via a distance cutoff, and what bounds recall quality | Accepted - built 2026-09-19/27; `aim:calibrate` follow-up in BACKLOG.md |
| [0022](resolved/0022-exclude-retired-facts-from-vector-index.md) | Retired facts stay out of the vector index (Search API processor) | Accepted - built and rolled out 2026-09-26 |
| [0023](resolved/0023-hnsw-tuning-and-thin-provider-shim.md) | HNSW tuning (M=16, ef_search=100), and keeping the provider shim thin | Accepted - built and verified 2026-09-26 |
| [0025](resolved/0025-scope-access-plugin-type.md) | Scope-specific view access as a plugin type, not hardcoded bundle branches | Accepted - built and verified 2026-09-28; widened by 0028 |
| [0026](resolved/0026-pluggable-scope-submodules.md) | Pluggable scope submodules: ThirdPartySettings for config, ADR-0025's plugin type for behavior | Superseded 2026-10-26 by [ADR-0063](0063-scopes-as-config-on-core-plugins.md) |
| [0027](resolved/0027-entity-scope.md) | `scope: entity`: facts about an arbitrary Drupal entity, view access mirroring that entity's own | Built and verified 2026-09-29; remaining polish tracked in BACKLOG.md |
| [0028](resolved/0028-scope-type-plugin.md) | Widen the scope-access plugin into a scope-type plugin: fields, settings, and behavior in one seam | Built and verified 2026-09-29 (pieces 0-4) |
| [0037](resolved/0037-transient-source-passages-for-grounding.md) | Grounded check: a decision model checks a candidate fact against the text it was written from, at write time, nothing stored; absorbs the gate semantics of the former 0033 | Built 2026-10-11 (shadow mode on this site); trust policy per entry channel and chunking built 2026-10-19 (addendum); a reviewer's own write starts trusted 2026-10-22; enforce decision, length cap and follow-ups in TODO.md |
| [0044](resolved/0044-tool-api-method-attributes.md) | Tool API method attributes (drupal/tool MR 162): watch upstream | Retired 2026-10-12 - a watching brief, not a decision; revisit trigger in TODO.md |
| [0055](resolved/0055-decision-rationale-as-entity-scope-facts.md) | Decision rationale as entity-scope facts: validate against grep before building a `decision` entity | Retired 2026-10-12 (parked) - test never run, nothing built; revisit trigger in TODO.md |
