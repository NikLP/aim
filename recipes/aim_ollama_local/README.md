# AIM: local Ollama defaults

Points [AIM](../../README.md) at a local Ollama instance for embeddings,
the sovereign path in [ADR-0004](../../adr/0004-sovereignty-and-poc-build-order.md).
For anyone who wants the same local-embeddings setup this module's own
PoC development uses, without hand-editing the three config objects
involved.

## What it applies

- `ai_provider_ollama.settings`: `host_name`/`port` pointed at
  `http://host.docker.internal:11434`.
- `ai.settings`: `default_providers.embeddings` set to the `ollama`
  provider, model `nomic_embed_text_latest`.
- `search_api.index.aim_vector_index`: `options.index_directly` on, so a
  fact told to the chat is searchable on the next question. Safe
  unconditionally for local embeddings; with a hosted provider each save
  would tie up a worker for seconds instead
  ([ADR-0015](../../adr/0015-immediate-consolidation-considered-deferred.md)
  addendum).

It does not set `recall_max_distance` or HNSW tuning (`M`/`ef_search`).
Both are dataset-specific ([ADR-0019](../../adr/0019-recall-abstention-distance-cutoff.md),
[ADR-0023](../../adr/resolved/0023-hnsw-tuning-and-thin-provider-shim.md)) - run
`drush aim:benchmark` against your own data and set those yourself, see
[DEVELOPING.md](../../DEVELOPING.md), "Rechecking accuracy".

## Requirements

- Ollama running and reachable from the DDEV container at
  `host.docker.internal:11434` (the DDEV default). A native or remote
  Ollama install needs a different `host_name`/`port` - edit `recipe.yml`
  before applying.
- The `nomic-embed-text` model pulled (`ollama pull nomic-embed-text`).
- `ai_provider_ollama` and `ai_vdb_provider_mariadb` composer-present; the
  recipe installs both if not already enabled.

## Apply

From the site root:

```bash
ddev exec vendor/bin/dr recipe:apply web/modules/custom/aim/recipes/aim_ollama_local
```

Then run `drush aim:benchmark` and follow DEVELOPING.md's "Rechecking
accuracy" to set a recall cutoff and HNSW tuning for your own data.

## Before you rely on it

- **Never applied.** The recipe passes core's schema validation, nothing
  more. Apply it to an isolated scratch site first.
- **`host.docker.internal` assumes DDEV.** It is this module's own
  development default, not a generic default - override it for any other
  environment.
- **No recall cutoff or HNSW tuning set.** Recall runs uncalibrated
  (module defaults: `M=6`, `ef_search=20`, no distance cutoff) until you
  run `aim:benchmark` and set your own.
