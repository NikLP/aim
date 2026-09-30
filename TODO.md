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
- [x] Decide whether `aim_tool_oauth` should get a setup command (generate
      the key pair, set `simple_oauth.settings` paths, verify the scopes
      and discovery URLs) instead of an `aim_mcp` recipe, which would be
      nearly empty (see DEVELOPING.md, "Recipes"). DECIDED 2026-09-27: no
      command for now, prose runbook in `modules/aim_tool_oauth/
      DEVELOPING.md`'s "Setup" stands - PoC stage, and the
      `user_scope_role_visibility` matrix it depends on isn't understood
      well enough yet to script around confidently (see next item).
- [x] `user_scope_role_visibility` matrix: the diagonal cells (viewer role
      R x subject role R, same role both sides) are inert under the
      shipped default (`user_scope_shared_role_fallback: true` already
      grants same-role visibility unconditionally) - only load-bearing if
      an admin turns that fallback off. DONE 2026-09-27: the admin form at
      `/admin/config/aim/user-scope-access` now disables (via `#states`,
      tied to the fallback checkbox) every diagonal cell except
      "authenticated" x "authenticated" - that one cell turned out NOT to
      be inert, because `checkViewAccess()`'s `$meaningfulRoles` exclusion
      strips the "authenticated" role from both sides before intersecting,
      specifically so two arbitrary logged-in accounts don't trivially
      match. `submitForm()` preserves the stored value for a disabled
      diagonal cell instead of reading it as unchecked, since a
      client-side-disabled checkbox isn't submitted at all.
- [x] `drush aim:status` - BUILT 2026-09-27: five checks (index parity,
      orphan vector rows, provider shim class, HNSW tuning, recall cutoff
      configured), `AimCommands::status()`, exit code 1 if any fails. Used
      in "Upgrading an existing site"'s step 6. Along the way, found that
      `SHOW CREATE TABLE` drops a VECTOR KEY's `M=`/`DISTANCE=` suffix
      under this site's own `sql_mode` (`ANSI,TRADITIONAL`) - the HNSW
      check works around it, detail in DEVELOPING.md's "aim:status".

## From the OpenKB competitor review (this thread)

- [ ] Close [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
      deferred governance gate before ship - the `trusted` field/
      `default_trusted` half is BUILT 2026-09-28 (see "PoC deviations"
      below); still open: whether a per-scope `trust {scope} aim facts`
      permission is worth generating, versus staying on the flat
      `administer aim memory` gate.
- [x] Build MCP exposure for aim - DONE, via a different mechanism than
      first proposed here: not direct `#[Mcp]` plugins, but `aim_tool`'s
      `#[Tool]` plugins (`AimRemember`/`AimRecall`, backed by
      `aim.memory_manager` directly) exposed over MCP through
      `mcp_server_tool_bridge`, OAuth-authenticated. Built and verified
      2026-09-12/13, see
      [ADR-0013](adr/resolved/0013-mcp-tool-exposure.md) (resolved).

## PoC deviations to close before non-PoC data goes in

- [x] Replace the flat `scope` list field with the four-bundle model
      (user/role/site/case) - BUILT 2026-09-15, scopes are `aim_scope`
      config entities. [ADR-0001](adr/0001-storage-and-scope-model.md)
- [x] Build the `trusted` boolean field + `aim.settings:default_trusted`
      draft-to-trusted gate - BUILT 2026-09-28: `trusted` base field on
      `aim_fact` (default value callback reads the config), `recall()`
      excludes untrusted facts unless `$includeUntrusted`/`drush aim:recall
      --include-untrusted`, indexed as a Search API attribute with a BTREE
      column same as `subject_uid`. Lightweight flag, NOT Content
      Moderation (no revisions, no new module deps).
      [ADR-0002 addendum](adr/0002-governance-deferred-guardrails-mandatory.md)
- [x] Per-caller `trusted` override - BUILT 2026-09-28 (commit
      `0b8921f`, right after `default_trusted` flipped to `false` turned
      out to silently break the demo - every fact written via
      `aim_chatbot`/`aim_tool`/`drush aim:remember`/`demo/seed.php`
      became invisible to `recall()`). `remember()`/
      `createFactsFromCandidates()` both gained `?bool $trusted = NULL`;
      `NULL` (the default) still falls through to `default_trusted`, so
      `aim_chatbot`'s `AimRemember` and `aim_tool`'s MCP `AimRemember`
      are both unchanged, still on the config default. `demo/seed.php`
      now passes `trusted: TRUE` explicitly on all four `remember()`
      calls, since it's curated demo data, not live LLM extraction. No
      `--trusted` flag added to `drush aim:remember`/`aim:extract` - not
      needed for the immediate demo fix; add one if another human-curated
      batch-load use case shows up.
      Caveat still true: this site's chatbot persona says to wait for the
      visitor's confirmation before saving (CLAUDE.md), which sounds like
      a human-in-the-loop signal, but it's prompt-level, not
      code-enforced - the model can ignore it, so "written via the
      chatbot" isn't the same reliability as "a human typed this into the
      admin form" even though both stay on the same `NULL` fallback here.

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
      theory confirmed) - see `aim_benchmark`'s
      [DEVELOPING.md](modules/aim_benchmark/DEVELOPING.md).
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
      [ADR-0018](adr/resolved/0018-index-subject-uid-with-btree.md).
