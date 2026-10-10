# AIM demo: community library

A fictional community library (Harbourside Community Library) as a demo
site for [AIM](../../README.md). It is the first archetype starter kit
from [ADR-0014](../../adr/0014-usecase-archetype-starter-kits.md): a
small, self-contained story that shows recall, abstention, scope and
consolidation without any real data.

## What it applies

- `recipe.yml`: site name and slogan, the chat assistant's persona
  (`aim_chatbot` agent and `aim_demo_assistant`) and its tools (the two
  memory tools plus `aim_literal` for exact values), the chat block's
  wording and its signed-in-only visibility (only if the block exists),
  `access deepchat api` for signed-in users, `recall_max_distance` 0.48, and
  `index_directly` on so a fact told to the chat is searchable on the next
  question.
- `content/`: default content, imported when the recipe is applied: 31
  fictional facts (19 site, 4 role, 4 user, 4 case) and three literals
  (`main_phone`; `staff_line` and `my_account`, restricted), exported
  from the demo site with `drush content:export`. Core's importer
  validates each entity, so aim's Guardrails constraint runs. With
  `index_directly` on (this recipe sets it) the facts are embedded and
  indexed as they are imported; the index step below catches anything
  left over, and is needed if you drop that action. An entity whose UUID already exists is skipped, so
  re-applying does not overwrite a literal edited on the site.

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
ddev drush recipe:apply /var/www/html/web/modules/custom/aim/recipes/aim_demo_library
ddev drush search-api:index aim_vector_index
```

The path is the one inside the container. To reset the facts to the
recipe's 31 (removing any added since), delete them first:
`ddev drush entity:delete aim_fact -y` and `ddev drush queue:delete
aim_consolidate`, then the two commands above.

Apply it to a fresh site, not one with data you care about: it overwrites
the settings above, and the facts are added on top of whatever is there
(a fact whose UUID already exists is skipped).

## Before you rely on it

- **Applied to this demo site only** (2026-10-10, after deleting its
  facts; the demo pre-flight passed), not yet to a fresh install. Apply
  it to an isolated scratch site first.
- **`index_directly` suits local embeddings.** With hosted embeddings each
  save ties up a worker for seconds ([ADR-0015](../../adr/resolved/0015-immediate-consolidation-considered-deferred.md)
  addendum); remove that action.
- **0.48 is calibrated to this dataset** with `nomic-embed-text`
  ([ADR-0019](../../adr/0019-recall-abstention-distance-cutoff.md)).
  Recalibrate if the model changes.
- **No review gate.** Every fact is live the moment it is saved, and any
  signed-in visitor can add facts through the chat widget (the recipe
  grants `access deepchat api` to authenticated users only)
  ([ADR-0002](../../adr/0002-governance-deferred-guardrails-mandatory.md)).
