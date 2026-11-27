# ADR-0061: How aim uses literals

**Status:** Sections 1 and 2 built 2026-10-08 to 10-15 (tokens at recall,
`aim_literal`, the finder-first gate); section 3 item 2's live source
built 2026-11-16 (see the addendum), the rest of sections 3 and 4
unbuilt; tokens on write proposed 2026-11-26 (second addendum), unbuilt.
Split out
of `literals`' ADR-0040 (sections 6 and 7, the gate measurements and the
aim-side deferred and rejected items) on 2026-10-23, when `literals` went
to drupal.org as `drupal/literals` and stopped carrying aim's design.
**Date:** 2026-10-23

## In plain terms

Before: how aim uses literals was written up inside literals' own decision
record, so a drupal.org reader of literals met aim's internals throughout.

After: literals' record
([ADR-0040](../../../contrib/literals/DESIGN.md))
says only what any consumer can use (tokens, the `literals.lookup` service,
the finder as a gate). This record holds aim's side: what aim built on
those seams, what it could build next, and what it rejected.

## Context

A literal is an exact value with a fuzzy address, not a fact, and a
pointer-only fact has no text to embed. aim depends on `literals` (hard)
but not on `literals_finder`, and stores nothing of a literal.

## Decision

### 1. Built (2026-10-08 to 10-15)

- `entity` scope stays
  ([ADR-0027](resolved/0027-entity-scope.md)): a fact about a literal is an
  ordinary `scope=entity` fact with `target_type=literal`.
