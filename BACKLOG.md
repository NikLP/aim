# BACKLOG

Parked, upstream-dependent and wait-for-a-trigger items, moved out of
[TODO.md](TODO.md) 2026-10-19 unchanged apart from deleting finished
entries. Pull an item back into TODO.md when work starts. Design reasoning
belongs in the [ADRs](adr/0000-index.md).

## Case picker widget (raised 2026-10-14, pinned)

- [ ] Pinned: a widget to pick from existing in-scope cases when writing a
      `scope=case` fact, instead of typing a case ID or minting a new one
      each time (`AimScopeCase::defaultSubject()` mints when `subject` is
      empty). Needs a "list existing case subjects the viewer may see" query
      first, and case-scope view access is still unbuilt, so the list would
      leak case IDs across viewers until it is.

## Design review findings (raised 2026-10-02)

Workflow diagram: [adr/workflow-diagram.md](adr/workflow-diagram.md).
Decisions marked "agreed" were settled with Nik in that review; the rest
are recommendations.

**Identity and access**

- [ ] Agreed: refuse new authored writes with uid 0 (Drush callers pass
      `--uid`). Consolidation retirements and merged facts are exempt
      (a merged fact copies `uid` and `user` from the kept fact). The
      current database is disposable, so no `uid` backfill is needed.
- [ ] Docs: say "the fact is about this user" for `user` scope (`user` is
      the subject, `uid` is the author; keep `uid`, it is Drupal's idiom).
- [ ] Grill access: what exists is fine for now (Drush with `--uid`, or
      MCP `aim_remember`). Do NOT build an `aim_extract` MCP tool: the
      calling agent already does the extraction reasoning. Review later.
      A web console ([ADR-0029](adr/0029-context-carrying-turns.md),
      `aim_console`) needs its own chat model; point it at local Ollama to
      stay sovereign.

**Personal data (raised 2026-10-10, [ADR-0056](adr/0056-personal-data-write-gate.md), parked 2026-10-11)**

- [ ] Parked by Nik 2026-10-11, revisit later. Accept or amend ADR-0056 (no personal data stored unless
      authorised). Then: a `pii` eval set and task in `decision-eval.php`,
      the `aim_personal_data` guardrail set, the extraction prompt change,
      and the demo's raw email fact moved into a literal.
      Revisit triggers (decide later, 2026-10-15): before real personal
      data goes in, or before the chat widget is reopened to anyone outside
      (an outside tester could type their own email, phone or IBAN into it,
      and it would be stored and sent to the hosted model). The redacting
      guardrail (Outside advice, below) is parked with it.
- [ ] If accepted, `aim_rejection` keeps no text for `personal_data`
      rejections, and ADR-0037 is built inline (no `aim_fact_source`).

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

- [ ] Open question: could Guardrails and the Decision API do the same
      job (see "Decision models")? Not yet written down elsewhere.

**Doc and code discrepancies found**

- [ ] DEVELOPING.md says `remember()`/`createFactsFromCandidates()`
      enqueue consolidation; the code enqueues in
      `AimHooks::factInsert()` for every non-syncing insert (merged facts
      re-enqueue themselves).
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

- [ ] Real (non-dry) run and queue-worker check of top-N consolidation;
      no automated tests cover it yet.
- [ ] Time-limited facts ("for two months from now") must not be UPDATE
      merged into permanent facts: proposed fix in
      [ADR-0057](adr/0057-dates-resolved-and-confirmed-before-write.md)
      (resolve and confirm dates before write, `expires` plus its sweep).
      `expires` input and `aim:expire` sweep BUILT 2026-10-11 (add the
      hourly crontab line; `hook_cron` backs it up); still to do: today's date to the assistant,
      persona wording, extraction date resolution, never merging a dated
      fact into an undated one, dated eval items.
- [ ] Pinned: `verified`/`verified_by` fields (not `trusted`), ADR-0036.
- [ ] Parked (ADR-0057 time stance): recency/importance re-rank,
      weights default 0, needs a gold set.
- [ ] Parked (ADR-0057 time stance): access counter / last-recalled.
- [ ] Later, on a real recall miss: RRF keyword path via `search_api_db`
      (hybrid keyword + vector recall; second keyword-only index fused in
      PHP, feasibility checked in [ADR-0036](adr/0036-memory-algorithm-appraisal.md)
      "Checked 2026-10-02"; open question there: whether a keyword-only
      hit may bypass the ADR-0019 distance cutoff).

## Outside advice (raised 2026-10-15, George Kastanis, OpenBrain author)

Source: a chat with George Kastanis, a Drupal AI key player who built
OpenBrain, an in-house second brain. His points, and where each landed:

- [ ] **Plumbing audit (do soon).** He measured about a fifth of his custom
      code as plumbing Drupal AI contrib now covers. List every aim service,
      form and plugin and mark each: memory rules (keep) or plumbing
      (contrib covers it, or could). Check against the Agent Access recipe
      (a login per person for Claude/ChatGPT), Tool API tool permissions,
      `ai_logging`, and the Guardrails rewrite mechanism. Less code also
      answers the neglect risk. Output: a table in DEVELOPING.md, then
      deletions.
- [ ] **Agent Access recipe trial (done 2026-10-15; follow-ups open).**
      Result: not a replacement for `aim_tool_oauth`; see DEVELOPING.md
      "Plumbing audit". It adds no aim scopes or tools, and permission-
      granularity scopes (ours and its) lose the user's roles, so the
      role-visibility matrix returns nothing for non-admin tokens and
      `aim:remember` lacks `create {scope} aim facts`. Follow-ups: (a)
      add role-granularity scope(s) to `aim_tool_oauth` and re-test on
      the live site; (b) DONE 2026-10-15: a permission scope does not
      grant a permission the user's roles lack (tested live: effective =
      scope AND user), so no upstream issue; (c) note
      for any recommendation: `agent_access` is pre-release and not under
      the security advisory policy; accepted as fine for now. Scratch
      copy removed.
