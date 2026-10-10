# Tests and evals

Everything still to be tested or evaluated, in one place. Status as of
2026-10-05. Items are gathered from [TODO.md](TODO.md), the ADRs and
`demo/ingest-test/`; the source is named on each so the original can be
updated when one is done. Hosted Jev takes synthetic or public data only
(ADR-0021 addendum).

## Three kinds of test (do not confuse them)

| Kind | Goes through | Lives in | Answers |
| --- | --- | --- | --- |
| **A. Model evals** | The model's HTTP API directly (`decision-eval.py`). No Drupal, no module code. | `modules/aim_benchmark/scripts/` | Can the model make this judgment at all? |
| **B. Module runs** | The module: `drush aim:remember` / `aim:extract` / `aim:consolidate` / `aim:recall` on a snapshot, real backends, real queue. | `demo/ingest-test/`, `aim_benchmark` commands | Does aim, wired to a model, do the right thing end to end? |
| **C. Automated tests** | PHPUnit (kernel/unit). One kernel test so far (below). | `tests/src/Kernel/` | Does a class behave, without a live site? |

The drift audit (ADR-0043) so far is type A only. Nothing in aim calls it
yet, so a pass there says nothing about the module.

## Done

- A. Drift audit, real passages: `decision-eval-drift-real.json`, 62 pairs
  from public library, council, community-centre and charity-shop pages.
  Hosted `jev-latest`: contradiction recall 28/28, precision 28/28, 0 false
  alarms in 34 non-contradicting pairs (ADR-0043 addendum).
- A. Borderline labels (ids 14, 37, 39, 48) adjudicated 2026-10-04 as worth
  human review, so they stay CONTRADICTS.
- A. `classifyPair`/`verifyMerge`/gate sets (`decision-eval-sets.json`,
  `decision-eval-merges-hard.json`, `decision-eval-real-pairs.json`): hosted
  Jev 23/24 pairs, 25/25 real pairs, merges and gate AUC 1.00 (ADR-0038,
  ADR-0021 addendum). Local models parked.
- B. Live verification of the embedding cache (ADR-0017), top-N
  consolidation dry run (40 vs 86 decisions), Ollama vs hosted recall
  latency (33-35 ms vs 450-540 ms, `aim_benchmark`).

## Next: model evals (A)

1. **Drift audit, facts from someone else.** The last condition before the
   content index work starts (ADR-0043). About 15 passages from the real
   set; a person other than the prototype author writes one fact each, as
   staff would say it (vague, partial, wrong in small ways). Score with the
   same script (`--drift-set`). Existing facts skew towards crisp numbers
   and times, so this is the test that can still lower precision. About 10
   minutes of writing.
2. **Literal-candidate check.** A typed per-fact question: "is this a single
   exact value that lives in one authoritative place?" (ADR-0043 open
   question, ADR-0040). Seed a labelled set from the 62 real pairs, split
   into value-shaped (rates, limits, periods, emails, hours) and
   policy-shaped (alcohol with permission, exceptions, tiers). Needs its
   own precision check, with labels not written by the question's author.
3. **Human labels for the drift set.** The blind labeller was a second
   model and agreed 62/62, which is not human-independent. Spot-check 10 to
   15 items by hand, starting with the 15 it marked below full confidence
   (ids 8, 9, 14, 16, 19, 20, 27, 34, 37, 38, 39, 44, 48, 58, 62).
4. **Groundedness set** (ADR-0037): passage plus candidate fact, labeled
   supported / unsupported / misattributed. Needed before the grounded
   check build (TODO).
5. **Harder UPDATE/NOOP pairs** and the **gold sets** TODO item (a)-(c):
   15-25 hand-written pairs per ADD/UPDATE/RETIRE/NOOP with hard
   negatives, human-verified labels; merge set from mutated good merges;
   site-context contradictions for the `gate` set.
6. **Pass bar** (ADR-0021 open question 2): decide before scoring, e.g.
   "decision path within X points of chat accuracy at under Y s per pair".
7. **Extend `decision-eval.py`:** confusion matrix by confidence bucket and
   a cost-weighted threshold sweep (a wrong UPDATE loses data, a missed
   merge is cheap).
8. **Can a small local model extract?** (`adr/model-call-budget.md`):
   untested for extraction and merge writing; decides whether any step
   can leave the frontier model.

### Literals (ADR-0040)

Run these before anything past Phase 3 of the build plan. All are type A
unless noted; synthetic data only for hosted Jev.

1. **Chooser accuracy and latency by menu size.** One Decision API
   `ChoiceQuestion` over 50, 200 and 500 key-plus-gist options, hosted Jev
   and local. Record per-option probability reliability, time per call
   including and excluding network, and whether a static menu prefix is
   reused (Ollama prefix reuse, hosted prompt caching). Also check the
   unsourced "15-80 ms p50 on a warm GPU" figure against the real model and
   a menu-length prompt.
2. **Literal gold set.** Queries per literal (generate with
    `aim_benchmark`, add hand-written hard ones), with approved aliases as
    known query-to-literal pairs. Compare (1) keyword with hand-written
    synonyms, (2) embedding gate only, (3) gate plus chooser. Score recall@1,
    miss rate, wrong-confident rate, and the share of queries each tier
    absorbs. The design only pays off if the cheap tiers absorb most
    traffic.
3. **Embedding gate in PHP.** Cosine compare time at 200, 2,000 and 10,000
    stored vectors, to find where a real vector index is needed. Tune the
    "nothing close" floor and the top-hit margin on near-neighbour pairs
    ("reservations phone" versus "main phone"). Also the non-literal
    question rate: how often the gate returns `none` without a chooser call.
