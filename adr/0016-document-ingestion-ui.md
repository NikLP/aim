# ADR-0016: Document ingestion via a Drupal form - three modes, one shared core

**Status:** Accepted for Mode 1 (build now). Mode 2 design only, not built,
blocked on ADR-0002's gate. Mode 3 out of scope, named only to draw the
boundary.
**Date:** 2026-09-14

## Context

`drush aim:extract <file>` is the only text-extraction path today, and it's
CLI-only - every existing write path (`aim:extract`, `aim:remember`, the MCP
`aim_tool` plugins, the chatbot's `#[FunctionCall]` tools, `aim_eca`'s
`FactWrite`) requires either a shell or a calling agent. No plain Drupal
admin form exists for handing aim a document.

Discussion surfaced that "add document ingestion" is actually three
different operations, previously conflated under one "media ingestion"
idea in CLAUDE.md/TODO.md:

1. An operator has a document (notes, a transcript export, a policy file)
   and wants to hand it to aim directly, without a shell.
2. An operator wants aim to scan something already in the Drupal site (a
   node body, a Media item, a webform submission) that wasn't written for
   aim's consumption.
3. An operator wants aim to keep a copy of source material around for
   later re-analysis, not just as one-shot extraction input.

These have different trust/provenance profiles even though (1) and (2)
feed the identical extraction call, and (3) is a genuinely separate
question from the other two: it's about persistence, not about where the
input text comes from.

## Decision

**One shared core, three modes.**

**Core.** Factor `AimCommands::extract()`'s body (call `extractFacts()`,
then `createFactsFromCandidates()`) out into a plain service method, e.g.
`AimMemoryManager::ingestText(string $text, string $source, ...)`, with
exactly one non-CLI-specific entry point. `drush aim:extract` becomes a
thin wrapper over it, same as it already is a thin wrapper over
`extractFacts()`/`createFactsFromCandidates()` today. Both drush and the
new form call this same method - no duplicated extraction logic.

**Mode 1 - ephemeral upload (build now).** A plain Drupal form: file
upload (or a textarea for the smallest first cut), `scope`/`subject`/
`category` fields matching `aim:remember`'s options, submits to
`ingestText()`. The uploaded file is never saved to a managed File/Media
entity - read into memory and discarded, same no-transcript-recording
posture `aim:extract` already has. `source` is tagged `upload:<filename>`
(or `form:<timestamp>` for pasted text), extending `aim:extract`'s
existing `extract:<filename>` convention rather than inventing a new one.

Route: `/admin/content/aim-facts/ingest`, reached via a local-action
button ("Ingest document") on the existing `views.view.aim_facts` listing
(`/admin/content/aim-facts`) - same `menu.type: normal` pattern CLAUDE.md's
Admin UI section already documents for that View, not a tab. Permission:
`store aim memory` (the existing write permission `aim_tool` already
split out), not the admin-only `administer aim memory`.

Text only for this first cut, per the scope given for this ADR - no
PDF/office-format parsing. A future format-conversion step is a separate,
later addition, not designed here.

**Mode 2 - scan an existing entity (design only, blocked).** A form (or an
alternative input on Mode 1's form) that reads text from an existing
entity's field - a node body, a Media item's extracted text, a webform
submission - instead of an upload, and calls the same `ingestText()`.
`source` is tagged with Drupal core's own `entity:` URI scheme -
`entity:node/118`, `entity:media/42` - not an invented scheme. Confirmed
against `web/core/lib/Drupal/Core/Url.php`: core documents and parses
`entity:{entity_type}/{entity_id}` (e.g. `entity:node/1`) as a first-class
URI scheme alongside `internal:`, and it's directly resolvable back to the
real entity via `Url::fromUri()` or the entity type manager - a better fit
than `internal:`, which core uses for path-based (not entity-ID-based)
references.

Not decided by this ADR: which entity types/fields are scannable in v1
(plain text fields only, matching Mode 1's text-only scope - a
PDF-holding Media item is excluded until a conversion step exists), and
how an operator authorizes which entities are eligible (most likely a
per-site allowlist by entity type/bundle, echoing the still-undesigned
"Source-boundary policy per site archetype" idea in CLAUDE.md, but not
designed here).

**Why Mode 2 is blocked, not just lower priority.** Mode 1's input is
always operator-supplied - the person running the ingestion chose the
text, same trust boundary `aim:extract`/`aim:remember` already operate
under, no new risk. Mode 2's input is not: an existing node or webform
submission may have been authored by a different, less-trusted party (a
site visitor, another editor), and extraction has no input-side
guardrail today (CLAUDE.md's "Extraction-input guardrailing" idea, still
unbuilt) - only candidate-fact *output* is checked
(`aim_write_guardrails`). A source document crafted to manipulate the
extracting model into asserting a false-but-clean-looking fact passes
today's output-only guardrails unchanged, since the guardrail set checks
shape (length, markup), not truth. **Mode 2 requires ADR-0002's deferred
Content Moderation / draft-to-trusted gate (or an equivalent human-review
step) to exist first** - candidate facts from a Mode 2 scan must land in
a reviewable, not-yet-live state, not go live automatically the way every
write path does today. This is now the concrete forcing case for building
that deferred piece, not just a "revisit before non-PoC data" note.

**Mode 3 - persisted source for later re-analysis.** Unchanged from
CLAUDE.md's existing "Media/source ingestion for re-analysis" idea, still
gated behind its own open question (does aim ever store source material
at all) and not addressed by this ADR. Named here only to keep the
boundary explicit: Modes 1 and 2 both discard the source text after one
extraction pass and so don't reopen that question; Mode 3 is a genuinely
different feature (persistence), not a bigger version of Mode 1 or 2.

## Consequences

- Mode 1 needs neither Mode 3's open storage question resolved nor
  ADR-0002's gate built - it's a thin, low-risk UI addition on an
  already-trusted write path, and can ship on its own.
- Mode 2 needs ADR-0002's gate built first. Building Mode 1 does not
  imply Mode 2 is close behind - it's blocked on a real prerequisite, not
  just unscheduled.
- `source` gains a second real provenance convention (`entity:type/id`)
  alongside the existing `extract:filename` and minted `case-xxxxxxxx`
  formats - no schema change, `source` is already a plain string field.
- `ingestText()` exists as a single method with two UI callers (drush,
  the new form) from day one, instead of `extract()`'s body being
  duplicated into a form handler later.

## Open questions

- Mode 2's entity/field allowlist mechanism - a config entity, a
  Guardrail-set-like plugin, or something else. Not designed.
- Whether Mode 1's form should accept multiple files/paste-many in one
  submission (mirroring `aim_remember`'s `facts` list input). Not decided
  - first cut is single-document.
- Whether Mode 2, once unblocked, is a separate form/route or a second
  input option on Mode 1's same form. Leaning separate, given the
  review-gate prerequisite makes them genuinely different operations, but
  not decided.