- [ ] **`aim_tool_oauth` per-tool scope gate is inert (found 2026-10-15).**
      `mcp_server_oauth` 1.0.0-alpha1 finds a tool's config by matching
      the wire name against `<base>.<config_id>` (its tests use
      `tool_api.protected_tool`), but `mcp_server_tool_bridge` 1.0.0-beta3
      names tools `tool_api__<config_id>`. No config matches, so the
      scopes we set on `aim_recall`/`aim_remember` are never checked: a
      token without them still calls the tools (seen in the trial; only
      the Drupal permission was enforced). The scopes still matter for
      the permission they carry. To do: file or find an upstream issue
      (name-format mismatch between the two modules), confirm on the live
      site, and until fixed do not rely on the per-tool scopes for
      anything. Update: upstream fixed this (#3604047, in 1.x-dev
      fb92204; no release yet). Installed on this site 2026-10-15 pinned
      as `1.x-dev#fb92204`; the subscriber now resolves `tool_api__aim_recall`/
      `aim_remember` to their configs. Verified live 2026-10-15 with a throwaway
      non-admin user (since deleted): a token without `aim:recall`/
      `aim:remember` is refused (logged `insufficient_scope`, but the
      client sees JSON-RPC -32603 "Internal server error", not a 403);
      with the scope it works. Same test found: no aim scope carries
      `access mcp server`, so a non-admin cannot connect at all (403 on
      initialize); and `aim_remember` with `aim:remember` still fails
      "No permission to create site-scope facts" for a role that holds
      it. Both are fixed by the role-scope change (documented 2026-10-15 in aim_tool_oauth's DEVELOPING.md; live re-test done 2026-10-15, see that file). Still to do: swap the
      pin for the first release containing the fix. No upstream issue needed from us. Once enforced, a role scope alone is
      rejected (403 insufficient_scope) by tools requiring `aim:recall`/
      `aim:remember`, so clients must request the role scope plus the
      aim scope; design the role-scope change (trial item above) for
      that and test after the upgrade.
- [ ] **Redacting guardrail.** Drupal AI's PII guardrails only block; none
      removes the PII, yet the rewrite mechanism exists
      (`runGuardrails()` already handles `RewriteInputResult`). Sketch:
      (a) deterministic redaction for pattern PII (email, phone, IBAN) as
      a guardrail plugin, upstreamable on its own; (b) for names, a
      decision-model pass (ADR-0056's single call); (c) aim-specific: in
      `scope=user` the `user` reference already carries identity, so the
      name in the text is redundant and can go; (d) the strongest form:
      move the value into `literals` with an audience and leave a
      `[literal:key]` token, so redaction is reversible and
      access-controlled, not destructive. Folds into ADR-0056 (parked);
      decide on (a) first as the small visible contribution.
- [ ] **Privacy review (top-level, before any real data).** "True privacy"
      is not available, so define levels and say what each protects
      against. Known leak paths to list: admin roles hold every permission
      (a hide-from-admins rule needs its own access check); AI Search
      checks entity access per hit, so `AimFactAccessControlHandler` is the
      line - needs a test that a private `scope=user` fact is absent from
      `recall()` for an admin and for another user; `ai_log` stores model
      prompts and replies in clear (ADR-0060); a hosted model sees every
      candidate fact (CLAUDE.md scope note); embeddings leak meaning.
      George's rule: when unsure, make it private.
      First pass BUILT 2026-10-15: `tests/src/Kernel/AimPrivacyTest.php`
      runs `recall()` through entity access (the fake search backend now
      applies it like the real one). Findings: a viewer with only the site
      permission, or no permissions, sees no user-scope fact; the shared-role
      fallback and the role-visibility matrix show it as designed; an
      administrator role sees every user's facts (the gap George named, pinned
      as today's behavior); `accessCheck: FALSE` (Drush) shows everything.
      Not covered: the subject seeing their own fact with no shared role (no
      rule gives it), `ai_log` prompt text, and the real AI Search per-hit
      check (the fake stands in for it).
- [ ] **PINNED (2026-10-15, Nik: parked until we regroup). Subject / user / uid audit.** The identity-ish fields have drifted:
      `uid` (author), `user` (who a `scope=user` fact is about, was
      `subject_uid`), `subject` (generic per-scope key, minted case IDs),
      `target_type`/`target_id` (entity scope). Write one table: field,
      meaning, which scopes, who sets it, what checks it (see the
      authorship gap under "PoC deviations"). Then decide what is
      redundant. Output: a short section in DEVELOPING.md.
- [ ] **Importers (open question).** George's view: importing from real
      systems (time tracking, git, chat, meetings), plus PII removal,
      privacy levels and numeric questions ("hours on project X last
      month"), is what no one else does and where aim would stand out.
      Not decided. Two cautions: each connector is its own product (auth,
      schema, upkeep - the neglect risk), and numeric questions are
      structured data plus a query tool, not vector memory. Cheapest probe:
      one importer feeding the existing ingest path (see "Ingestion"),
      to learn whether extraction copes with structured exhaust. Imports
      make the redaction item above urgent.
- [ ] **Human curation of the trust gate.** Policy, `review aim facts` and
      the `aim:status` count BUILT 2026-10-19 (ADR-0037 addendum); still no
      one holds the permission and no reason column on the facts list. None
      yet: nobody holds
      `trust {scope} aim facts` and the grounded check is in shadow mode
      (see "Loop back to the trust permission" under Governance). Decide
      the reviewer workflow before claiming draft-to-trusted is a feature.
- [ ] **Packaging.** Not a one-line installer. `recipes/aim_demo_library/preflight/preflight.js` lives in
      the recipe, not the module proper; consider a module-level check (a
      Drush command or status report) so installs are verifiable. Heavy
      parts already swappable (`DecisionBackend`, provider shim, Ollama
      embeddings); document the swap points. George suggested a site
      template after the module exists. Unresearched here: check what
      Drupal CMS site templates require against `aim_demo_library`.
- [ ] **OpenKB (later).** Position aim as the governed fact layer beside
      it, possibly feeding the planned RAG idea; one ADR when that
      starts. Earlier appraisal: it is a wiki, not a fact memory.
- Not a new item: merging. Consolidation is already non-destructive
  (retire plus `superseded_by`), which meets his "keep originals so you can
  undo". Jev as a judge is also largely done: one question per call, gate
  and grounding eval sets built (44 items), thresholds separate per
  activity. What remains is under "Decision models" (per-model question
  text, enforce decision).

## PoC deviations to close before non-PoC data goes in

- [ ] User-scope authorship gap (raised 2026-09-30): nothing checks that a
      `scope=user` fact's `user` (who it is about) is the poster (`uid`,
      who wrote it). `aim_tool`'s `AimRemember` only *defaults* `subject`
      to the current user when omitted; a caller can pass any uid/username
      and the fact is saved about that person, and `create user aim facts`
      is the only gate. The tool description asking the model to confirm
      first is prompt-level, not enforced. Proposed: in `remember()`,
      reject `subject != currentUser` unless the caller has
      `administer aim` or a new permission for writing about
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
- [ ] Tests still missing (harness: DEVELOPING.md, "Running the tests"):
      the model-judged paths of `consolidate()` (UPDATE merge, verifier,
      guardrail-blocked merge: needs a choice-answering fake decision
      backend), the ingest form's extract mode and file upload, the Tool
      API/MCP transport, and the user-scope role-visibility settings form.
- [ ] Upstream: ai_search re-index of an already fully indexed item (raised
      2026-10-15). `indexItemsWithChunkSlicing()` starts at the item's
      `processed_chunks`, so a second `indexItems()` on an item whose
      `processed_chunks` already equals `total_chunks` deletes its vector
      row, slices past the end, inserts nothing and reports success. aim
      hit it via `index_directly` plus `reindex()` (fixed locally, see
      DEVELOPING.md "Vector search"). Same mechanism as the closed
      #3584035, which only resets progress on clear/reset-tracker
      (`ChunkTrackingResetEventSubscriber`), not on a plain re-index. Not
      #3584058 (that one is about chunk recompute cost). Searched the open
      and closed queue 2026-10-15, no duplicate found. Before filing:
      reproduce on a bare ai_search index with `index_directly` and call
      `indexItems()` twice in one request. A fix upstream would reset or
      ignore `processed_chunks` when the tracker already lists the item as
      indexed, or the backend could treat offset >= total as offset 0.
      When fixed upstream, drop the post-request queue clearing in
      `AimMemoryManager::reindex()`.
- [ ] Autowire the four tool and chatbot plugins (`aim_tool` and
      `aim_chatbot`, `AimRecall` and `AimRemember` each). Left on their
      `create()` overrides 2026-10-15: `ToolBase::create()` and
      `FunctionCallBase::create()` build the instance with `new static(...)`
      and never call `createInstanceAutowired()`, so `#[Required]` setters
      would not run. Needs the parents to autowire (upstream in `tool` and
      `ai`) or hand-wiring the parent constructors, which is worse than
      today's override. Revisit when either parent adopts
      `AutowiredInstanceTrait`.
- [ ] Bulk actions for `category` on the admin facts view - deliberately
      not built (2026-09-30) because the field is not in use yet.
      `trusted`/retire/un-retire have them (`src/Plugin/Action/`,
      `system.action.aim_fact_*`). `category` needs a configurable action
      with a term form (add/replace/clear). Revisit when it starts being
      populated. (`state` was removed 2026-10-12.)
- [ ] Fast-path lookup for `category`, bypassing `recall()`'s vector
      search. `category` is exact-match, but everything currently pays
      `recall()`'s embed-then-search cost (33-540ms, see "Benchmarking").
      [ADR-0032](adr/0032-dated-category-listing-for-quick-notes.md)'s
      `listFacts()` is the non-vector query; build that first, then decide
      whether a Cache API layer (keyed scope+subject, invalidated on save)
      is still wanted. The boolean `state` field this was originally
      motivated by was removed 2026-10-12 (nothing read it).
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
      Consequences. Design sketch (2026-10-14, from the Oracle "enterprise
      brain" article): erase by **tombstone**, not delete. An `aim:erase`
      command (by user or fact) blanks `text` on the target and on every fact
      linked to it through `superseded_by`, both directions, because a
      consolidation UPDATE builds a merged fact from two inputs and erasing
      one leaves its content alive in the merge. Also drop the rows from the
      vector index, write an audit line (IDs only), keep ids, dates and the
      supersede chain so the trail survives. ADR-0056's `personal_data` flag
      is how it finds candidates. Check first what else holds text:
      `superseded_by_reason` (meant to hold none), ADR-0037's
      `aim_fact_source` (7 days), the unbuilt `aim_rejection` quarantine, and
      the edit-history item under Governance (revisions would keep old text).
- [ ] **Recall audit line (raised 2026-10-14).** `recall()` logs only counts
      and best score, and only at the verbose tier, so "what did the agent
      know when it acted" cannot be answered. Add an audit-tier line per
      recall: uid, scope, returned fact IDs (IDs are not personal data).
      Same data the eval plan's trace keeps, see
      [eval/DRAFT-eval-plan.md](eval/DRAFT-eval-plan.md) section 6. Facts
      are superseded not deleted, so IDs plus the timestamp reconstruct what
      was visible, except for in-place text edits (see edit history above).
- [ ] **Cross-user writes (raised 2026-10-14, decide when the use case is
      pinned).** `aim_remember` takes a caller-supplied `subject` and checks
      only `create user aim facts`, so anyone holding it can write a
      scope=user fact about any account
      (`AimRemember::saveOne()`, `AimRememberService::remember()`). Mitigated
      for now: new facts start untrusted and are not recalled until trusted.
      Legitimate in some cases (staff noting facts about a customer). When it
      matters, use two scope instances sharing one plugin with different
      `settings` ([ADR-0028](adr/resolved/0028-scope-type-plugin.md)): `user`
      own-subject-only, and a separate scope where writing about others is
      allowed. No new mechanism needed. Recall side is fine: a caller-supplied
      `user` for someone else's facts returns nothing the viewer cannot see.
- [ ] **Recall over-fetch is a code constant (noted 2026-10-14).**
      `recall()` asks for `$limit * 5` when filtering by user
      (`AimMemoryManager::recall()`), measured in ADR-0018 before HNSW tuning
      (ADR-0023) closed most of the gap, and never re-measured since. Costs
      little (tens of rows, the embedding call dominates), so it stays. Re-measured 2026-10-14 (ADR-0018 addendum): 1x already gave 100% at ef_search=100 on a synthetic corpus; repeat with `aim_benchmark` before lowering. If it
      ever needs tuning: re-run ADR-0018's probe method with the tuned HNSW
      settings at 1x, 2x and 5x and change the constant; a setting is only
      worth adding if different sites need different values. `ai_search`
      separately doubles its batch for access checks and retries up to 10
      times; that is upstream.
- [ ] **Idle lock for MCP callers (raised 2026-10-14, not verified).**
      Autologout ends Drupal sessions, but an MCP client holds an OAuth
      bearer token that outlives the session, so it likely does nothing here.
      First lever is configuration: short access and refresh token lifetimes
      per consumer in `simple_oauth` (refresh rotation then acts as a sliding
      idle window). Activity-based locking would need a small cron or event
      piece revoking tokens unused for N minutes. Search drupal.org for an
      existing module before building; not done yet.
- [ ] **Single-valued, filterable category, labelled on ingress** - see
      [ADR-0059](adr/0059-single-valued-category-and-ingress-labelling.md).
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

- [ ] Add `permission: 'recall aim facts'` to `AimRecall`'s `#[Tool]` and
      `permission: 'remember aim facts'` to `AimRemember`'s. Label-only
      change: Tool API reads it without instantiating the plugin (Tool
      Explorer, catalogs, `tool:info`, which shows `None` today) and
      checks it before input is processed. **Keep both `checkAccess()`
      overrides** - ToolBase's default only says "yes if a permission was
      declared", and `AimRemember` may need its own extra rules. A typo'd
      permission name denies every ordinary account but not admins, so
      test as a non-admin. Denial now precedes input validation (different
      error message when both apply): re-run the recipe's `preflight/preflight.js` (`node demo/preflight.js` on this site)
      afterward. Optional - safe to leave undone.

## aim_tool: schema discoverability and typed output (raised 2026-10-05, from Matt Glaman's "Define the capability once, call it anywhere")

Source: https://mglaman.dev/blog/define-capability-once-call-it-anywhere.
The post's claims about fixes (tool 1.0.0-beta3 `tool:run` failure
masking, `mcp_server_tool_bridge` 1.0.0-beta1 empty-input fix) and the
open Tool API issue #3582943 (`tool:info` flattens inputs to data types:
no enum constraints, no required permissions) are **unverified** against
the released tags and issue queue - check before building on them.
Installed 2026-10-05: tool 1.0.0-beta11, bridge 1.0.0-beta3, mcp_server
2.0.0-beta5.

- [ ] Stale/hardcoded scope lists: `AimRecall`'s `scope` description and
      `AimRemember`'s batch-entry `scope` description say "user, role,
      site, case" (no `entity`, ignores which scope submodules are
      installed, ADR-0028). Build the description from the installed
      `aim_scope` entities, as the refiner already does for
      `scope_fields`.
