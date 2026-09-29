# AIM Benchmark - Developer Reference

Mechanism detail and gotchas for `aim_benchmark`. Pitch/requirements in
[README.md](README.md), operating rules in [CLAUDE.md](CLAUDE.md), root
module reference in [../../DEVELOPING.md](../../DEVELOPING.md).

---

## `aim:benchmark` - retrieval latency

```bash
drush aim:benchmark [--scope] [--checkpoints] [--queries] [--cleanup] [--bypass-cache]
drush aim:benchmark-cleanup <tag>
```

Generates synthetic facts (a template/word-pool generator, not
`devel_generate`), reindexes, and times batches of `recall()` calls at
each checkpoint. Deliberately bypasses Guardrails and the consolidation
queue via `setSyncing(TRUE)` (core's `SynchronizableInterface` flag -
`AimHooks`/the guardrail constraint both check `isSyncing()` first).
Every generated fact is tagged `source=<run tag>` for cleanup.

**Numbers so far:** hosted embeddings (`amazeeio__mistral-embed`),
`recall()` averaged 450-540ms, dominated by the query-embedding network
round trip. Local Ollama (`nomic-embed-text`), same checkpoints: 33-35ms,
~15x faster - confirms the network-hop theory rather than the SQL/HNSW
layer being the cost. Not yet run at thousands-of-facts scale.

**Interplay with the query-embedding cache** (ADR-0017,
`Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber`, root aim's
`aim.embedding_cache_subscriber`): `aim:benchmark`'s sample queries repeat
every `--queries` iterations within a checkpoint, so without
`--bypass-cache`, every repeat after the first becomes a cache hit and
the numbers stop being comparable to the 2026-09-10 measurements above or
to a run with a different `--queries` value. The cache logs a hit/miss
line and, on a miss, the provider call's own duration - the embed-time
vs. DB-search-time split previously only inferred from `aim:benchmark`'s
one aggregate number. Verified live: two identical `aim:recall` calls
logged a miss then a hit with identical results; the missed call's embed
cost logged as 556ms on this site's local Ollama, noticeably higher than
`aim:benchmark`'s 33-35ms aggregate above - worth knowing before treating
that aggregate as a per-call guarantee.

## Using the generator outside `aim:benchmark`

`AimBenchmarkGenerator::generateBenchmarkFacts()` is also the tool for
building a throwaway skewed corpus to recheck vector-index accuracy (root
[DEVELOPING.md](../../DEVELOPING.md)'s "Rechecking accuracy" section) -
e.g. from `drush php:eval`:

```php
\Drupal::service('aim_benchmark.generator')
  ->generateBenchmarkFacts('user', $n, 'zzpar', [$uid]);
```

`aim:benchmark-cleanup zzpar` removes it again afterward.
