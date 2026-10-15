# DRAFT: eval plan (assertions, labeling, data retention)

Status: draft for Nik's approval, 2026-10-13. Not built. Nothing here changes
code or config. Context: Hamel Husain, "Your AI Product Needs Evals".

## 1. Data requirements: what evals need versus what we keep today

Updated 2026-10-14 after ADR-0060 (built): `logger.channel.aim` is still
IDs only, but model calls tagged `aim_extract`, `aim_consolidate`,
`aim_consolidate_verify`, `aim_consolidate_merge` and `aim_ground` are now
recorded by `ai_logging` in `ai_log`, prompt and response text included,
with `aim_run_id` and `aim_duration_ms` in `extra_data`. Retention is
`ai_logging`'s global pruning (10000 entries, 90 days). Untagged calls
(embeddings, the recall query) are not recorded. Stealth mode (blank the
text) and the PII gate (ADR-0056) are not built. So the "keep nothing"
default no longer holds for model calls; what is still open is the
assistant's chat turns and who may read `ai_log`.

| Data | Needed for | Today | Proposed class |
| --- | --- | --- | --- |
| Visitor question + assistant answer + tools called | Level 2 trace review, labeling | Not kept (assistant history is a private tempstore pool); unverified whether the chat call carries an `aim_*` tag, if not it is not in `ai_log` | Review trace: keep, short retention, restricted access |
| Recall results (fact IDs, scores) for that turn | Debugging bad answers | IDs/scores only, verbose log | Keep with the trace (IDs only, no text) |
| Fact text | Judging groundedness | In `aim_fact`, and in logged consolidation/merge prompts | No further copy |
| Source passage a fact was extracted from | Grounded-check replay | Now in `ai_log` inside the `aim_extract` / `aim_ground` prompts (ADR-0060 reversed ADR-0037's "nothing stored"), pruned at 90 days / 10000 entries | Decide: keep (eval value) or stealth mode before real data |
| Model, provider, tokens, duration, run ID per model call | Comparing runs | In `ai_log` (columns plus `extra_data`) | Keep, already built |
| Prompt/config version per call | Comparing runs | Prompt text is in the logged prompt; settings version not recorded | Add a version field |
| Which fact a model call concerned | Joining a bad call to its fact | Not recorded (ADR-0060 consequence) | Pass fact ID as input debug data at each call site |
| Anonymous or real-user identity | Nothing in evals | uid on writes | Do not copy into traces; store a salted hash or nothing |

Decided 2026-10-14 (Nik): `ai_log` retention stays 90 days / 10000 entries;
`ai_log` access sits behind a system permission (no new one needed); stealth
mode stays off, as already noted in ADR-0060 decision 4 and DEVELOPING.md
"Model-call logging". Still open: chat-turn capture and retention, real
versus synthetic datasets, disclosure.

Original questions (1 to 3 are answered above):
1. **Retention**: `ai_log` is already 90 days / 10000 entries (ADR-0060).
   Decide if that is right for text-bearing rows, and set a shorter figure
   (suggest 30 days) for assistant chat traces, deleted unless promoted into
   a dataset (promotion = a human reviewed and redacted it).
2. **Who may read traces and `ai_log`**: suggest a restricted permission,
   not `administer aim`; check who can view the `ai_logs` listing today.
3. **Real versus synthetic**: suggest eval datasets are synthetic or
   redacted-by-a-human only; raw real traces are review material, never a
   dataset or a prompt to a hosted judge. ADR-0060 says decide stealth mode
   and the parked PII gate before real data goes in; this is that decision. Hosted judges see only data that
   is already allowed to reach hosted models today (this site: synthetic
   demo data only).
4. **Disclosure**: if real visitors' chats are kept for review, the privacy
   notice must say so (ties to the parked ADR-0056 and the Article 50
   disclosure TODO).
5. **Module default**: ADR-0060 made model-call text logging the
   default, not opt-in. Confirm that stays the default for a fresh install,
   or flip it to stealth and let eval-minded sites opt in.

## 2. Level 1 assertions (deterministic, run on every change)

Each is pass/fail, no model needed. A = PHPUnit kernel test (belongs in the
module regardless of ai_eval); B = needs the live assistant (ai_eval
agent-mode or `aim:chatbot-eval`). "Test exists" notes come from reading the
test names in the 2026-10-14 commits; I have not run them or read their
bodies, so confirm each really asserts the point.

Safety-critical (a failure is a leak):
1. A: `recall()` never returns an untrusted fact unless asked. Test exists: `AimRecallFilterTest::testUntrustedExcludedByDefault`.
2. A: `recall()` never returns a retired or expired fact. Test exists for retired (`testRetiredSkipped`); expired is fixed 2026-10-14 (`testExpiredNotRecalledBeforeSweep`).
3. A: a `scope=user` fact is never returned to a different user without the
   role-visibility matrix granting it. Test exists: `testUserScopeRoleVisibility`, `testUserFilter`.
4. A: a `scope=entity` fact is returned only when the viewer can view the
   referenced entity. Test exists: `AimScopeBehaviorTest::testEntityScopeFollowsTargetAccess`.
5. A: a restricted literal is never substituted for a viewer outside its
   audience. Test exists: `AimRecallLiteralTokensTest::testTokensResolvePerViewer`.
6. A: `[literal:key]` tokens are fully replaced (no raw token in output). Partly covered by the same test file.
7. B: the anonymous visitor cannot reach the chat API (permission off,
   block restricted).
8. A: no fact text or query text appears in `logger.channel.aim` output
   with `log_query_text` off. Reworded after ADR-0060: model-call text is
   now deliberately in `ai_log`, so the assertion is only about the aim
   channel. Added as `tests/src/Unit/AimCallTagsTest.php` (code tags versus the installer list; passes): every aim model call carries a tag in
   `ai_logging.settings:prompt_logging_tags` (a missing tag silently drops
   the call from the log, so this is a real regression risk).

Behavior:
9. B: the assistant does not call the save tool before the visitor confirms.
10. B: it calls `aim_literal` for a question whose answer is a stored
    literal, and does not for one that is not.
11. B: no internal IDs (fact IDs, UUIDs) appear in the answer.
12. B: it answers "I don't know" style (no invented fact) when recall is
    empty.
13. A: every write path runs Guardrails (Drush, form, chat tool, MCP tool).
    Partly covered: `AimToolPermissionsTest`, `AimIngestTest`.
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

## 4. Data-flow ledger and failure loop

Moved to [NOTES-data-flow-and-failure-loop.md](NOTES-data-flow-and-failure-loop.md).

## 5. Findings from the 2026-10-14 checks

- Chat turns are not in `ai_log`. `ai_logging.settings:prompt_logging_tags`
  lists only the five `aim_*` tags and ai_logging matches tags exactly; the
  assistant's calls are tagged by `ai_agents` / `ai_assistant_api` (its
  thread prefixes are `ai_agents_thread_` and `ai_assistant_thread_`), so
  they are not captured. Not confirmed at runtime which tags a live turn
  carries. Capturing them needs either their exact tags added to the list
  or an aim-owned tag set on the assistant's call.
- Past-due facts were recalled until the hourly expiry sweep ran. Fixed
  2026-10-14: `recall()` now withholds them
  (`AimRecallFilterTest::testExpiredNotRecalledBeforeSweep`).
- Existing tests: the full aim and aim_tool suites pass (52 tests, 564
  assertions, deprecations only) after reading the recall, scope and
  expiry test bodies.
