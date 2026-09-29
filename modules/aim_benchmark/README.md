# AIM Benchmark

Synthetic fact generation and the `aim:benchmark`/`aim:benchmark-cleanup`
Drush commands for measuring `recall()` latency at scale. Part of
[AIM](../../README.md) - see the root module for the overall pitch.

## What it ships

- `AimBenchmarkGenerator` - creates template-generated synthetic facts
  (not `devel_generate` output; see [DEVELOPING.md](DEVELOPING.md) for
  why) tagged with a run tag, and deletes them again by that tag.
  Bypasses Guardrails and the consolidation queue, so the only real
  runtime cost is one embedding-API call per fact at reindex time.
- `drush aim:benchmark` - generates facts in batches up to each
  requested checkpoint, reindexes, and times a batch of `recall()` calls
  at each checkpoint.
- `drush aim:benchmark-cleanup <tag>` - removes every fact a previous
  `aim:benchmark` run created.

This is a development/ops tool, not something a production site's
runtime code depends on - a site that never needs to measure recall
latency can leave it uninstalled.

## Requirements

- `aim` (this module's parent) - specifically its `aim.memory_manager`
  and `aim.embedding_cache_subscriber` services.

## Getting started

```bash
drush aim:benchmark --scope=site --checkpoints=50,200,500 --cleanup
```

Full command reference, options, and interplay with ADR-0017's
query-embedding cache: [DEVELOPING.md](DEVELOPING.md).
