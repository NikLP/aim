# TODO

Living backlog, not an ADR - a decision here doesn't become binding until
it gets its own ADR entry in [adr/](adr/0000-index.md). Compiled
2026-09-10 from a Claude Code thread (OpenKB competitor review) plus the
open items already on record in the ADRs and [CLAUDE.md](CLAUDE.md)/
[DEVELOPING.md](DEVELOPING.md).
Expect entries folded in from other threads independently - don't treat
this list as exhaustive.

## Literals (raised 2026-10-08, from the literals session; design in [ADR-0052](../literals/adr/0052-what-literals-is-for.md) addendum, module notes in [literals/CLAUDE.md](../literals/CLAUDE.md))

The literals side is built (store, resolvers, tokens including `:link`,
finder, tool, search). What remains is aim's side. Order is a suggestion.

- [x] **Token replacement on recall.** `aim` replaces `[literal:key]` and
      `[literal:key:link]` in fact text at recall time as the viewing
      account, in code it controls (no text-format filter, no page cache
      involved). Built 2026-10-08: `LiteralReader::replaceTokens()`, a fact naming
      an unreadable literal is withheld whole (`show_redacted_facts` debug
      setting shows `[redacted]` instead). `literals` is now a hard dependency.
- [x] **Shared helper in `literals`** (small): find result to resolved
      items (`resolveItem()`, skip null or empty values, downgrade a match
      that resolves to nothing to `none`). The chat responder and the
      tool's `byQuestion()` repeat that loop today; move both onto it, then
      use it from `aim_recall`.
- [x] **Exact values as a tool, not a recall path.** Reworked 2026-10-09: the
      finder is not called from `aim_recall` (it was finder-first for a day).
      The on-site assistant gets the lookup tool through
      `tool_ai_connector` (`aim_literal` from 2026-10-10, see below); the
      large model is the gate for one-shot lookups today (a finder-first gate is planned, below). `aim` depends on `literals`, not `literals_finder`.
- [x] **Review literal access (decided 2026-10-10: document and accept).**
      The literal's view rule and the resolver's route check can disagree
      (`/user/login` is anonymous-only, so a signed-in user gets `none`).
      Only core's account routes do this here; documented in
      [literals/CLAUDE.md](../literals/CLAUDE.md), no save-time warning.
- [ ] **Convert-a-fact retires the fact.** Promoting an extracted one-shot
      fact to a literal supersedes it via `superseded_by`; the literal's
      gist is then the only description. Short form, never one click: a
      model splits the fact into gist and value, a human picks the type,
      the literal carries a `source_fact` back-reference. Unblocked: the
      `retired`/`expires` split was built 2026-10-09 (`aim_update_10009`).
- [ ] **Answer modes (decided 2026-10-10: a skill, wired up later, not a
      module setting).** Mode 1, literal only: fixed reply, no
      large model, the value never reaches a model. Mode 2, literal plus
      smoothing: the large model writes the reply around the resolved
      value; default to public (anonymous) values only. Escalation: a
      "think harder" control on a mode 1 answer re-runs the question
      through mode 2 or full recall. The public-values-only rule is a trade-off to
      keep and revisit, not a settled principle.
- [ ] **Optional pre-gate (setup-dependent, later).** Only matters in a
      setup where the finder runs on every question; the assistant's
      tool-call design (2026-10-09) does not. A tiny "is this a lookup?" question with no menu
      in its prompt, ahead of the finder, so non-lookup traffic does not pay
      for a menu-sized prompt (about 4,000 tokens at 200 literals). Its
      failures are safe. Keep only if measured to pay (share of traffic
      removed against its own latency). The finder itself is already the
      gate for whether a literal fits.
