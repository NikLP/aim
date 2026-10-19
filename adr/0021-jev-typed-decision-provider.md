# ADR-0021: Decision models: hosted Jev is the live path, local models are parked

**Status:** Accepted as current state (rewritten 2026-10-11; first
proposed as a Jev spike 2026-09-26). Absorbs the former ADR-0038 (local
decision models) and the processing-mode half of ADR-0033; the gate half
of 0033 moved to [ADR-0037](resolved/0037-transient-source-passages-for-grounding.md).
Open items (pass bar, retention, per-model config) are in TODO.md.
**Date:** 2026-09-26

## Context

TypeSafe AI's Jev (launched 2026-09-15) is a different kind of model from
anything `aim` calls otherwise: it returns typed, calibrated answers to
predefined questions instead of text. A caller sends a state (text or JSON)
plus named questions: Noul (yes/no probability), Choice (per-option
probabilities plus a confidence) or Score (a rubric level). It cannot
generate text. Hosted-only; no published weights, parameter count or
retention and training policy; benchmarks are the vendor's own.

`aim` has several decision points that are classifications, not
generation: `classifyPair()` (ADD/UPDATE/DELETE/NOOP,
[ADR-0005](resolved/0005-consolidation-algorithm.md)), `verifyMerge()` and
the grounded check ([ADR-0037](resolved/0037-transient-source-passages-for-grounding.md)).
`extractFacts()` and UPDATE's merged text are generative and stay on a chat
model.

## Decision

**1. Hosted Jev runs the decision points on this site.** `consolidation`,
`verifier` and `grounding` use `typesafeai`/`jev-latest`
(`backend: decision`), API key via the Key module's file provider outside
the web root. Measured 2026-10-02 on hand-written, human-verified sets (24
pairs, 14 merges, 20 gate items) plus 25 real near-neighbour pairs from
this site's facts: pairs 22-23/24 (one unsafe error, NOOP read as UPDATE),
25/25 real pairs, merges and gate AUC 1.00, 0.4 s per call; `jev-latest`
and `jev-preview` scored the same. The sets are easy, so the scores
overstate real accuracy; harder UPDATE/NOOP pairs are still to build.

**2. Temporary deviation from sovereignty** (CLAUDE.md decision 5): only
synthetic or demo fact text may reach hosted Jev until a local decision
model is good enough or the data owner accepts the hosted path. Revisit
before any real data goes in; retention and training policy are
unanswered in writing.

**3. Chat stays complete.** The chat backend, the provider-agnostic
`DecisionBackend` and the per-activity `activities.*` settings all stay, so
switching an activity to chat or a local decision model is a settings
change (provider, model, and the verifier threshold).

**4. Go through the core Decision API and the provider manager, never raw
HTTP.** The Decision API is in core `drupal/ai` 1.6 (the site runs
`1.6.x-dev`); callers build a `DecisionInput` of `NoulQuestion`/
`ChoiceQuestion`/`ScoreQuestion` and read normalized answers (`getNoul()`,
`getChoice()`, `isLikely()`, `isConfident()`). That brings Decision
guardrails and the module's own call events. The benchmark scripts may
call a model directly, because they measure the model. Model choice per
activity uses the AI suite's `ai_provider_configuration` element, stored in
aim's own config.

**5. Question wording and thresholds are config, not code.** Thresholds are
per model (the docs say to validate them per model). Today the question
text is still hardcoded (TODO.md).

**6. Guardrails.** A guardrail's outcome is pass or stop, while the
`trusted` flag quarantines ([ADR-0037](resolved/0037-transient-source-passages-for-grounding.md)).
A decision question may back a guardrail only for the clearly-bad tier.
Do not reuse `aim_write_guardrails` for Decision input: its 2000-character
limit would count the serialized input and its regex would scan question
text. Use a separate set.

**7. Trust gating by a probability.** Auto-trusting above a threshold
contradicts "nothing LLM-extracted is auto-trusted" unless a site opts in;
it is decided in ADR-0037 (grounded check, `grounding_mode`), and a model's
calibration says whether an extraction is faithful to its source, not
whether the source's claim is true.

