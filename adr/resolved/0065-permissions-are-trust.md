# ADR-0065: Permissions are trust: check the model, not the person

**Status:** Built 2026-11-01 (see "Built" at the end). Amends
[ADR-0002](0002-governance-deferred-guardrails-mandatory.md)'s
addendum (how a new fact's trust starts), replaces
[ADR-0037](0037-transient-source-passages-for-grounding.md)'s
2026-10-18 and 2026-10-22 addenda (the trust policy and the grounded
check's promotion), and narrows
[ADR-0005](0005-consolidation-algorithm.md) (the RETIRE decision
and the merge distance check go). Pays for
[ADR-0064](../0064-revisions-for-fact-edits.md)'s revisions.
**Date:** 2026-11-01

## In plain terms

Before: a new fact's trust is decided by up to five rules (the writer's
trust permission, then a rule per entry channel, per scope, a site
default and the older `default_trusted` switch), and a decision model can
then promote or demote it (the grounded check's off/shadow/enforce
modes). Consolidation can retire a fact it judges nonsensical, and checks
a merged sentence four ways.

After: a writer who may trust facts in a scope writes trusted facts there;
anyone else's wait for review. That is the whole rule. Model checks stay
only where a model rewrote what a person said and nobody saw the result:
the grounded check can send an extracted fact to review but never trusts
one, and a merge keeps the two checks that were measured to catch
different faults.

Gains: one trust rule a site sets with permissions it already
understands; two settings trees, one model decision and one embedding
check gone; every remaining model check either
de-duplicates or can only withhold trust. Costs: no per-channel or
per-scope exceptions for a writer the permissions trust; a junk fact that lands next to a real one is no longer
retired by the classifier.

## Context

Each failure found while building the write path got its own gate,
setting and threshold. A review on 2026-11-01 walked every check a fact
passes and sorted them: does it judge the *person* (whether they may say
this), or the *model* (whether a model's rewording of what they said is
faithful)? Permissions already answer the first question for every
write path: `create {scope} aim facts` says who may write, `trust {scope}
aim facts` and `review aim facts` say whose word counts. The checks that
judged the person again were:

| Check | In use on this site | Evidence |
| --- | --- | --- |
| Trust policy channel/scope/default rules, `default_trusted` | `chat: hold` only | Visitors lack the trust permission, so their facts wait for review under the permission rule alone |
| Explicit `trusted` argument on `remember()` | No shipped caller passes it | - |
| Grounded check promoting a fact | Shadow mode, never enforced | Promotion hands out the trust the permissions withheld |
| Classifier `RETIRE` | Never chosen on real data | It only sees a fact with a close neighbor, so as a junk filter it works by chance |
| Merge distance check (`merge_max_distance`) | Off (0.0) | Measured 2026-10-01: does not separate faithful from lossy merges |

Measured the same day on the live decision model (`subq-snap-1.1`), the
checks on the other side earn their place: on the hard merge set the
verifier at 0.9 lets no bad merge through, and the grounded check on
merges caught the one fault (an invented causal link) the verifier
scored highest; on the grounding set the grounded check at 0.7 held no
faithful fact and passed one of 25 bad ones. Both only judge model
output.

## Decision

1. **Starting trust is the writer's permission.** A new fact starts
   trusted when its writer holds `administer aim`, `review aim facts` or
   `trust {scope} aim facts` (`AimTrustPolicy::canApprove()`, unchanged),
   and untrusted otherwise. `trust_policy` (`default`, `channels`,
   `scopes`), the `hold` value and `default_trusted` are removed. A site
   that wants no review grants the trust permission alongside create; a
   site that wants review for some writers grants create alone.
2. **The writer is a real account on every path.** Chat and the ingest
   form are the signed-in user. An MCP caller is its token's user, with
   permissions narrowed to the token's OAuth scopes: `aim:remember` grants
   no trust permission, so a non-admin agent's writes wait for review
   (the case channel rules were kept for, covered by permissions).
   Drush takes `--uid` and refuses to write without it (the uid-0 write
   ban BACKLOG.md's design review agreed), switches to that account
   (core's `account_switcher`) and runs the same create and trust checks
   as the web paths: no Drush special case. This is consistency, not
   authentication: anyone on the shell can name any uid, and shell access
   already reaches everything (`sql:query`, `php:eval`), so the shell is
   trusted as a whole. What the check adds is a consistent audit trail (no
   trusted fact authored by an account without trust permission), a
   refused write for a scope the account cannot write, and a way for a
   shell caller to send a fact to review by writing as a non-reviewer.
   The human check for an agent on the shell (Claude Code's `aim-memory`
   and `aim-discovery` skills, which gain `--uid`) is the agent's own tool
   permission prompt, where the person sees the command and its text. The
   grounded check still applies to `aim:extract`, since a model wrote
   those facts.
3. **`remember()` loses its `trusted` argument**, and with it the
   "honored only for a reviewer" rule. No shipped caller sets it.
4. **The grounded check can demote, never promote.** It runs when the
   writer sends the original words (`source_text`, or the document being
   extracted) and a grounding model is configured in
   `activities.grounding`. `grounding_mode` has three values: `off`
   (the shipped default; for a site with no decision model, one that
   will not send source text to its model, or a provider outage, when
   `demote` would send every checked fact to review), `record` (today's
   `shadow`: the score is stored, nothing else changes; the way to try a
   new grounding model on real traffic) and `demote` (a score below the
   threshold, or a check that fails to run, saves the fact untrusted for
   review; otherwise the starting trust stands). Promotion goes. The
   score is stored for reviewers in `record` and `demote`.
5. **Consolidation works on trusted facts only.** The neighbor search
   filters on `trusted = 1` and an untrusted fact is not queued; trusting
   one (bulk action or edit form) queues it then. This closes a gap found
   in this review: today an untrusted fact can be merged with a trusted
   one, and the merged fact is untrusted (`createMergedFact()` takes the
   AND), so recall loses the trusted fact until someone reviews the
   merge.
6. **The classifier decides ADD, UPDATE or NOOP.** `RETIRE` is removed
   from the decision, the prompt and the eval pairs set. A site that sees
   junk in review adds a guardrail to `aim_write_guardrails`, which runs
   on every write, not only on facts with a close neighbor.
7. **A merge keeps three checks:** guardrails on the merged text, the
   verifier (this site 0.9) and the grounded check on the merge (in
   `demote` mode a low score keeps both facts, as a failed verifier does;
   in `record` mode it is only logged).
   `merge_max_distance` and its embedding comparison are removed.

## Bringing a cut back

Each has a trigger in BACKLOG.md ("Write-path cuts, ADR-0065"):

- **A per-channel exception** if an agent holding a trust-granting token
  is shown writing something it was manipulated into. It needs the
  channel stored on the fact, set by code: `source` will not do, since
  Drush and the ingest form let the caller type it (the 2026-10-19 build
  rejected it for that). The stored channel is planned with the audit
  fields (TODO.md "Record who trusted a fact").
- **Model promotion** only by a new decision: it is the model granting
  trust a permission withheld.
- **A junk filter**: a guardrail, not the classifier.

## Consequences

- Settings removed: `trust_policy`, `default_trusted`,
  `merge_max_distance`; `grounding_mode`'s `shadow` and `enforce` are
  renamed `record` and `demote`. An update hook does
  it (or a reinstall, while PoC). The settings form's trust fields go.
- This site moves to `demote` once built: the grounded check on merges
  only adds the protection measured above in that mode.
- Code removed: `AimTrustPolicy::resolve()`'s rule chain and `values()`'s
  promote branch, `CHANNEL_*` as trust inputs (they remain as `source`
  labels), `honoredTrusted()`, the `RETIRE` branch, `verifyMerge()`'s
  distance half with `embedText()`/`cosineDistance()` if nothing else
  uses them.
- Tests to rewrite: `AimGroundingTest` (policy and promotion cases),
  `AimToolPermissionsTest`'s held-fact case, consolidation tests with
  RETIRE.
- `AimRememberInvokerSubscriber` keeps only the `source` label;
  tool#3583125 drops from governance plumbing to provenance.
- A visitor's fact that repeats a trusted one now waits in the review
  queue instead of being retired as a NOOP before anyone sees it, since
  untrusted facts are not consolidated. Accepted: letting the classifier
  retire drafts would let a misread correction vanish unreviewed. The
  reviewer trusts it (consolidation then retires it as a duplicate) or
  deletes it. Trigger to revisit in BACKLOG.md.
- On this site chat visitors see no change (their facts already wait,
  as `hold`) and admin writes stay trusted, including the preflight's
  MCP and widget checks (both run as uid 1). What does change: the site
  sets `default_trusted` on, so today a non-reviewer's MCP, ingest or
  Drush write starts trusted; after the build it waits for review, and
  Drush needs `--uid`.
- The `aim_demo_library` recipe's comments and README (`hold`, "a
  reviewer's own fact starts trusted (ADR-0037)") and demo/README.md's
  `default_trusted` and `grounding_mode` rows change with the build.
- CLAUDE.md decision 3, ADR-0002's and ADR-0037's status lines and the
  settings docs in DEVELOPING.md are rewritten when this is built.

## Built (2026-11-01)

As decided, with these specifics:

- **Settings change by update hook**, `aim_update_10012` (removes
  `trust_policy`, `default_trusted` and `merge_max_distance`, renames the
  modes, drops the RETIRE paragraph from the consolidation prompt as
  shipped or as saved by the form). Chosen over reinstalling this site:
  the amazee.io keys are stored in config and four OAuth consumers are
  registered, so a reinstall would have lost credentials and connectors,
  and the recipe's thresholds already matched the live values.
- **The add form follows decision 1 too**: `trusted` defaults to the
  current user's trust permission in the scope (`AimFact::getDefaultTrusted()`),
  and only a writer who may trust can change the checkbox
  (`AimFactAccessControlHandler::checkFieldAccess()`). Before, anyone with
  create access could tick it.
- **"A grounding model is configured" is literal**: with no model in
  `activities.grounding` the check does not run (no score, starting trust
  stands), in `demote` as in `record` (`AimSettings::isGroundingOn()`).
- **Queueing**: `AimHooks::factInsert()` queues only trusted facts;
  `factUpdate()` queues a fact when `trusted` goes from off to on (Trust
  action or edit form). The queue worker and `consolidateFact()` skip a
  fact untrusted since it was queued, and the neighbor search filters on
  `trusted` in the index and again on the loaded entity.
- **Drush** single writes go through `rememberBatch()` with the `--uid`
  account as writer, so the create check is the ingest form's; a refused
  `--uid` (missing, 0, unknown or blocked) prints an error.
- This site moved to `grounding_mode: demote` the same day.
- **No `AimTrustPolicy` service.** With the rule chain gone it held only
  `canApprove()`, a copy of the access handler's trust operation, so
  `canApprove()` moved onto `AimFactAccessControlHandler` (the trust
  operation and the method share one check) and the demote rule into
  `AimRememberService::trustValues()`. The remember service, the
  `trusted` default value callback and the field access all ask the
  handler.
