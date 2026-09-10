# ADR-0007: User-scope facts must reference a real Drupal account

**Status:** Accepted
**Date:** 2026-09-09

## Context

`subject` was always documented as meant to hold a uid for `scope: user`
facts, but it was a free string that merely happened to contain one -
nothing stopped `"1"` and `"Nik"` from addressing the same person without
ever matching. This surfaced as a real bug, not a hypothetical: two
known-duplicate facts about the same person (worded differently) never
got compared by consolidation (ADR-0005), because neighbor search scoped
strictly by the `subject` string and one fact used `1` while the other
used `Nik`.

## Decision

A `scope: user` fact must be about an account that actually exists on
this site. New base field `subject_uid` (`entity_reference` to `user`,
cardinality 1) is the single source of truth for who a user-scope fact is
about; `subject` (the free string field) is now only meaningful for
`role` (a role machine name) and `case` (a case ID) scope.

- `aim:remember` resolves `--subject` (a uid or username) to a real
  account when `--scope=user`, and refuses to save if it doesn't resolve
  - reject rather than silently corrupt, same posture as `--state`'s
    validation.
- `aim:extract` does the same resolution automatically per fact. If it
  doesn't resolve, that candidate is skipped rather than saved with a
  broken/unaddressable subject - a real, deliberate behavior change:
  extraction can now silently drop a correctly-extracted fact about a
  real person who simply has no account yet. Accepted consequence, not a
  bug to fix later.
- Consolidation's neighbor search and `recall`'s optional filter both
  switched from an indexed `subject` condition to an over-fetch
  (5x/4x the limit) plus PHP-side exact `subject_uid` equality filter,
  since `subject_uid` isn't (and doesn't need to be) an indexed Search
  API attribute.
- `recall` gained a separate `--subject-uid` option rather than
  overloading `--subject` with scope-dependent meaning - a CLI flag that
  means two different things depending on another flag's value is a
  footgun worth avoiding even at the cost of one more option.

## Consequences

- The 5 existing `scope: user` facts on this site (all genuinely about
  the same person) were migrated by hand: `subject_uid` set, `subject`
  string cleared.
- Verified end to end against the bug this was built to fix, not just a
  synthetic test: the two known-duplicate facts now correctly appear as a
  consolidation pair (a real ambiguous-band score) and the classification
  call returned UPDATE, merging both statements into one surviving fact.
- `aim_eca`'s three plugins (`FactWrite`, `FactQuery`, `FactState`) all
  needed updating to resolve subject to a real account at execution time
  when scope is user, via a new shared `AccountResolverTrait` - not yet
  exercised through an actual ECA model, since `eca` isn't enabled on
  this site (same standing caveat as the rest of `aim_eca`).
- This is the concrete instance of a broader, still-open idea: `subject`
  as free text invites exactly this kind of addressing drift for *any*
  scope, not just user (see CLAUDE.md's "Ideas raised, not designed",
  the typed/taxonomy-facts idea) - this ADR only closes the gap for the
  identifier half of `scope: user`, not the general case.
