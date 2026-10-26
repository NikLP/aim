# TODO

Living backlog, not an ADR - a decision here doesn't become binding until
it gets its own ADR entry in [adr/](adr/0000-index.md). This file is the
active list: work being done or next. Design reasoning belongs in the ADRs
(link to them, don't copy them here); finished items are deleted (git
history keeps them); things waiting on a trigger, upstream issues, parked
ideas and open design questions live in [BACKLOG.md](BACKLOG.md).

## Tools (raised 2026-10-25)

- [ ] **Upstream: hand a `#[ToolMethod]` its invoker** (ADR-0062 decision
      2). `aim_remember` picks its trust channel and source from the Tool
      API invoker, which a method service never receives;
      `AimRememberInvokerSubscriber` catches it from `ToolInputTransformEvent`
      meanwhile. Upstream: [tool#3583116](https://git.drupalcode.org/project/tool/-/work_items/3583116)
      (open, 2026-10-21) passes the invoker to method-tool *access*
      callbacks; Nik commented 2026-10-25 asking for a runner-filled
      `?Invoker` method parameter too. Delete the subscriber when it lands.

## Chat quality and evals: open after the 2026-10-15/16 session

State: recall not forced (`require_usage` off since 2026-10-22, see
`modules/aim_chatbot/CLAUDE.md`), persona says "recall first", the demo
recipe installs `literals_finder` and puts its block on the gate, chat writes untrusted and permission-checked,
turns about 4.7 s with 2 hosted calls (details in the Literals entry below
and `modules/aim_chatbot/CLAUDE.md`). Open, in the order I would take them:

- [ ] **Shared ai_logging settings (decided, deferred 2026-10-19).** The
      settings are site-wide and aim no longer writes them (tag list:
      `ActivityBackendInterface::LOGGED_TAGS`; see DEVELOPING.md "Model-call
      logging"). When a second site needs it: an opt-in aim setting that only
      appends or removes aim's tags and never touches retention; `aim:status`
      flags an uncaptured tag, disabled logging or stealth mode and warns
      under 90 days; the settings form says the settings are shared;
      per-tag retention goes upstream (ADR-0060). New installs: opt-in.
- [ ] **Short category queries ("any upcoming events?") return only the book
      sale.** The Code Club and Baby Rhyme Time facts sit just past the
      0.52 cutoff for that word. Fix in the system, never by rewording
      facts or gists (feedback rule, Nik 2026-10-16): keyword matching
      alongside the vectors (below), a broader second recall when the first
      is thin, or a relevance step over a wider candidate set. Then the
      accept/pass widget as the safety net (parked, Literals entry).
- [ ] **Recall in code: keep, default or delete.**
      `AimChatbotRecallContextSubscriber` is off by default (results in
      `modules/aim_chatbot/CLAUDE.md`). Keep as an option, make it the
      default after more runs and scenarios (short category queries, unknown
      topics), or delete it (the subscriber, its test, the schema entry and
      the eval's `context` mode).
- [ ] **Move the chat eval commands out of `aim_chatbot`.**
      `aim:chatbot-eval` and `aim:chatbot-converse` are dev tooling; move
      them to `aim_benchmark` (which exists so production sites don't ship
      dev-only tools), offered only when the chat modules are present. Facts could
      carry `[literal:key]` tokens (resolved by `recall()` for the viewer)
      where a fact names a value, so the widget needs no lookup call - a
      data-model choice for the recipe, not tuning to pass the eval.
- [ ] **`aim:chatbot-eval` hides provider failures (found 2026-10-22).** When
      the provider cannot be reached the agent swallows the error
      (`getLastError()`), so the eval reports 0 calls and the question
      echoed back, not an error. Check `getLastError()` or zero calls and
      report it; `aim:chatbot-converse` has the same gap.
- [ ] **`aim:chatbot-eval` can't run anonymous questions.** With the demo
      assistant closed to anonymous, every gold question without `as:`
      errors "User does not have the required role to run the assistant"
      (40 of 43 on `gold.agentblind.yml`, 2026-10-25). Running the set as
      uid 1 works but skews it (restricted values count as leaks). Run
      anonymous questions as a plain authenticated test account instead:
      a temporary copy of the set with `as: <member>` on every item without
      one works (2026-10-25: 20/23, 5 leaks, same as uid 1; the 3 misses
      are the `login` questions, parked in BACKLOG.md).
- [ ] **Upstream: assistant roles check (ai_assistant_api).**
      `AiAssistantApiRunner::userHasAccess()` tests
      `isset($chosen_roles['anonymous'])` on a list, so ticking only
      "anonymous" lets nobody but uid 1 in. Search the queue, then file.
- [ ] **Processor test.** `AimGateProcessor` has no PHPUnit test; the finder
      overrides and the "unavailable" outcome do. Also no test for the
      chat remember permission check or the untrusted default.
- [ ] **Review process.** Use `ai_logging`'s threads screen
      (`/admin/config/ai/logging/threads`) for reading chats; there is no
      verdict field, so labeling needs ai_eval or a small field. ai_eval
      itself (drupal.org/project/ai_eval, in composer.json, not enabled) is
      untried: trial one agent-mode target on the labeled set before
      deciding whether `AimChatbotEvalCommands` can go. The eval plan and
      its decisions are in `eval/DRAFT-eval-plan.md`; the assertion list
      there is mostly not yet kernel tests.
- [ ] **Parked by Nik:** visitor/staff assistant split (staff agent with
      remember; fine for a demo, not essential), the accept/pass widget with
      outcome tracking, a combined MCP "ask" tool that does finder-first
      then recall on the server.
- [ ] **Do not commit `config/sync/key.key.amazeeio_ai*.yml` (found
      2026-10-16).** The live keys now use the `config` provider (the key
      value sits in the config itself, after the amazee re-authorisation),
      and a `config:export` wrote the plaintext values into the tracked
      sync directory. Either put the keys back on the `file`/env provider
      and re-export, or keep these three files out of git (config_ignore or
      `.gitignore`) before the next commit. Check with
      `git diff config/sync/key.key.*` (values not shown here).
- [ ] **Housekeeping.** `aim_chatbot`'s shipped block still uses the plain
      processor (the demo recipe switches it to the gate). Recipe `literals_demo_library` does
      not grant `view restricted literals` to authenticated. The `login` literal
      resolves to nothing for signed-in visitors (the widget is signed-in
      only), so "where do I log in" correctly finds nothing; the assistant
      does not yet say "you are already signed in".

## Literals, aim's side (design in [ADR-0061](adr/0061-how-aim-uses-literals.md))

Token replacement at recall, the `aim_literal` tool and the finder-first
gate are built. Work on the literals modules themselves is in
[literals/BACKLOG.md](../../contrib/literals/BACKLOG.md).

- [ ] **Unbuilt design from ADR-0061 section 3 ("How aim could use
      literals further").** Convert-a-fact (retires the fact, never one
      click); the two answer modes and "think harder" escalation (a skill,
      wired up later); the finder as a live recall source; copy controls
      (front-end only). Pull one out when a site needs it. Optional
      pre-gate ("is this a lookup?" ahead of the finder) only if measured
      to pay.
- [ ] **Finder-first gate: open points.** Whether gate replies go into the
      stored history; a friendlier reply than the bare template (our own
      wording: Jev is a decision model and cannot write text); local Jev latency unmeasured; a gate-hit line in the
      review queue (design in `modules/aim_chatbot/CLAUDE.md`).

## Plugin, skills and the verbal webform (raised 2026-10-09, important)

- [ ] **A Claude Code plugin for aim.** Bundle the skills (`aim-discovery`,
      `aim-memory`, the ones below), the MCP server config with its OAuth
      endpoint, and the tools (`aim_recall`, `aim_remember`, `aim_literal`).
      MCP tools alone do not tell a model *when* to use them; the skills do.
      Build once the grill-to-recipe skill exists, so version one has
      something to show. Needs an ADR.
- [ ] **Move `skills/` to `.agents/skills/` (raised 2026-10-25).** The
      convention `ai`, `tool` and `mcp_server` already use (Marcus
      Johansson's talk: drupal.org's knowledge base detects
      `.agents/skills/<name>/SKILL.md` and lists them per module). Repoint
      the site's `.claude/skills/` symlinks and the doc links to `skills/`.
- [ ] **A `create-aim-scope-type` skill (raised 2026-10-25).** A
      maintainer "extend it" skill, like `ai`'s `create-guardrail-plugin`:
      scaffold an `AimScopeTypeInterface` plugin plus the submodule that
      ships its `aim_scope`, with the traps (`setProvider()` on each base
      field, the `plugin` property and its `PluginExists` constraint,
      `settings` typed `ignore`). Model it on `aim_scope_entity`.
- [ ] **Skill chain: grill, then recipe.** The grill writes site memory; a
      recipe-building skill reads it and proposes a Recipe. ADR-0035's gate
      applies (dry-run and human review before any apply). Own ADR.
- [ ] **Verbal webform.** Chain: a webform builder tool (the `webform`
      module is not installed; search drupal.org for an existing Webform
      Tool API / MCP builder before building one) so a form is designed by
      talking; annotations on each element (purpose, plain-language help, what
      a good answer looks like); a walkthrough skill that asks each question,
      writes the answer back, and uses aim for anything already known. In
      use: an everyman describes the form they want, then is interviewed through
      it. Blockers: `annotations` has no write path and the `aim_annotations`
      bridge (ADR-0024) is unbuilt; submissions need a narrow,
      permission-gated write tool, not general entity writes.
- [ ] **Fresh install docs.** The recipe README's "Fresh install" should
      say to apply `aim_ollama_local` (or set the Ollama host and
      embeddings default) before this recipe, since facts are embedded on
      import, and add a stop after it for manual model setup (chat
      provider, decision models and thresholds on the Models tab,
      `grounding_mode`), then `node demo/preflight.js`.
- [ ] **Re-check what the personas assume the assistant can see** (a
      signed-in member only gets facts through `view site aim facts`;
      `aim_literal` needs only `view literals` since 2026-10-10), and
      the `aim-discovery` and `aim-memory` skills.

## Recipes (raised 2026-09-27)

- [ ] **Showcase (no date).** aim doing a handful of different things
      (chat recall and save, scopes, the review gate, MCP from a remote
      client), backed by literals, plus what literals does on its own
      (finder, token replacement). The recipe README's "Running the demo"
      questions and whether the site needs a front page fold into this.
- [ ] **`aim:consolidate --dry-run` prints every compared pair** (2026-10-22
      test report: 32 rows, 31 unrelated ADDs at 0.33-0.44, the one useful
      NOOP lost on stage). Add `--changes-only`, or make it the dry-run
      default with a flag for all rows.
- [ ] **MCP checks for `mcp_server_tool_bridge` beta5.** When
      `aim_tool_oauth` is next enabled: check the three tools list without a cache rebuild and
      that `pmu aim_tool` leaves no `mcp_tool_config.aim_*` behind; refresh
      the "observed with beta3" notes in aim_tool's README/DEVELOPING.md.
- [ ] **Note: recall is tied to MariaDB and `nomic-embed-text` today**
      (checked 2026-10-23). `aim.info.yml` hard-depends on
      `ai_vdb_provider_mariadb` (so the recipe needn't list it), and
      `search_api.server.aim_vector` hardcodes
      `ollama__nomic-embed-text:latest` at 768 dimensions.
      `recall_max_distance` (0.52) and `recall_gap` are tuned to that
      model's distances on MariaDB's `VEC_DISTANCE_COSINE`, so a different
      embeddings model or backend needs them re-tuned. Other MariaDB-only
      code: `AimMariaDBProvider`, the raw SQL in `AimStatusChecker`,
      `aim_uninstall()`'s table drop. Recall and consolidation themselves go
      through Search API, so they're portable.

## Ingestion

- [ ] Chunking for `aim:extract` and the ingest form: today the whole file
      goes into one extraction prompt (`AimMemoryManager::extractFacts()`),
      so a file larger than the chat model's context fails. Split on
      paragraph/heading boundaries with a small overlap, extract per chunk,
      then let consolidation dedupe across chunks. A small local extractor
      has a much smaller window, so chunk size should be per model
      (see `adr/model-call-budget.md`).
- [ ] **PARKED 2026-10-15 (Nik): file ingest from Claude.ai over MCP.** Idea:
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

## Decision models (ADR-0021 open questions and addenda, ADR-0037)

Reasoning, results and open questions are in
[ADR-0021](adr/0021-jev-typed-decision-provider.md) and
[ADR-0037](adr/resolved/0037-transient-source-passages-for-grounding.md);
only work to do is listed here.

- [ ] **ADR-0021 open questions.** Pass bar before the next re-scoring;
      retention/training policy of the hosted decision host (blocks real
      data); attach a guardrail set to a Decision call and verify the
      built-in length/regex plugins run for it (then a separate set, not
      `aim_write_guardrails`); raise `drupal/ai` to `^1.6` once tagged
      (enabling `ai_provider_typesafeai` needs `config:export` parity).
- [ ] **Per-model thresholds and editable question text** for the decision
      backend (ADR-0021 decision 5; text hardcoded in `AimMemoryManager`).
      Limits: `tev1` input ~2,050 tokens, Ollama max 26 options. Optional:
      per-activity `concurrency` (nice-to-have; only a bulk backfill is slow).
- [ ] **Trust policy follow-ups** (ADR-0037 addenda): settings form for
      `trust_policy`; reason column on the facts list; end-of-session
      summary for the chat path (ADR-0029); shipped channel rules for real
      data; the `tool:`/`mcp` channel still follows `default_trusted: true`
      here. Also `review own aim facts` (owner check in the `trust`
      operation of `AimFactAccessControlHandler`; build with the form), and
      later an optional `aim_trust` submodule (`TrustPolicyResolverInterface`
      out of `AimTrustPolicy`) when a second rule set or audit fields appear.
- [ ] **Settings form: say what is optional (raised 2026-10-22).** The
      Models tab's grounding fieldset should say the check is optional (off
      by default; trust then comes from `trust_policy` alone) and needs a
      provider with the `decision` operation (no chat path, ADR-0037).
      Consolidation and the verifier keep their chat option: it is the
      baseline any chat provider can run; a decision model is the upgrade.
- [ ] **`subq-snap-1.1`: find why the eval was refused, then re-measure**
      the verifier and grounding thresholds on it (ADR-0021's 2026-10-22
      addendum). The finder's calls on it work; `decision-eval.php`'s
      grounding run got "not permitted for this API key".
- [ ] **Sovereignty wording is stale (Nik, 2026-10-22).** Local chat model
      support is no longer forced, but ADR-0004 and CLAUDE.md decision 5
      still say extraction/consolidation "must support Ollama". Add an
      ADR-0004 addendum with the current position and fix decision 5.
- [ ] **Grounded check: enforce.** Review shadow scores on real use, then
      set `grounding_mode: enforce`. Also: per-model question text;
      `ingest_max_chars` and a passage limit for the decision call (untested
      above ~1,000 words); MCP `source_text` is only as verbatim as the
      calling agent.
- [ ] **Consolidation queue has no scheduler of its own (found 2026-10-19).**
      The worker has no `cron` key (ADR-0003), no crontab/DDEV/systemd entry
      was found, yet 28 queued items drained between two checks (cause
      unknown). Add the `queue:run aim_consolidate` line or build ADR-0031's
      cron fallback (Queue processing, below); `aim:status` flags a stall.
- [ ] **Eval work** (none built beyond the sets in `aim_benchmark/scripts/`):
      classifyPair gold set (15-25 pairs per verb, hard negatives, human
      labels); harder UPDATE/NOOP pairs; verifyMerge set from mutated good
      merges; `decision-eval.php` gaining the `gate`, `drift` and
      `merges-split` tasks, a confusion matrix by confidence bucket and a
      cost-weighted threshold sweep (a wrong UPDATE loses data, a missed
      merge is cheap); a PHP replay command in `aim_benchmark` through
      `ChatBackend` and `DecisionBackend`, grouped by `setRunId()`.
- [ ] **`drush aim:calibrate`** (ADR-0019): labeled queries in, answerable /
      near-topic / off-topic distance distributions and gap out, suggesting
      a `recall_max_distance`. Role and case scope never measured.
- [ ] **Length cap on `text`** (proposed 500 characters, longest real fact
      214): a field constraint plus an extractor prompt hint.
- [ ] **Atomic extraction.** Check the extraction prompt asks for one claim
      per fact; a compound fact makes a later update a partial overwrite.
- [ ] **Jev rerank of recall results** (ADR-0036, pinned): widen the cutoff,
      have the model filter the top N, for paraphrase misses. Measure on
      the known misses first; it sends fact text to the model.
- [ ] **subq as decision host: open points** (history in ADR-0021's
      2026-10-19 addendum). Thresholds (0.8 verifier, 0.7 grounding) were
      tuned on Jev; harder pairs unmeasured; subq retention and training
      policy unread (synthetic data only); the `/models` shape fix in
      `TypeSafeAiClient::listModels()` is a local patch that `composer
      update` overwrites (upstream handoff); `tokens_output` reads 0 on
      subq decision calls (expected, ignore in cost reads). Switching back
      is `host` plus three `activities.*.model` values, then `config:export`.
- [ ] **Check the facts View on data with retired facts** (the update hooks
      that touched it were removed; they had only run against empty data).
- [ ] Verify Jev reports token usage live and the Anthropic key in watchdog
      is rotated and cleared; run
      `ai_provider_typesafeai/tests/check-decision-api.php` (offline class
      check, never run).

## Governance

- [ ] Close [ADR-0002](adr/0002-governance-deferred-guardrails-mandatory.md)'s
      deferred governance gate before ship - the `trusted` field/
      `default_trusted` half is BUILT 2026-09-28 (see "PoC deviations"
      below); per-scope `trust {scope} aim facts` permission BUILT
      2026-09-30 (custom `trust` access operation, used by the Trust/
      Untrust bulk actions). The `trusted: TRUE` override on `remember()`
      now needs `administer aim` or the scope's trust permission (BUILT
      2026-10-19).
- [ ] **Record who trusted a fact (raised 2026-10-11; deferred 2026-10-19 to
      before real data, with ADR-0056; add a `trust_basis` field too:
      reviewer, policy, grounded or override).** Add `trusted_by`
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

## Queue processing (ADR-0031)

- [ ] Build the opt-in cron fallback for `aim_consolidate`
      ([ADR-0031](adr/0031-cron-fallback-for-queue-processing.md)):
      `consolidate_on_cron`/`cron_time` settings, `hook_queue_info_alter`,
      `aim:status` warning, then amend CLAUDE.md decision 4, README.md and
      DEVELOPING.md's "never `hook_cron`" wording.
