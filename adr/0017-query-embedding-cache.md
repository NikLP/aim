# ADR-0017: Cache query embeddings via a drupal/ai event subscriber

**Status:** Proposed - designed, not built
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
- Depends on drupal/ai's `setForcedOutputObject()` contract. A kernel test
  pins it.

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
