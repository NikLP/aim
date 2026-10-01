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
