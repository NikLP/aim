# ADR-0021: Jev (TypeSafe AI) as a typed-decision provider - spike, not adoption

**Status:** Deferred (2026-09-27), updated 2026-10-02 - the Decision API
is in core `drupal/ai` 1.6 and Ollama serves decision models; see the
2026-10-02 addendum. No spike is scheduled yet.
**Date:** 2026-09-26

## Context

TypeSafe AI's Jev (launched 2026-09-15) is a different kind of model from
anything `aim` calls today: it returns typed, calibrated answers to
predefined questions instead of text. A Drupal integration exists
(`drupal/ai_provider_typesafeai`, on top of `drupal/ai_decision`). The
question: does it change what `aim` can do, and how should it be
evaluated?

Everything below comes from the drupal.org module pages and public
coverage (MarkTechPost launch article, TypeSafe's docs). None of it has
been exercised against `aim`.

## Findings

1. **What Jev is.** A hosted-only "System One" model. The caller sends a
   state (text or JSON) plus named questions. Question types are Noul
   (yes/no probability), Choice (up to 255 options, per-option
   probabilities plus a confidence), and Score (a rubric, probability-
   weighted level). Questions run in parallel and in isolation. Reported
   latency is 70-500ms, price $0.042 per million input tokens with free
   output. It cannot generate text.
2. **What is not known.** No published weights, parameter count, or
   self-hosting option. Data retention and training-on-inputs policy were
   not found. The benchmarks are the vendor's own, and the coverage read
   notes "zero hallucinations" means the output always matches the schema,
   not that the answer is right.
3. **The Drupal side.** `ai_provider_typesafeai` (1.x-dev) provides the
   Decision operation type plus Text Classification and Moderation
   operation types mapped onto it. `ai_decision` (1.0.x-dev) supplies the
   shared Decision API, its own guardrails (regex, length, moderation),
   Automators, and an Explorer. Both are dev-only and not covered by the
   security advisory policy. Whether `ai_decision`'s guardrails are
   `drupal/ai` Guardrail plugins (what `aim_write_guardrails` uses) was
   not checked.
4. **Where it fits.**
   - **Consolidation.** `AimMemoryManager::classifyPair()` is already a
     four-way enum (ADD/UPDATE/DELETE/NOOP,
     [ADR-0005](resolved/0005-consolidation-algorithm.md)) asked of a chat model
     via structured output. That is a Choice question. Only UPDATE's
     `merged_text` needs generation, so a Jev call would decide and the
     chat model would run only when the answer is UPDATE. Jev's
     probabilities could also replace the two hard cosine thresholds'
     ambiguous band with a confidence-based one.
   - **Triage of extracted candidates.** Scope, category, `state`, and
     "durable fact or noise" are per-fact Choice/Noul questions, asked in
     one parallel call.
   - **Confidence-gated trust.** A calibrated probability could make the
     draft-to-trusted gate ([ADR-0002](0002-governance-deferred-guardrails-mandatory.md))
     cheap: high confidence goes live, low confidence goes to review. See
     the trust-gating decision below.
5. **What it cannot replace.** `extractFacts()` (text in, facts out) and
   UPDATE's merge text are generative and stay on a chat model.

## Decision (proposed)

**Spike Jev as an additional provider for the consolidation decision
only. Do not adopt it, and do not build anything that depends on it,
until the spike has a result.**

- **Additional, never the only path.** [ADR-0004](resolved/0004-sovereignty-and-poc-build-order.md)
  requires Ollama support, with hosted providers as extra options. Any
  Jev code path sits behind a setting (chat versus decision) and the chat
  path stays the default and stays complete.
- **Spike on synthetic data only.** Jev is a third-party hosted service
  with no documented retention policy, and `scope=user` facts are
  personal. Use `generateBenchmarkFacts()` and hand-labeled duplicate
  pairs, never real facts, until retention is answered in writing.
- **Evaluate with the existing harness.** Build a labeled set of fact
  pairs (the known duplicate pairs from ADR-0005's calibration are the
  seed) with a human-assigned ADD/UPDATE/DELETE/NOOP. Compare Jev
  against the current chat path (Ollama and amazeeio) on agreement with
  the labels, on whether higher confidence actually means higher accuracy
  on this data, and on latency. `aim:benchmark` is where this lives.
- **Trust gating is a separate decision and is blocked.** Auto-trusting
  above a probability threshold contradicts "nothing LLM-extracted is
  auto-trusted" (CLAUDE.md decision 3). It needs its own ADR that
  supersedes that clause, and it inherits ADR-0016's Mode 2 dependency on
  ADR-0002. There is also a definitional problem: Jev's calibration is
  about a question's answer being right, so it can gate whether an
  extraction is faithful to its source, not whether the source's claim is
  true. What "correct" would mean for a trust question is undefined.

## Consequences

- If the spike succeeds, consolidation gets fewer chat calls and a
  calibrated confidence instead of a cosine-only band. It also gets an
  explicit "unsure, queue for review" outcome, which does not exist today.
- If it fails or access is refused, nothing in `aim` has changed. The
  spike sits behind a setting and adds no hard dependency.
- `aim`'s `composer.json` would eventually gain `suggest` entries for
  `drupal/ai_provider_typesafeai` and `drupal/ai_decision`, not `require`:
  both are dev-only, and the module is headed for drupal.org
  independently.
- The Decision operation type is not `chat()`. `classifyPair()` cannot
  just receive a different provider ID: it needs a second code path
  through `ai_decision`'s API, which has not been read yet.
- Threshold recalibration per provider (ADR-0005) applies to Jev's
  confidence values as well.

## Addendum (2026-09-27): Laya, an open-source alternative

Laya (Convai, Apache 2.0, self-hosted) is a Jev-shaped open model that
fits the sovereignty requirement. It was measured and set aside; see
[ADR-0038](0038-local-decision-models-parked.md).

## Addendum (2026-10-02): the Decision API is in core, Ollama serves it

Two things in the Context above have changed.

**The Decision API merged into `drupal/ai` 1.6** (MR !2046; the site runs
`1.6.x-dev`). There is no separate `ai_decision` module and no unmerged
core patch any more. `ai_provider_typesafeai` 1.1.0-beta1 targets it and
requires `drupal/ai ^1.6`. Callers build a `DecisionInput` of `NoulQuestion`/
`ChoiceQuestion`/`ScoreQuestion` value objects and call the provider
manager; answers are normalized (`getNoul()`, `getChoice()`,
`isLikely()`, `isConfident()`). Decision guardrails
(`DecisionGuardrailInterface`, `DecisionGuardrailRunner`) and AI Logging
apply to calls made through the manager. Reference:
`web/modules/contrib/ai/docs/developers/call_decision.md`.

**Ollama 0.35 serves decision models** on `/v1/systemone` (port 11434):
`nimble` (9B), `tev1` (4B) and `tev1:0.8b`. Ollaya is no longer needed
to host the wire format. The provider's `host` setting can point at
Ollama with `/v1` included (the client appends `/systemone` and
`/models`), and `isUsable()` needs a non-empty API key (any placeholder).

Local measurements are in [ADR-0038](0038-local-decision-models-parked.md).

**Decisions**

1. **Go through the provider manager, never raw HTTP.** That is what
   brings Decision guardrails, AI Logging and events. The benchmark
   script stays direct because it measures the model.
2. **Use the AI suite's model selection, not a new aim mechanism.** The
   `ai_provider_configuration` form element (with its "Default" option
   resolving the site default for `decision`) is the standard way for a
   module to let an admin pick provider and model for its own activity.
   There is no central activity registry; each module stores the
   element's value in its own config. aim's free-text
   `merge_verifier_model` (`provider__model`) and the Drush
   `--provider`/`--model` options predate this and should move to it.
3. **Question wording and thresholds are config, not code.** Thresholds
   are per model (the docs say to validate them per model), keyed to the
   selected model, with admin-editable question text like
   `consolidation_prompt`/`extraction_prompt`.
4. **Chat stays the default and stays complete** for consolidation
   (decision 5, sovereignty). The decision path sits behind a setting.
5. **Target `drupal/ai ^1.6`** in `composer.json` once 1.6.0 is tagged
   (it is `^1.4` today). No compatibility code for 1.5.
6. **Guardrails.** A decision question could back a custom guardrail
   plugin, but a guardrail's outcome is pass or stop (reject the
   write), while ADR-0033 wants quarantine (save untrusted). Use a
   guardrail only for the clearly-bad tier; the `trusted` flag stays the
   quarantine. `aim_write_guardrails` should not be reused as is: its
   2000-character limit would count the whole serialized Decision input
   and its regex would scan question text. Use a separate decision set.