- [ ] Constrain `scope` to an enum if Tool API supports one on
      `InputDefinition` (check first; #3582943 suggests `tool:info` may
      drop it even when declared). Otherwise the description is the only
      discovery channel.
- [ ] Typed output for `aim_recall`: today one formatted `results` string.
      A structured list (id, text, scope, distance, asserted) lets agents
      chain on it. Trade-off: MCP clients may render the string better;
      could return both.
- [ ] Optional: use `drush tool:run aim_recall` / `aim_remember` as a
      smoke test of the tool layer without an MCP client.

## Backlog - explicitly "wait for a trigger" per the docs' own framing

- [ ] Fact verification as a user-facing feature - now reinforced twice
      (OpenAI's editable memory summary, and OpenKB's review-as-UX pattern
      from this thread).
- [ ] Sub-scope visibility/audience control within `scope: site`.
- [ ] EU AI Act Article 50 disclosure - evaluate `drupal/ai_disclosure`
      before public launch.
- [ ] Fact-to-fact authored relations graph.
- [ ] Export / portability (raised 2026-10-14, from a second-brain feature
      appraisal): a Drush command and admin action dumping facts to
      Markdown and JSON (scope, text, category, source, asserted,
      expires, trusted, retired/superseded edges), honoring the viewer's
      access. Counterpart to ingest, so there is no lock-in; the
      `aim_demo_library` recipe's `facts.json` shows the import shape.
