# CLAUDE.md — AIM Benchmark

Synthetic fact generation and the `aim:benchmark`/`aim:benchmark-cleanup`
Drush commands, split out of core `aim`'s `AimMemoryManager`/`AimCommands`
so a production site doesn't have to ship dev-only load-generation code.
Part of the [aim](../../CLAUDE.md) project - see the root module's
CLAUDE.md for environment, architecture decisions, and the code-style/git
rules shared across all of `aim`'s submodules; this file only covers what
is specific to `aim_benchmark`.

## What lives here

- `src/Service/AimBenchmarkGenerator.php` - `generateBenchmarkFacts()`/
  `deleteBenchmarkFacts()`/`sampleUserIds()`, plus the sentence-template
  and word-pool constants they draw from. Depends on `aim.memory_manager`
  only for scope validation (`allowedScopes()`/`scopeRequiresAccount()`)
  - the thing actually being measured (`reindex()`/`recall()`) stays on
  `AimMemoryManager` and is called directly by the Drush commands below,
  not proxied through this service.
- `src/Drush/Commands/AimBenchmarkCommands.php` - `aim:benchmark`/
  `aim:benchmark-cleanup`. Depends on `aim.memory_manager`,
  `aim_benchmark.generator`, and `aim.embedding_cache_subscriber`
  (ADR-0017's cache, toggled off for `--bypass-cache`).

## Why a separate module, not just files in `aim`

Nothing outside this module's own two files ever called
`generateBenchmarkFacts()`/`deleteBenchmarkFacts()`/`sampleUserIds()` -
they existed solely to support `aim:benchmark`. Moving them here means a
site that installs `aim` for real memory storage never ships load-testing
code it will never run, without changing anything about how the commands
themselves work (same command names, same options, same output).
