# ADR-0038: Local decision models: what was measured, and how to bring one back

**Status:** Parked (2026-10-02). Hosted Jev is the live decision path
([ADR-0021](0021-jev-typed-decision-provider.md) addendum); this records
the local-model work so it need not be redone or kept as scattered docs.
**Date:** 2026-10-02

## Context

CLAUDE.md decision 5 (sovereignty) wants a local path for the decision
points (`classifyPair()`, `verifyMerge()`, the plausibility gate of
[ADR-0033](0033-plausibility-gate-processing-modes.md)). Two local
options were tried on the dev laptop (14 GB RAM, no GPU, 4 GB swap):
Ollama serving `tev1` (Ollama 0.35 hosts decision models on
`/v1/systemone`, port 11434; `nimble` 9B, `tev1` 4B, `tev1:0.8b`), and
Ollaya serving `laya:en` (Convai's Laya, Apache 2.0, a fine-tuned
ModernBERT-large, about 421M parameters, 512-token context; third-party
benchmarks only: fine-tuned accuracy 0.766, calibration error 0.081, zero-
shot base 0.362 so fine-tuning is required; accuracy drops past about 20
choice options; `127.0.0.1:11435`, wire-compatible with Jev, not
weight-compatible).

## Measurements (single runs, hand-written easy sets, not calibrations)

Plausibility, 2026-10-01 (31 true facts, 6 blatant and 8 subtle
falsehoods, 0.5 pass mark, fact alone as input): `tev1:0.8b` passed 30/31
and rejected 2/6 and 2/8, so it cannot gate; `tev1:4b` passed 31/31 and
rejected 6/6 and 5/8, cold load 11-19 s, median 6.5-7.1 s per fact.
Misses were site-specific contradictions that need site context. Bare
plausibility was the wrong test for `laya:en` (best reworded AUC 0.77).

Decision eval, 2026-10-02 (`aim_benchmark/scripts/decision-eval.py`,
24 pairs, 14 merges, 20 gate items, labels human-verified):

| | pairs | merges AUC | gate AUC | per call |
| --- | --- | --- | --- | --- |
| `laya:en` | 6/24 (never ADD or NOOP right) | 0.71 | 0.62 | 0.5 s |
| `tev1:4b` | 17/24, 3 unsafe | 0.96 | 1.00 | 10-14 s |
| hosted `jev-latest` | 23/24, 0 unsafe | 1.00 | 1.00 | 0.4 s |

CPU-bound: parallel requests to a local model queue instead of speeding
up (16 requests 62.6 s serial, 51.1 s parallel), so one worker at a time.
`tev1` accepts about 2,050 tokens of input and Ollama rejects Choice
questions over 26 options; four-option ADD/UPDATE/DELETE/NOOP is well
inside both. Fact text is short (63 facts averaged 92 characters).

## Decision

**The dev laptop is not a yardstick for local model hosting.** Every
local number here comes from a 14 GB, no-GPU laptop that also runs an
editor and a browser; a machine meant for inference (a GPU, or just
dedicated RAM) would change speed and stability by a wide margin, and
the conclusions below are about this hardware, not about local decision
models.

Park local decision models. The juice was not worth the squeeze on this
hardware: 25x slower than Jev, and memory pressure exhausted RAM and swap
and OOM-killed the editor (2026-09-28; again 2026-10-02 until a browser
was closed). Nothing local is removed in code:

- The chat backend, the provider-agnostic `DecisionBackend` and the
  per-activity `activities.*` settings stay, so switching an activity
  back is a settings change (provider, model; threshold for the verifier).
- `decision-eval.py` accepts any `/v1/systemone` host (`--host`,
  `--key-env` for a bearer token) and the sets under
  `aim_benchmark/scripts/` are model-agnostic, so a new local model is
  one command: `python3 scripts/decision-eval.py all <model> --host
  http://localhost:11434`, then calibrate the verifier threshold from its
  output.
- Embeddings stay local (`nomic-embed-text` on Ollama, `index_directly`);
  only decisions moved to the hosted model.

Superseded and removed: `plausibility-benchmark.py` and
`plausibility-controls.json` (bare-fact plausibility is the wrong test;
`decision-eval.py` covers the gate, pairs and merges), and the DEVELOPING
section on running Ollaya/`tev1` under systemd (below).

## Running a local model service on a small machine

Reconstructed from what worked on the dev laptop; apply if a local model
is brought back.

- **Cap it.** Run it as a systemd service with a drop-in (`sudo
  systemctl edit <unit>`): `MemoryMax` about 5-7G, `MemoryHigh` a little
  below it, `OOMScoreAdjust=500` so the kernel kills the service before
  the editor, `Restart=on-failure` with `StartLimitBurst=3`, not enabled
  at boot. Measured working sets: Ollama embeddings about 650 MB; Ollaya
  `laya:en` about 3.8 GB (4.3 GB on full-context input); `tev1:4b` peaked
  5.45 GB under a 6G cap.
- **`MemoryHigh` from a measured peak.** It throttles instead of killing;
  a cap below the working set just makes the model load slowly (Ollaya
  cold load 36 s at 2G, 21 s at 2.5G, 7 s at 3.5G+). `MemoryPeak` equal
  to the cap means the cap is binding.
- **A cap near the model file size kills the pull** (page cache is charged
  to the service's cgroup; 4.5G was OOM-killed mid-pull). Pull with file
  size plus about 1.5 GB of headroom, then restart before timing.
  A mistyped `MemoryMax=7` is 7 bytes and crash-loops under `Restart=always`.
- **Restart before timing**: `systemctl set-property --runtime` does not
  unload the model, so the next timing is warm.
- **Keep-alive trades load time for memory** (`OLLAMA_KEEP_ALIVE=30s`;
  `OLLAYA_KEEP_ALIVE=10m` while testing). No `systemd-zram-generator` on
  the laptop (CPU cost, breaks suspend).
- **Judge headroom with `free -h` "available"**, not "free".
- **The biggest memory hog on the machine meant to test local AI was the
  web browser.** Brave used about 7.9 GB with many tabs, more than either
  model, and closing it is what finally let the timed-out run finish.
- Ollama listens on `0.0.0.0:11434` (DDEV needs it); Ollaya on
  `127.0.0.1:11435`. Ollaya check: `curl -s localhost:11435/v1/models`.
  Input beyond the context window is rejected (`STATE_TRUNCATED`), so
  bound fact length on write.

## Consequences

- One place for the local story; the other docs point here.
- Sovereignty is a documented deviation, not a lost option. Reopen when a
  local decision model or the hardware improves, or before real data goes
  in (hosted Jev has no documented retention policy, ADR-0021 open
  question 1).
