# TODO

Living backlog, not an ADR - a decision here doesn't become binding until
it gets its own ADR entry in [adr/](adr/0000-index.md). Compiled
2026-09-10 from a Claude Code thread (OpenKB competitor review) plus the
open items already on record in the ADRs and [CLAUDE.md](CLAUDE.md)/
[DEVELOPING.md](DEVELOPING.md).
Expect entries folded in from other threads independently - don't treat
this list as exhaustive.

## Recipes (raised 2026-09-27)

- [ ] Apply `recipes/aim_demo_library` to an isolated scratch site (its
      own database) and fix whatever breaks: it has only passed core's
      schema validation. Then confirm `aim:remember --file` loads all 31
      facts and the demo questions in the site repo's `demo/README.md`
      answer as before.
## Ingestion

- [ ] Chunking for `aim:extract` and the ingest form: today the whole file
      goes into one extraction prompt (`AimMemoryManager::extractFacts()`),
      so a file larger than the chat model's context fails. Split on
      paragraph/heading boundaries with a small overlap, extract per chunk,
      then let consolidation dedupe across chunks. A small local extractor
      has a much smaller window, so chunk size should be per model
      (see `adr/model-call-budget.md`).
- [x] Ingest form (ADR-0016 Mode 1) BUILT 2026-10-02: Batch API for large files is the follow-up.

## Decision models (raised 2026-10-02, ADR-0021/0033 addenda)

- [x] Move aim's model choices (BUILT 2026-10-02: extraction, consolidation, verifier; add a `decision` entry when classifyPair moves to the Decision API) to the AI suite's `ai_provider_configuration`
      element (one per activity: extraction, consolidation classifier,
      merge verifier, decision), replacing the free-text
      `merge_verifier_model` and the Drush-only `--provider`/`--model`.
