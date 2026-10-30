# ADR-0064: Revisions for fact edits

**Status:** Proposed 2026-10-30 - design only, nothing built. Revisits the
part of [ADR-0002](resolved/0002-governance-deferred-guardrails-mandatory.md)'s
addendum that left `aim_fact` non-revisionable; keeps its decision
(`trusted`, not Content Moderation) unchanged.
**Date:** 2026-10-30

## In plain terms

Before: an admin who edits a fact's text on
`/admin/content/aim-facts/{aim_fact}/edit` overwrites it. The old wording
is gone, nothing records who changed it or when, and the fact keeps its
trust and its grounding score although both were judged against the old
wording.

After: an edit that changes the text saves a new revision, with author,
time and a log message, and core's revision history, revert and delete
pages come with it. Everything else about a fact (trust, retirement,
supersede, expiry) still changes in place. A fact whose *world* changed
("now closed Tuesdays") is still a new fact that supersedes the old one;
a revision is for correcting the wording of the same fact.

Gains: an audit trail for manual edits, the one write path that has none;
an answer to "who changed this?"; old wording recoverable. Costs: one
revision table; erasure (BACKLOG's tombstone sketch) must clear old
revisions too; one more rule for editors to learn (revise vs. add a new
fact).

## Context

ADR-0002's addendum (2026-09-28) rejected Content Moderation for the
draft-to-trusted gate and chose the `trusted` flag. Revisions were
dropped with it, because Content Moderation needs them, and the addendum
counted making `aim_fact` revisionable as part of CM's cost. Revisions on
their own were not decided against. [ADR-0010](resolved/0010-drupal-native-agent-memory-rationale.md)
lists "Audit trail: Revisioning" among the reasons to build on Drupal at
all, and TODO.md's "Edit history for facts" (raised 2026-10-11) proposed
them alone.

Concerns raised when this was revisited (2026-10-30), checked:

- **Search API would have to join across tables.** That was Content
  Moderation's cost, not revisions': `moderation_state` lives in its own
  entity, so filtering the index on it needed a processor (ADR-0002's
  addendum). For a revisionable, non-translatable entity, core keeps every
  current value in the base table and copies revisionable fields into the
  revision table (`DefaultTableMapping::create()`). Views and entity
  queries keep reading `aim_fact`, Search API indexes the default revision
  only, and `recall()` filters on the vector table's own attributes. No
  join appears anywhere.
- **`trusted` already does the governance job.** It still does; revisions
  record edits, they do not gate retrieval.
- **Data privacy.** Real: revisions keep old text. See decision 5.

The schema change itself (an update hook running
`updateFieldableEntityType()` on a populated table) is the expensive
step, and is nearly free now: CLAUDE.md's PoC rule is reinstall rather
than migrate, and this site is rebuilt from the `aim_demo_library` recipe.
It gets harder once real data exists.

## Decision

1. **`aim_fact` becomes revisionable, not translatable, with no Content
   Moderation.** Revision metadata (`revision_user`, `revision_created`,
   `revision_log_message`) through core's `RevisionLogEntityTrait`; the
   revision UI from core's `RevisionHtmlRouteProvider`
   (`VersionHistoryController`, `RevisionRevertForm`,
   `RevisionDeleteForm`), gated on `administer aim` like edit.
2. **Revise vs. supersede.** A revision corrects how the same fact is
   worded (a typo, clearer phrasing, a wrong word). A change in what is
   true is a new fact that supersedes the old one, as consolidation
   already does. The edit form says so beside the text field.
   Consolidation keeps its supersede edge: a merge is two facts becoming
   one, not an edit.
3. **A new revision only when `text` changes.** Set in
   `AimHooks::factPresave()` by comparing against `getOriginal()`, like
   the subject check there. Trust/untrust, retire, `aim:expire`, the
   grounded check and consolidation save in place and leave no revision,
   so the history is edits only.
4. **Only `text` and `grounding_score` are revisionable.** The score
   belongs to the wording it was measured against, so each revision keeps
   its own, and a new revision clears it (the BACKLOG grounding-score fix,
   which this decision absorbs). `trusted`, `retired`, `expires`,
   `superseded_by`/`superseded_by_reason` are current state; scope,
   subject, `user`, target and `category` are identity, and changing one
   is a different fact. `trusted` is not revised: a reviewer's approval
   applies to the fact as it stands, and `trusted_by`/`trusted_at`
   (TODO.md) remain the answer to "who approved this", not revisions.
5. **Erasure covers revisions.** Deleting a fact already deletes its
   revisions (core). The planned `aim:erase` tombstone (BACKLOG, "blanks
   `text` on the target and on every fact linked to it") must also delete
   the fact's non-default revisions, or blank their `text`, or erased
   wording survives in history. Folded into that item's design; no
   separate mechanism.

## Consequences

- One new table (`aim_fact_revision`; a non-translatable entity needs no
  revision data table), the update hook (or a reinstall while PoC), and a
  check of every View on `aim_fact` afterwards: the `subject_uid` rename
  broke one silently.
- The edit form gains a revision log field; `trusted` keeps whatever the
  editor had, as now (an `administer aim` editor could trust it anyway).
- Search API, the vector table and `recall()` are unchanged: they see the
  default revision only.
- The admin listing and review queue are unchanged.
- Not decided here: showing a fact's edit history in recall output or to
  models. Nothing reads old revisions except the revision pages.