**Known provider limits.** The provider declares one capability profile
for every model, including 255 choice options, while Ollama rejects
questions with more than 26 options and `tev1` rejects inputs over about
2,050 tokens. The validator will not catch these before sending. Its
model dropdown lists whatever `/v1/models` returns (unfiltered on
Ollama) or falls back to two Jev names. Four-option ADD/UPDATE/DELETE/
NOOP is well inside every limit.

Open questions 3 and 4 above are partly answered: the module is now in
core (4), and Decision guardrails exist, though attaching a set to a
call is unverified (3). Question 1 (retention) and 2 (pass bar) stand.
The spike's first real targets are `classifyPair()` and `verifyMerge()`,
where the pair supplies the context; neither is built.

## Open questions

1. **Retention and training policy.** Unanswered. Blocks any real data.
2. **Pass bar.** Agreement and calibration numbers that justify
   adoption are not set. Decide before running the spike, not after.
3. **Guardrails.** Can a Jev-backed Moderation operation, or
   `ai_decision`'s guardrails, run as a `drupal/ai` Guardrail in
   `aim_write_guardrails`? Would it add value over the regex and
   moderation checks already there?
4. **Module stability.** Both Drupal modules are dev-only, and the
   `ai_provider_typesafeai` page says it is planned to fold into the main
   AI module. Building on the dev API may need rework.
