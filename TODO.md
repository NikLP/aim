# TODO

Living backlog, not an ADR - a decision here doesn't become binding until
it gets its own ADR entry in [adr/](adr/0000-index.md). Compiled
2026-09-10 from a Claude Code thread (OpenKB competitor review) plus the
open items already on record in the ADRs and [CLAUDE.md](CLAUDE.md)/
[DEVELOPING.md](DEVELOPING.md).
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

## Recipes (raised 2026-09-27)

- [ ] Apply `recipes/aim_demo_library` to an isolated scratch site (its
      own database) and fix whatever breaks: it has only passed core's
      schema validation. Then confirm `aim:remember --file` loads all 31
      facts and the demo questions in the site repo's `demo/README.md`
      answer as before.
- [ ] Decide whether `aim_tool_oauth` should get a setup command (generate
      the key pair, set `simple_oauth.settings` paths, verify the scopes
      and discovery URLs) instead of an `aim_mcp` recipe, which would be
      nearly empty (see DEVELOPING.md, "Recipes").
- [ ] `drush aim:status`: the database-side checks from the site's
      `demo/preflight.js` (index parity, orphan rows, shim class, HNSW
      settings, cutoff set) as a module command, replacing step 6 of
      "Upgrading an existing site".

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

- [x] Replace the flat `scope` list field with the four-bundle model
      (user/role/site/case) - BUILT 2026-09-15, scopes are `aim_scope`
      config entities. [ADR-0001](adr/0001-storage-and-scope-model.md)
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
      theory confirmed) - see DEVELOPING.md's "aim:benchmark" section.
- [ ] #7 - product framing (own venture vs. folded into an existing pitch) -
      explicitly out of that ADR's own scope, still open.
- [ ] #8 - "speckit-for-Drupal" per-archetype question sets - no design work
      done.
- [ ] #10 - CCC adopt/integrate decision - revisit once `ai_context` leaves
      beta (currently beta5).

([ADR-0010](adr/0010-drupal-native-agent-memory-rationale.md), "Open
questions" section)

## Concrete near-term fixes flagged in CLAUDE.md/DEVELOPING.md

- [x] Index `subject_uid` as a search_api attribute - BUILT and verified
      2026-09-26, with the BTREE index it needs and empty values written
      as `NULL` by `AimMariaDBProvider`. Exact for users up to 100 facts
      in a 5k-row corpus; a user holding a large share of the table stays
      approximate (82-97%), crossover not located. Results and limits in
      [ADR-0018](adr/0018-index-subject-uid-with-btree.md).
- [ ] Add a `related_reason` field on `aim_fact` (provenance-on-invalidation,
      ADR-0010 parity target #6 /
      [ADR-0005](adr/0005-consolidation-algorithm.md)).
- [x] Add the `asserted` field - BUILT 2026-09-11 (installed live via
      `installFieldStorageDefinition()`, no data loss on the 81 existing
      facts). Covers valid-time *start* only, defaults to `created`.
      Valid-time end was considered and rejected as rarer/harder to
      elicit, approximated well enough by `expires` on contradiction.
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
      queries, a local model helps every query. Design and decision in
      [ADR-0017](adr/0017-query-embedding-cache.md) (drupal/ai event
      subscriber, query-time embeds only).
- [ ] Instrument `recall()` to log embed-time vs. DB-search-time separately,
      before further latency work - confirms the split rather than
      inferring it from one aggregate number. ADR-0017's subscriber
      logging covers this.
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
- [x] Fix abstention correctness in `aim_chatbot:recall` - BUILT
      2026-09-19 (`aim.settings:recall_max_distance`, 0.45), decision and
      measurements in
      [ADR-0019](adr/0019-recall-abstention-distance-cutoff.md). Extended
      2026-09-27: `recall()` takes an optional `$maxDistance`, applied
      before the limit; `aim_tool`'s MCP `aim_recall` applies the site
      default unless the caller passes `max_distance`, and `drush
      aim:recall --max-distance` opts in (raw otherwise, for
      calibration). **Still open:** a near-topic question the facts don't
      answer still gets its nearest facts (the distance ranges overlap, so
      this needs the model's judgment, not a threshold); no `drush
      aim:calibrate` yet; the 0.45 default was checked on site and user
      scope only, and needs a recheck against any new dataset.
- [x] Retired facts eating `recall()`'s result slots - BUILT 2026-09-26:
      a Search API processor keeps retired facts out of the vector index
      instead of the over-fetch this item first proposed. Rolled out on
      the live site (115 vector rows to 58, `recall(limit 5)` back to 5
      rows). Decision, verification and rollout in
      [ADR-0022](adr/0022-exclude-retired-facts-from-vector-index.md).
- [ ] Upstream work for `AimMariaDBProvider`'s overrides (status checked
      against drupal.org and the git history 2026-09-27; the provider project
      has only 6 issues):
      (1) index save throws "table exists" - already
      [#3609961](https://www.drupal.org/i/3609961) (RTBC), but its MR 8
      makes `createCollection()` drop the collection first, and the
      index-update hook calls it on every save, so it would wipe the vectors
      on every index save. **Comment there** that `CREATE TABLE IF NOT
      EXISTS`, or tolerating MariaDB error 1050 as the shim does, is the safe
      fix, and do not take a provider release containing MR 8 as written;
      (2) `getVdbIds()` uses `querySearch()`'s default `limit = 10`, so
      deletes remove at most 10 rows - no issue, **to file**; (3) an empty
      integer/decimal/date/boolean attribute is inserted as `''`, which
      strict mode rejects (`ERROR 1366`) - no issue, **to file**; (4)
      optionally a BTREE index for filterable attribute columns, a feature
      request; (5) string attributes stored Markdown-escaped (`_` as `\_`,
      so `subject` filters miss) - **already fixed** by
      [#3572801](https://www.drupal.org/i/3572801) in ai_search 1.3.0-alpha5
      / 2.0.0-alpha2 and `drupal/ai`'s 1.x head, not in the code bundled with
      `drupal/ai` 1.4.9 or 1.5.0; nothing to file, drop the shim override
      once the site has that code.
      The two tuning overrides (M, ef_search) go as soon as a provider
      release includes [#3605665](https://www.drupal.org/i/3605665) (merged
      on the 1.0.x head, one commit past 1.0.1, not yet released);
      `mhnsw_ef_search` in the shipped server config carries over unchanged.
      When the last override goes, delete the class and
      `AimHooks::vdbProviderInfoAlter()`. See
      [ADR-0023](adr/0023-hnsw-tuning-and-thin-provider-shim.md).
- [ ] Recheck the HNSW tuning (M=16, ef_search=100) when the table grows
      about tenfold, the embeddings model or dimensions change, or the
      provider is upgraded. Method in DEVELOPING.md, "Rechecking accuracy".
      Still unmeasured: where the optimizer switches from the BTREE index to
      HNSW for a user, and whether MariaDB's 16 MB `mhnsw_max_cache_size`
      matters at larger sizes.
- [ ] Retention/erasure policy for retired facts (prune or archive old
      `expires` rows out of `aim_fact`) - retired facts about a person are
      still personal data. Own ADR when needed, see ADR-0022's
      Consequences.
- [ ] Delete the 7 live site facts with NULL text (ids 144-150, created
      2026-09-15 20:42-20:46 by uid 1, source NULL) - test residue from
      the scope-to-bundle conversion. `text` is required now; they have no
      vector row, so they never surface in recall, but they count as live.

(DEVELOPING.md's "aim:benchmark" and "Chatbot" sections)

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
