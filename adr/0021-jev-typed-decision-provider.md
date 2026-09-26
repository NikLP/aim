# ADR-0021: Jev (TypeSafe AI) as a typed-decision provider - spike, not adoption

**Status:** Proposed - researched 2026-09-26, not built. Waitlist access
requested; nothing can be tried until a key arrives.
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
     [ADR-0005](0005-consolidation-algorithm.md)) asked of a chat model
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

- **Additional, never the only path.** [ADR-0004](0004-sovereignty-and-poc-build-order.md)
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