- [ ] Review digest (raised 2026-10-14): a periodic summary (email or
      dashboard block) of facts added, untrusted awaiting review, expired
      or stale, and rejected candidates. Self-maintaining upkeep against
      the neglect risk; likely the delivery mechanism for the scheduled
      staleness review below. Queue/cron per ADR-0003, never `hook_cron`.
- [ ] Scheduled TTL/staleness review - mechanism undecided now that
      Content Moderation isn't happening (ADR-0002 addendum, 2026-09-28);
      don't build ad hoc.
- [ ] Pre-extraction summarization as a dedup lever.
- [ ] **Policy discussion: disclosure of logged chats (raised 2026-10-15).**
      Chat turns and model-call prompts are now stored in `ai_log` (90 days,
      ADR-0060) for evaluation. A site that keeps real visitors' chats needs
      its privacy notice to say so (what is kept, why, how long, who can read
      it); ties to the parked PII gate (ADR-0056, decide before real data),
      stealth mode (ADR-0060 decision 4) and the Article 50 disclosure item.
      Decided for now: eval datasets may use real visitor questions only
      when a human has redacted them; text logging is opt-in for new sites
      (this site has it on). Draft: eval/DRAFT-eval-plan.md.
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

## Parked ADRs, revisit triggers (retired 2026-10-11 and 2026-10-12)