- [x] **Measure the gate end to end (first run 2026-10-12).**
      `drush aim:chatbot-eval` (aim_chatbot) runs a gold set through the real
      assistant in three modes and splits calls and tokens by hosted versus
      in-house provider (`--inhouse`, default `typesafeai,ollama`, so Jev
      counts as in-house). 39 questions of `gold.agentblind.yml`, 12
      literals, per question: `tool` (the chat model calls `aim_literal`)
      3.4k hosted tokens, 4.4s, 18-22 of 23 correct across runs; `prompt`
      (values in the system prompt) 1.9k hosted tokens, 2.6s, 23/23; `gate`
      (finder first, chat model only on a pass) 0.9k hosted tokens, 1.6s,
      22/23, with the finder answering 23 of 39 questions alone. The finder
      already runs on Jev here. Gate wins on hosted tokens and time. Still to
      done at 101 literals too (prompt 5.6k hosted tokens, tool 3.4k, gate
      0.6k; the chat model under-calls the tool). Restricted
      values checked too (tool and gate hold, prompt cannot serve both
      audiences). Still to do: the finder genuinely local for latency. Numbers in literals/adr/how-it-works.md.
- [ ] **Finder-first gate on the on-site assistant (proven 2026-10-12, not
      built).** The eval's `gate` mode won on hosted tokens, time and
      visibility (numbers in literals/adr/how-it-works.md). The Jev call
      stays: it is the fuzzy match. What is skipped on a hit is the hosted
      chat model. Plan: an `aim_chatbot` `ChatProcessor` plugin extending
      `ai_assistant_api_processor` (the seam `/api/deepchat` already uses,
      chosen per block). It asks `literals.lookup` by question as the
      visitor; a `match` with a non-empty value returns a fixed template
      (label, value, link for URLs) with no chat model; anything else, or a
      finder error or timeout, falls through to the parent processor, tool
      still available. A block config switch keeps the old path. Keep it
      small: last message only (a follow-up like "and the accounts one?" goes
      to the chat model), check a hit and a miss render in the widget
      (streaming), audit hit and pass counts with IDs only, never the
      question. Open: whether gate replies go into the stored history; a
      Jev-written sentence around the value instead of the bare template
      (one more in-house call, zero hosted). Still unmeasured: local Jev
      latency. The `aim_literal` tool stays for MCP and external agents.
- [ ] **Copy controls** ("copy value", "copy link") on chat answers, shown by
      role or permission: front-end work; the chat items already carry value,
      label and kind. Waits: cosmetic.
- [x] **A blind question set (2026-10-12).** Nik could not write one, so
      Claude drafted 32 questions (22 answerable, 10 not) from the literal
      names and gists alone, without opening `eval/gold.seed.yml`; Nik
      reviewed and approved them (`eval/gold.nikblind.yml`). Baseline, with
      the four restricted literals run `as: 1`: 16 of 22 hits (73%), 4
      misses, **2 wrong-confident**, 10 of 10 unanswerable correctly none.
      Both wrong answers sent "what I've borrowed" and "check my own
      details" to `login` instead of `my_account` (overlapping gists:
      "check loans" appears in both). Of the four misses, `cfg_phone` (empty
      gist) and `balls_email` (nonsense gist) are test fixtures nothing
      could match, so the fair denominator is 20 answerable, 16 hits. The
      gists were NOT edited afterwards: that would turn the set into a
      training set. Judge by wrong-confident, not hit rate; a miss falls
      back safely, a confident wrong value is the security-relevant failure.
      Not yet done: a general overlap check (warn when two gists share a
      phrase), designed without looking at these failures and measured on a
      fresh set. Still open: re-check `chooser_context` and the thresholds.
      The set is Claude-drafted, so still not fully independent.
- [ ] **Revisit example phrasings per literal (raised 2026-10-10).** ADR-0040
      "Deferred and pinned" has the history and the `ALSO` convention.
      Intent-matching systems (Dialogflow, Rasa, Azure custom question
      answering) all treat several example phrasings per answer as the main
      lever, which our "wider alias list pulled near-topic questions in"
      trial contradicts on one in-sample run. Re-test after the blind set
      above, against the in-gist `ALSO` convention.