- [x] BUILT 2026-10-02 (`AimActivityMetricsSubscriber`, `src/Backend/`; Jev's token reporting still untested live): activity metrics subscriber (decided 2026-10-02): generalize
      `AimEmbeddingCacheSubscriber`'s Pre/Post timer into a subscriber that,
      for calls tagged `aim_*`, writes one row per call (activity, run ID,
      provider, model, input/output tokens, ms), no prompt text. Replaces
      the embedding timing log lines. `ai_logging` (stores prompts) and
      `ai_observability` (no tokens/time outside OTel) were enabled, found
      unsuitable, and uninstalled. Verified live: amazeeio and Ollama chat
      both report token usage (shared `OpenAiBasedProviderClientBase`);
      `tev1` is a decision model and must be sent decision-shaped input.
      Then a replay harness in `aim_benchmark`: one fixed pair set through
      each backend (frontier, local, Jev) for accuracy, time and tokens.
- [x] BUILT 2026-10-02 (`activities.*.backend`; thresholds/question text still hardcoded, not per-model config): build `classifyPair()` and `verifyMerge()` on the core Decision API
      through the provider manager, chat path staying the default, behind a
      setting. Thresholds and question text as per-model config.
- [ ] Decide the pass bar (ADR-0021 open question 2) before scoring, e.g.
      "decision path within X points of chat accuracy at under Y s per pair".
- [ ] Model eval sets (verified 2026-10-02; none built): (a) classifyPair
      gold set, 15-25 (kept, candidate) pairs per ADD/UPDATE/DELETE/NOOP
      with hard negatives, human-verified labels (`demo/seed-facts.json` has
      only ~23 facts, so most pairs are hand-written); (b) verifyMerge set
      from mutated good merges, also try split noul questions (keeps A,
      keeps B, adds nothing); (c) add to the `gate` set in `decision-eval-sets.json`
      site-context contradictions. Synthetic data only for hosted Jev.
- [ ] 2026-10-02 eval rework ([ADR-0037](adr/0037-transient-source-passages-for-grounding.md)):
      `aim_benchmark/scripts/decision-eval.py` + `decision-eval-sets.json`
      built (pairs/merges/gate; hand-written, labels human-verified
      2026-10-02). Results (hosted Jev 23/24 pairs, 25/25 real pairs,
      merges and gate AUC 1.00; local models parked): ADR-0038 and the
      ADR-0021 addendum. Still to build: a groundedness set (passage +
      candidate, labeled supported/unsupported/misattributed), harder
      UPDATE/NOOP pairs, and an `nli` model run (an Ollaya model).
- [ ] Extend `decision-eval.py`: confusion matrix by confidence bucket and
      a cost-weighted threshold sweep (a wrong UPDATE loses data, a missed
      merge is cheap).
- [ ] PHP replay command in `aim_benchmark` running the same pairs through
      `ChatBackend` (baseline: one-hot answers, so no confidence buckets)
      and `DecisionBackend`, grouped by `setRunId()`.
- [ ] Hard merge set `decision-eval-merges-hard.json` (20 subtle faults, 2026-10-02): `jev-latest` AUC 1.00 but at the live 0.5 cutoff one bad merge (max 0.68) passes; perfect split at 0.81-0.86. `verifyMerge` uses `isLikely()` default 0.5: BUILT 2026-10-02: `activities.verifier.threshold` (settings form + schema; empty/0 = 0.5, live site 0.8). Still open: per-activity `concurrency` (default 1; the AI module calls are synchronous, so pooling needs HTTP-level work or parallel queue workers) Extra cross-check questions TESTED and DROPPED 2026-10-02 (3 noul: same/new/conflict next to the choice, 55 pairs): neither of the 2 misses is fixed (the noul answers agree with the wrong choice) and "same" does not separate NOOP (median 0.49). Concurrency judged nice-to-have: consolidation runs per fact via the queue (about 3 decisions, ~1 s), so only a bulk backfill is slow. `merges-split` mode added (3 small noul questions, min score).
- [ ] Per-model thresholds and editable question text for the decision
      backend (ADR-0021 decision 3); question text is hardcoded in
      `AimMemoryManager` today. Limits: `tev1` input ~2,050 tokens, Ollama
      max 26 options.
- [ ] Verify Jev (`typesafeai`) reports token usage live; confirm the
      Anthropic key in watchdog is rotated and the entries cleared.
- [ ] A separate decision guardrail set (not `aim_write_guardrails`, whose
      2000-character limit and HTML regex would hit the serialized input).
- [ ] Raise `drupal/ai` to `^1.6` in aim's `composer.json` once 1.6.0 is
      tagged (the site runs `1.6.x-dev` with `ai_provider_typesafeai`
      1.1.0-beta1, installed but not enabled; enabling needs
      `drush config:export` for `config/sync` parity).
- [ ] Unverified, check before relying on any of it:
  - How a caller attaches a guardrail set to a Decision call, and that the
    built-in length and regex plugins really run for Decision (docs say so;
    plugin code not opened).
  - `web/modules/contrib/ai_provider_typesafeai/tests/check-decision-api.php`
    (offline class check) has not been run.

## Design review findings (raised 2026-10-02)

Workflow diagram: [adr/workflow-diagram.md](adr/workflow-diagram.md).
Decisions marked "agreed" were settled with Nik in that review; the rest
are recommendations.

**Identity and access**

- [ ] Agreed: refuse new authored writes with uid 0 (Drush callers pass
      `--uid`). Consolidation retirements and merged facts are exempt
      (a merged fact copies `uid` and `user` from the kept fact). The
      current database is disposable, so no `uid` backfill is needed.
- [ ] Replace `isAnonymous()` as the trigger for
      `search_api_bypass_access` in `AimMemoryManager::executeSearchQuery()`
      with an explicit `$systemCaller` argument (default FALSE) that only
      Drush commands and the queue worker pass as TRUE. Web and MCP always
      get access checks, so an anonymous web caller gets nothing instead
      of everything.
- [ ] Docs: say "the fact is about this user" for `user` scope (`user` is
      the subject, `uid` is the author; keep `uid`, it is Drupal's idiom).
- [ ] Grill access: what exists is fine for now (Drush with `--uid`, or
      MCP `aim_remember`). Do NOT build an `aim_extract` MCP tool: the
      calling agent already does the extraction reasoning. Review later.
      A web console ([ADR-0029](adr/0029-context-carrying-turns.md),
      `aim_console`) needs its own chat model; point it at local Ollama to
      stay sovereign.

**Lossy rejection**

- [ ] Agreed: a quarantine entity (e.g. `aim_rejection`) for every
      candidate fact dropped without being stored, with a reason code:
      guardrail/validation failure, user-scope candidate skipped for no
      `--subject-uid`, unknown scope. Holds the text, scope, source,
      caller, failed check and time. Not in the vector index or recall,
      own permission, retention window (it is personal data), admin list.
      Later feeds the review queue (approve a rejection into a fact).
      Logs keep IDs only plus the rejection ID, so no `log_rejected_text`
      setting is needed. Not in scope: consolidation downgrades (both
      facts are kept), permission refusals (the caller was told).

**Fact lifecycle**

- [ ] Agreed: add a `retired` timestamp (set by consolidation and the
      Retire action) and keep `expires` as a real sell-by date (nullable,
      unused today). `ExcludeRetired` and the PHP safety nets key off
      `retired`; a sweep sets `retired` once `expires` passes. Migrate
      existing `expires` values to `retired` (base-field change: the
      `subject_uid` rename needed three update hooks and silently broke a
      View, check Views). Needed by ADR-0035's `release` and ADR-0032's
      auto-expiry.
- [ ] Agreed: rename the consolidation verb DELETE to RETIRE. It is a
      soft retire. Touches the structured-output enum and prompt line in
      `AimMemoryManager` (the model must answer with the new word),
      dry-run output, any edited prompt override, and old
      `superseded_by_reason` rows that say DELETE.
- [ ] Call the `superseded_by_reason` field a "retirement note" in docs.
      It is a JSON string in a `string_long` column, no fact text.
- [ ] Guardrails on merged text run twice (`decideAndApply()`, then
      `createMergedFact()`'s `saveFact()`). Checked 2026-10-02: leave
      both. `saveFact()`'s `validate()` is the universal gate every write
      route shares, so removing a pass there would leave routes unguarded;
      the first pass is what yields the graceful BLOCKED decision and the
      dry-run row, since the second would throw mid-sweep. Revisit only if
      a guardrail becomes LLM-backed (then validate once and reuse the
      result, without a skip flag on `saveFact()`).
- [ ] Auto band (distance under 0.09, no model check): decide whether it
      should go through the decision model or verifier. Revisit once a
      decent decision model setup exists (one call per pair, see the cost
      measurement under "Decision models").

**Indexing and settings**

- [ ] Expose `index_directly` on the AIM settings form (writes the index
      entity, then needs `config:export`). Needed when embeddings move to
      hosted (Jev). Warn when it is on with a hosted provider.
- [ ] Open question: could Guardrails and the Decision API do the same
      job (see "Decision models")? Not yet written down elsewhere.

**Doc and code discrepancies found**

- [ ] DEVELOPING.md says `remember()`/`createFactsFromCandidates()`
      enqueue consolidation; the code enqueues in
      `AimHooks::factInsert()` for every non-syncing insert (merged facts
      re-enqueue themselves).
- [x] (addendum 2026-10-02) [ADR-0005](adr/resolved/0005-consolidation-algorithm.md) body is
      stale: thresholds 0.05/0.20 (code: 0.09/0.45), in-place UPDATE,
      hard-delete DELETE. The 2026-10-01 addendum covers the last two.
- [ ] Comments in `createFactsFromCandidates()` and `AimGuardrails` still
      say Guardrails run in `hook_aim_fact_presave()`; they run in
      `saveFact()` via `validate()`.
- [ ] Decision-backend UPDATE writes its merged text with a separate
      `writeMerge()` chat call on the site default provider, not the
      consolidation model, and downgrades to ADD with no default. Not
      documented.
- [ ] Shipped vs this site: `default_trusted` false/true,
      `index_directly` off/on, `recall_max_distance` 0.45/0.48. Only
      partly stated in the docs.

## Memory algorithm follow-ups (raised 2026-10-02, ADR-0036)

- [x] Dates in `recall()` output (`created`/`asserted`, `formatFactLine()`).
- [x] Consolidation compares top-N neighbors, `neighbor_limit` setting
      (default 3); live dry run 40 vs 86 decisions.
- [ ] Real (non-dry) run and queue-worker check of top-N consolidation;
      no automated tests cover it yet.
- [ ] Time-limited facts ("for two months from now") must not be UPDATE
      merged into permanent facts; see ADR-0036 for options.
- [ ] Pinned: `verified`/`verified_by` fields (not `trusted`), ADR-0036.
- [ ] Pinned until the above is tested: recency/importance re-rank,
      weights default 0, needs a gold set.
- [ ] Later: access counter / last-recalled (after the `retired` split).
- [ ] Later, on a real recall miss: RRF keyword path via `search_api_db`.

## Governance

- [ ] Close [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
      deferred governance gate before ship - the `trusted` field/
      `default_trusted` half is BUILT 2026-09-28 (see "PoC deviations"
      below); per-scope `trust {scope} aim facts` permission BUILT
      2026-09-30 (custom `trust` access operation, used by the Trust/
      Untrust bulk actions). Still open: the `trusted` override on
      `remember()` bypasses it - decide whether callers passing
      `trusted: TRUE` should need the permission.
## PoC deviations to close before non-PoC data goes in

- [ ] User-scope authorship gap (raised 2026-09-30): nothing checks that a
      `scope=user` fact's `user` (who it is about) is the poster (`uid`,
      who wrote it). `aim_tool`'s `AimRemember` only *defaults* `subject`
      to the current user when omitted; a caller can pass any uid/username
      and the fact is saved about that person, and `create user aim facts`
      is the only gate. The tool description asking the model to confirm
      first is prompt-level, not enforced. Proposed: in `remember()`,
      reject `subject != currentUser` unless the caller has
      `administer aim memory` or a new permission for writing about
      others; later option, accept it but force `trusted=FALSE` so the
      per-scope trust permission reviews it. Same class of gap as the
      `trusted` override on `remember()` above. Not built.
      [ADR-0007](adr/resolved/0007-user-scope-requires-real-account.md)

## ADR-0035 - Standing constraints (design only, nothing built)

- [ ] Spike FlowDrop in a scratch site (approval/release flows, and as
  a recipe-apply review gate, ex-ADR-0009); verify whether its entity triggers can veto.
- [ ] Verify whether ECA exposes a blockable event for config-entity
  creation (e.g. a content type), before relying on it as a second gate.

## Open questions, ADR-0010

- [ ] #1 - first concrete, sellable feature/scope - still unanswered.
- [ ] #3 - local model choice + hardware sizing for the sovereign tier -
      not researched.
- [ ] #4 - queue-runner cadence - untuned default ("every 1-5 min").
      Reasoned from code 2026-10-01, not measured. An idle tick is one
      `ddev exec` plus a Drupal bootstrap (about a second of CPU), so
      every minute is cheap on a quiet queue. Per item: one local Ollama
      embed in `reindex()` plus the neighbor search (a second embed,
      cached by ADR-0017); the chat call only happens for pairs between
      `auto_threshold` and `ambiguous_threshold`, and goes to the hosted
      provider, not the laptop. The real laptop cost is memory, not heat:
      Ollama keeps the embed model resident after each call (see the
      laptop memory budget note). Recommendation: `*/5` day to day, queue_ui
      "Run" for a burst, every minute only for the demo (the
      save-then-recall beat needs the fact searchable quickly). Measure
      before treating any of this as settled. Related: nothing records
      each decision (see the `superseded_by_reason` item below).
- [ ] #5 - retrieval latency at realistic scale: still not benchmarked at
      thousands of facts. Local-Ollama half done and confirmed 2026-09-10
      (`recall()` 33-35ms vs. 450-540ms hosted, ~15x faster, network-hop
      theory confirmed) - see `aim_benchmark`'s
      [DEVELOPING.md](modules/aim_benchmark/DEVELOPING.md).
- [ ] #7 - product framing (own venture vs. folded into an existing pitch) -
      explicitly out of that ADR's own scope, still open.
- [ ] #8 - "speckit-for-Drupal" per-archetype question sets - no design work
      done.
- [ ] #10 - CCC adopt/integrate decision - revisit once `ai_context` leaves
      beta (currently beta5).

([ADR-0010](adr/resolved/0010-drupal-native-agent-memory-rationale.md), "Open
questions" section)

## Concrete near-term fixes flagged in CLAUDE.md/DEVELOPING.md

- [x] `superseded_by_reason` built 2026-10-01 (ADR-0005 addendum).
- [ ] Consolidation/recall baselines (none exist, so no improvement can be
      claimed): merge-fidelity review of dry-run UPDATE/DELETE rows on a
      snapshot copy; a disclosed LongMemEval slice (50-100 questions) for
      recall precision, knowledge-update correctness and abstention (parity
      targets 1, 4, 5); recall payload size vs. full-history tokens
      (target 3); duplicate rate; then tune `recall_max_distance` (0.48),
      `auto_threshold` (evaluate 0) and `ambiguous_threshold` against
      them; `aim:benchmark` at thousands of facts (open question #5).
      Count hosted calls while doing it: the verifier adds one call only
      per UPDATE (1 of 23 pairs on the live set, 2026-10-01), but it
      compounds with `auto_threshold` 0, which sends more pairs to the
      model and so yields more UPDATEs. Measure the two together.
      Retention (the item further down) also needs the `getVdbIds()`
      limit-10 shim (#3626257) re-verified before relying on deletes, and
      its own ADR plus sign-off first.
- [ ] Extraction-input guardrailing, distinct from the existing output-side
      candidate-fact guardrails.
- [ ] The whole module has no test infrastructure yet (no `tests/`
      directory, checked 2026-09-27 building `AimEmbeddingCacheSubscriber`
      above) - first real gap is a kernel test for that subscriber (a
      second identical query makes zero provider calls, an index-time
      embed is not cached, a different model ID misses), deferred at
      build time rather than standing up a test harness from scratch for
      one class. Verified live instead - see ADR-0017's "Built
      2026-09-27". Whatever sets up `tests/src/Kernel` first should cover
      this too.
- [ ] Bulk actions for `state` and `category` on the admin facts view -
      deliberately not built (2026-09-30) because neither field is in use
      yet. `trusted`/retire/un-retire have them
      (`src/Plugin/Action/`, `system.action.aim_fact_*`). `state` is three
      plain field-set actions (true/false/unset); `category` needs a
      configurable action with a term form (add/replace/clear), so it is
      the larger job. Revisit when either field starts being populated.
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
      state cache for booleans" - not designed. Partly superseded
      2026-10-01 by [ADR-0032](adr/0032-dated-category-listing-for-quick-notes.md)'s
      `listFacts()` (same non-vector query, category/date filters):
      build that first, add a `state` filter to it, and only then decide
      whether the Cache API layer is still wanted. Also open: `state`
      duplicates what the fact text already says and nothing reads it
      (recall() just echoes it), so if no consumer for the hot-path
      lookup appears, drop the field rather than keep a column that can
      silently disagree with its text.
- [ ] Upstream work for `AimMariaDBProvider`'s overrides (status checked
      against drupal.org and the git history 2026-09-27; the provider project
      has only 6 issues):
      (1) index save throws "table exists" - already
      [#3609961](https://www.drupal.org/i/3609961) (RTBC), but its MR 8
      makes `createCollection()` drop the collection first, and the
      index-update hook calls it on every save, so it would wipe the vectors
      on every index save. **Commented** on 2026-09-27 that `CREATE TABLE IF
      NOT EXISTS`, or tolerating MariaDB error 1050 as the shim does, is the
      safe fix, and not to take a provider release containing MR 8 as
      written;
      (2) `getVdbIds()` uses `querySearch()`'s default `limit = 10`, so
      deletes remove at most 10 rows - filed as
      [#3626257](https://www.drupal.org/i/3626257) 2026-09-27; (3) an empty
      integer/decimal/date/boolean attribute is inserted as `''`, which
      strict mode rejects (`ERROR 1366`) - filed as
      [#3626262](https://www.drupal.org/i/3626262) 2026-09-27; (4)
      optionally a BTREE index for filterable attribute columns, a feature
      request; (5) string attributes stored Markdown-escaped (`_` as `\_`,
      so `subject` filters miss) - **DONE 2026-09-27**: fixed upstream by
      [#3572801](https://www.drupal.org/i/3572801) in ai_search 1.3.0-alpha5
      / 2.0.0-alpha2 and `drupal/ai`'s 1.x head, not in the code bundled
      with `drupal/ai` 1.4.9 or 1.5.0 - the site moved from that bundled
      copy to the standalone `drupal/ai_search:^1.3@alpha` package
      (DEVELOPING.md, "Upgrading to standalone `ai_search`") and removed
      the shim override.
      The two tuning overrides (M, ef_search) go as soon as a provider
      release includes [#3605665](https://www.drupal.org/i/3605665) (merged
      on the 1.0.x head, one commit past 1.0.1, not yet released);
      `mhnsw_ef_search` in the shipped server config carries over unchanged.
      When the last override goes, delete the class and
      `AimHooks::vdbProviderInfoAlter()`. See
      [ADR-0023](adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md).
- [ ] `ai_vdb_provider_mariadb`'s `MariaDBProvider` does not implement
      `getRawEmbeddingFieldName()` (inherits `AiVdbProviderClientBase`'s
      default, which returns `NULL`; checked 2026-09-27) - a feature
      request to file. Standalone `ai_search` 1.3.0-alpha5 added a real
      `vector_input` query option (`$query->setOption('vector_input',
      $vector)`, read by `SearchApiAiSearchBackend::getSearchVectorInput()`)
      that skips the provider's embed call when the caller already has a
      vector - explored 2026-09-27 as a way to stop
      `findNearestNeighbor()` re-embedding each fact's own text on every
      consolidation sweep (see [ADR-0017](adr/resolved/0017-query-embedding-cache.md)),
      by feeding it the fact's own already-indexed vector. Blocked: with
      `getRawEmbeddingFieldName()` unimplemented for MariaDB, there is no
      way to read a fact's already-stored vector back out of `aim_fact_vectors`
      through the supported provider API, so `vector_input` has nothing
      to be given except a freshly embedded vector - no cheaper than
      `->keys()`. ADR-0017's embedding cache remains the correct near-term
      fix; revisit `vector_input` once this is filed and fixed.
- [ ] Recheck the HNSW tuning (M=16, ef_search=100) when the table grows
      about tenfold, the embeddings model or dimensions change, or the
      provider is upgraded. Method in DEVELOPING.md, "Rechecking accuracy".
      Still unmeasured: where the optimizer switches from the BTREE index to
      HNSW for a user, and whether MariaDB's 16 MB `mhnsw_max_cache_size`
      matters at larger sizes.
- [ ] Measure whether `scope`, `subject`, `target_type` and `target_id` need
      BTREE indexes on `aim_fact_vectors` (only `user` and `trusted` have
      one, `AimHooks::BTREE_INDEXED_COLUMNS`). Matters when one scope is a
      large share of the table, and for entity-scope lookups by target.
      Same method as ADR-0018. `target_type`/`target_id` are not even
      indexed attributes yet, check that first.
- [ ] Retention/erasure policy for retired facts (prune or archive old
      `expires` rows out of `aim_fact`) - retired facts about a person are
      still personal data. Own ADR when needed, see ADR-0022's
      Consequences.
- [ ] `simple_oauth`'s signing keys are a plain file pair
      (`simple-oauth:generate-keys`) per `aim_tool_oauth`'s setup runbook -
      no Key module support today. Tried 2026-09-27: `key` module and
      `league/oauth2-server`'s `CryptKey` already accept raw PEM content
      with no file at all (confirmed by reading `CryptKey`'s constructor),
      so the gap is only that `simple_oauth` calls `file_get_contents()` on
      its config value before that. [#3133698](https://www.drupal.org/i/3133698)
      is the open issue (since 2020); its MR !45 targets the 6.0.x branch
      and does not apply to the 6.1.1 installed here, and the maintainer's
      stated blocker is the MR's hard constructor dependency on
      `KeyRepositoryInterface`, breaking sites without Key installed.
      Commented there 2026-09-27 with a fix (a lazy
      `\Drupal::hasService('key.repository')` call inside
      `getPrivateKey()`/`getPublicKey()`, gated on a `keys_storage`
      config value, instead of constructor injection) and an offer to test
      a reroll. Not worth hand-patching a live OAuth path against a
      moving demo deadline - revisit once a working reroll exists upstream,
      or write one properly with time to test it.

## aim_tool: declare permissions on the tools (raised 2026-10-01, tool 1.0.0-beta11)

- [ ] Add `permission: 'read aim memory'` to `AimRecall`'s `#[Tool]` and
      `permission: 'store aim memory'` to `AimRemember`'s. Label-only
      change: Tool API reads it without instantiating the plugin (Tool
      Explorer, catalogs, `tool:info`, which shows `None` today) and
      checks it before input is processed. **Keep both `checkAccess()`
      overrides** - ToolBase's default only says "yes if a permission was
      declared", and `AimRemember` may need its own extra rules. A typo'd
      permission name denies every ordinary account but not admins, so
      test as a non-admin. Denial now precedes input validation (different
      error message when both apply): re-run `node demo/preflight.js`
      afterward. Optional - safe to leave undone.

## Backlog - explicitly "wait for a trigger" per the docs' own framing

- [ ] Fact verification as a user-facing feature - now reinforced twice
      (OpenAI's editable memory summary, and OpenKB's review-as-UX pattern
      from this thread).
- [ ] Sub-scope visibility/audience control within `scope: site`.
- [ ] EU AI Act Article 50 disclosure - evaluate `drupal/ai_disclosure`
      before public launch.
- [ ] Fact-to-fact authored relations graph.
- [ ] Scheduled TTL/staleness review - mechanism undecided now that
      Content Moderation isn't happening (ADR-0002 addendum, 2026-09-28);
      don't build ad hoc.
- [ ] Pre-extraction summarization as a dedup lever.
- [ ] Source-boundary policy per site archetype (a Guardrail set per
      archetype).
- [ ] Graduated-detail retrieval (L0/L1/L2) - source unverified, candidate
      mechanism only.
- [ ] Media/source ingestion for re-analysis - gated behind the still-open
      "does aim ever store source material" question.
- [ ] `revision_graph` module - premise gone: Content Moderation isn't
      landing (ADR-0002 addendum, 2026-09-28), so `aim_fact` has no
      planned path to revisionable. Revisit only if that changes for an
      unrelated reason.

## Queue processing (ADR-0031)

- [ ] Build the opt-in cron fallback for `aim_consolidate`
      ([ADR-0031](adr/0031-cron-fallback-for-queue-processing.md)):
      `consolidate_on_cron`/`cron_time` settings, `hook_queue_info_alter`,
      `aim:status` warning, then amend CLAUDE.md decision 4, README.md and
      DEVELOPING.md's "never `hook_cron`" wording.

## Case scope access control (raised 2026-09-30, ADR-0029)

- [ ] Design and build case-scope view access (`AimScopeCase::checkViewAccess()`
      is neutral today) plus a case membership model. Needs its own ADR.
      Required before the console's case chips ship; user and entity chips
      do not depend on it.

## Scope architecture follow-ups (from ADR-0025 to 0028, all built)

- [ ] `checkViewAccess()`'s "allowed or neutral, never forbidden" contract
      (`AimScopeTypeInterface`) is a deliberate design choice, not a
      technical ceiling - Drupal's own `AccessResult::orIf()` already lets
      a forbidden result override an allowed one, the same idiom node
      grants/content_moderation use to veto access, so
      `AimFactAccessControlHandler` could combine plugin results that way
      instead if a scope ever needs a genuine *gate* rather than a
      widening (e.g. case-scope "must have access to the fact's attached
      taxonomy term" is a gate: someone holding the flat `view case aim
      facts` permission needs to be *narrowed*, not just have extra people
      let in). Today the only way to get that narrowing effect is the
      pattern `AimScopeUser` already uses: don't grant the flat permission
      broadly, let the plugin's `checkViewAccess()` be the sole grant.
      Revisit if that stops being good enough - raised 2026-09-28.
- [ ] **Drush oddity found verifying ADR-0028, not yet diagnosed
      further or filed upstream.** `drush pmu aim_scope_entity` throws
      `SQLSTATE[42S22]: Column not found: 'target_type'` even after the two
      ADR-0028 fixes; calling
      `\Drupal::service('module_installer')->uninstall(['aim_scope_entity'])`
      directly (confirmed via stack trace to be the exact same
      `ModuleInstaller::uninstall()` -> `EntityDefinitionUpdateManager->
      uninstallFieldStorageDefinition()` code path Drush's `pm:uninstall`
      itself calls into) completes cleanly. Isolated to Drush's command
      layer specifically for a module providing 2+ base fields merged via
      another module's hook. Not blocking - `aim_scope_entity`'s own
      lifecycle works correctly through the real API - but a site
      operator using `drush pmu` on a scope-field-providing submodule
      should expect to hit this until it's actually root-caused.
- [ ] Widen `AimMemoryManager::remember()`'s `$targetType`/`$targetId`
      positional parameters into one generic scope-fields bag - found
      2026-09-29 while building ADR-0028 piece 4, not built.
      Piece 4 made the Tool API/MCP *schema* genuinely generic, but
      `rememberOne()` still extracts exactly `target_type`/`target_id`
      by name from the now-generic `scope_fields` map before calling
      `remember()`, because that is all `remember()`'s own signature
      accepts - a real (if currently silent, since no other shipped
      scope type has fields to lose) gap: a future scope type's own
      base field would be correctly advertised and accepted by the Tool
      layer, then silently dropped at this boundary. Same CLI-side gap
      in `aim:remember --target-type`/`--target-id` (ADR-0027, still
      two named flags, not generic either). No forcing function yet -
      revisit once a second field-bearing scope type exists, the same
      "don't abstract before it's earned" call ADR-0028's own
      Consequences already made for role/site's plugin.
- [ ] `aim_annotations` bridge module itself
      ([ADR-0024](adr/0024-annotations-integration-target-scoped-promotion.md)) -
      both prerequisites are cleared (`trusted`
      field built; `scope: entity` + its ADR-0025 plugin built per
      ADR-0027 above). The bridge module's own write path is still
      unbuilt. Promotion logic itself is fully custom/programmatic, not
      gated on any of this.
- [ ] Bundling recipe for the scope submodules
      ([ADR-0026](adr/resolved/0026-pluggable-scope-submodules.md) piece 6).
- [ ] Write-time validation that a `scope: entity` fact carries a target,
      and an entity-type-select plus autocomplete widget for
      `target_type`/`target_id` on the admin form
      ([ADR-0027](adr/resolved/0027-entity-scope.md)). Today a targetless
      fact is just neutral for view access, not a security gap.
