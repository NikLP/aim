# AIM demo: community library

A fictional community library (Harbourside Community Library) as a demo
site for [AIM](../../README.md). It is the first archetype starter kit
from [ADR-0014](../../adr/resolved/0014-usecase-archetype-starter-kits.md): a
small, self-contained story that shows recall, abstention, scope and
consolidation without any real data.

## What it applies

- `recipe.yml`: site name and slogan, the chat assistant's persona
  (`aim_chatbot` agent and `aim_demo_assistant`) and its tools (the two
  memory tools plus `aim_literal` for exact values), the chat block's
  wording and its signed-in-only visibility (only if the block exists),
  the block's finder-first gate (`aim_chatbot_gate`, with
  `literals_finder` installed: a clear exact-value question is answered
  from the literal with no chat model),
  `access deepchat api`, `create site aim facts` and `view restricted
  literals` for signed-in users,
  `view literals` for everyone, and
  `index_directly` on so a fact told to the chat is searchable on the next
  question.
- `content/`: default content, imported when the recipe is applied: 31
  fictional facts (19 site, 4 role, 4 user, 4 case) and eight literals
  (`main_phone`, `accounts_phone`, `reservations_phone`, `login`,
  `password_reset`; `staff_line`, `my_account` and `site_name`,
  restricted), exported
  from the demo site with `drush content:export`. Core's importer
  validates each entity, so aim's Guardrails constraint runs. With
  `index_directly` on (this recipe sets it) the facts are embedded and
  indexed as they are imported; the index step below catches anything
  left over, and is needed if you drop that action. An entity whose UUID already exists is skipped, so
  re-applying does not overwrite a literal edited on the site.

It does not set a front page, place the chat block, or configure a
provider.

## Requirements

- Drupal 11 and MariaDB 11.7 or newer (for `VECTOR`).
- A default decision model (Decision on `/admin/config/ai/settings`) for
  the finder; without one the gate passes every question to the
  assistant.
- `drupal/ai` plus a chat provider and an embeddings provider: Ollama with
  `nomic-embed-text` (the shipped index uses it), or a hosted one, in which
  case also remove the `index_directly` action.
- `ai_vdb_provider_mariadb`, `ai_search` and `search_api` **not** enabled
  yet: let the recipe install them. With the provider already enabled the
  apply fails (`The "mariadb" plugin does not exist`, or `Table
  'aim_fact_vectors' already exists`) and rolls back; `drush pmu` the
  three and apply again (2026-10-23).
- Olivero as the front-end theme, if you want the chat block.
- `aim_chatbot`'s chat block only exists if Olivero was the front-end
  theme when the module was enabled. Without it the persona still applies
  and the block wording is skipped.
- The four user-scope facts are about uid 1 ("Sam Okafor", the site
  administrator, in the story). On your site that is your admin account.

## Apply

From the site root:

```bash
ddev drush recipe /var/www/html/web/modules/contrib/aim/recipes/aim_demo_library
ddev drush search-api:index aim_vector_index
```

Use the container path, and adjust `contrib` to `custom` (as on this dev
site) to wherever the module lives. The recipe is strict: it refuses to
apply over config that differs from what it ships, so once the models are
set up (below) it cannot be re-applied; reset by reinstalling instead.

Apply it to a fresh site, not one with data you care about: it overwrites
the settings above, and the facts are added on top of whatever is there
(a fact whose UUID already exists is skipped).

## Fresh install

1. Add the repository for `aim` (until it is on drupal.org; `literals` is,
   as `drupal/literals`):
   `composer config repositories.aim vcs https://github.com/NikLP/aim` and
   `composer config repositories.aim.no-api true`.