- Parked, with reasons in ADR-0040 "Deferred and pinned": staged choice,
  groups, keyword fallback, per-medium context registry, local-model
  re-measure, `search_api`, tokens in body copy. The `literals_chat` module
  is likely to be scrapped; aim's own chat is a demo that survives for now
  as a non-console way in.

## Plugin, skills and the verbal webform demo (raised 2026-10-09, important)

- [ ] **A Claude Code plugin for aim.** Bundle the skills (`aim-discovery`,
      `aim-memory`, the ones below), the MCP server config with its OAuth
      endpoint, and the tools (`aim_recall`, `aim_remember`, `aim_literal`).
      MCP tools alone do not tell a model *when* to use them; the skills do.
      Build once the grill-to-recipe skill exists, so version one has
      something to show. Needs an ADR.
- [ ] **Skill chain: grill, then recipe.** The grill writes site memory; a
      recipe-building skill reads it and proposes a Recipe. ADR-0035's gate
      applies (dry-run and human review before any apply). Own ADR.
- [ ] **Verbal webform demo.** Chain: a webform builder tool (the `webform`
      module is not installed; search drupal.org for an existing Webform
      Tool API / MCP builder before building one) so a form is designed by
      talking; annotations on each element (purpose, plain-language help, what
      a good answer looks like); a walkthrough skill that asks each question,
      writes the answer back, and uses aim for anything already known. Demo:
      an everyman describes the form they want, then is interviewed through
      it. Blockers: `annotations` has no write path and the `aim_annotations`
      bridge (ADR-0024) is unbuilt; submissions need a narrow,
      permission-gated write tool, not general entity writes.
