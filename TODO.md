# TODO

Living backlog, not an ADR - a decision here doesn't become binding until
it gets its own ADR entry in [adr/](adr/0000-index.md). Compiled
2026-09-10 from a Claude Code thread (OpenKB competitor review) plus the
open items already on record in the ADRs and [CLAUDE.md](CLAUDE.md).
Expect entries folded in from other threads independently - don't treat
this list as exhaustive.

## MCP connector persona/capability parity (raised 2026-09-14)

- [ ] Decide whether the Claude.ai/Claude Desktop MCP connector
      (`aim_tool` + `mcp_server_oauth`) should get its own persona/
      behavioral guidance, or stay a plain tool-augmented assistant.
      Currently it shares the exact same `AimMemoryManager` calls and
      guardrails as `aim_chatbot`'s Deepchat widget, with no capability
      gap - if anything the connector is *less* restricted (any scope:
      user/role/site/case) than the widget's hardcoded `scope: site`
      lock. The widget has a persona (`system_prompt`) and no-login
      public access; the connector has neither. A provisional note is
      parked in `mcp_server.settings:server_instructions` itself -
      resolve there too once this is decided.

## From the OpenKB competitor review (this thread)

- [ ] Close [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
      deferred governance gate before ship - priority sharpened by the
      stated ship-soon timeline and OpenKB shipping draft-to-trusted as a
      day-one feature, not deferred.
- [ ] Build MCP exposure for aim - `#[Mcp]` plugins (via `drupal/mcp_server`/
      `drupal/mcp`, confirmed real and maintained on drupal.org) wrapping
      `AimMemoryManager::remember()`/`recall()`, mirroring `aim_chatbot`'s
      existing `#[FunctionCall]` plugins. Ties to the Tool API/MCP pattern
      already flagged in
      [ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md).

## PoC deviations to close before non-PoC data goes in

- [ ] Replace the flat `scope` list field with the four-bundle model
      (user/role/site/case). [ADR-0001](adr/0001-storage-and-scope-model.md)
- [ ] Make `aim_fact` revisionable and build the Content Moderation
      draft-to-trusted gate.
      [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)

## ADR-0009 - Recipe/apply surface (design only, nothing built)

- [ ] Build the spec-to-Recipe generation/apply surface with a
      dry-run/human-review gate. First check whether `mcp_tools_recipes`
      (beta, uninstalled, already exists in the ecosystem) satisfies the
      gate requirement before building one from scratch.
      [ADR-0009](adr/0009-recipe-apply-safety-gate.md)

## Open questions, ADR-0010

- [ ] #1 - first concrete, sellable feature/scope - still unanswered.
- [ ] #3 - local model choice + hardware sizing for the sovereign tier -
      not researched.
- [ ] #4 - queue-runner cadence - untuned default ("every 1-5 min").
- [ ] #5 - retrieval latency at realistic scale: still not benchmarked at
      thousands of facts. Local-Ollama half done and confirmed 2026-09-10
      (`recall()` 33-35ms vs. 450-540ms hosted, ~15x faster, network-hop
      theory confirmed) - see CLAUDE.md's "Benchmarking" section.
- [ ] #7 - product framing (own venture vs. folded into an existing pitch) -
      explicitly out of that ADR's own scope, still open.
- [ ] #8 - "speckit-for-Drupal" per-archetype question sets - no design work
      done.
- [ ] #10 - CCC adopt/integrate decision - revisit once `ai_context` leaves
      beta (currently beta5).

([ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md), "Open
questions" section)

## Concrete near-term fixes flagged in CLAUDE.md

- [ ] Index `subject_uid` as a search_api attribute (Consolidation
      gotchas - currently over-fetches + PHP-filters).
- [ ] Add a `related_reason` field on `aim_fact` (provenance-on-invalidation,
      ADR-0010 parity target #6 /
      [ADR-0005](adr/0005-consolidation-algorithm.md)).
- [x] Add the `asserted` field - BUILT 2026-09-11 (installed live via
      `installFieldStorageDefinition()`, no data loss on the 81 existing
      facts). Covers valid-time *start* only, defaults to `created`. See
      CLAUDE.md's "Ideas raised" section for why valid-time end wasn't
      built too.
- [x] Add the `category` field - BUILT 2026-09-11 (entity_reference,
      unlimited cardinality, vocabulary `aim_category`, wired into
      `drush aim:remember --category`). Installed live, no data loss. The
      `aim_category` vocabulary itself is also built (created
      programmatically, not by hand, so the machine name is exact; shipped
      in `config/install/taxonomy.vocabulary.aim_category.yml`; `aim.info.yml`
      gained the `drupal:taxonomy` dependency it needed). **Still open:**
      no terms exist yet - add real ones via
      `/admin/structure/taxonomy/manage/aim_category/add` as categories
      emerge; the field resolves names to existing terms only, never
      auto-creates one.
- [ ] Extraction-input guardrailing, distinct from the existing output-side
      candidate-fact guardrails.
- [ ] Cache query embeddings via Drupal's Cache API, keyed on (query text,
      embeddings model ID) - nearly free (deterministic mapping, no
      invalidation needed), and the direct fix for the 450-540ms `recall()`
      latency the benchmark measured 2026-09-10. This site has no Redis
      today (`cache.default` is `DatabaseBackend`) - start DB-backed, add
      Redis only if that itself becomes a bottleneck. Complementary to
      local Ollama embeddings, not a substitute - caching helps repeat
      queries, a local model helps every query.
- [ ] Instrument `recall()` to log embed-time vs. DB-search-time separately,
      before further latency work - confirms the split rather than
      inferring it from one aggregate number.
- [ ] Fast-path lookup for typed facts (`state`, `category`), bypassing
      `recall()`'s vector search entirely. Both fields are exact-match
      (scope+subject), not fuzzy semantic - but everything, including a
      plain boolean check, currently pays `recall()`'s full
      embed-query-then-vector-search cost (33-540ms, see "Benchmarking").
      Needs a second retrieval method (e.g.
      `AimMemoryManager::getState($scope, $subject)`) doing a direct
      indexed `WHERE` query against `aim_fact`, optionally behind a Cache
      API layer (keyed scope+subject, invalidated on save) for a true
      page-load hot path (e.g. "should this user see the marketing
      banner"). Originally motivated by a 2026-09-09 question about a "warm
      state cache for booleans" - not designed, no ADR yet.
- [ ] Fix abstention correctness in `aim_chatbot:recall`
      (`AimRecall::execute()`) - no similarity-score threshold today, only
      a zero-rows check, so a poor top match still gets formatted as
      "Relevant facts:" instead of an honest no-match response.

(CLAUDE.md, "Ideas raised, not designed", "Benchmarking", and "Chatbot"
sections)

## Backlog - explicitly "wait for a trigger" per the docs' own framing

- [ ] Fact verification as a user-facing feature - now reinforced twice
      (OpenAI's editable memory summary, and OpenKB's review-as-UX pattern
      from this thread).
- [ ] Sub-scope visibility/audience control within `scope: site`.
- [ ] EU AI Act Article 50 disclosure - evaluate `drupal/ai_disclosure`
      before public launch.
- [ ] Fact-to-fact authored relations graph.
- [ ] Scheduled TTL/staleness review (defer to Content Moderation, don't
      build ad hoc).
- [ ] Pre-extraction summarization as a dedup lever.
- [ ] Source-boundary policy per site archetype (a Guardrail set per
      archetype).
- [ ] Graduated-detail retrieval (L0/L1/L2) - source unverified, candidate
      mechanism only.
- [ ] Media/source ingestion for re-analysis - gated behind the still-open
      "does aim ever store source material" question.
- [ ] `revision_graph` module - real fit once Content Moderation lands.
