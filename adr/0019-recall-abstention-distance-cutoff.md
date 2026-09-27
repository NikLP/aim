# ADR-0019: Recall abstention via a distance cutoff, and what bounds recall quality

**Status:** Accepted - chatbot cutoff built 2026-09-19; the follow-ups
under "Not built" are open. The retired-slot finding is resolved by
[ADR-0022](0022-exclude-retired-facts-from-vector-index.md) (2026-09-26)
**Date:** 2026-09-20 (measurements taken 2026-09-19)

## Context

`aim_chatbot:recall` had one abstention rule: zero rows returned meant
"No relevant facts found." Any non-empty result set was formatted as
"Relevant facts:" and handed to the model, however poor the best match.
The index always returns its nearest neighbors, so an off-topic question
got a confident-looking list of unrelated facts. A 50-fact benchmark had
shown the model's own judgment covering the gap, which is not proof it
holds at scale or across models.

`score` in `recall()`'s rows is a cosine **distance** from
`ai_vdb_provider_mariadb` (0.0 identical, larger is less similar, lower is
better). The consolidation thresholds (ADR-0005) are in the same unit but
compare a fact to a fact. A question against a fact runs larger, so they
cannot be borrowed.

### Measurements

Site scope, `nomic-embed-text` via Ollama, 18 distinct live facts about
one company. Each figure is the best live match's distance for a query.

| Query type | Queries | Best-match distance |
| --- | --- | --- |
| Answerable, full question ("How many GPUs does Project Titan run on?") | 8 | 0.124 - 0.269 |
| Answerable, keyword style ("titan gpu count") | 4 | 0.144 - 0.289 |
| Near-topic but unanswerable ("What is NeuralPulse refund policy?") | 4 | 0.254 - 0.333 |
| Off-topic, full question ("What is the capital of France?") | 5 | 0.526 - 0.640 |
| Off-topic, keyword style ("python list comprehension") | 5 | 0.511 - 0.632 |