## Local decision models: measured, parked

Two local options were tried on the dev laptop (14 GB RAM, no GPU, 4 GB
swap): Ollama 0.35 serving `tev1` (4B; also `nimble` 9B and `tev1:0.8b`)
on `/v1/systemone`, and Ollaya serving `laya:en` (Convai's Laya, Apache
2.0, a fine-tuned ModernBERT-large, about 421M parameters, 512-token
context; wire-compatible with Jev, not weight-compatible; accuracy drops
past about 20 choice options).

| 2026-10-02, single runs, easy sets | pairs | merges AUC | gate AUC | per call |
| --- | --- | --- | --- | --- |
| `laya:en` | 6/24 (never ADD or NOOP right) | 0.71 | 0.62 | 0.5 s |
| `tev1:4b` | 17/24, 3 unsafe | 0.96 | 1.00 | 10-14 s |
| hosted `jev-latest` | 23/24, 0 unsafe | 1.00 | 1.00 | 0.4 s |

Bare-fact plausibility (2026-10-01; 31 true facts, 6 blatant and 8 subtle
falsehoods) was the wrong test: `tev1:0.8b` could not gate, `tev1:4b`
caught 6/6 blatant and 5/8 subtle (6.5-7.1 s per fact), and `laya:en`
reached AUC 0.77 at best. Misses were site-specific contradictions that
need site context, which is why ADR-0037 grounds the check in the source
text.

Local calls are CPU-bound: parallel requests queue instead of speeding up
(16 requests 62.6 s serial, 51.1 s parallel), so one worker at a time.
`tev1` accepts about 2,050 tokens of input and Ollama rejects Choice
questions over 26 options (the provider declares 255 for every model, so
the validator will not catch it); four-option ADD/UPDATE/DELETE/NOOP is
well inside both. Fact text is short (63 facts averaged 92 characters).

**The dev laptop is not a yardstick for local hosting.** The numbers are
about this hardware, not about local decision models; a machine meant for
inference would change speed and stability a great deal. Parked because
the juice was not worth the squeeze here: 25x slower than Jev, and memory
pressure exhausted RAM and swap and OOM-killed the editor (2026-09-28;
again 2026-10-02 until a browser was closed). Nothing local was removed in
code. To bring one back: set the activity's provider and model, run
`aim_benchmark`'s `decision-eval.php` against it (the sets under
`aim_benchmark/scripts/` are model-agnostic), calibrate the verifier
threshold from its output. Embeddings stay local (`nomic-embed-text` on
Ollama, `index_directly`).

### Running a local model service on a small machine

Reconstructed from what worked on the dev laptop.

- **Cap it.** Run it as a systemd service with a drop-in (`sudo systemctl
  edit <unit>`): `MemoryMax` about 5-7G, `MemoryHigh` a little below it,
  `OOMScoreAdjust=500` so the kernel kills the service before the editor,
  `Restart=on-failure` with `StartLimitBurst=3`, not enabled at boot.
  Measured working sets: Ollama embeddings about 650 MB; Ollaya `laya:en`
  about 3.8 GB (4.3 GB on full-context input); `tev1:4b` peaked 5.45 GB
  under a 6G cap.
- **`MemoryHigh` from a measured peak.** It throttles instead of killing; a
  cap below the working set just makes the model load slowly (Ollaya cold
  load 36 s at 2G, 21 s at 2.5G, 7 s at 3.5G+). `MemoryPeak` equal to the
  cap means the cap is binding.