- ADR-0012 (fact-to-fact relation graph, multi-hop retrieval): revisit when a
  consumer needs more than the supersede edge in `related`.
- ADR-0014 (use-case archetype starter kits): revisit when a third Recipe
  beyond `aim_demo_library` and `aim_ollama_local` is wanted.
- ADR-0044 (Tool API method attributes, drupal/tool MR 162): revisit when the
  MR merges, or when a new `aim_tool` tool is about to be written.
- ADR-0055 (decision rationale as entity-scope facts): revisit when a
  decision-provenance need is real. The test is to hand-model 5 to 10 ADRs
  as `scope=entity` facts and check recall beats grepping the ADR files.

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
- [ ] Widen `AimRememberService::remember()`'s `$targetType`/`$targetId`
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

- [ ] ADR-0060 follow-ups: stealth mode (presave hook on `ai_log` blanking
      `prompt`, `output_text`, `response_text`, `configuration`,
      `extra_data`; off by default; optional PII reduction) before real
      data; fact ID as input debug data at each call site so a log row joins
      to its fact; port the benchmark/eval queries to `ai_log` with
      `JSON_VALUE`; upstream asks once proven (duration column on `ai_log`,
      per-tag retention); test `AimCallLogSubscriber` (none yet, only a live
      check on 2026-10-14).
- [ ] Metrics backend decided against for now (2026-10-14): nothing runs one
      on this site, and `ai_log` + `JSON_VALUE` answers the eval questions.
      Cheapest chart route if wanted: a Views chart over `ai_log` (untried).
      A production site wanting budgets or alerts enables `ai_observability`
      plus `opentelemetry_metrics` itself; no aim code needed (ADR-0060).