Supporting (second and later) facts for an answerable question reach 0.42
(the enterprise API tier question's second hit was 0.419).

The same off-topic queries against all 120 facts (adding 77 user-scope
facts about people and hobbies, retired ones included) matched closer by
up to 0.035: "hello" 0.526 to 0.511, "Who won the 2022 World Cup?" 0.557
to 0.528 (it matched a fact about a 2022 Tesla), "Best recipe for
chocolate biscuits" 0.640 to 0.605.

Small sample: 18 facts, one domain, about 26 queries. Treat the numbers as
the shape of the gap, not a tight calibration.

## Decision

1. **Abstain at the tool layer.** `AimRecall::execute()` drops every row
   whose distance exceeds `aim.settings:recall_max_distance` and answers
   "No relevant facts found." when none remain. The filter is per row, not
   a gate on the top match, so it also trims a poor tail behind a good hit.
2. **`recall()` itself does not filter.** It keeps returning raw scored
   rows. `drush aim:recall` prints those distances, which is how the
   cutoff gets calibrated, and silently filtering it would hide the data.
   `AimMemoryManager::getRecallMaxDistance()` exposes the live value for
   any caller that wants to abstain.
3. **Default 0.45, admin-editable.** Same shape as the consolidation
   thresholds: `aim.settings` value (float, range 0-1, "Recall" group at
   `/admin/config/aim/settings`), with `DEFAULT_RECALL_MAX_DISTANCE` as the
   shipped default and in-code fallback.
4. **Why 0.45.** It sits in the empty band between the highest near-topic
   best match (0.333) and the lowest off-topic one (0.511): about 0.12
   above the first, 0.06 below the second. The errors are asymmetric. A
   cutoff too low hides legitimate answers (the worse failure). One too
   high only readmits a poor match, which is the behavior before this
   change. So it leans high, and clears the 0.42 supporting-fact hits.

### What it does not do

It cannot separate an answerable question from a near-topic unanswerable
one: their best-match ranges overlap (answerable reaches 0.289, near-topic
starts at 0.254).
"How many employees does NeuralPulse have?" scores 0.254 and still gets
the nearest facts. That case stays with the model, which has to notice the
facts do not answer the question. The cutoff removes the off-topic case,
not the near-topic one, and is a heuristic rather than a guarantee.

## Does it need retuning as data changes?

- **Fact volume alone: no.** A pair's cosine distance does not move when
  other facts are added.
- **Embeddings model: always.** Retune this and both consolidation
  thresholds (ADR-0005, ADR-0004).
- **Corpus diversity: yes, gradually.** More and more varied facts give an
  irrelevant query more chances at a coincidental near match, so the
  off-topic floor drifts down (0.035 across the 18-fact to 120-fact
  comparison above). The margin to the cutoff is 0.06 today, and the
  chatbot only searches site scope, which is the smaller, more uniform
  corpus. Answerable distances move the safe way: more facts mean closer
  best matches.
- **Query phrasing: no.** Terse keyword queries, as an agent may send,
  scored within the same bands.
- **Recheck when:** the embeddings model changes, the site-scope corpus
  grows about tenfold, or its character changes (for example long-form
  facts in place of one-line ones).
- **How to recheck:** run a labeled set of answerable, near-topic and
  off-topic queries through `drush aim:recall --format=json` and look for
  the empty band between the last two. Place the cutoff in it, leaning
  high per decision 4.

## Related finding: retired facts consume recall's result slots

Found while measuring the above; it limits how many facts the cutoff has
to work with.

**Resolved 2026-09-26 by
[ADR-0022](0022-exclude-retired-facts-from-vector-index.md):** a Search
API processor keeps retired facts out of the index, which is neither
the over-fetch recommended below nor the indexed `retired` flag argued
against below (it adds no column and no query condition). The analysis
below is kept as the evidence it was built on.

`recall()` asks the index for `range(0, $limit)` and only then drops
facts whose `expires` is set, in PHP, because the index does not know
`expires` (ADR-0005). Retired facts stay in the index by design (soft
supersede keeps an audit trail), so they take up result slots.

- **Live data:** 18 of the 43 site facts are retired, ids 102-119, each a
  twin of a live one (51-68): 17 with identical text, one differing by an
  apostrophe. So the same distance and adjacent ranks. The raw top 12 for
  "Who founded NeuralPulse Systems?" is 6 live and 6 retired. `recall()`
  at the chatbot's limit of 5 returned 2 or 3 rows for five queries while
  12 or 13 live matches sat in the raw top 25.
- **Trend:** it worsens. Retirement adds retired facts and never removes
  them.
- **Effect on abstention:** reduced breadth (fewer supporting facts), not
  false abstention. A retired twin ranks beside its live original, so the
  best live match survives unless five or more retired facts outrank it,
  which was not observed.
- **Same pattern** in `findNearestNeighbor()` (`range(0, 5)` outside user
  scope, 20 inside it). Latent: it needs four or more retired
  near-duplicates of one fact before a live neighbor is missed, and the
  miss is a silently skipped consolidation.
- **Recommended fix:** over-fetch and stop at `$limit` (`$limit * 5`, as
  the `subject_uid` post-filter already does). One query, not paging:
  each `execute()` re-embeds the query text, and ADR-0017's cache would
  only soften that for repeats.
- **Why not an index-level `retired` flag as the main fix.** It needs a
  new index column, a full reindex, and still a PHP post-filter for the
  gap before a retirement is reindexed. It also inherits how MariaDB 11.8
  filters an HNSW query, which is lossy for sparse filters
  ([ADR-0018](0018-index-subject-uid-with-btree.md), finding 3, which
  also shows a BTREE index on the column restoring exact results). A spot
  check on 2026-09-20 (`aim_facts`, 115 rows) reproduced its result:
  `scope='role'` (1 row) returned 0 rows through the vector index and 1
  with `IGNORE INDEX`. It also refines it: `scope='site'` (36 rows)
  returned the full 5 of 5 and 25 of 25 through the index even for a query
  vector taken from a user-scope fact, whose 15 nearest rows overall were
  all user-scope. So the filter is not applied strictly to the top
  `LIMIT` candidates; it degrades when few rows match. A `retired` flag
  matches roughly half the rows, the opposite of the sparse case
  ADR-0018's BTREE fix targets, so it would likely behave like `site`, but
  that is untested (and a BTREE index on so unselective a column may not
  be chosen by the optimizer). Validate against exact ground truth with
  `aim:benchmark` before relying on it.
- The `scope` condition on today's queries is unaffected in practice:
  every scope that matters here is well populated. A scope with very few
  facts (a new `aim_scope` bundle, for example) can return fewer rows than
  exist.

## Alternatives considered

- **Relative cutoff (distance gap to the top match, or a ratio).** Does
  not help when the top match is itself the poor one, which is the case
  this ADR exists for. Reasoned, not measured.
- **An LLM relevance judgment per recall.** One extra call on every
  recall, against the local-first, cheap-recall posture (ADR-0004). Not
  evaluated.
- **Prompt the agent to say "I don't know" when facts do not fit.**
  Complementary, and still what covers the near-topic case. Not a
  replacement: it is the unenforced behavior this ADR replaces.
- **Filter inside `recall()` for every caller by default.** Would change
  `drush aim:recall` and `aim_tool`'s MCP `aim_recall` output without
  their owners asking, and hides the distances calibration needs.
- **A hardcoded constant.** Rejected for the reason the consolidation
  thresholds became settings: the right value changes with the model and
  needs a deploy to change.

## Consequences

- An off-topic chat question now gets an honest "No relevant facts
  found." with no facts presented as relevant.
- One more model-specific number to maintain. The retuning triggers above
  are documented but nothing enforces them.
- No cutoff exists on `aim_tool`'s MCP `aim_recall` or `drush aim:recall`.
  The MCP connector can reach every scope, so it is the caller most likely
  to want one.
- The retired-slot problem was recorded here and fixed by ADR-0022, not by
  the over-fetch recommended above.

## Not built

- **Cutoff for the other callers.** Built 2026-09-27 as recommended: an
  opt-in `?float $maxDistance = NULL` on `recall()`, applied before the
  limit (so it interacts correctly with the over-fetch), `drush
  aim:recall` staying raw unless given `--max-distance`. `aim_tool`'s MCP
  `aim_recall` applies `recall_max_distance` by default, since the MCP
  connector reaches every scope, and takes an optional `max_distance` of
  its own (2 disables it). A spot check on user scope (uid 1, nomic) put
  related queries at 0.14-0.36 and off-topic at 0.53-0.63, so 0.45 holds
  there too; role and case scope were not measured.
- **The over-fetch fix** in `recall()` and `findNearestNeighbor()`.
  Dropped: ADR-0022 removes retired facts from the index instead.
- **A `drush aim:calibrate` command** that takes labeled queries, prints
  the three distributions and the gap, and suggests a cutoff, replacing the
  manual recheck above.

## Implementation (as built)

- `config/install/aim.settings.yml` and `config/schema/aim.schema.yml`:
  `recall_max_distance`, float, range 0-1, default 0.45.
- `AimMemoryManager::getRecallMaxDistance()` and
  `DEFAULT_RECALL_MAX_DISTANCE` (the docblock records the measurements).
- `AimSettingsForm`: "Recall" details group.
- `modules/aim_chatbot/src/Plugin/AiFunctionCall/AimRecall.php`: the filter.
- Verified by calling the tool through the
  `plugin.manager.ai.function_calls` service (the chat endpoint itself was
  not exercised, see
  `modules/aim_chatbot/DEVELOPING.md`): on-topic questions return their
  facts, "What is the capital of France?", "Best recipe for chocolate
  biscuits" and "hello" return "No relevant facts found."

## Addendum (2026-09-27): a second calibration, on the demo dataset

The 0.45 above was measured on one corpus (18 to 120 facts about a
fictional company). The demo site now holds a different one (31 facts about
a fictional library, 19 of them site scope), and the band is narrower. Best
match per query, nomic-embed-text, site scope:

| Query type | Queries | Best-match distance |
| --- | --- | --- |
| Answerable ("Do you charge late fees?" finds "overdue fines", "opening hours") | 13 | 0.244 - 0.457 |
| Near-topic, unanswerable ("Do you have a cafe?", "Is there parking?") | 6 | 0.299 - 0.505 |
| Off-topic | 5 | 0.497 - 0.621 |

At 0.45 the answerable "Do you charge late fees?" (0.457) would have been
dropped, the worse failure per decision 4. The demo site therefore runs
`recall_max_distance: 0.48`, 0.023 above the highest answerable and 0.017
below the lowest off-topic. The shipped default stays 0.45: the two corpora
disagree, so the value is dataset-specific, which the recheck triggers above
already say. A small, uniform corpus has fewer close matches for an unrelated
query to land on, so its off-topic floor is higher and its band different.
Cutoff for the MCP tool and drush was built the same day (see "Not built").

