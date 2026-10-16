---
name: aim-memory
description: One memory capability, two directions - write anything worth remembering into aim's memory store with `drush aim:remember`, and pull relevant memory back with `drush aim:recall`. Use continuously through a session whenever you learn or need something scope-appropriate, not as a one-time end-of-conversation step.
---

# aim-memory

This is a single memory capability with two directions, not two unrelated
commands - reach for it the way you'd reach for one `memory` tool with a
read and a write side, not as a pair of narrow, separately-considered
tools. (Modeled on how Hermes Agent shapes its own memory tool - see
`web/modules/custom/aim/CLAUDE.md`'s "CLI agent adapter" section for the
comparison and why aim diverges from it on the read side.)

Both commands run via Bash, from the project root
(`/home/niklp/websites/aim` unless told otherwise):

```
ddev drush aim:remember "<text>" --scope=<user|role|site|case> [--subject=<id>] [--source=<tag>]
ddev drush aim:recall "<query>" [--scope=<...>] [--subject=<...>] [--limit=<n>] [--format=json]
```

Neither command makes an LLM call - `aim:remember` stores exactly what you
give it, `aim:recall` runs a real semantic query against the vector index
and returns matches. Prefer `--format=json` on `aim:recall` when you intend
to parse the output rather than just read it.

## Write side: `aim:remember`

Use it as soon as you learn something worth keeping past this session, not
saved up for the end. Small individual calls are fine and expected.

- `--scope=user` for a fact about one specific person (pass `--subject`).
- `--scope=role` for something true of everyone holding a role.
- `--scope=site` for a fact about the site/organization itself (the
  default, and usually right when nothing more specific applies).
- `--scope=case` for something tied to one support case or tracked issue.
- `--source` is a short provenance tag for where this came from (e.g.
  `chat:2026-09-09`, or a more specific tag if one fits).

## Read side: `aim:recall`

Use it before assuming you need to ask, and before writing a fact that
might already exist - a duplicate write is worse than one extra query.
Filter with `--scope`/`--subject` when you already know which slice of
memory is relevant; leave them off for a broad search.

## Don't remember what's already available right now

If a fact is directly derivable from the live request or conversation
context - the current user's roles, a field value still unchanged on the
entity being viewed, something you could just re-read a moment from now -
writing it to memory creates a stale duplicate, not memory. Memory is for
what would otherwise be lost once this session or event passes.
