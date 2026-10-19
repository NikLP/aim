# TODO

Living backlog, not an ADR - a decision here doesn't become binding until
it gets its own ADR entry in [adr/](adr/0000-index.md). This file is the
active list: work being done or next. Design reasoning belongs in the ADRs
(link to them, don't copy them here); finished items are deleted (git
history keeps them); things waiting on a trigger, upstream issues, parked
ideas and open design questions live in [BACKLOG.md](BACKLOG.md).

## Chat quality and evals: open after the 2026-10-15/16 session

State: finder-first gate live, recall forced (`require_usage`, `max_loops` 7),
persona says "recall first", chat writes untrusted and permission-checked,
turns about 4.7 s with 2 hosted calls (details in the Literals entry below
and `modules/aim_chatbot/CLAUDE.md`). Open, in the order I would take them:

- [ ] **Shared ai_logging settings (decided in principle, not built).** The
      `ai_logging` settings are site-wide. The update hooks that wrote them
      were removed (2026-10), so a fresh install records nothing; tag list is
      `ActivityBackendInterface::LOGGED_TAGS`. Plan: an opt-in setting that
      only appends aim's tags and never lower or overwrite
      retention; an explicit opt-in aim setting for recording model-call
      text; `aim:status` shows the shared settings and flags an uncaptured
      aim tag; document that retention has no per-tag control (upstream
      candidate, ADR-0060). New installs: opt-in. This site keeps it on.
- [ ] **Short category queries ("any upcoming events?") return only the book
      sale.** The Code Club and Baby Rhyme Time facts sit just past the
      0.52 cutoff for that word. Fix in the system, never by rewording
      facts or gists (feedback rule, Nik 2026-10-16): keyword matching
      alongside the vectors (below), a broader second recall when the first
      is thin, or a relevance step over a wider candidate set. Then the
      accept/pass widget as the safety net (parked, Literals entry).
- [ ] **Latency and cost of the fall-through path.** 2 hosted calls per turn
      (recall, answer). Options if it must drop: run recall in code and hand
      the model the facts for that turn only (not the stored history), or
      drop the assistant's own lookup tool now the gate runs the finder.
      Test against the 74-item gold set before dropping anything.
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
      processor (only this site's block uses the gate). Test facts 141, 148,
      149, 150 are retired, not deleted. Recipe `literals_base` no longer
      grants `view restricted literals` to authenticated. The `login` literal
      resolves to nothing for signed-in visitors (the widget is signed-in
      only), so "where do I log in" correctly finds nothing; the assistant
      does not yet say "you are already signed in".

## Literals (raised 2026-10-08, from the literals session; design in [ADR-0052](../literals/adr/0052-what-literals-is-for.md) addendum, module notes in [literals/CLAUDE.md](../literals/CLAUDE.md))

The literals side is built (store, resolvers, tokens including `:link`,
finder, tool, search). What remains is aim's side. Order is a suggestion.

- [ ] **Reassess showing the literal key (2026-10-16).** The key (machine
      name) is hidden on the literal edit form (shown on add only, core's
      machine_name element) because the form has little room, so on an
      existing literal it is visible only in the URL. Decide whether a
      collection list column, or the `[literal:key]` token read-only in the
      sidebar, is worth the space.

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
- [ ] **Tool routing: the assistant stops after one tool (found 2026-10-15).**
      With recall at 0.52, repeats of the same question: "I forgot my
      password" found the reset link 1 in 5 times (the other 4 it called only
      `aim_recall`), "what is the wifi password" 0 in 5 (it called only
      `aim_literal`, "none", and stopped; the answer is a fact, not a
      literal). The persona says not to retry the lookup tool, so a "none"
      ends the turn without checking memory. Options: a persona line telling
      it to check memory once when the lookup finds nothing (cheap, measure
      with a repeat run); or the finder-first gate below, which makes the
      routing code rather than the model's choice. Repeat harness: ask each
      question 5 times and count.
