# ADR-0017: Cache query embeddings via a drupal/ai event subscriber

**Status:** Accepted - built and verified 2026-09-27
**Date:** 2026-09-20

## Context

`aim:benchmark` (2026-09-10, see DEVELOPING.md) measured `recall()` at
450-540ms per call on hosted embeddings, dominated by the query-embedding
network round trip, against 33-35ms on local Ollama. The same text always
embeds to the same vector for a given model, so the round trip is pure
repeat work whenever a query recurs.

aim never calls an embeddings API itself, which shapes where a cache can
go:

- `recall()` and consolidation's `findNearestNeighbor()` both build a
  Search API query with `->keys($text)` and run it through
  `AimMemoryManager::executeSearchQuery()`. No other aim code builds a
  query (checked by grep, submodules included).
- `SearchApiAiSearchBackend` (contrib `ai_search`) embeds the keys
  internally through the `ai.provider` plugin, then runs the vector
  search. aim never holds the vector.
- Fact text is embedded at index time through the same provider path
  (`EmbeddingBase`): same operation type (`embeddings`), same `ai_search`
  tag, plus an optional `skip_moderation` tag. Query-time and index-time
  calls cannot be told apart by tag, and aim's server config has
  `skip_moderation: false`, so the tag sets are identical.
- `ProviderProxy` dispatches `PreGenerateResponseEvent` before every
  provider call and returns the event's forced output object immediately
  when a subscriber sets one, without calling the provider.

## Decision

**Cache query-time embeddings with an aim event subscriber on drupal/ai's
provider events.** No contrib patch, no decorated service.

1. **Hook point.** On `PreGenerateResponseEvent`, a cache hit calls
   `setForcedOutputObject()`. On `PostGenerateResponseEvent`, a miss stores
   the output. Only operation type `embeddings` is considered.
2. **Query-time only, via a request-scoped flag.** `executeSearchQuery()`
   is the one choke point for aim queries, so it sets a flag on the
   subscriber around `$query->execute()` (try/finally). The subscriber
   ignores every call made while the flag is off, which excludes
   index-time embeds without any tag guessing.
3. **Key.** sha256 of provider ID, model ID, output-affecting embeddings
   configuration (dimensions), and the trimmed query text. Case is
   preserved, since models can be case-sensitive. The model ID in the key
   means a model change needs no invalidation; old entries age out.
4. **Storage.** A dedicated cache bin (`cache.aim_embeddings`), not
   `cache.default`, so it can be flushed on its own and redirected to
   another backend from `settings.php` later. Database-backed today (this
   site has no Redis); Redis only if the bin itself becomes a bottleneck.
   The entry is the normalized vector (roughly 15-20KB serialized at 768
   dimensions) with a TTL so the bin cannot grow without bound. A hit
   rebuilds an `EmbeddingsOutput` from it; the backend only reads
   `getNormalized()`.
5. **Instrumentation in the same subscriber.** Log hit/miss and embed time
   per call. This delivers the embed-time vs. DB-search-time split TODO.md
   asks for, without touching `recall()`.
6. **Benchmark.** `aim:benchmark` gets a bypass flag (or a cold/warm
   split). Without one its numbers silently become cache numbers, and the
   450-540ms vs. 33-35ms comparison stops being reproducible.

## Alternatives considered

- **Cache inside `recall()`.** Not possible without re-implementing the
  backend's query path: aim never holds the vector.