- [ ] Add a `superseded_by_reason` field on `aim_fact` (provenance-on-invalidation,
      ADR-0010 parity target #6 /
      [ADR-0005](adr/0005-consolidation-algorithm.md)). Named to match the
      2026-09-28 rename of `related` to `superseded_by` - see that ADR's
      addendum.
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
- [x] Cache query embeddings via Drupal's Cache API, keyed on (query text,
      embeddings model ID) - BUILT 2026-09-27 as designed in
      [ADR-0017](adr/resolved/0017-query-embedding-cache.md): `AimEmbeddingCacheSubscriber`
      on drupal/ai's `PreGenerateResponseEvent`/`PostGenerateResponseEvent`,
      active only for the duration of `AimMemoryManager::executeSearchQuery()`
      (so index-time embeds are never cached), dedicated `cache.aim_embeddings`
      bin, DB-backed. Verified live: two identical `aim:recall` calls logged
      a miss then a hit, with identical results both times; the missed
      call's own embed cost logged as 556ms on this site's local Ollama -
      notably higher than the 33-35ms `aim:benchmark` aggregate, a real
      finding from the instrumentation below. `aim:benchmark` gained
      `--bypass-cache` so its numbers stay comparable to the 2026-09-10
      measurements (sample queries otherwise repeat within a checkpoint
      and would silently become cache hits).
- [x] Instrument `recall()` to log embed-time vs. DB-search-time separately,
      before further latency work - confirms the split rather than
      inferring it from one aggregate number. BUILT 2026-09-27 as part of
      `AimEmbeddingCacheSubscriber` (above): logs a hit/miss line and, on a
      miss, the provider call's own duration.
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
      [ADR-0022](adr/resolved/0022-exclude-retired-facts-from-vector-index.md).
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

