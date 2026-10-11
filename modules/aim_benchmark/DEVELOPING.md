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

## `scripts/decision-eval.php` - decision-model scorer through Drupal

The way to score a decision model: every call goes through
`aim.backend.decision` and the provider and model set for that activity in
`aim.settings` (keys stay in Drupal; calls land in `aim_activity_metrics`).
Tasks `grounding` (every wording in the script's `$grounding_qs`, batched
per passage as live), `merges` (the live verifier question and cutoff) and
`pairs` (the live pair question). An optional second argument names a set
file in `scripts/`; `merges` takes an optional cutoff third.

```bash
ddev drush php:script web/modules/custom/aim/modules/aim_benchmark/scripts/decision-eval.php -- grounding
ddev drush php:script .../decision-eval.php -- merges decision-eval-merges-hard.json
ddev drush php:script .../decision-eval.php -- pairs decision-eval-real-pairs.json
```

Sets: `decision-eval-sets.json` (24 hand-written pairs, merges with five
supersession items added 2026-10-11, plus a `gate` set no task reads
yet), `decision-eval-real-pairs.json` (25 real near-neighbour pairs),
`decision-eval-merges-hard.json` (20 subtle faults) and
`decision-eval-grounding.json` (52 items; the first 44 labels
human-verified 2026-10-10, the 8 clean-up and meaning-changing-drop items
added 2026-10-11 not yet). The drift sets belong to ADR-0043's parked
prototype. Output: accuracy, a confusion matrix and unsafe errors (a
data-losing UPDATE/RETIRE) for pairs; AUC, errors at the live cutoff and
score ranges for merges and grounding; per-call time. Local-model results:
[ADR-0038](../../adr/0038-local-decision-models-parked.md).