- **Reuse the fact's own stored vector via `vector_input`, skipping the
  embed call entirely instead of caching it.** Re-examined 2026-09-27
  after the site moved to standalone `ai_search` 1.3.0-alpha5, which adds
  exactly this as a public option (`$query->setOption('vector_input',
  $vector)`, read by `SearchApiAiSearchBackend::getSearchVectorInput()`).
  Still blocked: getting the fact's already-indexed vector back out of
  `aim_facts` requires the VDB provider's `getRawEmbeddingFieldName()`,
  which `ai_vdb_provider_mariadb`'s `MariaDBProvider` does not implement
  (inherits the interface's default `NULL`). Without a stored vector to
  hand it, `vector_input` only accepts a freshly embedded one - the same
  cost as `->keys()`, no saving. Tracked in TODO.md as a feature request
  to file; revisit this alternative if it lands, since it would eliminate
  the redundant embed rather than just caching it.
- **Patch `ai_search`.** aim is headed for drupal.org as an independent
  project (see CLAUDE.md), and a patch in its own `composer.json` would
  not reliably reach consumer sites.
- **Decorate `ai.provider`.** Wider blast radius (every AI call on the
  site, chat included) and more code than a subscriber.
- **Cache whole search results (query to fact IDs).** Also skips the DB
  search, but results depend on the viewer's access and change on every
  fact write, expiry, and consolidation, so it needs real invalidation.
  An embedding is a pure function of (model, text): nothing to invalidate.

## Consequences

- Repeat queries skip the embeddings call entirely. Two beneficiaries:
  repeat identical `recall()` calls (wherever MCP clients, the chatbot, or
  the benchmark repeat a query), and consolidation sweeps -
  `findNearestNeighbor()` embeds each fact's stored text as a query, so
  every sweep after the first re-embeds unchanged facts for free. The
  second is probably the larger win, and being async it saves cost and
  wall time rather than user-facing latency.
- Hit rate for free-form chatbot questions is likely low; not measured.
  The subscriber's logging (decision 5) is what will answer it.
- The win is small on local Ollama (33-35ms). This is a hosted-embeddings
  optimization, complementary to a local model: caching helps repeat
  queries, a local model helps every query.
- A hit returns before `PostGenerateResponseEvent` is dispatched, so other
  subscribers to it (logging, usage tracking) do not see cache hits. That
  is accurate, since no provider call was made, but aim's own log line is
  then the only record of one.
- The cache holds vectors keyed by a hash, not query text, but embeddings
  can leak information about their source text and these derive from user
  queries. Fine under CLAUDE.md's non-classified assumption; worth knowing
  before pointing the bin at a shared backend.
- Depends on drupal/ai's `setForcedOutputObject()` contract. Not pinned by
  a kernel test (see "Built 2026-09-27" below) - if a future drupal/ai
  release changes that contract, the failure mode is silent (the
  subscriber's forced output is ignored, every call falls through to the
  real provider), not a hard error, so watch for it if `ai`'s version
  bumps and this cache's hit-rate logging goes quiet.

## Implementation outline

- New `src/EventSubscriber/` subscriber; the service and `cache_bin`
  registration go in `aim.services.yml`.
- `executeSearchQuery()` toggles the flag, which adds a constructor
  argument to `AimMemoryManager` (a `@param` and the services.yml entry).
- `aim:benchmark` bypass flag.
- Kernel test with a call-counting stub provider: a second identical
  query makes zero provider calls, an index-time embed is not cached, a
  different model ID misses.
- At build time: fold the result into DEVELOPING.md (replacing its
  "Recommended next fix, not built" paragraph), tick the TODO.md items,
  and mark this ADR Accepted.
- Left to build time: the TTL value, the bypass flag's name, and whether
  the TTL belongs in `aim.settings`.

## Built 2026-09-27

All of the above, as designed, with two deviations:

- **No kernel test.** aim has no test infrastructure yet (checked before
  starting - no `tests/` directory anywhere in the module), and a kernel
  test exercising this subscriber would need `aim` fully installed,
  pulling in its whole dependency chain (`ai_search`, `ai_vdb_provider_mariadb`,
  `search_api`, `taxonomy`) just to test a subscriber whose own
  dependencies are three generic Drupal services. Verified live instead,
  against the real site and the real event dispatcher: confirmed the
  service resolves and is tagged for both `ai.pre_generate_response` and
  `ai.post_generate_response`; ran two identical `aim:recall` calls and
  confirmed a logged miss then hit with identical results both times (the
  cached vector produces the same search results as a fresh embed); ran
  `aim:consolidate --dry-run` and `aim:benchmark --bypass-cache`
  afterward and got the same output as before this change. A kernel test
  is still worth adding once the module has test infrastructure for
  anything else - own TODO.md item.
- **TTL is a class constant** (`AimEmbeddingCacheSubscriber::TTL`, 7
  days), not an `aim.settings` value - nothing in "left to build time"
  argued for admin-editable over hardcoded, and every other value this
  ADR discusses (the bypass flag's name, the cache bin name) stayed a
  code-level decision too.

Real finding from the instrumentation (decision 5): a single missed
embed call on this site's local Ollama logged 556ms - well above the
33-35ms `aim:benchmark` aggregate (DEVELOPING.md, "Benchmarking"). The
aggregate is a real average across many calls in one warm process; a
single call, especially the first in a fresh `drush` bootstrap, can cost
much more. Worth knowing before treating "local Ollama recall() is
~35ms" as a per-call guarantee rather than a steady-state average.
