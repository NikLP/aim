# BACKLOG

Parked, upstream-dependent and wait-for-a-trigger items, moved out of
[TODO.md](TODO.md) 2026-10-19 unchanged apart from deleting finished
entries. Pull an item back into TODO.md when work starts. Design reasoning
belongs in the [ADRs](adr/0000-index.md).

## Case picker widget (raised 2026-10-14, pinned)

- [ ] Pinned: a widget to pick from existing in-scope cases when writing a
      `scope=case` fact, instead of typing a case ID or a new case's name
      (`AimScopeCase::resolveSubject()` names or mints the ID). Needs a "list existing case subjects the viewer may see" query
      first, and case-scope view access is still unbuilt, so the list would
      leak case IDs across viewers until it is.

## Design review findings (raised 2026-10-02)

Workflow diagram: [adr/workflow-diagram.md](adr/workflow-diagram.md).
Decisions marked "agreed" were settled with Nik in that review; the rest
are recommendations.

**Identity and access**

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
      authorized). Then: a `pii` eval set and task in `decision-eval.php`,
      the `aim_personal_data` guardrail set, the extraction prompt change,
      and the demo's raw email fact moved into a literal (ADR-0061 section
      3.1's convert-a-fact form, unbuilt, in TODO.md "Literals, aim's side").
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
**Indexing and settings**

- [ ] Open question: could Guardrails and the Decision API do the same
      job (see "Decision models")? Not yet written down elsewhere.

## Recall cutoff per embeddings model (raised 2026-11-19)

