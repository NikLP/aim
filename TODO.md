# TODO

Living backlog, not an ADR - a decision here doesn't become binding until
it gets its own ADR entry in [adr/](adr/0000-index.md). Compiled
2026-09-10 from a Claude Code thread (OpenKB competitor review) plus the
open items already on record in the ADRs and [CLAUDE.md](CLAUDE.md).
Expect entries folded in from other threads independently - don't treat
this list as exhaustive.

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
- [ ] #5 - retrieval latency at realistic scale: benchmark at thousands of
      facts, and run against local Ollama embeddings to confirm the
      network-hop theory.
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
- [ ] Add the `asserted` field (bi-temporal fix - resolved in design
      2026-09-10 per ADR-0010 open question 7, not yet built). Rationale:
      covers valid-time *start* only (a late-reported fact's real-world
      date), not valid-time end - the existing `expires`/consolidation
      mechanism already approximates that case well enough, and there's no
      real example yet demanding more. Defaults to `created` when a caller
      doesn't specify.
- [ ] Add the `category` field (taxonomy, "classification of stuff" -
      confirmed 2026-09-10, not yet built). Proposed naming pending
      confirmation: field `category` (entity_reference, unlimited
      cardinality, optional), vocabulary machine name `aim_category` /
      label "AIM Category", no seeded terms - admin adds real ones as
      categories emerge. The provenance/routing half of the original idea
      (which archetype/source-policy governs a fact) stays a separate,
      later field per the 2026-09-10 discussion, not folded into this one.
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

(CLAUDE.md, "Ideas raised, not designed" and "Benchmarking" sections)

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