- `recall()` replaces `[literal:key]` and `[literal:key:link]` in fact text
  as the viewing account; a fact naming an unreadable literal is withheld
  whole (detail in [DEVELOPING.md](../DEVELOPING.md), "Exact values from
  `literals`").
- The chat assistant looks values up with `aim_literal`, a wrapper over
  `literals.lookup` exposed through `tool_ai_connector`. It stays for MCP
  and external agents, which have no gate in front of them.
- **The finder is the gate, ahead of the chat model** (`AimGateProcessor`,
  a `ChatProcessor` in `aim_chatbot`, live 2026-10-15; open points in
  [TODO.md](../TODO.md)). A hit skips the hosted model only; the Jev call
  stays, as it is the fuzzy match.
- `aim_recall` does **not** call the finder (section 3). Reversed
  2026-11-16: see the addendum.

### 2. The gate, end to end (measured 2026-10-12)

`drush aim:chatbot-eval`, 39 agent-written questions. `tool`: the chat
model calls `aim_literal` (the mode's meaning then, see below); `prompt`: every readable value in the system
prompt; `gate`: the finder first, the chat model only on `none` or
`ambiguous`.

| Mode | Hosted tokens, 11 / 101 literals | Seconds, 101 | Correct, agent set | Correct, 101-literal set |
| --- | --- | --- | --- | --- |
| `tool` | 3,422 / 3,360 | 4.2 | 20/23 | 20/51 |
| `prompt` | 1,874 / 5,599 | 2.6 | 22/23 | 37/51 |
| `gate` | 923 / 604 | 1.3 | 23/23 | 50/51 |

The chat model is a poor gate (it often never called the tool, and cost
two hosted calls plus the finder when it did); the prompt path scales with
the pool and cannot serve two audiences (restricted values: `tool` and
`gate` 5/5 for the signed-in user, `prompt` 1/5, none leaked to
anonymous). "Correct" means the stored value appears in the reply, so
chat-model rephrasing counts as wrong.

The `tool` row is the path the addendum replaced. Since 2026-11-16 the
eval's `tool` mode means `aim_recall` returning exact values
(`recall_literals` on), not measured on these sets; `prompt` and `gate`
switch `recall_literals` off for their run. The current end-to-end figure
is the site's multi-turn chat scenarios (2026-11-17, recall carrying
values): 23/24 turns, every literal turn right, one decision call more per
recall, median 3.9 s per turn.

### 3. How aim could use literals further (unbuilt)

Pulled out one at a time when a site needs it.

1. **Convert-a-fact retires the fact.** Promoting an extracted one-shot
   fact (an exact value) to a literal goes through a short form, never one
   click: a model splits the fact into gist and value (optional: a first
   version can prefill the fact text for a person to edit), a human picks
   the type and key, and the fact is retired after a cool-off. The link
   lives on aim's side: `superseded_by` references `aim_fact` only, so the
   literal key goes in the fact's `superseded_by_reason` JSON, and the
   literal carries no `source_fact` field (literals must not depend on
   aim). The cool-off is the fact's `expires` set N days ahead, retired by
   `aim:expire` (ADR-0057), so nothing new is needed for it. The literal's
   gist is then the only description of the thing; the value lives once.
   Facts that merely mention a literal in a longer sentence stay facts and
   carry `[literal:key]`, so the value is never stale. Likely first use:
   ADR-0056's "the demo's raw email fact moved into a literal".
2. **Two ways to show a literal in recall.**
   - *Finder as a live source* (built 2026-11-16, addendum):
     `aim_recall` asks the finder as a second
     source and returns the literal as a fact-shaped live result (value
     resolved for the viewer). Nothing stored in aim. The finder's failure
     mode is `none` (0 wrong-confident measured), not a wrong value.
   - *Pointer fact with a tracked gist:* a fact with no text of its own
     whose index entry is computed from the literal's gist. Only worth
     building if the live source proves insufficient. If both run,
     de-duplicate by literal key.
3. **Two answer modes, a setting.**
   - *Mode 1, literal only:* the reply is the fixed text and link; no large
     model, lowest cost, the value never reaches a model. (The gate's
     template reply today.)
   - *Mode 2, literal plus smoothing:* the question and the resolved
     literal go to the large model for a natural reply.
   - *Escalation:* mode 1 can show a "think harder" control that re-runs
     the question through mode 2 or the full recall path (a skill, wired
     later).
4. **Visibility decides what may reach a hosted model.** Default: mode 2
   only for unrestricted values; restricted values stay mode 1, or go to a
   local model. A trade-off accepted until local reasoning models are
   available, not a settled principle. It assumes aim's standing rule that
   it is not for classified data (CLAUDE.md, Scope).
5. **Copy controls are front-end work.** The chat items already carry
   value, label and kind, so a "copy value" / "copy link" button shown by
   role or restriction needs nothing new in `literals`.
6. **An optional pre-gate** ("is this a lookup?" ahead of the finder), only
   if measured to pay.

### 4. Parked

- **An aim-stored gist backend** (the gist lives only as a `scope=entity`
  fact targeting the literal). The finder's interface already allows it;
  it is not built because it needs two storage modes, a literal form
  writing another entity's text, no draft gists on a fact, a
  retire-on-delete link, and a consolidation skip.
- **A pointer field on `aim_fact`** naming a literal. Facts reference
  literals through `aim_literal` or an inline `[literal:key]`; a field
  would cut across "aim stores nothing about literals".
- **A Jev-written sentence around the gate's value**: Jev cannot write
  text, so this would be our own wording or a chat model; worth it only if
  the bare template proves too curt.

## Rejected

- **A literal scope, an `aim_scope_literal` bridge and a gist mirror in
  `aim_fact`:** a second copy of the gist with its own embedding, sync code
  on every save, delete and draft, and a standing duplicate invariant.
- **A separate vector row per literal under aim.**

## Consequences

- aim's demo literals ship in the `aim_demo_library` recipe, alongside
  literals' own `literals_demo_library`.
- A site that adopts convert-a-fact takes on a review queue someone must
  staff.

## Addendum (2026-11-16): the finder as a live source in recall

**Before:** a chat model got exact values only by choosing `aim_literal`
over `aim_recall`. Site Agent's model did not: asked "do you have any
phone numbers" on habanero (2026-11-15), it called `aim_recall`, got "No
relevant facts found." and answered that none were recorded, as aim's
own MCP instructions told it to. It found `main_phone` only when the
person pushed. Section 2 had already measured this (`tool` 20/51), and the
gate that fixes it on the chat block is a `ChatProcessor`, which cannot
apply to Site Agent's own loop or to an MCP client.

**After:** the `aim_recall` tool also asks `literals.lookup` with the
query as a question (the finder, as the calling account, under the
finder's own thresholds), behind `aim.settings:recall_literals` (shipped
on for new installs; a site installed earlier has no key, which reads as
off until set, since aim ships no update hooks; nothing without
`literals_finder`). A match, or each readable
candidate of an ambiguous one, comes back in a `literals` list beside the
facts, with value, label, kind, gist and `match`/`ambiguous`. "No relevant
facts found." now appears only when the finder was not asked; when both
come back empty the reply says neither was found, and a lookup that could
not run says exact values could not be checked rather than that none are
recorded. A call narrowed to a scope or account asks too: a literal is
not always a site value (a per-user account page). The model never has to choose a second memory tool, so Site Agent's toolset
and the demo chatbot dropped `aim_literal`. MCP dropped it too
(2026-11-27, `aim_tool_oauth` no longer ships its `mcp_tool_config`):
recall and brief already replace `[literal:key]` in what they return, so
an MCP client never holds a bare key, and in one live MCP exchange Claude
called both tools for the same value. The tool stays for a site without
`literals_finder` or with `recall_literals` off, added by hand.

**Rejected:** a `site_agent.prompt_section` injecting finder hits into the
system prompt (Site Agent only, and from the person's message rather than
the query); replacing or decorating `SiteAgentProcessor` (a second local
patch on `site_agent`); more tool-description wording (the `tool` path
section 2 scored 20/51).

**Costs, accepted:** one more decision-model call (the finder's chooser)
per recall, for every client. On the chat block, a turn the gate passes to
the assistant asks the finder a second time when the assistant recalls;
skipping that needs the invoker in `AimRecall`'s method body (tool#3583125
or a plugin-class tool), not built until a measurement calls for it.
Section 2 notes what its `tool` row measured and gives the current figure.

## Addendum (2026-11-26): tokens on write (proposed, unbuilt)

**Before:** a fact carries `[literal:key]` only if its writer typed the
token. Facts written by chat, extraction, ingest or MCP carry the raw
value (a phone number, a URL), so when the literal changes those facts
go stale. Fixing them means finding each one and writing a superseding
fact (ADR-0064: a changed world is a new fact, not an edit), and every
new fact goes through Guardrails, the grounded check and consolidation.

**After (proposed):** before a fact is saved, a matcher looks for
existing literals' values in its text and replaces each with its token,
on every write path. A changed value is then one edit to the literal, and
the value in the fact stays exact and access-checked at recall. Matches
come in two tiers:

| Match | Example | Action |
| --- | --- | --- |
| Exact, or equal after normalizing | `01234 567890` and `+44 1234 567890`; `Example.com/` and `https://example.com` | Substitute automatically: deterministic, no model, no person |
| Near | "open 9 till 5" against a literal "9am-5pm"; a reworded address | A suggestion, never applied automatically |

A suggestion is accepted or dismissed at the review points that already
exist: the chat persona's confirmation before saving (it can show the
fact with the token in place), and the fact form or trust review for
other paths. No new queue, and a suggestion never blocks a write: one
nobody acts on leaves a raw value that was right when written. That keeps
the upkeep mostly self-maintaining rather than adding a review chore.

