# AIM demo: community library

A fictional community library (Harbourside Community Library) as a demo
site for [AIM](../../README.md). It is the first archetype starter kit
from [ADR-0014](../../adr/0014-usecase-archetype-starter-kits.md): a
small, self-contained story that shows recall, abstention, scope and
consolidation without any real data.

## What it applies

- `recipe.yml`: site name and slogan, the chat assistant's persona
  (`aim_chatbot` agent and `aim_demo_assistant`), the chat block's wording
  (only if the block exists), `recall_max_distance` 0.48, and
  `index_directly` on so a fact told to the chat is searchable on the next
  question.
- `facts.json`: 31 fictional facts in `drush aim:remember --file` format
  (19 site, 4 role, 4 user, 4 case). A recipe can't run a command, so they
  load as a second step, through `remember()`, which means Guardrails,
  embedding and indexing all run.

It does not set a front page, place the chat block, or configure a
provider.

## Requirements

- A working AIM install with a chat provider and embeddings
  ([DEVELOPING.md](../../DEVELOPING.md), "Setting up vector search").
- `aim_chatbot`'s chat block only exists if Olivero was the front-end
  theme when the module was enabled. Without it the persona still applies
  and the block wording is skipped.
- The four user-scope facts are about uid 1 ("Sam Okafor", the site
  administrator, in the story). On your site that is your admin account.

## Apply

From the site root:

```bash
ddev exec vendor/bin/dr recipe:apply web/modules/custom/aim/recipes/aim_demo_library
ddev drush aim:remember --file=web/modules/custom/aim/recipes/aim_demo_library/facts.json
```

Apply it to a fresh site, not one with data you care about: it overwrites
the settings above, and the facts are added on top of whatever is there.

## Before you rely on it

- **Never applied.** The recipe passes core's schema validation, nothing
  more. Apply it to an isolated scratch site first.
- **`index_directly` suits local embeddings.** With hosted embeddings each
  save ties up a worker for seconds ([ADR-0015](../../adr/resolved/0015-immediate-consolidation-considered-deferred.md)
  addendum); remove that action.
- **0.48 is calibrated to this dataset** with `nomic-embed-text`
  ([ADR-0019](../../adr/0019-recall-abstention-distance-cutoff.md)).
  Recalibrate if the model changes.
- **No review gate.** Every fact is live the moment it is saved, and the
  chat widget lets any visitor add facts unless you restrict the
  `access deepchat api` permission
  ([ADR-0002](../../adr/0002-governance-deferred-guardrails-mandatory.md)).