- **A cap near the model file size kills the pull** (page cache is charged
  to the service's cgroup; 4.5G was OOM-killed mid-pull). Pull with file
  size plus about 1.5 GB of headroom, then restart before timing. A
  mistyped `MemoryMax=7` is 7 bytes and crash-loops under `Restart=always`.
- **Restart before timing**: `systemctl set-property --runtime` does not
  unload the model, so the next timing is warm.
- **Keep-alive trades load time for memory** (`OLLAMA_KEEP_ALIVE=30s`;
  `OLLAYA_KEEP_ALIVE=10m` while testing). No `systemd-zram-generator` on
  the laptop (CPU cost, breaks suspend).
- **Judge headroom with `free -h` "available"**, not "free".
- **The biggest memory hog on the machine meant to test local AI was the
  web browser** (Brave about 7.9 GB with many tabs).
- Ollama listens on `0.0.0.0:11434` (DDEV needs it; its default is
  `127.0.0.1` only, unreachable from the DDEV network); Ollaya on
  `127.0.0.1:11435` (`curl -s localhost:11435/v1/models`). The provider's
  `host` setting can point at Ollama with `/v1` included, and `isUsable()`
  needs a non-empty API key (any placeholder). Input beyond the context
  window is rejected (`STATE_TRUNCATED`), so bound fact length on write.

## Processing modes for a model check on a new fact

The check that gates a fact ([ADR-0037](resolved/0037-transient-source-passages-for-grounding.md))
can be triggered three ways, a setting and not a fork:

| Mode | How | Cost |
| --- | --- | --- |
| Queued | Save enqueues a job; a worker scores it, drained by the dedicated crontab ([ADR-0003](resolved/0003-async-processing-dedicated-crontab.md)), optionally the `hook_cron` fallback ([ADR-0031](0031-cron-fallback-for-queue-processing.md)) | Nothing in the request path; needs a running worker |
| Inline | Score synchronously inside the write | Blocks the save for the call time (0.35 s hosted; not viable on the dev laptop's local models) |
| Post-response | Score in `kernel.terminate`, the mechanism prototyped in [ADR-0015](resolved/0015-immediate-consolidation-considered-deferred.md) | Holds a PHP worker; fragile if the process dies first |

The grounded check runs inline because hosted Jev answers in about
0.35 s. Consolidation stays queued. With no crontab on a demo machine, run
the worker by hand (`drush queue:run <queue>`) or in a loop.

## Open questions

1. **Retention and training policy** of hosted Jev: unanswered; blocks any
   real data.
2. **Pass bar**: the numbers that justify adoption over chat were never set
   (TODO.md); decide before the next re-scoring, not after.
3. **Attaching a guardrail set to a Decision call**, and whether the
   built-in length and regex plugins really run for Decision: unverified.
4. **Module stability.** `ai_provider_typesafeai` is 1.1.0-beta1 against
   `drupal/ai` 1.6.x-dev; raise aim's `composer.json` to `^1.6` once 1.6.0
   is tagged.

## Addendum (2026-10-19): subq as a decision host, hard merge set

Moved here from TODO.md.

**subq (tried 2026-10-14).** `host` `https://api.subq.ai/v1`, model
`subq-snap-preview` on consolidation, verifier and grounding, wire-compatible
with the TypeSafe provider. On the existing sets: pairs 24/24, merges AUC
1.00, grounding AUC 0.99-1.00 (`narrow_ok`; one false trust and one false
hold at 0.7), about 0.25-0.4 s per call, 469 input tokens per call on
average (`ai_log`). Its `/models` returns OpenAI-style `{data:[{id}]}`, not
`{models:[{name}]}`, so the provider's key check failed until
`TypeSafeAiClient::listModels()` was patched locally. `tokens_output` reads 0
on its decision calls (153 logged; Jev reported 22): not counted by the host,
`tokens_input` is real. `checkGrounding()` sends groups of
`activities.grounding.max_questions` (default 16), built 2026-10-19.

**Hard merge set (2026-10-02).** `decision-eval-merges-hard.json`, 20 subtle
faults: `jev-latest` AUC 1.00, but at the default 0.5 cutoff one bad merge
(max 0.68) passed and a perfect split sat at 0.81-0.86, hence
`activities.verifier.threshold` (live 0.8). Extra cross-check noul questions
(same/new/conflict next to the choice, 55 pairs) were tested and dropped:
neither of the 2 misses was fixed and "same" does not separate NOOP (median
0.49). The `merges-split` mode (three small noul questions, minimum score)
exists in the eval script.
