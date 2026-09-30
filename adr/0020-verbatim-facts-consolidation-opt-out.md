# ADR-0020: Verbatim facts - an explicit opt-out from consolidation

**Status:** Proposed - analyzed 2026-09-26, not built. Whether to build it
is not decided yet.
**Date:** 2026-09-26

## Context

The question: can a caller add a fact verbatim, bypassing AI processing?
The write half already works. `AimMemoryManager::remember()` stores its
`$text` exactly as given, with no extraction call
([ADR-0006](resolved/0006-agent-native-write-path.md)). It is reached by
`drush aim:remember`, `aim_tool`'s `aim_remember` (Tool API/MCP),
`aim_chatbot`'s `AimRemember` function call, and the admin add form at
`/admin/content/aim-facts/add/{scope}`.

But "verbatim" is only as strong as the processing that runs *after* the
write, and one of those steps can rewrite or retire the fact.

## Findings

Read from the code 2026-09-26, not exercised live.

1. **Who supplies the text.** Only `drush aim:remember` and the admin
   form take a human's literal text. Through MCP and the chatbot the
   calling model composes the text before the tool runs, so "verbatim"
   there means verbatim relative to the call, not to what the human said.
   That paraphrase happens upstream of `aim` and nothing in the module
   can close it.
2. **Guardrails run on every write** (ADR-0002) and change no text today:
   `aim_max_length` and `aim_no_markup` are Stop-only, they reject rather
   than rewrite. `AimGuardrailsConstraintValidator` does write a
   `RewriteInputResult` back for a programmatic caller, so a rewriting
   guardrail added later would silently break verbatim.
3. **Consolidation is the real gap.** `AimHooks::factInsert()` enqueues
   every new fact unless the entity is flagged `setSyncing(TRUE)`.
   `consolidateFact()` pairs the fact with its nearest neighbor; the
   lower id is `kept`, the higher id is `candidate`
   ([ADR-0005](resolved/0005-consolidation-algorithm.md)). For a verbatim fact:
   - **As the newer fact (candidate):** NOOP retires it (`expires` set,
     so it drops out of recall); DELETE hard-deletes it; UPDATE retires
     it and overwrites the *older* neighbor's text with the model's
     merge.
   - **As the older fact (kept):** a later non-verbatim fact's UPDATE
     overwrites the verbatim text with an LLM merge. Skipping the
     enqueue on its own would not catch this case.
4. **`setSyncing(TRUE)` is not the answer.** It only skips the
   insert-time enqueue (the candidate side), it also skips every other
   insert side effect, no write path exposes it, and it does nothing to
   protect a fact acting as `kept`.

## Decision (proposed)

Add a `verbatim` boolean base field on `aim_fact`, default FALSE, meaning
"consolidation never touches this fact".

- `remember()` gains a `$verbatim` argument, surfaced as `--verbatim` on
  `aim:remember`, an input on `aim_remember`, and a checkbox on the admin
  form.
- `AimHooks::factInsert()` skips the enqueue for a verbatim fact, and
  `consolidate()`'s sweep query excludes verbatim facts as candidates.
- `findNearestNeighbor()` skips a verbatim neighbor, in the same place it
  already skips `expires`-set facts. A verbatim fact therefore never
  appears in a pair, as either `kept` or `candidate`.
- The flag is a plain column, not a Search API attribute, so it needs no
  index change: consolidation reads it off the loaded entity, same as
  `expires`.

Alternatives rejected:

- **`source`:** free-text provenance, nothing enforces it.
- **`state`:** the tri-state boolean fact value, a different meaning.
- **A category term:** admin-curated taxonomy, no enforcement behavior.
- **Exposing `setSyncing(TRUE)`:** see finding 4.

## Consequences

- A near-duplicate of a verbatim fact is no longer retired against it, so
  both stay live and `recall()` may return both. Accepted: the caller
  asked for the text as written.
- Guardrails still apply to a verbatim fact. If a rewriting guardrail is
  ever shipped, decide then whether a verbatim fact is rejected instead
  of rewritten.
- This is a different axis from ADR-0002's draft-to-trusted gate:
  verbatim means "do not machine-rewrite or retire", not "trusted".
- New base field: no `hook_update_N()` while there is no real data to
  preserve (PoC, reinstall); needed once real data exists.

## Open questions

1. **Build it at all,** or is the existing path enough?
2. **Who may set it.** A verbatim fact is exempt from dedup, so it may
   warrant its own permission rather than riding on the per-scope create
   permission. `aim_chatbot` probably should not set it.
3. **Refinement:** let a verbatim fact be `kept` in a NOOP-only pair
   (retire the newer restatement, never touch the verbatim text), to keep
   dedup working without risking a rewrite.