2. `composer require drupal/aim:dev-main drupal/literals:^1.0@alpha
   drupal/ai_provider_ollama` plus a chat provider (`literals` has only an
   alpha so far, which aim's `*` constraint does not pull on its own).
3. Run Ollama with `nomic-embed-text`. At `/admin/config/ai/settings` set
   Embeddings (`ollama` / `nomic_embed_text_latest`), a Chat default and a
   Decision default (for the finder).
4. Apply the recipe (above).
5. `drush search-api:index aim_vector_index` (already 100% with
   `index_directly` on).
6. `drush uli` and ask the widget; it is hidden from anonymous visitors.
7. For the MCP part of the demo and the pre-flight: enable `aim_tool_oauth`
   and the OAuth/MCP stack, set the key paths and expose the site over
   HTTPS, per `aim_tool_oauth`'s
   [DEVELOPING.md](../../modules/aim_tool_oauth/DEVELOPING.md) "Setup". A
   recipe cannot do this part (site-specific key paths, the tunnel).

Expected: "Applied AIM demo: community library", 31 facts, and
`drush search-api:status` shows 31/31. Smoke test:
`drush aim:recall "When are you open on Sundays?" --scope=site`.

Known noise: `[warning] Schema errors...` lines on enable are harmless. A
failed recipe prints a long stack trace after the real error; the first
`In ...php line N: message` is the useful one.

## Before you rely on it

- **Applies to a fresh install in one go** (verified 2026-10-19 on Drupal
  11.4): the recipe installs AIM's modules and imports their config itself,
  so there is no `drush en` step. It still overwrites the settings above,
  so use a scratch site.
- **`index_directly` suits local embeddings.** With hosted embeddings each
  save ties up a worker for seconds ([ADR-0015](../../adr/resolved/0015-immediate-consolidation-considered-deferred.md)
  addendum); remove that action.
- **Thresholds are tuned to particular models.** The distance settings
  (Chatbot recall maximum distance `recall_max_distance`, Recall gap
  `recall_gap`, Auto-merge distance `auto_threshold`, Ambiguous distance
  `ambiguous_threshold`) were measured on this dataset with
  `nomic-embed-text` on MariaDB
  ([ADR-0019](../../adr/resolved/0019-recall-abstention-distance-cutoff.md)).
  This recipe's Gate match threshold (`gate_match_threshold`, 0.8) and
  Gate choice margin (`gate_choice_margin`, 0.4) were measured with hosted
  Jev (`typesafeai`) as the Decision default. Re-check them if you change
  either model.
- **Review gate by writer.** Any signed-in visitor can tell the chat a
  fact (the recipe grants `access deepchat api` and `create site aim
  facts` to authenticated users only). A member's fact waits untrusted for
  review (the chat channel ships as `hold`); a writer who could trust it
  by hand (`administer aim`, `review aim facts`, `trust site aim facts`)
  starts it trusted, so the presenter's own fact is live at once
  ([ADR-0037](../../adr/resolved/0037-transient-source-passages-for-grounding.md)'s
  2026-10-22 addendum).

## Running the demo

### Before going on stage

```bash
node demo/preflight.js   # site wrapper; see below
```

It checks the site (local and through the public funnel URL), Ollama, the
vector index, the full OAuth handshake as Claude.ai does it (client
registration, consent, PKCE), all four scopes over MCP, MCP abstention on an
off-topic question, save-then-search, and the chat widget in a real browser
(answer, then write-then-read), plus that anonymous visitors can't see or
call the widget. It needs DDEV up, a public URL that reaches the site (a
tunnel such as Tailscale Funnel), Ollama running, and Playwright (set
`PLAYWRIGHT_PATH`). The script is [preflight/preflight.js](preflight/preflight.js);
run it from the site root. Site-specific values (`publicUrl`, `localUrl`)
go in `<site root>/demo/preflight.local.json` or the
`PREFLIGHT_PUBLIC_URL`/`PREFLIGHT_LOCAL_URL` environment variables; the
questions and expected answers are in
[preflight/preflight.config.json](preflight/preflight.config.json). Any FAIL line says what to check.

### Reset between runs

Facts added during a run stay until you reset. The chat widget is for
logged-in users only (anonymous visitors don't see it and `/api/deepchat`
returns 403), so the audience can't add facts through it. Return to the
starting state by reinstalling the site and following "Fresh install"
below (the recipe is strict, so it will not re-apply over the manual model
setup). A literal edited on stage is restored by the reinstall too.

### Suggested flow (about 8 minutes)

1. **The widget, site scope.** Log in first (`ddev drush uli
   --uri=<public URL>`), since the widget is
   hidden from anonymous visitors. Open the chat and ask:
   - "When are you open on Sundays?" (recalls from memory)
   - "Do you charge late fees?" (finds "overdue fines": meaning, not keywords)
   - "Can I bring my dog?"
   - "Do you have a cafe?" (it says it doesn't have that recorded)
   - "We now have a cafe, open 10 to 3 every day." Then ask about the cafe
     again. You are signed in as the admin, a reviewer, so the fact is
     trusted the moment it is saved and recalled immediately.
   - Optional, the review gate: in a private window sign in as a member
     (any account without a trust permission; `ddev drush user:create
     member --password=...`) and tell the chat a new fact. It says the
     fact waits for staff review, and asking about it (as either account)
     finds nothing. Trust it at `/admin/content/aim-facts` (Trusted filter,
     Trust action) and ask again.
   - "What is the capital of Australia?" (it stays on topic)
2. **Under the hood.** Log in (`ddev drush uli --uri=<public URL>`)
   and open `/admin/content/aim-facts`: facts are content entities, with
   scope (a bundle), source, provenance and the person who wrote them.
   `/admin/config/aim/settings` has the recall cutoff, and
   `/admin/config/aim/user-scope-access` the role-visibility matrix.
3. **The same memory from another agent, over MCP.** In Claude.ai add a
   custom connector for `<public URL>/mcp`
   (OAuth, dynamic client registration, scopes `aim:recall aim:remember`).
   Then ask Claude:
   - "What must editors do before publishing an image?" (role scope)
   - "How does Sam like answers formatted?" (user scope, your own account)
   - "When does the events calendar launch?" (case scope)
   - Tell Claude a new fact, then ask the widget about it.
4. **Optional, from the terminal.**
   - `ddev drush aim:recall "opening hours" --scope=site --max-distance=0.48`
   - Save a near-duplicate ("The library opens at 9am on weekdays"), then
     `ddev drush aim:consolidate --scope=site --dry-run` to show the
     merge/retire decision.

### Exact values (literals), about 3 minutes

The recipe imports eight literals: five public (`main_phone`,
`accounts_phone`, `reservations_phone`, `login`, `password_reset`) and
three restricted to signed-in visitors through `view restricted literals`
(`staff_line`, `my_account`, `site_name`). It also gives the assistant aim's lookup tool
(`tool:aim_literal`), and its persona says to use it for phone numbers and
web addresses.

1. **Live value.** In the widget ask "How do I ring the library?" The
   assistant calls the lookup tool and gives the number exactly. Edit
   `main_phone` at `/admin/content/literals` and ask again: the new number,
   no reindex. A reset does not overwrite an edited literal; put it back
   by hand.
2. **Different viewer.** The staff line is visible only when logged in. As
   anonymous it is not found; no fact names it.
3. **Another agent.** Over MCP, ask Claude for the library phone number: the
   `aim_literal` tool returns the same value, as the OAuth user.

Pre-flight covers the lookup tool and the restricted-literal boundary
(`literals:` check). Keep demo literals one exact value each ("the main phone number"),
not topics like opening hours, which vary by day.

### What to say about how it is built

- Facts are content entities. The four scopes (user, role, site, case) are
  bundles, real `aim_scope` config entities.
- Search is Search API with the AI Search backend and MariaDB's native
  `VECTOR` type with an HNSW index, in the same database as everything
  else. Embeddings run locally on Ollama.
- Every write goes through `drupal/ai` Guardrails. Consolidation
  (dedupe/supersede) runs from the Queue API, not on the request.
- The MCP tools are Tool API plugins, exposed with `mcp_server`, secured
  with `simple_oauth` scopes and Drupal permissions per scope.
- Recall drops matches past a calibrated distance, so an unrelated
  question gets "nothing relevant" instead of the nearest unrelated fact.

### Be upfront about

- It is a proof of concept. A member's chat fact waits for review, but a
  reviewer's own fact is live at once. The grounded check ships off; in
  shadow mode it only records a score.
- The chat widget needs the `access deepchat api` permission, which only
  authenticated users have (registration is admin-only). Anyone you give an
  account can add held facts, so reset after a session.
- Chat replies take about 6 seconds (a hosted model plus a memory lookup);
  MCP recall and the search itself take milliseconds.
- The recall cutoff is a heuristic, calibrated per dataset. A question near
  a topic the facts don't cover can still get the nearest facts, and the
  model has to notice they don't answer it.
- Do not use it for classified data: a hosted model can see what it recalls.