4. **Second-language gold set.** A query in one language against gists in
    another, to confirm the multilingual claim before it is made.
5. **Staged choice versus flat menu** (only if item 1 shows the flat menu
    failing): group-then-literal and chunked menu, round-1 misroute rate.

## Next: module runs (B)

1. **Ingest test, end to end.** `demo/ingest-test/` (85 synthetic facts,
   `expected.json` gives the intended outcome per fact: new, restatement,
   update, contradiction, junk, blocked). Snapshot first
   (`ddev snapshot --name pre-ingest-test`), run `aim:remember --file`,
   `aim:extract` on the three prose files, `aim:consolidate`, diff against
   `expected.json`. No recorded run exists.
2. **PHP replay command** in `aim_benchmark`: the same pairs through
   `ChatBackend` and `DecisionBackend`, grouped by `setRunId()`, for
   accuracy, time and tokens (TODO). This is the missing bridge between
   type A and type B; the drift audit should get a module path the same
   way once the content index exists.
3. **Real (non-dry) consolidation run** and a queue-worker check of top-N
   consolidation (TODO, ADR-0036).
4. **Jev token reporting live** (`AimActivityMetricsSubscriber` is built,
   Jev's reporting is not confirmed) and confirm the Anthropic key in
   watchdog is rotated and the entries cleared (TODO).
5. **Decision guardrails:** how a caller attaches a guardrail set to a
   Decision call, and that the built-in length and regex plugins really
   run for it. Plugin code not opened (TODO). Also run the offline
   `ai_provider_typesafeai/tests/check-decision-api.php` check.
6. **Baselines** (none exist, so no improvement can be claimed): merge
   fidelity review of dry-run UPDATE/RETIRE rows on a snapshot copy; a
   disclosed LongMemEval slice (50-100 questions) for recall precision,
   knowledge-update correctness and abstention; recall payload size vs
   full-history tokens; duplicate rate. Then tune `recall_max_distance`
   (0.48), `auto_threshold` and `ambiguous_threshold` against them,
   counting hosted calls (verifier plus `auto_threshold` 0 compounds).
7. **Retrieval at scale** (open question #5): `aim:benchmark` at thousands
   of facts. Re-verify the `getVdbIds()` limit-10 shim (#3626257) before
   relying on deletes for retention.
8. **Filter behavior in HNSW** (ADR-0019): whether a `retired`-style
   unselective filter degrades like `site` does, and whether the optimizer
   picks a BTREE on it. Validate against exact ground truth with
   `aim:benchmark`.
9. **Pinned until tested:** recency/importance re-rank (needs a gold set),
   time-limited facts not UPDATE-merged into permanent ones.
10. **Drush `pmu aim_scope_entity` SQLSTATE oddity** (ADR-0028): not
    reproducible on retry 2026-09-29; recheck before filing or closing.
11. **Unverified, from ADRs:** whether ECA exposes a blockable event for
    config-entity changes (ADR-0035); whether `remember()`-style direct
    entity writes run Guardrails (DEVELOPING.md); whether scope-access
    refusal holds through Annotations (ADR-0026).

## Automated tests (C)

`Kernel/AimRecallLiteralTokensTest` (2026-10-10): `recall()` resolves
`[literal:key]` tokens as the viewing account (staff, member, anonymous); a
fact naming a literal the viewer cannot read is withheld, or shows
`[redacted]` with `show_redacted_facts` on; a changed, unpublished or
deleted literal reads as it is now, and the stored fact keeps the token.
The vector search is stubbed: `aim_vector_index` sits on
`search_api_test`'s backend with its `search` method overridden to return
every fact, and only `aim.settings` is installed from aim's config (the
real server needs MariaDB vectors). Reuse that setup for the items below.

```bash
ddev exec "cd /var/www/html && SIMPLETEST_DB='sqlite://localhost/sites/default/files/aim-test.sqlite' vendor/bin/phpunit -c web/core web/modules/custom/aim/tests"
```

Still to write, in this order:

1. `AimEmbeddingCacheSubscriber` (ADR-0017): a second identical query makes
   zero provider calls, an index-time embed is not cached, a different
   model ID misses. Use a call-counting stub provider. Kernel setup needs
   `aim` plus `ai_search`, `ai_vdb_provider_mariadb`, `search_api` and
   `taxonomy`, which is why it was deferred.
2. Scope access plugins (`AimScopeUser` role-visibility matrix,
   `AimScopeEntity` view-access mirroring), which are silent-fail if
   `plugin` is reverted by a stale `drush cim`.
3. Consolidation decisions with a stub backend: top-N neighbors, supersede
   edge, verifier threshold, non-destructive retire.

## Literals: automated tests (C), when the module exists

- No candidate the caller cannot view reaches the chooser or the result
  (access filter runs first, ADR-0040 "The finder and the chooser").
- Tokens check view access and carry cache contexts and tags; a cached page
  never serves a staff value to another user; only the published revision
  resolves.
- Key unique per pool; tier 0 exact key and alias lookup; outcome cache
  keyed by permission set and invalidated by the literal's tags.
- A stored vector with a different embedding model ID is ignored by the
  gate and queued for re-embedding.

## Open, not started

- Retrieval of the right passage from a full page (the content index part
  of ADR-0043). The real drift set uses short quoted passages, so this is
  untested.
- Unrelated pairs that Jev calls SUPPORTS (3 of 17). Harmless to the audit,
  but watch whether it grows on vaguer facts.

## Decision rule (ADR-0043)

Build only if contradiction precision stays high enough that a reviewer
would not switch the audit off: no more than about 1 false alarm in 10
findings. If model eval A1 misses that, park ADR-0043.
