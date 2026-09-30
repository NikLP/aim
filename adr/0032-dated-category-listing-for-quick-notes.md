# ADR-0032: Category-and-date listing for quick notes (a non-vector "list" path)

**Status:** Proposed 2026-10-01 - design only, nothing built.
**Date:** 2026-10-01

## Context

Discussed as a possible `event` scope for a personal diary: short notes
("getting hair done on Tuesday") punched in quickly, often from a mobile
app, with no nodes involved - everything lives in `aim_fact`. The
question was whether that needs a new scope.

It does not, today. What the use case needs is **capture with a type and
a date, and retrieval by type and date**, none of which is a scope
concern:

- `scope=user` already gives per-person ownership and privacy (the
  diary's main requirement).
- `category` (an `entity_reference` to the `aim_category` vocabulary,
  unlimited cardinality) is the natural "type of thing" tag, chosen by
  the user at capture time instead of guessed by an extractor.
- `asserted` (timestamp) already carries a date. Its description
  ("an earlier real-world date") only contemplates past dates, but
  nothing in `remember()` rejects a future one.
- `entity` scope needs a referenced Drupal entity (ADR-0027) and `case`
  scope has no access control yet, so neither fits a node-less personal
  diary.

What is actually missing:

1. **Retrieval.** `recall()` takes free text and returns nearest
   meaning. "Everything tagged Appointment, soonest first" is a filter
   plus a sort, not a meaning match, and an embedding call is wasted on
   it.
2. **Capture.** `aim_tool`'s `AimRemember` exposes neither `category`
   nor `asserted`, so a mobile client cannot set either.
3. **Vector-side filtering**, if semantic recall should ever be narrowed
   by category or date: neither field is in `aim_vector_index`.

**Provider filter support, checked 2026-10-01 against
`ai_vdb_provider_mariadb`'s `processConditionGroup()`:**

- Single-valued fields (`asserted`): the condition's operator is passed
  straight into the SQL, so `>=` and `<=` as two conditions work.
  `BETWEEN` does not (values are formatted as a parenthesized list).
- Multi-valued fields (`category`): only `=`, `!=`, `IN`, `NOT IN`,
  through a join table. "Has category X" is `=`, so it works.
- A condition on a field that is **not in the index** is dropped with a
  messenger warning, not an error. Unfiltered results come back
  silently. Any filter added to `recall()` must be paired with indexing
  its field.

## Decision

1. **No `event` scope.** Model diary notes as `scope=user` facts with a
   `category` and an `asserted` date. Revisit a dedicated scope-type
   plugin ([ADR-0028](resolved/0028-scope-type-plugin.md)) only if a
   need appears that category plus date cannot express: grouping several
   facts under one occurrence (see [ADR-0030](0030-fact-groups.md)'s
   `group` field first), auto-expiry at an event's end, or
   attendance-based visibility.
2. **Add a non-vector list method** to `AimMemoryManager` (working name
   `listFacts()`): a plain entity query on `aim_fact`, filtered by
   `scope`, `user`/`subject`, `category` (term IDs), an `asserted`
   range, and excluding retired (`expires`) and, by default, untrusted
   facts, sorted by `asserted`. No embedding call, no index change.
   Exposed through a Drush command and an `aim_tool` Tool, each
   permission-gated and access-checked exactly as `recall()` is.
3. **Capture by term ID.** `AimRemember` (Tool API) gains `category`
   (array of term IDs, an entity reference in the Tool's schema) and
   `asserted` (an ISO 8601 date-time, the format a mobile client
   sends). `remember()` today resolves category *names* via
   `resolveCategoryTerms()`; it should accept term IDs at this boundary,
   which removes duplicate or misspelled term creation. Term selection
   in admin UI and console uses the `cshs` module (already in the repo)
   instead of free-text autocomplete.
4. **Vector-side filtering is a separate, later step.** If semantic
   recall should be narrowable by category or date, add `category`
   and `asserted` to `aim_vector_index` (integer attributes, BTREE per
   [ADR-0018](resolved/0018-index-subject-uid-with-btree.md)'s pattern),
   an update hook and reindex, and range/`=` conditions in `recall()`.
   Not needed for the quick-notes flow and deliberately not built with
   the list method.

## Consequences

- Cheap: the list method touches no index and no provider code, and is
  roughly a day of work including Drush/Tool wiring.
- The mobile capture path depends on decision 3; without it a client
  cannot tag or date a note.
- Decision 4 carries the silent-drop hazard above. Whoever builds it
  must add the index fields in the same change as the `addCondition()`
  calls, and a test that a filter on each field actually narrows results.

## Open questions

- **Future-dated facts.** Verify consolidation
  ([ADR-0005](resolved/0005-consolidation-algorithm.md)) and the
  extraction prompts do not treat a future `asserted` as invalid or
  supersede on it; update `asserted`'s field description to allow it.
- **Staleness.** A past appointment is still a true fact. Decide
  whether the list method hides facts whose `asserted` is in the past by
  default (an "upcoming" mode) or leaves that to the caller's range.
- **Does `state` earn its place?** The tri-state boolean is stored on
  the fact but nothing reads it (`recall()` only echoes it, no bulk
  actions, no UI in use), and it duplicates what the fact text already
  says, so the two can disagree. Its one stated purpose, an exact-match
  flag lookup with no vector search, is the same query `listFacts()`
  does. Plan: give `listFacts()` a `state` filter, and if no real
  consumer appears by the time it is built, deprecate the field (update
  hook removing it, like the `subject_uid` rename) instead of carrying
  it. Text stays canonical either way. `category` has no such problem:
  it carries information the text does not.
- **Term IDs across environments.** Term IDs are not portable between
  sites. Fine for one site's own clients, but worth noting if term
  IDs are ever baked into a shared recipe or demo seed.