The vector server now takes the site's default embeddings model
(`aimUseDefaultModels`), but `recall_max_distance` (and the recipes' 0.6)
was measured on `nomic-embed-text`: distances are not comparable across
models, so another model gets a cutoff that may be too tight or too loose.
Found applying `aim_demo_library` on habanero (no Ollama).

- [ ] Options, cheapest first: ship cutoffs for measured models and have
  `aim:status`/the pre-flight flag an untuned engine; lean on top-N plus the
  calling model's judgement with a loose cutoff; calibrate on apply from a
  few known related/unrelated pairs. Same applies to the verifier threshold
  and grounded check (see "Operating language" below).

## Operating language (raised 2026-11-11)

Goal: a site runs aim in one language of its choice (Spanish, say), not
translated facts. `aim_fact` stays non-translatable. Nothing in storage or
recall assumes English; the work is model setup and tuning.

- [ ] Multilingual embeddings model (the shipped `nomic-embed-text` is
  English-centric). A new dimension count means the table rebuild in
  DEVELOPING.md's "Setting up vector search", step 5.
- [ ] Re-tune `recall_max_distance`, the verifier threshold and the grounded
  check on the new model; the current values are English-on-nomic.
- [ ] Prompts are English and the extraction prompt never says what language
  to write a fact in, so a model may translate to English. Untested. Idea: an
  `aim.settings` operating-language value (a selector on a multilingual
  site) that the extraction, consolidation, verifier and grounded-check
  prompts interpolate. Not built, not yet tested.
- [ ] Interface strings and the chat persona are separate (Drupal interface
  translation, the persona text).
- Done 2026-11-11: the orphan-vector check reads each fact's real langcode
  instead of assuming `en`.

## Memory algorithm follow-ups (raised 2026-10-02, ADR-0036)

- [ ] Time-limited facts ("for two months from now") must not be UPDATE
      merged into permanent facts: proposed fix in
      [ADR-0057](adr/0057-dates-resolved-and-confirmed-before-write.md)
      (resolve and confirm dates before write, `expires` plus its sweep).
      `expires` input and `aim:expire` sweep BUILT 2026-10-11 (hourly
      crontab here; `hook_cron` stays as the backup, since aim cannot
      promise a site a cron job); still to do: today's date to the assistant,
      persona wording, extraction date resolution, dated eval items. Facts
      with different end dates are never compared (BUILT 2026-10-27).
- [ ] Idea (raised 2026-10-27): `remember()` could skip a fact whose text
      matches a live fact in the same scope and subject
      (`AimFact::hasSameText()`), instead of saving it for consolidation to
      retire later. Saves a write, an embed and a queue item, and keeps
      the admin list free of short-lived duplicates. Open: what the caller
      is told (the existing fact's ID, as if saved?), and whether a
      differing `expires` or trust start still counts as new. Wait for a
      real duplicate problem.
- [ ] Pinned: `verified`/`verified_by` fields (not `trusted`), ADR-0036.
- [ ] Parked (ADR-0057 time stance): recency/importance re-rank,
      weights default 0, needs a gold set.
- [ ] Parked (raised 2026-11-13): recall-time decay for fuzzy user-scope
      facts (preferences, interests): a result reorder by category or
      scope, never a change to stored data. ADR-0057 declines decay for
      discrete facts (opening hours) and that stands; "allergic to nuts"
      must not fade like "likes jazz", so this needs a durable-versus-
      perishable signal that `category` does not carry today. Trigger: a
      real recall where a stale personal fact outranks a fresher one.
- [ ] Parked (ADR-0057 time stance): access counter / last-recalled.
- [ ] Later, on a real recall miss: RRF keyword path via `search_api_db`
      (hybrid keyword + vector recall; second keyword-only index fused in
      PHP, feasibility checked in [ADR-0036](adr/resolved/0036-memory-algorithm-appraisal.md)
      "Checked 2026-10-02"; open question there: whether a keyword-only
      hit may bypass the ADR-0019 distance cutoff). Brushed past again
      2026-10-25 (Marcus Johansson's talk slide: FTS5 BM25 + sqlite-vec
      fused by the same RRF formula, for a Drupal docs search). Still
      parked: checked 2026-10-27 against the "any upcoming events?" miss
      and it would not help (no missed fact shares a word with the question).
- [ ] Later, on a real recall miss: an optional "about to" hint on
      `aim_recall` (raised 2026-10-30, from Memlo's `search_memories`
      `about_to` field). The agent states the step it is about to take
      ("About to deploy to production") alongside its query, and the hint
      joins the text that gets embedded, so recall also finds facts about
      the action ("never deploy on Fridays") that the question's own words
      would not match. One optional tool input plus a line in the tool
      description; no schema change. Trigger: a recall that missed a
      relevant fact because the query described the question, not the
      action.

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
      `AimPrivacyTest` covers user-scope visibility through `recall()`
      (administrators see every user's facts: pinned as today's behavior).
      Not covered yet: the subject seeing their own fact with no shared
      role (no rule gives it), `ai_log` prompt text, and the real AI Search
      per-hit check (the test's fake backend stands in for it).
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
- [ ] **Human curation of the trust gate.** The policy, `review aim
      facts` and the `aim:status` count are built (ADR-0037 addendum), but
      on this site no role holds `review aim facts` or any `trust {scope}
      aim facts`. Decide the reviewer workflow before claiming
      draft-to-trusted is a feature (the reason column is in TODO.md's
      "Trust follow-ups").
- [ ] **Packaging.** Not a one-line installer. `drush aim:status` is the
      module-level health check; the recipe's `preflight/preflight.js`
      adds the demo's end-to-end checks. Heavy parts already swappable (`DecisionBackend`, Ollama
      embeddings); document the swap points. George suggested a site
      template after the module exists. Unresearched here: check what
      Drupal CMS site templates require against `aim_demo_library`.
- [ ] **OpenKB (later).** Position aim as the governed fact layer beside
      it, possibly feeding the planned RAG idea; one ADR when that
      starts. Earlier appraisal: it is a wiki, not a fact memory.
- Not a new item: merging. Consolidation is already non-destructive
  (retire plus `superseded_by`), which meets his "keep originals so you can
  undo". A decision model as a judge is also largely done: one question per call, gate
  and grounding eval sets built (44 items), thresholds separate per
  activity. What remains is under "Decision models" (per-model question
  text).

## PoC deviations to close before non-PoC data goes in

- [ ] User-scope authorship gap (raised 2026-09-30): nothing checks that a
      `scope=user` fact's `user` (who it is about) is the poster (`uid`,
      who wrote it). `aim_tool`'s `AimRemember` only *defaults* `subject`
      to the current user when omitted; a caller can pass any uid
      and the fact is saved about that person, and `create user aim facts`
      is the only gate. The tool description asking the model to confirm
      first is prompt-level, not enforced. Proposed: in `remember()`,
      reject `subject != currentUser` unless the caller has
      `administer aim` or a new permission for writing about
      others; later option, accept it but force `trusted=FALSE` so the
      per-scope trust permission reviews it. Same class of gap as the
      `trusted` override on `remember()` (removed 2026-11-01, ADR-0065). Not built. Legitimate in some cases (staff noting facts
      about a customer): then use two scopes sharing the user plugin with
      different `settings` ([ADR-0028](adr/resolved/0028-scope-type-plugin.md)),
      one own-subject-only, one allowing writes about others. Recall is
      fine: a caller-supplied `user` returns nothing the viewer cannot see.
      (Folded in 2026-10-30 from a duplicate "Cross-user writes" item, whose
      "starts untrusted" mitigation does not hold for a writer with a trust
      permission.)
      [ADR-0007](adr/resolved/0007-user-scope-requires-real-account.md)
- [ ] **Derived facts can widen their source's audience** (raised
      2026-11-23). A fact's scope is checked against the writer's create
      permission only, never against what it was drawn from: an editor
      reading an unpublished node, or another user's user-scope facts, can
      write a `site` fact from it, and anonymous recall returns it.
      Proposed, as taint tracking on aim's own tools: record during a
      request the narrowest scope `aim_recall` returned (another user's
      user facts, a role's facts); an `aim_remember` in the same request
      into a wider scope saves untrusted, so it goes to review (a model
      check withholds trust, ADR-0065). Person-approved calls keep their
      trust (ADR-0067: the person made the call). Limits: only sees aim's
      tools, so page search of unpublished nodes needs that tool to report
      it too (and search_api's content access processor, "Larger demo"
      below); a person retyping restricted text is not caught, which
      stays a policy line (the writer answers for what they publish, as
      with content). Belongs with the privacy review under "Outside
      advice".

## Larger demo: page search + aim + FlowDrop (raised 2026-10-30)

A future demo where aim sits beside a site's existing content search
rather than replacing it, with FlowDrop managing the agents and tools.
Positioning: many Drupal sites already run an `ai_search` chatbot, and
"add aim beside it as the governed facts layer" is the likelier adoption
path than "replace your assistant" (README line, once built). The demo's
point: the page says one thing, a trusted fact says it changed on a date,
and the assistant answers with the fact.

- [ ] Page search is `ai_search`'s existing `ai_search:rag_search`
      function call on a second search_api index over nodes; config in
      the demo recipe, no code in `aim` (memory is aim's job, page search
      is not). The tool does no access check of its own, so the node index
      needs search_api's content access processor or the chatbot can quote
      unpublished pages.
- [ ] FlowDrop for agent/tool management: see the FlowDrop spike under
      "ADR-0035" below; the two spikes can share a scratch site.
- [ ] Precedence policy, decide before the persona wording (ADR when
      decided). Draft from 2026-10-30: a literal wins for exact values
      (ADR-0061, unchanged); a trusted, unexpired fact beats page content
      when they conflict; untrusted facts are not recalled, so never
      override anything; with no fact, answer from the page with its link;
      a conflict is never resolved silently (the reply says what the page
      says and what changed, and when). A page edited after the fact's
      `asserted` date (node `changed` later than `asserted`): neither wins,
      the reply states the contradiction (decided 2026-10-30, initial
      stance). Not yet (too complex for now): a detected conflict flagging
      the page for an editor, which is
      [ADR-0043](adr/0043-content-truth-drift-audit.md)'s drift audit
      (proposed; it needs a content index, which this demo's node index
      would supply).
- [ ] At least one demo question where the page is silent or stale, so
      page search alone does not make aim look redundant.

## ECA: `eca_tool` and `ai_integration_eca` (raised 2026-11-26, nothing built)

Neither is installed and `eca` is not enabled. `eca_tool` (1.0.0-beta3,
2026-10-21) works both ways: Tool API tools as ECA actions, and an ECA
model's "Tool" event as a Tool API tool. `ai_integration_eca` (1.0.1,
stable) turns `drupal/ai` operations into ECA actions; its Interceptor
submodule fires ECA events around any provider call. No aim code either
way (`aim_eca` stays deleted).