- [x] **Switch the assistant to `aim_literal` (done 2026-10-10).**
      The `aim_demo_library` recipe adds `tool:aim_literal` to the `aim_chatbot` agent
      (plus the widget's block visibility and `access deepchat api`), and
      the demo README and preflight use it. `literals_tool` is still
      enabled on this site but no longer needed by the demo.
- [x] **Replace `demo/seed.php` with recipe content (done 2026-10-10).**
      The 31 facts and three literals are default content in
      `recipes/aim_demo_library/content/`, the full persona is in the
      recipe's config actions, and `demo/seed.php`, `demo/seed-facts.json`
      and the recipe's `facts.json` are deleted. Guardrails run on import
      (core's importer validates). The Welcome page node went with
      `page_content_type`, `basic_html_format_editor` and `page.front`.
- [ ] **Apply `aim_demo_library` to a fresh install (raised 2026-10-10).**
      The reset on this site (delete facts, re-apply; commands in
      `demo/README.md`) ran 2026-10-10: 31 facts indexed on import, all 13
      pre-flight checks passed. A fresh install is still untested. Also
      decide whether the demo needs a front page again.
- [ ] **Re-check what the personas assume the assistant can see** (a
      signed-in member only gets facts through `view site aim facts`;
      `aim_literal` needs only `view literals` since 2026-10-10), and
      the `aim-discovery` and `aim-memory` skills.
- [x] **A `field` literal resolver (built 2026-10-10, `FieldResolver`, shipped `field` type).** Returns one field's
      value from one entity, `entity_type:id:field_name` (for example
      `site_settings:3:field_phone`), so Site Settings, Config Pages or any
      node field can back a literal without a bridge module per source. The
      existing `entity` resolver returns a link to the entity instead.
      Access is the intersection: the literal's own view rule and the
      field's `$item->access('view', $account)`; document that choice.

## Case picker widget (raised 2026-10-14, pinned)

- [ ] Pinned: a widget to pick from existing in-scope cases when writing a
      `scope=case` fact, instead of typing a case ID or minting a new one
      each time (`AimScopeCase::defaultSubject()` mints when `subject` is
      empty). Needs a "list existing case subjects the viewer may see" query
      first, and case-scope view access is still unbuilt, so the list would
      leak case IDs across viewers until it is.

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

## Decision models (raised 2026-10-02, ADR-0021/0037)

- [x] Move aim's model choices (BUILT 2026-10-02: extraction, consolidation, verifier; add a `decision` entry when classifyPair moves to the Decision API) to the AI suite's `ai_provider_configuration`
      element (one per activity: extraction, consolidation classifier,
      merge verifier, decision), replacing the free-text
      `merge_verifier_model` and the Drush-only `--provider`/`--model`.
- [x] BUILT 2026-10-02 (`src/Backend/`; the metrics table was replaced by `ai_logging` + `AimCallLogSubscriber` 2026-10-14, ADR-0060; Jev's token reporting still untested live): activity metrics subscriber (decided 2026-10-02): generalize
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
- [ ] Length cap on `text` (proposed 500 characters, longest real fact 214),
      a field constraint plus a hint in the extractor prompt, and untrusted
      count on `aim:status` (ADR-0037 gate semantics 2-3, from the former 0033).
- [ ] `drush aim:calibrate` (ADR-0019): takes labeled queries, prints the
      answerable / near-topic / off-topic distance distributions and the gap,
      suggests a `recall_max_distance`. Role and case scope were never measured.
- [ ] Decide the pass bar (ADR-0021 open question 2) before scoring, e.g.
      "decision path within X points of chat accuracy at under Y s per pair".
- [ ] Model eval sets (verified 2026-10-02; none built): (a) classifyPair
      gold set, 15-25 (kept, candidate) pairs per ADD/UPDATE/DELETE/NOOP
      with hard negatives, human-verified labels (the demo recipe has only 31
      facts, so most pairs are hand-written); (b) verifyMerge set
      from mutated good merges, also try split noul questions (keeps A,
      keeps B, adds nothing); (c) add to the `gate` set in `decision-eval-sets.json`
      site-context contradictions. Synthetic data only for hosted Jev.
- [ ] 2026-10-02 eval rework ([ADR-0037](adr/resolved/0037-transient-source-passages-for-grounding.md)):
      `aim_benchmark/scripts/decision-eval.py` + `decision-eval-sets.json`
      built (pairs/merges/gate; hand-written, labels human-verified
      2026-10-02). Results (hosted Jev 23/24 pairs, 25/25 real pairs,
      merges and gate AUC 1.00; local models parked): ADR-0021 and the
      ADR-0021 addendum. Groundedness set BUILT 2026-10-10
      (`decision-eval-grounding.json`, 44 items, `grounding` task; results
      in ADR-0037's addendum; labels verified by Nik 2026-10-10). Still to build:
      harder UPDATE/NOOP pairs.
- [x] Grounded check BUILT 2026-10-11 as a write-time check, nothing
      stored (ADR-0037 "Built"); shadow mode on this site.
- [ ] Grounded check follow-ups: (1) review shadow scores on real use,
      then decide enforce and `default_trusted: false`; (2) the strict
      question marks faithful clean-ups down (preflight canary 0.52-0.62):
      narrower wordings tested 2026-10-11 and not adopted (ADR-0037
      addendum; eight new eval items, reviewed). Fixed at the
      preflight instead: its chat canary has no junk token now and scores
      0.84, so enforce no longer breaks the preflight; (3) per-model question text (same item as
      the decision backend's, below); (4) `ingest_max_chars` and a
      passage-size limit for the Jev call (untested above about 1,000
      words); (5) MCP `source_text` is whatever the calling agent quotes,
      so it is only as verbatim as that agent.
- [ ] Extend `decision-eval.php`: the `gate`, `drift` and `merges-split`
      tasks the removed `decision-eval.py` had (sets still in `scripts/`),
      a confusion matrix by confidence bucket and
      a cost-weighted threshold sweep (a wrong UPDATE loses data, a missed
      merge is cheap).
- [ ] PHP replay command in `aim_benchmark` running the same pairs through
      `ChatBackend` (baseline: one-hot answers, so no confidence buckets)
      and `DecisionBackend`, grouped by `setRunId()`.
- [ ] Hard merge set `decision-eval-merges-hard.json` (20 subtle faults, 2026-10-02): `jev-latest` AUC 1.00 but at the live 0.5 cutoff one bad merge (max 0.68) passes; perfect split at 0.81-0.86. `verifyMerge` uses `isLikely()` default 0.5: BUILT 2026-10-02: `activities.verifier.threshold` (settings form + schema; empty/0 = 0.5, live site 0.8). Still open: per-activity `concurrency` (default 1; the AI module calls are synchronous, so pooling needs HTTP-level work or parallel queue workers) Extra cross-check questions TESTED and DROPPED 2026-10-02 (3 noul: same/new/conflict next to the choice, 55 pairs): neither of the 2 misses is fixed (the noul answers agree with the wrong choice) and "same" does not separate NOOP (median 0.49). Concurrency judged nice-to-have: consolidation runs per fact via the queue (about 3 decisions, ~1 s), so only a bulk backfill is slow. `merges-split` mode added (3 small noul questions, min score).
- [ ] Per-model thresholds and editable question text for the decision
      backend (ADR-0021 decision 5); question text is hardcoded in
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

- [x] **Re-measured consolidation after the 2026-10-09 changes** (2026-10-11,
      `jev-latest` through `decision-eval.php`). Verifier (live wording,
      supersession rule, cutoff 0.8): merges AUC 1.00, 0 of 11 bad passed,
      2 of 8 good held (the borough+Eastvale supersede 0.71, computers 60
      to 90 minutes 0.74: the demo case sits just under 0.8); hard merges
      AUC 1.00, 0 of 12 bad passed, 0 of 8 good held, bad max 0.70. Pairs
      (question unchanged): 23/24, 0 unsafe; real pairs 24/25 and 25/25
      over two runs, the one unsafe UPDATE on the Hyperslop demo-request
      pair. No regression. The `auto_threshold` 0.09 to 0.04 change noted
      here earlier never landed (code, install, sync and live all 0.09).
      The prompt line and ADD-as-UPDATE only run on the chat backend, which
      this site does not use for consolidation: unmeasured.
- [ ] **Atomic extraction.** Check the extraction prompt asks for one claim per
      fact: a compound fact ("60 minutes, and printing costs 10p") makes a
      later update a partial overwrite the verifier has to untangle.
- [ ] **Jev rerank of recall results** (ADR-0036's re-rank, pinned): widen the
      distance cutoff and have Jev filter the top N, aimed at paraphrase
      misses ("wheelchair accessible" vs "step-free"). About 0.4 s per hosted
      call; measure on the known misses first. Sends fact text to the model,
      unlike the literals finder (key and gist only).
- [ ] **Check the facts View on data with retired facts** (the
      `aim_update_10009`/`10010` hooks were removed in 05979b3 under the
      no-update-hooks rule; they had only run against empty data).

## Design review findings (raised 2026-10-02)

Workflow diagram: [adr/workflow-diagram.md](adr/workflow-diagram.md).
Decisions marked "agreed" were settled with Nik in that review; the rest
are recommendations.

**Identity and access**

- [ ] Agreed: refuse new authored writes with uid 0 (Drush callers pass
      `--uid`). Consolidation retirements and merged facts are exempt
      (a merged fact copies `uid` and `user` from the kept fact). The
      current database is disposable, so no `uid` backfill is needed.
- [x] BUILT 2026-10-10: `search_api_bypass_access` is keyed on an explicit
      `$accessCheck` argument on `executeSearchQuery()`/`recall()`
      (default TRUE, core's `accessCheck()` idiom), FALSE passed only by `aim:recall`, `aim:benchmark` and
      consolidation's neighbor search. Web and MCP always get access
      checks; anonymous now gets nothing instead of everything (verified
      live). ADR-0006 addendum.
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

- [x] BUILT 2026-10-09 (`aim_update_10009`; the sweep that retires past `expires` is still unbuilt, ADR-0032): add a `retired` timestamp (set by consolidation and the
      Retire action) and keep `expires` as a real sell-by date (nullable,
      unused today). `ExcludeRetired` and the PHP safety nets key off
      `retired`; a sweep sets `retired` once `expires` passes. Migrate
      existing `expires` values to `retired` (base-field change: the
      `subject_uid` rename needed three update hooks and silently broke a
      View, check Views). Needed by ADR-0035's `release` and ADR-0032's
      auto-expiry.
- [x] BUILT 2026-10-09 (aim_update_10010): rename the consolidation verb DELETE to RETIRE. It is a
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

## Governance

- [ ] Close [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
      deferred governance gate before ship - the `trusted` field/
      `default_trusted` half is BUILT 2026-09-28 (see "PoC deviations"
      below); per-scope `trust {scope} aim facts` permission BUILT
      2026-09-30 (custom `trust` access operation, used by the Trust/
      Untrust bulk actions). Still open: the `trusted` override on
      `remember()` bypasses it - decide whether callers passing
      `trusted: TRUE` should need the permission.
- [ ] **Loop back to the trust permission (raised 2026-10-09).** Nobody
      holds `trust {scope} aim facts` and the ADR-0037 grounded check (Jev,
      not built yet) is to set `trusted` automatically, so it guards a workflow that is not
      running. Decide whether it is for a human clearing what the verifier
      rejects (then likely one `review` permission, not one per scope) or
      goes, with the trust actions falling back to `administer aim`.
      Left as is for now.
- [ ] **Record who trusted a fact (raised 2026-10-11).** Add `trusted_by`
      (user reference) and `trusted_at` (timestamp) base fields, set by the
      Trust/Untrust actions, the edit form and the grounded check (its
      service account). `uid` cannot stand in: it is the author, often a
      service account, and the reviewer is meant to be someone else
      (ADR-0002 addendum skipped this on purpose). Answers "who approved
      this?", the first audit question an enterprise buyer asks.
- [ ] **Edit history for facts (raised 2026-10-11).** A text edit on
      `/admin/content/aim-facts/{aim_fact}/edit` replaces the text in
      place, so manual changes leave no trail, unlike consolidation's
      non-destructive supersede. Proposed: make `aim_fact` revisionable
      on its own, without Content Moderation (the ADR-0002 addendum
      rejected CM and counted revisionability as one of its costs; on
      its own it is much cheaper). The fact ID stays stable; each edit
      gets a revision with author, time and log message, and core's
      revision UI comes with it. Search API indexes the default revision
      only, so recall and the vector table are unchanged. Costs:
      `aim_fact_revision` tables and an update hook converting the
      existing entity type (`updateFieldableEntityType()`; check Views,
      the `subject_uid` rename broke one silently). Consolidation keeps
      its supersede edge: a merge is two facts becoming one, not an
      edit. Rejected: saving an edit as a new fact that supersedes the
      old one, because the ID would change on every edit.

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
- [ ] Tests still missing (harness: DEVELOPING.md, "Running the tests"):
      the Retire/Unretire actions, `aim_literal`'s access rules,
      `consolidate()`, and the ingest form.
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
      (`AimRemember::saveOne()`, `AimMemoryManager::remember()`). Mitigated
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
      error message when both apply): re-run `node demo/preflight.js`
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
- [x] `aim_recall`'s `subject_uid` input renamed `user` 2026-10-05, to
      match the field renamed 2026-09-28. No alias kept; a caller still
      passing `subject_uid` has it ignored.

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

- [ ] ADR-0060 follow-ups: stealth mode (presave hook on `ai_log` blanking
      `prompt`, `output_text`, `response_text`, `configuration`,
      `extra_data`; off by default; optional PII reduction) before real
      data; fact ID as input debug data at each call site so a log row joins
      to its fact; port the benchmark/eval queries to `ai_log` with
      `JSON_VALUE`; upstream asks once proven (duration column on `ai_log`,
      per-tag retention); test `AimCallLogSubscriber` (none yet, only a live
      check on 2026-10-14).