(`aim_benchmark`'s DEVELOPING.md and this file's DEVELOPING.md's "Chatbot" section)

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

## Case scope access control (raised 2026-09-30, ADR-0029)

- [ ] Design and build case-scope view access (`AimScopeCase::checkViewAccess()`
      is neutral today) plus a case membership model. Needs its own ADR.
      Required before the console's case chips ship; user and entity chips
      do not depend on it.

## Governance/scope design thread (2026-09-27/28, continued 2026-09-29) - handoff

Four ADRs came out of one long design conversation about unblocking the
Annotations bridge (a fourth, ADR-0026, branched off discussing ADR-0025's
premise into a broader "make scopes genuinely pluggable" ask). A fifth,
[ADR-0027](adr/0027-entity-scope.md), followed on 2026-09-29 to build the
`scope: entity` bundle this thread had left as ADR-0025's second
still-unbuilt data point - see its own entry below. Status as of
2026-09-28 unless noted otherwise:

- [x] Annotations' `save()` write path - DONE per Nik, 2026-09-28. Was
      [ADR-0024](adr/0024-annotations-integration-target-scoped-promotion.md)'s
      second blocker.
- [x] `trusted` boolean field + `aim.settings:default_trusted` - BUILT
      2026-09-28, see "PoC deviations to close before non-PoC data goes
      in" above.
      [ADR-0002 addendum](adr/0002-governance-deferred-guardrails-mandatory.md)
- [x] `AimScopeAccessInterface` plugin type (seam only, default
      no-op fallback + `AimUserScopeVisibility` as the first dedicated
      plugin) - BUILT 2026-09-28: `AimScopeAccessPluginManager`
      (`plugin.manager.aim_scope_access`, attribute-discovered from
      `src/Plugin/AimScopeAccess/`), `AimFactAccessControlHandler`
      refactored off its hardcoded `bundle() === 'user'` branch,
      `AimUserScopeVisibility` converted into the first dedicated plugin
      (still in core `aim`, not yet moved to a submodule - see the next
      item). Verified live with a disposable scope=user fact: a viewer
      with no flat permission got view access solely through the
      plugin's shared-role fallback, a viewer sharing no role was denied.
      No forcing function yet for a second dedicated plugin (entity
      scope, case scope).
      [ADR-0025](adr/0025-scope-access-plugin-type.md)
- [x] Pluggable scope submodules (`aim_scope_user`/`role`/`site`/`case`,
      each shipping its own `aim.aim_scope.<id>.yml`; scope-level config
      differences as ThirdPartySettings the owning submodule reads/writes)
      - raised 2026-09-28, six-piece breakdown in
      [ADR-0026](adr/0026-pluggable-scope-submodules.md). Pieces 1-4 BUILT
      and live-verified 2026-09-28: core `aim` ships zero scope instances;
      the four submodules each ship their own `aim.aim_scope.<id>.yml`
      (real save + export, not hand-typed, per this file's dependencies
      rule - `role`/`site`/`case` carry only an `enforced` module
      dependency on themselves, `user` additionally carries the
      `requires_account` ThirdPartySetting, which also pulls in its
      module dependency automatically); `AimUserScopeVisibility` moved
      into `aim_scope_user` and renamed `AimScopeUser` (Nik's naming call
      - `AimScope[Name]`, not `[Name]ScopeVisibility`, so it scales to
      role/site/case plugins without describing one current behavior);
      ADR-0007's "requires a real account" rule genericized off
      `bundle() === 'user'`/`scope === 'user'` into
      `AimMemoryManager::scopeRequiresAccount()`, called from
      `AimMemoryManager.php`, `AimCommands.php`, and `aim_tool`'s
      `AimRemember` tool plugin. Live-verified: `remember()`/`recall()`
      round-trip for scope=user still works; `drush pmu aim_scope_role`
      is correctly BLOCKED by `ScopeUninstallValidator` while role-scope
      facts exist (5 on this site) - the real payoff of the enforced
      dependency, proven against a real split-out submodule, not just the
      earlier scratch-module test. Piece 5 (`aim_chatbot`'s explicit
      dependency on `aim_scope_site`) BUILT 2026-09-29; piece 6 (the
      bundling recipe) remains unbuilt. Explicitly does not move
      `subject`/`user` to per-bundle
      fields - already tried and reverted (DEVELOPING.md's "Scope/bundle
      model"). (The `user` field was itself `subject_uid` until
      2026-09-28, `aim_update_10001()` - renamed for readability,
      unrelated to this item.)
- [x] Follow-up audit, same day, prompted by Nik asking "what other
      bespoke-to-one-scope code is still in core `aim`?" toward the goal
      of core working with any subset of scope submodules installed, no
      dead code for the ones absent. Found and fixed:
      - `AimUserScopeAccessForm` renamed `AimScopeUserAccessForm`
        (`getFormId()` now `aim_scope_user_access_form`) to match the
        plugin's `AimScope[Name]` rename - route/path unchanged.
        `requires_account` ThirdPartySetting shortened from
        `requires_user_account` (redundant - a Drupal "account" already
        means "user account").
      - A third hardcoded `$fact['scope'] === 'user'` check the original
        grep missed (array-bracket syntax, not `bundle() ===`/
        `scope ===`) in `AimMemoryManager::createFactsFromCandidates()`
        (the `aim:extract` write path) - now also
        `scopeRequiresAccount()`.
      - Four places defaulted an omitted `--scope`/`scope` to `site`,
        silently assuming `aim_scope_site` is installed:
        `aim:remember`'s CLI default, its `--file` batch fallback,
        `aim:benchmark`'s CLI default, and `aim_tool`'s `AimRemember`
        Tool/MCP plugin (single-fact and batch-entry `scope` inputs).
        All four now require scope explicitly and error clearly when
        it's missing, rather than guessing.
      - **Subject-minting fixed 2026-09-28, reusing the existing plugin
        type:** `case` scope's subject-auto-minting in
        `AimMemoryManager::remember()` was genuinely scope-specific
        *behavior* (mint a UUID), not a config flag, so it couldn't become
        a ThirdPartySetting the way `requires_account` did. Extracted via
        a `defaultSubject(): ?string` method added to the existing
        `AimScopeAccessInterface` (not a second plugin type - chosen
        because `AimScopeAccessPluginManager::getAccessPlugin()` already
        treats "no plugin for this scope" as valid, so `role`/`site`
        gained no PHP by this), implemented by a new `AimScopeCase`
        plugin in `aim_scope_case` (mints the case ID, `checkViewAccess()`
        stays neutral) and a one-line `NULL` addition to `AimScopeUser`.
        The "Case ID: ..." CLI hint in `AimCommands.php` and its
        `aim_tool` equivalent were left untouched - they're an unrelated,
        purely cosmetic `bundle() === 'case'` check on the *output*
        message, not part of the minting decision.
- [x] Verify `AimScopeDeleteForm`'s refusal to delete a scope while
      `aim_fact` entities of that bundle exist actually fires during
      **module uninstall** (`drush pmu`), and fix it - DONE 2026-09-28. It
      did not fire (verified live with a disposable scratch module before
      building anything); fixed with `Drupal\aim\ScopeUninstallValidator`
      (`aim.scope_uninstall_validator`, tagged
      `module_install.uninstall_validator`, same mechanism as core's
      `field.uninstall_validator`), which blocks both `/admin/modules/
      uninstall` and `drush pmu` with a worded reason whenever a scope
      still has `aim_fact` rows - except when the module being uninstalled
      is `aim_fact`'s own entity-type provider (core `aim` today), since
      that drops the whole table and orphans nothing; without that guard
      it would have also blocked `drush pmu aim` on this site's own 34
      live facts, breaking the PoC reinstall workflow above. Full writeup
      in [ADR-0026](adr/0026-pluggable-scope-submodules.md)'s "Open
      questions".
- [ ] `checkViewAccess()`'s "allowed or neutral, never forbidden" contract
      (`AimScopeAccessInterface`) is a deliberate design choice, not a
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
- [x] `scope: entity` bundle - BUILT 2026-09-29, see
      [ADR-0027](adr/0027-entity-scope.md). Two always-present base
      fields on `aim_fact` (`target_type`/`target_id`, plain strings, no
      new package dependency - `drupal/dynamic_entity_reference` was
      considered and rejected, see that ADR's Context), a new
      `aim_scope_entity` submodule, and `AimScopeEntity` as the second
      real `AimScopeAccessInterface` plugin (`checkViewAccess()` returns
      the referenced entity's own view-access result directly). CLI/Tool
      wiring also built same day - `aim:remember --target-type`/
      `--target-id` (single and `--file` batch), and `aim_tool`'s
      `AimRemember` plugin (single and `facts` batch) - all three
      verified live. Write-time validation that a `scope: entity` fact
      actually carries a target is deliberately still not built (see that
      ADR's "Not yet built") - a missing target just makes
      `checkViewAccess()` neutral forever, not a security gap.
- [x] `AimScopeAccessInterface`/`AimScopeAccessPluginManager` renamed
      `AimScopeTypeInterface`/`AimScopeTypePluginManager` - BUILT
      2026-09-29, see [ADR-0028](adr/0028-scope-type-plugin.md) piece 0.
      Raised by Nik asking whether "access" still fit once the plugin was
      proposed to also own field declarations and settings, not just
      view-access/default-subject; mechanical rename only, all three
      existing plugins (`AimScopeUser`, `AimScopeEntity`, `AimScopeCase`)
      and every call site moved.
- [x] Widen the scope type plugin - field declaration, settings schema,
      decoupling plugin ID from scope ID (ADR-0028 pieces 1-3) - BUILT
      2026-09-29, raised by Nik asking whether the module split had
      actually made scopes pluggable end-to-end or just structurally
      separated, given `AimMemoryManager`/`aim_tool` still hardcoded
      `target_type`/`target_id`. `target_type`/`target_id` moved from
      `AimFact::baseFieldDefinitions()` into
      `AimScopeEntity::getBaseFieldDefinitions()`, merged generically by
      `AimHooks::entityBaseFieldInfo()`; `AimScope` gained `plugin`/
      `settings` (`config_export` now `[id, label, plugin, settings]`),
      `AimScopeForm` grew a type selector + AJAX settings sub-form;
      `AimScopeTypePluginManager::getTypePlugin()` now resolves via
      `$scope->get('plugin')` instead of assuming plugin ID == scope ID.
      Two real bugs found and fixed live, not just a clean build - see
      ADR-0028's Consequences for detail: (a) a field merged through
      another module's `hook_entity_base_field_info()` needs
      `->setProvider()` called explicitly or it's misattributed to the
      *hook's* module; (b) a hand-written `hook_uninstall()` calling
      `uninstallFieldStorageDefinition()` races core's own generic pass
      (`ModuleInstaller::uninstall()` already does this for any field
      whose provider matches the uninstalling module) and crashes -
      removed entirely, `hook_install()` alone is correct.
- [x] Migrate `aim_scope_user`'s `requires_account` off its
      ThirdPartySetting onto ADR-0028 piece 2's `settings` mechanism -
      BUILT 2026-09-29, the nice-to-have ADR-0028 named but left undone.
      `AimScopeUser::defaultSettings()`/`buildSettingsForm()` now own it;
      `AimMemoryManager::scopeRequiresAccount()` reads
      `settings['requires_account']`; `aim_scope_user`'s
      `config/schema/aim_scope_user.schema.yml` deleted (nothing left to
      type). Surfaced and fixed a real schema gap along the way: generic
      `aim.aim_scope.*.settings` was typed `mapping: {}`, which errors as
      "missing schema" on the first real (non-empty) settings value
      rather than acting as an open wildcard - retyped to `ignore`. See
      [ADR-0028](adr/0028-scope-type-plugin.md)'s Consequences for the
      full write-up and live verification.
- [ ] **Drush oddity found while verifying the above, not yet diagnosed
      further or filed upstream.** `drush pmu aim_scope_entity` throws
      `SQLSTATE[42S22]: Column not found: 'target_type'` even after both
      fixes above; calling
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
- [x] ADR-0028 piece 4, found 2026-09-29 while answering "are the
      endpoints pluggable too?" - BUILT AND VERIFIED same day.
      `aim_tool`'s `AimRemember` no longer hardcodes `target_type`/
      `target_id` in its `#[Tool]` `input_definitions`: replaced by one
      generic `scope_fields` free-map input, refined per call via
      `InputDefinitionRefinerInterface` (`input_definition_refiners:
      ['scope_fields' => ['scope']]`) using the existing
      `getTypePlugin()`/`getBaseFieldDefinitions()` machinery pieces 1
      and 3 already built - no new coupling between core `aim`'s scope-
      type interface and the `tool` module was needed. `aim_recall`'s
      read side deliberately left alone - no scope type offers a filter
      to genericize yet. Live-verified at three layers: the refined
      `InputDefinition` objects, a full write execution (single, plain-
      scope, and batch calls), and the actual MCP `tools/list` JSON
      Schema read off the live derivative. See
      [ADR-0028](adr/0028-scope-type-plugin.md)'s piece 4 entry for the
      full write-up, including the one thing this piece deliberately
      does not solve (`AimMemoryManager::remember()`'s own signature
      still takes two fixed positional params, not a generic bag - no
      live gap today since `target_type`/`target_id` are the only base
      fields any shipped scope type declares, but a real limit on how
      far the new generic schema actually reaches).
- [ ] Widen `AimMemoryManager::remember()`'s `$targetType`/`$targetId`
      positional parameters into one generic scope-fields bag - found
      2026-09-29 while building ADR-0028 piece 4 above, not built.
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
      both prerequisites this was blocked on are now cleared (`trusted`
      field built; `scope: entity` + its ADR-0025 plugin built per
      ADR-0027 above). The bridge module's own write path is still
      unbuilt. Promotion logic itself is fully custom/programmatic, not
      gated on any of this.

Build order, followed: `trusted` field done -> `scope: entity` ADR
(ADR-0027) done -> its ADR-0025 plugin done -> the bridge module (not
started).
