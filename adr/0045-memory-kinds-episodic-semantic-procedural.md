# ADR-0045: Memory kinds - should `aim_fact` distinguish semantic, episodic and procedural memory?

**Status:** Proposed 2026-10-04 - design only, nothing built. Recommendation
is to defer the `kind` field until a real episodic or procedural use case
exists (see Assessment).
**Date:** 2026-10-04

Follow-up: [ADR-0046](0046-core-cutoff-time-axis-and-shared-gist-finder.md).

## Context

[ADR-0001](resolved/0001-storage-and-scope-model.md) split memory by
*whose* it is (user/role/site/case, later entity). It never asked *what
kind* of memory a fact is. The standard agent-memory taxonomy has three:

- **Semantic** - "what is true" (corporate facts, product names, who the
  clients are).
- **Episodic** - "what happened, and when" (publish logs, ranking history,
  traffic spikes).
- **Procedural** - "what works best" (editorial corrections, layout
  patterns that perform, preferred tone).

`aim_fact` has no field for this. The shape is semantic by design: `text`
is "one short statement", consolidation, `superseded_by` and `expires`
assume a fact is a claim a newer claim can replace. Several columns have
nonetheless picked up type-like meaning without a decision:

| Column | Implied meaning | Problem |
| --- | --- | --- |
| `category` | A user-facing filing axis (quick notes, ADR-0032) | Legitimate; just not a memory kind (corrected in ADR-0046) |
| `state` | "This fact is a flag" | Flags are semantic; it hides preference/rule as a distinct thing |
| `superseded_by` / `expires` | Every fact is replaceable | Wrong for episodic (append-only) and procedural (revised, not replaced) |
| `asserted` | One validity point | Episodic needs an event time, maybe a duration |
| `source` | Provenance of the episode | A pointer, not the episode itself |
| `scope` | Whose it is | Orthogonal: site scope can hold all three kinds |

## How each kind fits today

- **Semantic:** the real design; fully supported.
- **Episodic:** partly. `source` and `asserted` give provenance and a date,
  but consolidation would merge or retire near-duplicate events ("published
  X on 3 Oct" vs "published Y on 5 Oct"). The README's "support tracking"
  scope names episodic memory with no schema behind it.
- **Procedural:** absent. "Headlines under 60 characters perform better"
  would be stored as prose and returned by recall as if it were a fact.
  [ADR-0035](0035-standing-constraints-action-gate.md)'s standing
  constraints are the nearest thing, and they are an action gate, not
  recall.

## Assessment: live use against the three kinds

Live data (2026-10-04): 63 facts, 58 live, 5 retired; by live scope: case 27,
site 22, role 4, user 4, entity 1. `source` is set on every fact; `state`
and `asserted` on none.

Reading a 25-fact random sample:

- **Semantic: the large majority.** Opening hours, room-booking rules, who
  works where, what the site is built on, how Hyperslop prices apps.
- **Episodic: effectively none.** Nothing records something that happened.
  The three dated items ("presenting on 14 October", "calendar launches
  20 October", "opens an hour earlier for two months") are *future or
  time-bounded claims*, which is semantic with a validity window, the job
  `asserted`/`expires` was built for (and `asserted` is unused, so even
  that is not exercised).
- **Procedural: policy, not learned practice.** "Editors must add alt text",
  "copy must not infringe trademarks", "buyers must have an account" are
  rules and requirements, humans stating constraints. None is a learned
  "what worked" with an outcome. They belong with ADR-0035's standing
  constraints, not a new memory kind.

So the module's actual use case so far is **governed, scoped, retrievable
statements of site and organizational truth**, plus a chat assistant and
MCP clients that recall them. That is one kind. The three-way split came
from another thread's CMS framing and describes behavior (publish logs,
ranking decay, learned layouts) that nothing here produces or consumes.

## If kinds were implemented

A `kind` field (`semantic` default / `episodic` / `procedural`), not
`category`, because it would change behavior rather than label:

- **Consolidation:** skip episodic (or merge only identical events);
  procedural revised in place with history rather than superseded.
- **Recall:** episodic ranked by recency and filterable by time window;
  semantic by similarity; procedural surfaced by trigger.
- **Shape:** episodic needs an event time and optionally an actor/target;
  procedural needs a trigger and an outcome, ideally linked to the
  episodes that justify it ([ADR-0012](0012-fact-relation-graph.md)).

Costs: an update hook, a schema change on the vector index if `kind` is
filterable, extraction prompts that classify (a new hallucination surface
on every write), consolidation branching, and admin UI. Benefits today:
near zero, because no live data would populate the new values.

## Options

1. **Do nothing.** Cheap, but the accidental overloading of `category` and
   `state` continues.
2. **Document only.** State in CLAUDE.md/README that `aim_fact` is a
   semantic store; episodic and procedural are out of scope until a
   concrete consumer exists. Zero code. **Recommended now.**
3. **Add `kind` now.** Speculative; the cost above buys nothing yet.
4. **Add `kind` when the first non-semantic consumer lands**, likely the
   support-tracking scope (episodic, with workflow states, probably its own
   entity rather than a fact kind) or a CMS-publishing integration
   (episodic logs). Revisit then, and decide whether episodic belongs in
   `aim_fact` at all or in a sibling entity, since append-only event logs
   have different volume, retention and indexing needs.

## Decision (proposed)

Option 2 now, option 4 as the trigger. Record that `aim_fact` is semantic
memory, stop treating `category` as a memory-kind tag, and route
policy-style rules to ADR-0035 rather than a procedural kind.

## Open questions

- Is support tracking an `aim_fact` kind or its own entity? (Leaning own
  entity.)
- Is any "procedural" memory ever *learned* here, or always human-authored
  policy? If always authored, ADR-0035 covers it and procedural can be
  dropped from the taxonomy.
- Should `asserted` and `state`, both unused in live data, be exercised by a
  real caller before anything else is added?