- [ ] **No-code tools for the assistant** (`eca_tool`, ECA model as a
      tool; best demo): a site builder adds "escalate to a librarian",
      "open a case" or "notify the reviewer of this fact" as a tool the
      chat agent can call, no PHP. Access is checked per tool (`execute eca
      tools` or the event's own permission).
- [ ] **Event-driven memory** (`eca_tool`, aim's tools as ECA actions):
      on node publish or webform submit, `aim_remember` an entity-scope
      or case-scope fact (ECA tokens supply the subject); on a support
      request, `aim_recall` related facts into the staff email. ADR-0065
      covers trust: give the acting account create but not trust, so
      these land untrusted. To check: ECA runs as the current user or
      anonymous on cron, and ADR-0065 needs a real writer (ECA's switch
      account action); the grounded check needs the triggering text
      passed in as the source.
- [ ] **Rules around aim's model calls** (Interceptor): fail over from
      the hosted decision endpoint to local Ollama when it is down, and a
      token budget per `aim_*` tag (what ADR-0060's dropped metrics table
      did). Check first that the Interceptor sees aim's decision calls
      (`DecisionBackend`'s path to the provider).
- [ ] Maybe: a reviewer nag (email when untrusted facts pass N days),
      cheap upkeep against the neglect risk, but it is another cron job,
      so weigh it against ADR-0003.

Not useful: the plain AI actions (embeddings, moderation) duplicate aim,
and the modeler agent is not about aim.

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
      cached by ADR-0017); the model call only happens for pairs within
      `compare_distance` (not identical text), and goes to the hosted
      provider, not the laptop. The real laptop cost is memory, not heat:
      Ollama keeps the embed model resident after each call (see the
      laptop memory budget note). Recommendation: `*/5` day to day, queue_ui
      "Run" for a burst, every minute only for the demo (the
      save-then-recall beat needs the fact searchable quickly). Measure
      before treating any of this as settled. This site runs it hourly from
      the host crontab (with `drush cron`). Related: nothing records
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
      claimed): merge-fidelity review of dry-run UPDATE rows on a
      snapshot copy; a disclosed LongMemEval slice (50-100 questions) for
      recall precision, knowledge-update correctness and abstention (parity
      targets 1, 4, 5); recall payload size vs. full-history tokens
      (target 3); duplicate rate; then tune `recall_max_distance`
      (shipped 0.48, this site 0.60) and `compare_distance` against
      them; `aim:benchmark` at thousands of facts (open question #5).
      Count hosted calls while doing it: the verifier adds one call only
      per UPDATE (1 of 23 pairs on the live set, 2026-10-01), but it
      compounds with every compared pair going to the model (since
      2026-10-27), which sends more pairs to the
      model and so yields more UPDATEs. Measure the two together.
- [ ] Extraction-input guardrailing, distinct from the existing output-side
      candidate-fact guardrails.
- [ ] Tests still missing (harness: DEVELOPING.md, "Running the tests"):
      the model-judged paths of `consolidate()` (UPDATE merge, verifier,
      guardrail-blocked merge: needs a choice-answering fake decision
      backend), the ingest form's extract mode and file upload, the Tool
      API/MCP transport, and the user-scope role-visibility settings form.
- [ ] Test suite runtime (raised 2026-11-10; same entry in literals'
      BACKLOG.md for its suite). Removing `#[RunTestsInSeparateProcesses]`
      saves nothing: `KernelTestBase`/`BrowserTestBase` force isolation in
      their constructors, and from drupal:11.3 the attribute is required
      (deprecation now, exception in 12, change record 3548485). Time
      each class first, then merge small methods in the slowest ones (each
      method pays a process plus module install) and try `run-tests.sh
      --concurrency`.
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
- [ ] Upstream leftovers for `ai_vdb_provider_mariadb` after 1.0.2
      (2026-11-23), which fixed every override `AimMariaDBProvider` carried
      (#3609961 with `CREATE TABLE IF NOT EXISTS`, not MR 8's drop;
      #3626257; #3626262; #3605665's `m`/`mhnsw_ef_search`) and made
      `ai_search` optional, so the shim was deleted (ADR-0023's addendum).
      The maintainer agreed to all four asks below; they are built on the
      issue fork branch `3641260-index-filter-columns`
      ([#3641260](https://www.drupal.org/i/3641260)). Once a release ships
      them: delete `AimHooks::ensureColumnIndex()`, `vectorIndexUpdate()` and
      `BTREE_INDEXED_COLUMNS` (the provider then indexes every "attributes"
      field, under the same `idx_<column>` names), update DEVELOPING.md's
      "What ties recall to MariaDB" paragraph, and drop the `scope`/`subject` half of the
      BTREE measuring item below. The asks:
      (a) 1.0.2 changed Search API scores from cosine distance to
      similarity (#3635830, deliberate: `ai_search` reads scores as
      higher-is-better). Its README says positive `min_score` thresholds
      "keep their existing meaning", true only against `ai_search`'s
      intent: a threshold tuned on 1.0.1 inverts silently. Ask for a BC
      note on the release. It also added `$server_id` to `getVdbIds()`,
      fatal for any subclass overriding it (aim's did);
      (b) one `Incorrect column specifier for column 'embedding'` error
      logged on every fresh install. Cause (reproduced 2026-11-24 by saving
      a size-0 server config): `ai_search`'s `NewServerEventSubscriber`
      runs on every `search_api.server.*` config save, raw data, enabled or
      not, and calls `ensureCollectionExists()`, which creates the table at
      the server's `dimensions`. aim ships `aim_vector` at 0 (the model is
      per site; the recipe's `aimUseDefaultModels` sets it), so the import
      asks MariaDB for `VECTOR(0)`. Harmless (the real table follows once
      the model is set), but it reads as a failure to anyone checking the
      log. Ask: `MariaDBProvider::createCollection()` returns early on
      `dimension <= 0`, which covers every path (1.0.2 guards only its own
      index-save hook). No clean aim-side fix: a shipped guess would build
      the table at the wrong size for another model;
      (c) the requirements hook and config page still load only the
      server with ID `mariadb`, so the debug-logging warning never checks
      `aim_vector` - minor;
      (d) a BTREE index on each filter column (`ai_search`'s
      "attributes" fields) when the provider creates it: the HNSW index
      post-filters, so every MariaDB user who filters returns short
      results without one (ADR-0018). aim does it in
      `AimHooks::ensureColumnIndex()` for `user` and `trusted`, the last
      raw MariaDB SQL in aim's hooks.
      (A built-M-vs-configured-`m` warning was dropped from this list
      2026-11-24: since 1.0.2 the provider writes M from `m` at create, so
      it only matters after a later edit of `m`; aim keeps its own
      `AimStatusChecker::checkHnswTuning()`.)
- [ ] Read a fact's stored vector instead of re-embedding it:
      `ai_vdb_provider_mariadb` 1.0.2 implements
      `getRawEmbeddingFieldName()` (#3626352), so with the server's
      `include_raw_embedding_vector` on, a result's
      `getExtraData('raw_vector')` can feed
      `$query->setOption('vector_input', $vector)`
      (`SearchApiAiSearchBackend::getSearchVectorInput()`). That would stop
      `findNeighbors()` re-embedding each fact's own text on every
      consolidation sweep ([ADR-0017](adr/resolved/0017-query-embedding-cache.md)'s
      cache covers repeats only). Unblocked 2026-11-23, not built; measure
      the embed cost first (local Ollama is fast).
- [ ] Recheck the HNSW tuning (M=16, ef_search=100) when the table grows
      about tenfold, the embeddings model or dimensions change, or the
      provider is upgraded. Method in DEVELOPING.md, "Rechecking accuracy".
      Still unmeasured: where the optimizer switches from the BTREE index to
      HNSW for a user, and whether MariaDB's 16 MB `mhnsw_max_cache_size`
      matters at larger sizes.
- [ ] Measure whether `scope`, `subject`, `target_type` and `target_id` need
      BTREE indexes on `aim_fact_vectors` (only `user` and `trusted` have
      one, `AimHooks::BTREE_INDEXED_COLUMNS`; the provider fix above indexes
      `scope` and `subject` too, as "attributes"). Matters when one scope is a
      large share of the table, and for entity-scope lookups by target.
      Same method as ADR-0018. `target_type`/`target_id` are not even
      indexed attributes yet, check that first.
- [ ] Retention/erasure policy for retired facts (prune or archive old
      `retired` rows out of `aim_fact`) - retired facts about a person are
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
      `superseded_by_reason` (meant to hold none), `ai_log`'s logged
      prompts and replies (ADR-0060, which hold source passages), the unbuilt `aim_rejection` quarantine, and
      [ADR-0064](adr/resolved/0064-revisions-for-fact-edits.md)'s revisions (decision 5:
      erasure must clear old revisions too).
- [ ] **Recall audit line (raised 2026-10-14).** `recall()` logs only counts
      and best score, and only at the verbose tier, so "what did the agent
      know when it acted" cannot be answered. Add an audit-tier line per
      recall: uid, scope, returned fact IDs (IDs are not personal data).
      Same data the eval plan's trace keeps, see
      [eval/DRAFT-eval-plan.md](eval/DRAFT-eval-plan.md) section 6. Facts
      are superseded not deleted, so IDs plus the timestamp reconstruct what
      was visible, except for in-place text edits (see edit history above).
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
- [ ] **Filterable category, labelled on ingress** - see
      [ADR-0059](adr/0059-single-valued-category-and-ingress-labelling.md)
      decisions 2-5 (single-valued, decision 1, is built).
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
      a reroll. Not worth hand-patching a live OAuth path - revisit once
      a working reroll exists upstream, or write one properly with time
      to test it.

## aim_tool: schema discoverability and typed output (raised 2026-10-05, from Matt Glaman's "Define the capability once, call it anywhere")

Source: https://mglaman.dev/blog/define-capability-once-call-it-anywhere.
The post's claims about fixes (tool 1.0.0-beta3 `tool:run` failure
masking, `mcp_server_tool_bridge` 1.0.0-beta1 empty-input fix) and the
open Tool API issue #3582943 (`tool:info` flattens inputs to data types:
no enum constraints, no required permissions) are **unverified** against
the released tags and issue queue - check before building on them.
Installed 2026-10-05: tool 1.0.0-beta11, bridge 1.0.0-beta3, mcp_server
2.0.0-beta5.

- [ ] Constrain `scope` to an enum if Tool API supports one on
      `InputDefinition` (check first; #3582943 suggests `tool:info` may
      drop it even when declared). Otherwise the description is the only
      discovery channel.
- [ ] Optional: use `drush tool:run aim_recall` / `aim_remember` as a
      smoke test of the tool layer without an MCP client.

## Waiting on upstream

Filed; aim carries a stopgap until a release ships the fix.

- [ ] **Site Agent marks person-approved calls** (ADR-0067). Filed as
      [site_agent#3638825](https://www.drupal.org/project/site_agent/issues/3638825)
      (2026-11-17); a Tool API issue for the case's home only if the
      maintainer favors it. This site carries it as
      `patches/site_agent-person-approved-invoker.patch` (site root), and
      aim's side is built against it (`AimRemember::PERSON_APPROVED`).
      Drop the patch once a release ships it; follow upstream's name.
- [ ] **Hand a `#[ToolMethod]` its invoker** (ADR-0062 decision 2).
      `aim_remember` picks its source from the Tool API invoker, which a
      method service never receives; `AimRememberInvokerSubscriber`
      catches it from `ToolInputTransformEvent` meanwhile.
      [tool#3583125](https://git.drupalcode.org/project/tool/-/work_items/3583125)
      (filed 2026-10-28) asks for a runner-filled `?Invoker` method
      parameter, split out of
      [tool#3583116](https://git.drupalcode.org/project/tool/-/work_items/3583116)
      (the invoker for *access* callbacks, which CCC needs) at the
      maintainer's request. When it lands: add `?Invoker $invoker = NULL`
      to `aim_remember` and delete the subscriber.

## A second vector backend

- [ ] Recall runs on MariaDB and one tuned embeddings model today
      (DEVELOPING.md "Vector search", "What ties recall to MariaDB").
      A second backend needs: the hard dependency on
      `ai_vdb_provider_mariadb` made optional, its score semantics
      confirmed, recalibration of the distance settings, an ADR and its
      server config.

## Write-path cuts, ADR-0065 (raised 2026-11-01)

What [ADR-0065](adr/resolved/0065-permissions-are-trust.md) removes, and what
would bring each back. Nothing here is built.

- [ ] **Per-channel trust exception.** Trigger: an agent holding a
      trust-granting token is shown writing something it was manipulated
      into (prompt injection). Read the stored channel (planned with the
      audit fields, TODO.md), not `source`, which callers can type; no
      `trust_policy` tree.
- [ ] **Model promotion by the grounded check.** Trigger: none on its own;
      it needs a new decision, since it is a model granting trust a
      permission withheld.
- [ ] **Duplicate drafts in the review queue.** Untrusted facts are not
      consolidated, so a visitor repeating a known fact queues it.
      Trigger: reviewers spend real time on duplicates. Then show a
      "near-duplicate of #id" hint in the queue, or let NOOP retire drafts
      (riskier: a misread correction vanishes unreviewed).
- [ ] **Junk filter.** Trigger: junk facts seen in review. Add a
      guardrail (restrict-to-topic or moderation) to
      `aim_write_guardrails`, not a classifier decision.
- [ ] **Contradiction check on trusted writes** (raised 2026-11-08).
      Trigger: same as the per-channel exception (a manipulated trusted
      writer). A trusted writer's fact that contradicts an existing trusted
      fact goes to review instead of live: changing an established fact is
      where poisoning bites (ADR-0065's 2026-11-08 addendum). Demote-style
      like the grounded check, not a guardrail (those can only reject).
      Consolidation's UPDATE already spots it, but after the fact is live.
- [ ] **Human gate on merges** (raised 2026-11-07). Permissions gate every
      written fact, but a merge of two trusted facts is trusted on the model
      checks alone (`createMergedFact()`), retires both inputs, and no person
      reads the new text; no setting holds it. Trigger: a site needs every
      recalled fact reviewed by a person. Then make a merge a proposal: both
      inputs stay live and trusted, the merged text waits for a reviewer,
      and the inputs retire only when it is accepted. Saving the merge
      untrusted and retiring the inputs at once won't do: recall loses two
      good facts until someone reviews it, which is why a merge that fails
      the checks keeps both (ADR-0065). Needs a pending-merge state and adds
      to the review queue.

## Backlog - explicitly "wait for a trigger" per the docs' own framing

- [ ] **Agent-assisted review in Site Agent (raised 2026-11-18).** A
      reviewer asks Site Agent for the facts waiting for review; the agent
      lists them with its view of each (likely duplicate, low grounding
      score, conflicts with a trusted fact), and each trust or untrust
      goes through an approval card, so the person still decides
      (ADR-0065: model checks only withhold trust, never grant it). Needs
      `aim_tool` tools to list untrusted facts and to trust/untrust them
      (gated by `review aim facts` or the scope's trust permission, with a
      Site Agent classification that always asks), plus a card listing
      the facts as `aim_remember`'s does. Cost is a model turn per batch.
      Trigger: a review queue long enough that a reviewer wants help
      working through it.

- [ ] **Exact values from `aim_recall` when the fact search fails (raised
      2026-11-16).** With embeddings down (seen with Ollama stopped), the
      whole `aim_recall` call fails before the finder is asked, though the
      finder could still return the phone number. About 20 lines and a
      test in `AimRecall`: on a fact-search failure, still ask the finder
      and return any value as a success with "memory could not be
      searched, do not say a fact is not recorded". Not built: it adds a
      second partial state for models to read (values, no facts) and turns
      an outage into a success visible only in the log. The chat block's
      gate already answers clear lookups without embeddings. Trigger: an
      embeddings outage costs a real answer.

- [ ] **Eval: the `login` literal for signed-in viewers (parked
      2026-10-25).** `/user/login` is access-checked, so it is correctly
      empty for a signed-in visitor, and the three `login` questions in
      `gold.agentblind.yml` read as wrong when the set runs as uid 1 or a
      member. Not a chat fault; revisit with the eval set itself.

- [ ] Read-only MCP connections from the consent screen (raised
      2026-10-30, from Nerra's per-connection "save knowledge" toggle). A
      "Let this assistant save facts" checkbox on simple_oauth's consent
      screen; when it is cleared, the token is granted `aim:recall` but not
      `aim:remember`. The per-tool scope gate that would enforce it works
      since the `mcp_server_oauth` pin (see "`aim_tool_oauth` per-tool
      scope gate" above); unchecked whether simple_oauth's consent form
      can grant fewer scopes than the client asked for. Trigger: a real
      user asking for a read-only connection.
- [ ] **Poppy (Personal Agent Protocol), parked 2026-11-12.** Sierra/Meta
      open protocol (announced 2026-10-06, Apache 2.0, draft 0.2 in the
      announcement and 0.4 in the site's example, ~35 design partners, mostly
      commerce/payments/support): a company publishes
      `/.well-known/poppy.json` declaring session start, OAuth sign-in,
      `poppy:read`/`poppy:write` scopes and channels (website, OpenAPI/MCP,
      company agent); the agent signs requests with its own key. Fit: our
      `simple_oauth` + `aim_tool_oauth` + MCP stack already matches the
      model, a `poppy.json` pointing at the existing MCP endpoint and scopes
      would be cheap, and it could give per-site agent discovery that the
      Claude plugin format lacks (fixed `.mcp.json` URL). Against: moving
      draft, overlaps MCP (a second discovery surface to maintain), aim's
      scopes (site/role/user/case/entity) don't map onto read/write, and
      neglect is the main risk. Read from summaries only; the spec itself
      is unread. Trigger: the spec stabilizes, or a real caller asks. First
      step then: a small `poppy.json` generator reusing the OAuth/MCP
      config, nothing deeper. Spec: <https://personalagentprotocol.org>.
- [ ] **Parked by Nik (moved from TODO.md 2026-10-30):** visitor/staff
      assistant split (staff agent with remember; fine for a demo, not
      essential; [ADR-0008](adr/resolved/0008-chatbot-integration-mechanism.md)'s
      addendum), the accept/pass widget with outcome tracking, a combined
      MCP "ask" tool that does finder-first then recall on the server.
- [ ] **Parked 2026-10-15 (Nik), moved from TODO.md 2026-10-30: file ingest from Claude.ai over MCP.** Idea:
      `aim_media_upload` (filename + text/base64 content -> Media entity in
      the private file store, returns the ID) plus `aim_ingest` (media ID ->
      the ADR-0016 pipeline, facts untrusted). Both gated on `ingest aim
      facts` plus an `aim_tool_oauth` scope. Limit: Claude.ai can only hand a
      file to an MCP tool as an argument, so bytes go through model context
      (fine for small text, poor for PDFs/images/large files). WebMCP is a
      possible route, UNVERIFIED (checked 2026-10-16): its spec lists binary
      inputs as an open question, doesn't name Claude.ai as a caller, and is
      Chrome 146+ behind a flag. Revisit when binary input is resolved.
      Small text files could skip the upload and pass text straight to
      `aim_ingest`.
      Raise the PII gate (ADR-0056) before real files go through.
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
      "does aim ever store source material" question (ADR-0037 is probably
      out of date, 2026-10-30). Plan: ingest from a Media item already on
      the site, `source` naming it by ID rather than path. Staleness
      (raised 2026-10-30, after droost_wiki's provenance check):
      - The kept original plus the fact's `created` (ingestion date) is
        enough. "Media changed after the fact was created" only says look
        again; the verdict is re-running the grounded check of each fact
        against the current file (still stated: keep; gone: untrust/retire,
        or re-extract and let consolidation supersede). False alarms
        (re-save, alt text, metadata) cost one check per fact, nothing
        worse; a content hash stored at ingestion would skip them, later.
      - Trigger: a media update hook (fires on change, covers a replaced
        file), with a crontab sweep of `media.changed > fact.created` as
        the safety net (ADR-0003, never `hook_cron`).
      - Entity scope: the same comparison, "fact predates the entity's last
        update". Strong signal when the fact's source is that item, weaker
        when the fact is only about it, so it queues a re-check against the
        entity's current text rather than deciding.
      Likely the mechanism for the scheduled staleness review above.
- [ ] `revision_graph` module - Content Moderation isn't landing
      (ADR-0002 addendum), but `aim_fact` is revisionable on its own since
      [ADR-0064](adr/resolved/0064-revisions-for-fact-edits.md)
      (2026-11-01), so it would now work. No need yet: a fact's history
      is a straight line of text edits, and core's Revisions tab lists it.
      Revisit if revisions ever branch (e.g. drafts).

- [ ] **Core `dr` CLI (raised 2026-11-11).** Drupal core's `dr`
      (`vendor/bin/dr`, 11.4) can't run our Drush commands
      (`AimCommands`, `AimBenchmarkCommands`, `AimChatbotEvalCommands`, all
      in `src/Drush/Commands/`): it only discovers Symfony `#[AsCommand]`
      classes in a module's `src/Command/`, not Drush's `#[CLI\Command]`.
      Core `dr` also lacks `config:*`, `queue:run`, `php:eval` and `pm:*`,
      which the demo kit, runbooks and crontab (ADR-0003) rely on. Trigger:
      `dr` gains those. A port is mechanical (the logic is in injected
      services): Symfony command classes in `src/Command/`, or thin
      wrappers shared with Drush.

## Parked ADRs, revisit triggers (retired 2026-10-11 and 2026-10-12)

- ADR-0012 (fact-to-fact relation graph, multi-hop retrieval): revisit when a
  consumer needs more than the supersede edge in `related`.
- ADR-0014 (use-case archetype starter kits): revisit when a third Recipe
  beyond `aim_demo_library` and `aim_personal` is wanted.
- ADR-0055 (decision rationale as entity-scope facts): revisit when a
  decision-provenance need is real. The test is to hand-model 5 to 10 ADRs
  as `scope=entity` facts and check recall beats grepping the ADR files.

## Case scope access control (raised 2026-09-30, ADR-0029)

- [ ] Design and build case-scope view access (`AimScopeCase::checkViewAccess()`
      is neutral today) plus a case membership model. Needs its own ADR.
      Required before the console's case chips ship; user and entity chips
      do not depend on it.

## Scope architecture follow-ups (from ADR-0025 to 0028, all built)

- [ ] **Entity-scope facts outlive their target** (raised 2026-11-23).
      Nothing acts when a fact's `target_type`/`target_id` entity is
      deleted or unpublished: the facts stay in recall, and
      `validateSubject()` deliberately lets them save (DEVELOPING.md).
      Proposed, on the entity plugin: on delete, retire the target's live
      facts with `superseded_by_reason`
      `{"decision":"target_deleted","target":"<type>:<id>"}`; on
      unpublish, retire them with `target_unpublished` and un-retire
      those carrying that reason on republish, the same pattern as
      ADR-0068's case close and reopen. Entity types without a published
      status get the delete half only. The opposite direction (content
      drifting from facts) is ADR-0043.

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
- [ ] An entity-type select plus autocomplete widget for
      `target_type`/`target_id` on the admin form
      ([ADR-0027](adr/resolved/0027-entity-scope.md)); write-time
      validation of the target is built (`AimScopeEntity::
      validateSubject()`). The scope is meant for notes added through a
      widget on content pages; extraction does not offer it.

**One subject field pair (raised 2026-11-11)**

- [ ] When cases become a real entity: who a fact is about is one idea
      stored four ways (`subject` holds a role or a case ID, `user` an
      account, `target_type`/`target_id` an entity). Collapse them into
      one pair (entity type + ID), rendered by the plugin. Costs `user`'s
      real entity reference (integrity on user deletion, Views
      relationships, the BTREE in the vector index, ADR-0007/0018), so it
      needs its own ADR. Per-scope fact forms and the role plugin, built
      2026-11-11, hide the symptom (DEVELOPING.md, "Fact forms show only
      their scope's subject fields").

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
