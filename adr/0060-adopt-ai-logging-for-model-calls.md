# ADR-0060: Model-call logging goes through `ai_logging`, text included; stealth is a later option

**Status:** Built 2026-10-14. Reverses the "store no text" stance in
[ADR-0037](resolved/0037-transient-source-passages-for-grounding.md) and the
CLAUDE.md logging rule for model calls. Stealth mode (decision 4) is not
built.
**Date:** 2026-10-14

## In plain terms

Before: aim logged only IDs and counts, kept its own table of call timings
(`aim_activity_metrics`) and stored no prompt or response text, so a bad
extraction or a rejected merge could not be inspected afterwards.

After: each model call aim makes is recorded by the AI suite's own
`ai_logging` module, prompt and response included, with aim adding only the
run ID and the call's duration. aim's own table is gone. A "stealth" switch
that stores nothing is a later, optional addition for sites that want it.

Gain: the text behind every extraction, consolidation, merge and grounding
decision is reviewable, which is what improving the prompts and models
needs; less custom code; alignment with the suite (and `ai_context`).
Cost: prompts and responses now sit in the database, and the pruning limits
bound how long.

## Context

- The "never log fact text" rule and ADR-0037's "nothing stored" were
  precautions (personal data, non-sovereign providers), not requirements.
  The "no recording" constraint they echoed only ever meant audio. Keeping
  the text limits how far the service can be improved, and a business site
  may reasonably want it.
- `aim_activity_metrics` re-implemented what `ai_logging` already does:
  one row per call with provider, model and tokens.
  [ADR-0021](0021-jev-typed-decision-provider.md)'s metrics need only
  duration and a run ID beyond that.
- Upstream (Scott Euser, 2026-10-14): an option to omit the prompt is
  unwelcome, since a response can repeat the prompt anyway; redaction is
  left to a site through core's `hook_entity_presave` on `ai_log`.

## Decision

1. **`ai_logging` is a dependency of aim.** It captures only calls tagged
   with aim's activity tags (`aim_extract`, `aim_consolidate`,
   `aim_consolidate_verify`, `aim_consolidate_merge`, `aim_ground`, and the
   chat agent's `ai_agents_aim_chatbot`, applied by `ai_agents`): its
   `prompt_logging_tags` filter is an exact match, so the list is explicit
   and must grow with any new tag. Untagged calls (the vector index's
   embeddings) are not recorded.
2. **aim adds two things to each captured call, nothing else:** an
   `AimCallLogSubscriber` writes `aim_run_id` and `aim_duration_ms` into the
   call's debug data, which `ai_logging` stores in its `extra_data` column.
   Tokens, provider, model and time already have columns.
3. **`aim_activity_metrics`, its schema and its subscriber are removed.**
   History in the table is dropped (the figures that matter are in
   [model-call-budget.md](model-call-budget.md)). Eval queries read
   `ai_log`, with duration and run ID via `JSON_VALUE(extra_data, ...)`.
4. **Stealth mode is a later, optional, off-by-default switch**, built as a
   presave hook on `ai_log` that blanks `prompt`, `output_text`,
   `response_text`, `configuration` and `extra_data` (the response and the
   system prompt can carry the same personal data as the prompt, so blanking
   the prompt alone is not enough). Optional PII reduction sits on top. A
   personal-memory site would want it; a business site may not. Implementers:
   decide this before real data goes in.
5. **Duplicated verbose text lines are removed.** The grounding and
   merge-verifier text lines under `log_query_text` are now in the logged
   prompts. The recall query line stays: its embedding call is untagged, so
   `ai_log` never sees it.
6. **Retention is `ai_logging`'s global pruning**, a site-wide setting
   shared with every module and with no per-tag control (an upstream ask).
   Eval work wants 10000 entries and 90 days; this site sets that. aim no
   longer writes `ai_logging.settings` (the update hooks that did were
   removed), so a fresh install records nothing until the site opts in. The
   tag list lives in `ActivityBackendInterface::LOGGED_TAGS`. Planned, not
   built: an opt-in aim setting and an `aim:status` check (TODO.md).

## Consequences

- Personal data can reach the database through logged prompts and
  responses. The PII gate ([ADR-0056](0056-personal-data-write-gate.md))
  stays parked; revisit it with stealth mode before real data.
- aim's own audit lines (`logger.channel.aim`, IDs only) are unchanged: they
  record decisions, which `ai_log` (provider calls only) does not.
- No fact ID on a log row yet. Joining a call to the fact it concerned would
  need the fact ID passed in as input debug data at each call site.
- Upstream candidates, to raise once this is proven: a duration column on
  `ai_log`, and per-tag retention.

## Alternatives considered

- **Keep `aim_activity_metrics`.** Works, but duplicates the suite and stays
  text-free by construction.
- **`ai_observability`.** Emits to the logger and OpenTelemetry; needs a
  metrics backend and has no queryable store or per-run grouping.
- **A second always-on aim-only store.** Rejected as duplication. A snapshot
  of selected runs for eval baselines is fine, written from `ai_log` on
  demand.

## Reviewed and not adopted (2026-10-14)

Checked against the installed `ai` 1.6.x suite while deciding this:

- **Batch embeddings** (`EmbeddingsCollectionInput`): indexing already
  batches inside the standalone `ai_search`; aim's one direct embeddings call
  (`embedText()`) embeds a single string. Nothing to change.
- **Request metadata bag** (`setRequestMetadataValue()`): neither
  `ai_observability` nor `ai_logging` reads it, so it would carry data to no
  consumer. Input debug data (`setDebugDataValue()`) is what reaches
  `ai_log`, and is what decision 2 uses.
- **`ChatMemory` plugins**: the demo recipe already uses
  `private_tempstore_pool` (the chat history reader this note named,
  `AimChatbotSourceTextSubscriber`, went with ADR-0062). No change.
- **Moderation guardrail and AI Reranker**: available, not needed yet;
  check them before building a custom equivalent for ADR-0002 or ADR-0036.
- **`ai_observability`** would be the route to token and latency metrics in
  an external system (OpenTelemetry counters and spans); it needs a collector
  and backend, which this site does not run.
- **`ai_search` dependency**: aim declared the bundled `ai:ai_search`, which
  the suite removes in 1.6.0. Fixed to the standalone
  `ai_search:ai_search` the site already ran.
- **`AimMariaDBProvider` shim**: unchanged. The HNSW `M`/`ef_search`
  overrides are fixed in the provider's 1.0.x head (#3605665) and go once a
  release carries them; the other three (#3609961, #3626257, #3626262) are
  not fixed upstream.
