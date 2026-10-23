# ADR-0061: How aim uses literals

**Status:** Sections 1 and 2 built 2026-10-08 to 10-15 (tokens at recall,
`aim_literal`, the finder-first gate); sections 3 and 4 unbuilt. Split out
of `literals`' ADR-0040 (sections 6 and 7, the gate measurements and the
aim-side deferred and rejected items) on 2026-10-23, when `literals` went
to drupal.org as `drupal/literals` and stopped carrying aim's design.
**Date:** 2026-10-23

## In plain terms

Before: how aim uses literals was written up inside literals' own decision
record, so a drupal.org reader of literals met aim's internals throughout.

After: literals' record
([ADR-0040](../../../contrib/literals/adr/0040-literals-probabilistic-lookup-of-exact-values.md))
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
- `aim_recall` does **not** call the finder (section 3).

### 2. The gate, end to end (measured 2026-10-12)

`drush aim:chatbot-eval`, 39 agent-written questions. `tool`: the chat
model calls `aim_literal`; `prompt`: every readable value in the system
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

### 3. How aim could use literals further (unbuilt)

Pulled out one at a time when a site needs it.

1. **Convert-a-fact retires the fact.** Promoting an extracted one-shot
   fact (an exact value) to a literal supersedes the fact through the
   existing non-destructive `superseded_by` edge, through a short form,
   never one click: a model splits the fact into gist and value, a human
   picks the type, the literal carries a `source_fact` back-reference, and
   the fact is retired after a cool-off. Depends on the agreed
   `retired`/`expires` split. The literal's gist is then the only
   description of the thing; the value lives once. Facts that merely
   mention a literal in a longer sentence stay facts and carry
   `[literal:key]`, so the value is never stale.
2. **Two ways to show a literal in recall.**
   - *Finder as a live source:* `aim_recall` asks the finder as a second
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