The same matcher can run once as a backfill over existing facts.

**Relation to the rest of this record:** section 3.1 (convert-a-fact)
turns a fact that *is* a value into a literal and retires the fact; this
keeps longer facts as facts and swaps the values inside them for tokens.
Autocomplete for literals in an input field is the smaller, later piece:
it only helps where a person types a fact (the add form, a console), and
belongs with [ADR-0049](0049-finder-family-parked.md)'s inline `@` picker
and [ADR-0029](0029-context-carrying-turns.md)'s mention tokens, both
parked.

**Open questions:**

1. **Normalization.** `literals` compares nothing today. A per-type
   comparison ("are these two strings the same value of this kind?")
   belongs in `literals` as a consumer-neutral seam the finder could use
   too; logged in its BACKLOG.md.
2. **Resolved as whom.** Only the Text type has a stored value; Path,
   Token, Content, Entity and Field compute theirs at read time. The
   matcher compares against current resolved values as some account:
   the writer (substitute only what the writer can read) is the likely
   answer.
3. **Access changes with the token.** A fact naming a literal its viewer
   cannot read is withheld whole (section 1). Substituting a restricted
   literal would hide a fact from viewers who saw it before. Likely rule:
   automatic substitution for unrestricted literals only, a suggestion
   for restricted ones.
4. **Short or common values.** "9am" or a one-word name could match text
   that does not mean that literal: a minimum length or distinctiveness,
   or opt-in per literal type.
5. **Finding near matches and storing suggestions.** String distance (no
   model call) or a decision-model call per write (cost). A suggestion
   needs somewhere to live: a field on `aim_fact` cuts across section 4's
   parked "no pointer field naming a literal", so a separate store or the
   review UI computing it on view are the alternatives.
6. **Order on the write path.** The grounded check compares fact text
   with the source text, which holds the raw value, so substitution runs
   after the check, just before save. A backfill substitution changes
   `text`, so `applyRevisionPolicy()` makes a revision and clears
   `grounding_score`, although the meaning is unchanged; whether the
   score should survive a token-only change is open.

