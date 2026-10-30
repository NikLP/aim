# ADR-0059: Single-valued category, filterable in recall, set on ingress

**Status:** Accepted 2026-10-30 for decision 1 (Nik: if `category` stays,
it is single-valued for the foreseeable future); decisions 2-5 proposed.
Nothing built. Takes over the category capture and filter parts of
[ADR-0032](0032-dated-category-listing-for-quick-notes.md).
**Date:** 2026-10-14

## Before and after

**Today:** `category` is a free-tagging field: an `entity_reference` to the
`aim_category` vocabulary, unlimited cardinality. A fact can carry any number
of tags, nothing sets them automatically on most write routes, and recall
cannot filter by them (a multi-value field lives in its own join table and is
not in the vector index, see [ADR-0032](0032-dated-category-listing-for-quick-notes.md)).

**After:** a fact has at most one category, a real column on the `aim_fact`
table. It is an indexed attribute on `aim_vector_index` with a BTREE, the same
way `user` is ([ADR-0018](resolved/0018-index-subject-uid-with-btree.md)), so
recall can narrow by category inside the vector query ("only policy facts")
instead of searching everything and filtering afterward. Every write route
labels the fact on the way in.

**Gains:**

- Recall can be pre-filtered by category, which is cheaper and more precise
  than post-filtering. This is the practical payoff of labelling on ingress.
- One label per fact forces one decision at capture. A classifier or a user
  picking from a short list does that more reliably than picking a set.
- Lighter: no join table, simpler listing queries (ADR-0032's category-and-date
  list becomes a plain column filter).
- Labelled facts make the eval sets easier to slice (accuracy per category).

**Costs:**

- A fact cannot sit under two categories. A quick note that is both
  "Appointment" and "Family" must pick a primary one.
- An update hook has to convert the field (copy the first term of each fact
  into the new single column). Cheap while this is a PoC with synthetic data
  ([CLAUDE.md](../CLAUDE.md), "Cross-cutting conventions"); costs more once
  real data exists, so doing it early is part of the point.
- Existing facts with several tags lose all but one.

## Context

Prompted by Cole Medin's second-brain schema (2026-10-13), which labels
documents with a project and domain on ingress. aim is "sort of" doing this:
`remember()` accepts `category`, but extraction and the chat and MCP tools do
not reliably set it (not verified route by route; check before building).
Free tagging was the original intent, a way for a person to mark their own
notes. That use is real but small, and it is the one a single value costs.

## Decision (proposed)

1. **Cardinality 1.** Change `category` to a single-valued `entity_reference`
   on the `aim_category` vocabulary, stored as a base-table column. Update
   hook copies each fact's first term across (lowest weight, then lowest term
   ID, so the result is deterministic).
2. **Index it.** Add `category` to `aim_vector_index` as an attribute and to
   `AimHooks::BTREE_INDEXED_COLUMNS`. Re-run ADR-0018's probe method first to
   see whether the optimizer pre-filters it or post-filters it (a category
   holding a large share of the table will post-filter, as a big user does,
   so the 5x over-fetch applies there too).
3. **`recall()` gains a `category` filter** and the `aim_recall` tool an
   optional `category` input. Build it as a generic seam, not a
   category-only argument (taken from retired
   [ADR-0030](resolved/0030-fact-groups.md), 2026-10-30): `recall()` takes
   `extra_filters` (indexed attribute name => value) and adds each as a
   condition on the vector query, honored only for attributes present on
   `aim_vector_index`, so a submodule's own indexed field filters the same
   way with no change to core. Post-filtering is not an option: it breaks
   the result limit and ranking. A filter on a field missing from the
   index is dropped silently by the provider (ADR-0032's Context), so the
   seam must refuse or log an unknown attribute, and each filter needs a
   test that it actually narrows results.
4. **Label on ingress, per route:**
   - Extraction: the model picks one term from the existing vocabulary or
     none. It may not invent terms (an invented term is a new tag nobody
     reviewed). Unmatched means empty, not a guess.
   - Chat and MCP `aim_remember`: expose `category` (ADR-0032 already lists
     this as missing) and let the assistant set it from the vocabulary.
   - Ingest form and Drush: a category select / `--category`.
5. **Free tagging, if still wanted, is a scope concern, not core.** A scope
   plugin may declare its own multi-value `tags` base field through
   `getBaseFieldDefinitions()` ([ADR-0028](resolved/0028-scope-type-plugin.md)),
   so a personal-notes scope can tag freely while core `category` stays
   single and filterable. Do not build `tags` until a real need shows up.

## Open questions

- Does the vocabulary stay admin-managed only (recommended: yes, a fixed short
  list keeps classification reliable), or may users add terms?
- Is "no category" allowed forever, or does a required category per scope
  make sense (`aim_scope` `settings` could carry it)?
- How is a wrong label corrected? Label drift is the neglect risk here:
  suggest the eval failure loop counts mislabelled facts as a failure mode
  ([eval/DRAFT-eval-plan.md](../eval/DRAFT-eval-plan.md), section 5).

## Not decided here

Hybrid keyword and semantic search stays parked in
[ADR-0036](resolved/0036-memory-algorithm-appraisal.md) until a measured recall miss
shows it is needed. Category filtering and `literals` cover the main cases it
would help with (exact names and identifiers).
