# ADR-0011: Extraction never guesses scope=user account matches

**Status:** Accepted
**Date:** 2026-09-10

## Context

ADR-0007 established that a `scope: user` fact must reference a real
Drupal account (`subject_uid`), and that `aim:extract` resolves this
"automatically per fact" by taking the model's own freeform `subject`
text (a name it read out of the source document) and matching it against
the accounts table.

A 50-fact synthetic benchmark file surfaced why that mechanism is wrong,
not just unreliable. The file was third-party narrative content about a
fictional person ("Alex Chen") who has no account on this site. The
extraction prompt's scope rubric only said `"user": specific to one named
person` - nothing about that person needing to be *this site's* user - so
the model correctly, per its instructions, classified 32 of 50 facts as
`scope: user`. Every one was then skipped by ADR-0007's resolution
failure. The failure mode is not "the name didn't happen to match" - it's
that a source document naming someone is no evidence at all that the
person is a site account, and asking the model's own extraction to also
decide account identity conflates two unrelated judgments: what the text
says, and who on this site it's about.

## Decision

`aim:extract` / `createFactsFromCandidates()` no longer attempts to
resolve a `scope: user` candidate's model-supplied `subject` text against
the accounts table at all. Instead:

- A new `--subject-uid` option (uid or username), same shape as
  `aim:remember`'s `--subject` and `aim:recall`'s `--subject-uid`, is
  resolved once per `aim:extract` invocation.
- If supplied, every candidate the model classifies as `scope: user` is
  attached to that one account - the model still decides *which*
  sentences are user-scoped, a human decides *whose* account they belong
  to.
- If omitted, every `scope: user` candidate is skipped, unconditionally -
  extraction never even attempts name-based matching.

This means one `aim:extract` invocation can only ever attach `scope: user`
facts to a single account, matching the shape of the source material this
command targets (one file, one document) - a source discussing several
different people's individual facts in one pass still has no path to
`scope: user` without multiple invocations, one per account.

## Consequences

- ADR-0007's own extraction paragraph is now historical - this is the
  mechanism it originally shipped with, before this ADR replaced it. Left
  as-is there (ADRs are a record of what was decided when), not edited in
  place.
- The 2026-09-10 synthetic benchmark file's 32 `scope: user` candidates
  remain correctly unaddressed unless a demo account is created and
  `--subject-uid` passed on a re-run - the point of the file was to
  exercise the pipeline's temporal-override/negation/multi-hop handling,
  which needs those facts actually saved to be testable at all.
- Same open gap as ADR-0007 flagged: `subject` as free text for `role`/
  `case` scope still has no equivalent "must resolve to something real"
  guard. Not addressed here.
- A discovery-skill or chatbot surface that wants to attach extracted
  facts to a *resolved-at-runtime* account (not known until the CLI
  invocation) still has no path through `aim:extract` - it would need
  `remember()`/`createFactsFromCandidates()` called with a uid already in
  hand, same as this ADR's mechanism, not a new one.
