# Notes: data flow and the failure loop (2026-10-14)

Reference material split out of DRAFT-eval-plan.md. Not decisions.

## A. Data-flow ledger (what went in, what came out, to and from whom)

One row per hop. "Hosted" means the text leaves this machine. Providers are
as recorded in CLAUDE.md and the site notes on 2026-10-14; verify against
live config before relying on a row.

| Hop | In | Out | Provider | Leaves machine? | Stored where |
| --- | --- | --- | --- | --- | --- |
| Visitor to widget | Question | Answer | none | no | assistant history (private tempstore pool) |
| Chat turn | Question + persona + recalled facts + history | Answer, tool calls | amazeeio (hosted) | yes | not stored |
| Embedding | Query / fact text | Vector | local Ollama | no | `aim_fact_vectors` |
| Recall | Query vector, viewer | Fact IDs, scores, text | none | no | not stored |
| Literals finder | Question | Literal key or none | Jev (hosted) | yes | not stored |
| Extraction | Source text | Candidate facts | amazeeio (hosted) | yes | facts; prompt and reply in `ai_log` (tag `aim_extract`) |
| Consolidation, verifier, grounded check | Fact text pairs, source text | Decision, score | Jev (hosted) | yes | decision in fact fields; prompt and reply in `ai_log` (tags `aim_consolidate*`, `aim_ground`) |
| LLM judge (ai_eval) | Question, answer, ground truth | Score | judge provider (to choose) | depends | eval result entity |
| Review trace (proposed) | Whole turn + versions | none | none | no | trace store, 30-day retention |

The `ai_log` rows are the only place most hosted-hop text is kept, so they
are the first thing the retention and access decisions apply to.

Gap this exposes: the same question travels to two or three hosted
providers per turn (chat, finder, grounded check). The retention and
disclosure decisions in section 1 should be made per hop, not per feature.

## B. The failure loop (what went wrong, how do we repeat it, how do we fix it)

Treat this as part of the build process: every failure, from a labeled
review or a live trace, goes through all three steps before it is closed.

1. **What went wrong.** Open the trace, read the whole turn, label it bad
   with a one-line reason and a failure mode. The trace must show: the
   question, the recall results (fact IDs and scores), the tools called and
   their results, the answer, and the model, prompt and settings version.
   Without those fields this step is guesswork.
2. **How do we repeat it.** Replay the same input against the same
   configuration and see the same failure. Needs the trace's inputs, the
   config version, and the fact state it saw (facts are superseded, not
   deleted, so fact IDs plus the turn timestamp reconstruct it). Models are
   not deterministic, so run it several times and record the failure rate.
   A failure that cannot be repeated is logged as "intermittent" with its
   rate, not dropped.
   For the model-call hops this already half exists: `ai_log` holds the
   prompt, reply, model, tokens and run ID, so a bad extraction or merge can
   be read and re-sent. What is missing is the fact ID on each row and, for
   the chat turn, whether it is logged at all.
3. **How do we fix it.** Change the prompt, threshold, code or data, then
   re-run the repro and the full suite. The failing case is promoted into
   the dataset as a permanent regression test (in synthetic or redacted
   form, per section 1), so it can never silently return.

Rules:
- A failure is closed only when its repro passes and is in the suite.
- Failure modes are counted. The most frequent one gets fixed first; this
  is the prioritized backlog, not a gut call.
- Review a fixed sample of live traces on a schedule (suggest weekly while
  there is traffic), plus every user-reported problem, so the loop does not
  depend on someone remembering (neglect is the main risk).
- Fixes that change the judge or the labels get re-validated against human
  labels (section 3).

Existing material to fold in rather than duplicate: [TESTS.md](../TESTS.md)
already tracks tests and evals (kinds A, B, C); this plan's assertions are
kind C and its labeling sheet feeds kind A. Link them once approved.

What this adds to the build: chat-turn capture with version fields (model-call capture exists), a replay
command (`aim:replay <trace>`), and a promote-to-dataset step with a
redaction check. ai_eval already covers annotation, failure modes and
promotion; replay and version capture are what we would build or confirm.

## 6. Recall audit line (added 2026-10-14)

The failure loop (section 5) and the enterprise audit question ("what did the
agent know when it acted?") need the same data: who asked, when, and which
facts came back. Today `recall()` logs counts and best score only, at the
verbose tier. Proposed: one audit-tier line per recall with uid, scope and the
returned fact IDs (no text, per the logging rule). The trace then needs no
second copy of it. Caveat: facts edited in place keep no earlier text, so IDs
plus timestamp reconstruct what was visible only once the edit-history item
in TODO.md/BACKLOG.md (Governance) is built. Source: Oracle's "enterprise brain" article
asks the same question; TODO.md/BACKLOG.md has the build item.

