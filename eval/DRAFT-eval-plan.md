# DRAFT: eval plan (assertions, labeling, data retention)

Status: draft for Nik's approval, 2026-10-13. Not built. Nothing here changes
code or config. Context: Hamel Husain, "Your AI Product Needs Evals".

## 1. Data requirements: what evals need versus what we keep today

Today (see DEVELOPING.md "Logging" and ADR-0037): no fact text or query text
is logged except behind opt-in `log_query_text`; the grounded check reads
source text but never stores it. Reviewing real conversations needs the
opposite for some data. The decision is which, for how long, who sees it.

| Data | Needed for | Today | Proposed class |
| --- | --- | --- | --- |
| Visitor question + assistant answer + tools called | Level 2 trace review, labeling | Not kept (assistant history is a private tempstore pool) | Review trace: keep, short retention, restricted access |
| Recall results (fact IDs, scores) for that turn | Debugging bad answers | IDs/scores only, verbose log | Keep with the trace (IDs only, no text) |
| Fact text | Judging groundedness | In `aim_fact` already | No new copy |
| Source passage a fact was extracted from | Grounded-check replay | Deliberately not stored (ADR-0037 d7) | Stay unstored; replay uses synthetic/labeled sets |
| Model/prompt/config version per turn | Comparing runs | Not recorded | Keep with the trace |
| Anonymous or real-user identity | Nothing in evals | uid on writes | Do not copy into traces; store a salted hash or nothing |

Open decisions for Nik (these set what is allowed, so they come first):
1. **Retention for review traces**: suggest 30 days, then deleted unless
   promoted into a dataset (promotion = a human reviewed and redacted it).
2. **Who may read traces**: suggest a new restricted permission, not
   `administer aim memory`.
3. **Real versus synthetic**: suggest eval datasets are synthetic or
   redacted-by-a-human only; raw real traces are review material, never a
   dataset or a prompt to a hosted judge. Hosted judges see only data that
   is already allowed to reach hosted models today (this site: synthetic
   demo data only).
4. **Disclosure**: if real visitors' chats are kept for review, the privacy
   notice must say so (ties to the parked ADR-0056 and the Article 50
   disclosure TODO).
5. **Scope**: this applies per site; the module default stays "keep
   nothing", with trace capture an opt-in setting.

## 2. Level 1 assertions (deterministic, run on every change)

Each is pass/fail, no model needed. A = PHPUnit kernel test (belongs in the
module regardless of ai_eval); B = needs the live assistant (ai_eval
agent-mode or `aim:chatbot-eval`).

Safety-critical (a failure is a leak):
1. A: `recall()` never returns an untrusted fact unless asked.
2. A: `recall()` never returns a retired or expired fact.
3. A: a `scope=user` fact is never returned to a different user without the
   role-visibility matrix granting it.
4. A: a `scope=entity` fact is returned only when the viewer can view the
   referenced entity.
5. A: a restricted literal is never substituted for a viewer outside its
   audience.
6. A: `[literal:key]` tokens are fully replaced (no raw token in output).
7. B: the anonymous visitor cannot reach the chat API (permission off,
   block restricted).
8. A: no fact text or query text appears in log output with the
   `log_query_text` option off.

Behavior:
9. B: the assistant does not call the save tool before the visitor confirms.
10. B: it calls `aim_literal` for a question whose answer is a stored
    literal, and does not for one that is not.
11. B: no internal IDs (fact IDs, UUIDs) appear in the answer.
12. B: it answers "I don't know" style (no invented fact) when recall is
    empty.
13. A: every write path runs Guardrails (Drush, form, chat tool, MCP tool).
14. A: a rejected write leaves no partial fact.

Pass-rate gate: 100% for 1-8 and 13-14; 1-2 misses tolerated on 9-12 until
the baseline is known (product decision, set after the first run).

## 3. Labeling sheet (for Nik, about 30 items)

Source: real assistant answers to questions you write or pick, run on the
demo data. Binary label first, one-line reason, optional failure mode.

| # | Question | Assistant answer | Good? (Y/N) | Why (one line) | Failure mode (free text) |
| --- | --- | --- | --- | --- | --- |
| 1 | | | | | |

Rules: judge the answer, not the style; N if wrong, unsupported by the
site's facts, leaks something, or is confidently wrong; note "missing" when
the answer was right but left out something it should have given. Failure
modes build up as you go (examples to start: wrong literal, invented fact,
saved without confirming, refused a valid question, leaked restricted value).
Aim for a rough balance: include at least 10 items you expect to be bad.

What the labels are used for: (a) a real gold set to replace the
Claude-drafted blind set; (b) measuring the grounded check, the verifier and
any LLM judge against you (precision and recall separately, not raw
agreement); (c) deciding the pass-rate gates above.

## 4. Data-flow ledger (what went in, what came out, to and from whom)

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
| Extraction | Source text | Candidate facts | amazeeio (hosted) | yes | facts only; passage not stored |
| Consolidation, verifier, grounded check | Fact text pairs, source text | Decision, score | Jev (hosted) | yes | decision in fact fields |
| LLM judge (ai_eval) | Question, answer, ground truth | Score | judge provider (to choose) | depends | eval result entity |
| Review trace (proposed) | Whole turn + versions | none | none | no | trace store, 30-day retention |

Gap this exposes: the same question travels to two or three hosted
providers per turn (chat, finder, grounded check). The retention and
disclosure decisions in section 1 should be made per hop, not per feature.

## 5. The failure loop (what went wrong, how do we repeat it, how do we fix it)

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

What this adds to the build: trace capture with version fields, a replay
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
in TODO.md (Governance) is built. Source: Oracle's "enterprise brain" article
asks the same question; TODO.md has the build item.