5. **Vendor risk.** A weeks-old vendor with hosted-only access, and
   pricing that reporters could not confirm is unsubsidized.

## Addendum 2026-10-02: hosted Jev is the live decision path

Measured on hand-written, human-verified sets (24 pairs, 14 merges, 20
gate items) plus 25 real near-neighbour pairs from this site's facts:
`jev-latest` and `jev-preview` scored identically, pairs 22/24 (1 unsafe
error, NOOP read as UPDATE) and 25/25 on the real pairs, merges and gate
AUC 1.00, 0.41 s per call. Local models measured on the same sets:
`tev1:4b` pairs 17/24, 10-14 s per call, and the RAM pressure exhausted
this laptop's swap; `laya:en` pairs 6/24. The sets are easy, so the scores
overstate real accuracy; harder UPDATE/NOOP pairs and a groundedness set
are still to build.

Decision: this site runs `consolidation` and `verifier` on
`typesafeai`/`jev-latest` (`backend: decision`), API key via the Key
module's file provider outside the web root. Local decision models are
set aside, not removed: the chat path, the provider-agnostic
`DecisionBackend` and the eval harness (`aim_benchmark/scripts/`) all
stay, so a local decision model (Ollama or Ollaya) can be switched back
in per activity with a settings change. This is a temporary deviation
from CLAUDE.md decision 5 (sovereignty): only synthetic or demo fact text
may reach hosted Jev until a local decision model is good enough, or the
data owner accepts the hosted path. Revisit when local models improve or
before any real data goes in.

First live run found a bug in `DecisionBackend`: `createInstance()`
returns a `ProviderProxy`, so `instanceof DecisionInterface` always
failed; it now tests `getPlugin()`, as core's own
`DecisionProviderFormHelper` does. The backend had never run before.