- [ ] **Finder-first gate: open points.** Built (`AimGateProcessor`, live on
      this site's chat block; design in `modules/aim_chatbot/CLAUDE.md`). Left:
      whether gate replies go into the stored history; a Jev-written sentence
      around the value instead of the bare template; local Jev latency
      unmeasured; a gate-hit line in the review queue; the accept/pass widget
      (parked, above).
- [ ] **Gist overlap check.** "where do I log in" and "what I've borrowed"
      fell through or went wrong-confident because the `login`, `password_reset`
      and `my_account` gists overlap (blind set, 2026-10-12: 16 of 22 hits, 2
      wrong-confident). Do NOT reword the gists to pass the set (feedback
      rule). Build a general check (warn when two gists share a phrase),
      designed without looking at those failures and measured on a fresh set;
      re-check `chooser_context` and the thresholds. The set is
      Claude-drafted, so not fully independent. Also still to do from the
      gate eval: the finder genuinely local, for latency.
- [ ] **Copy controls** ("copy value", "copy link") on chat answers, shown by
      role or permission: front-end work; the chat items already carry value,
      label and kind. Waits: cosmetic.
- [ ] **Revisit example phrasings per literal (raised 2026-10-10).** ADR-0040
      "Deferred and pinned" has the history and the `ALSO` convention.
      Intent-matching systems (Dialogflow, Rasa, Azure custom question
      answering) all treat several example phrasings per answer as the main
      lever, which our "wider alias list pulled near-topic questions in"
      trial contradicts on one in-sample run. Re-test after the blind set
      above, against the in-gist `ALSO` convention.
- [ ] **Chooser confidence on the `Find:` watchdog line** (raised 2026-10-18,
      not built; unrelated to the ambiguity fix). Today the line reads
      `Find: match via chooser (-), literals [pl_loan_period], pool 98` and
      says nothing of how sure the model was. Proposal: add the top pick, the
      runner-up and none, e.g. `top pl_loan_period 0.92, next pl_loan_limit
      0.04, none 0.007`, plus the model id. Makes a borderline match (0.65 vs
      0.21) visible and tells a real `chose_none` from a near miss when
      `ai_logging` is off or in stealth mode. Values-free, behind the same
      debug/stealth switch as the other audit logging. The probabilities are
      already in the Decision output (`getChoice('choice')->getProbabilities()`),
      so no extra call. Worth most once real traffic exists; also the
      natural feed for the threshold-tuning skill (`literals_finder/eval/TUNING.md`).
      Note `literals_choose` is not in `ai_logging`'s `prompt_logging_tags`, so
      chooser calls are not in `ai_log` at all today.
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
- [ ] **Apply `aim_demo_library` to a fresh install (raised 2026-10-10).**
      The reset on this site (delete facts, re-apply; commands in
      `demo/README.md`) ran 2026-10-10: 31 facts indexed on import, all 13
      pre-flight checks passed. A fresh install is still untested. Also
      decide whether the demo needs a front page again.
- [ ] **Re-check what the personas assume the assistant can see** (a
      signed-in member only gets facts through `view site aim facts`;
      `aim_literal` needs only `view literals` since 2026-10-10), and
      the `aim-discovery` and `aim-memory` skills.

## Recipes (raised 2026-09-27)

- [ ] Apply `recipes/aim_demo_library` to an isolated scratch site (its
      own database) and fix whatever breaks: it has only passed core's
      schema validation. Then confirm `aim:remember --file` loads all 31
      facts and the demo questions in the recipe README's "Running the demo"
      answer as before.

## Docs site (raised 2026-10-16)

- [ ] Build and check the MkDocs Material site, written 2026-10-16 but never
      built: `pip install mkdocs-material` in a virtualenv, then
      `bin/build-docs.sh`. Expect broken-link warnings from ADRs that link
      to unpublished files (`src/`, `CLAUDE.md`), and confirm the nav
      matches the files copied. It publishes only once aim is a project on
      drupal.org and `.gitlab-ci.yml`'s `pages` job runs there, at
      `project.pages.drupalcode.org/aim`.
- [ ] The look is the Drupal.org project-pages stylesheet copied from the
      Site Agent project, **for research purposes only** (note on the home
      page, footer and `docs/assets/NOTICE.md`). Before a public release,
      swap it for aim's own palette or get clearance. ZT Gatha is not
      bundled (free for personal use only), so headings use Noto Sans.
      `docs/assets/aim.svg` is a placeholder logo.

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
