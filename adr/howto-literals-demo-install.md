# Install the Literals demo (finder + chat) on a DDEV Drupal 11 site

For someone who knows Drupal and composer but has never seen Literals. Follow the steps in order.

Last verified 2026-10-18 on DDEV, Drupal 11.4, PHP 8.4, MariaDB 11.8, `drupal/literals` dev-main @ bc42a40.
Install method: composer vcs repository (not yet on drupal.org/packagist).

**What you get:** a chat box that answers simple questions like
"what is the main phone number?" or "how do I reset my password?" by picking one stored value (a "literal") and replying with a fixed template. **No chat model is involved.** If the question isn't covered, it says "I don't have that."

**What you need:** a Drupal 11 site with the AI module and one AI provider that supports the **decision** operation, plus its API key. This guide uses the TypeSafe/SubQ provider.

---

## 1. Get the code

```bash
ddev composer config repositories.literals vcs https://github.com/NikLP/literals
ddev composer require drupal/literals:dev-main drupal/ai drupal/ai_provider_typesafeai
```

Notes:
- Literals needs no vector database, search_api or embeddings.
- `ai_chatbot` and `ai_assistant_api` ship inside `drupal/ai`, so there's nothing extra to require.
- If composer tries SSH for the GitHub repo, add `"no-api": true` to the repository entry or use an https URL with a token.

## 2. Configure a decision provider

The finder asks a decision model to choose the matching literal. Without one, nothing works.

1. Enable the provider: `ddev drush en ai_provider_typesafeai key -y`
2. Add a Key at `/admin/config/system/keys/add` holding the API key. **Make sure the stored value has no trailing space or newline**, or the HTTP header breaks.
3. Select that Key at `/admin/config/ai/providers/typesafeai`.
4. At `/admin/config/ai/settings`, set the default provider for the **Decision** operation to TypeSafe, model `subq-snap-preview`.

Check it, expecting `subq-snap-preview` to print:

```bash
ddev drush ev '$i=\Drupal::service("ai.provider")->createInstance("typesafeai"); echo implode(", ", array_keys($i->getConfiguredModels("decision")));'
```

Notes:
- TypeSafe/SubQ supports only decision, text classification and moderation. It has no chat model, and that's fine here.
- Any other provider works if it lists a model for Decision. With Ollama (`ai_provider_ollama`), the Chat list also shows embedding-only models, which is a known provider bug. Check the real capabilities with `curl http://localhost:11434/api/tags`.

## 3. Apply the demo recipe

Run it from a site where Literals is **not** installed yet. The recipe installs everything. Don't enable any module first.

```bash
ddev drush recipe /var/www/html/web/modules/contrib/literals/recipes/literals_demo_library
```

Use the container path as shown, not a path relative to your host. If your code lives elsewhere, adjust it to the module's location inside the container.

Expect 7 steps and "Literals: demo library applied successfully". The recipe:
- installs `literals`, `literals_finder`, `literals_chat`, `ai_chatbot` and `ai_assistant_api`
- creates 8 literals (4 phone numbers, login, password reset and my-account links, and the site name)
- places the chat block bottom-right in the front-end theme's `content` region
- gives **Authenticated** users `use literals chat` and `access deepchat api`

If the recipe fails, run `ddev drush cr` and check `ddev drush watchdog:show`.

## 4. Check the finder from the command line

```bash
ddev drush ev '$r=\Drupal::service("literals_finder.finder")->find("What is the main phone number?", \Drupal::currentUser()); echo $r->outcome;'
```

Expected: `match`. A question the data doesn't cover, such as "how much is a coffee", gives `none`.

## 5. Try the chat

1. Log in as a normal user (not anonymous: see step 6).
2. Open the front page and ask the box:

| Question | Expected answer |
| --- | --- |
| What is the main phone number? | Main phone: +44 1223 000000 |
| How do I reset my password? | A link to the password reset page |
| How much is a coffee? | I don't have that. Try asking another way. |

Only phone numbers, links and the site name are in the demo data. Opening hours, borrowing and events questions get "I don't have that".

Testing the API with `curl` returns 403 because the endpoint needs a CSRF token tied to a browser session. Test in a browser.

## 6. Optional: let anonymous visitors chat

The recipe doesn't grant this. To allow it:

```bash
ddev drush role:perm:add anonymous 'use literals chat'
ddev drush role:perm:add anonymous 'access deepchat api'
ddev drush cr
```

Literals marked restricted are visible to nobody by default. Grant `view restricted literals` to a staff role to see them.

## 7. Optional: score the example question set

The module ships a scored question set for the demo literals.

```bash
ddev drush literals:eval --check
ddev drush literals:eval
```

`--check` confirms every expected literal exists, with no model calls. Expected with `subq-snap-preview` (2026-10-21): 22/22 answerable questions found, none wrong-confident, 15/15 unanswerable questions answered "none". About 0.3 seconds a query. Bigger pools and more question sets are site data, not shipped: see the module's `eval/TUNING.md` for how to write and sweep your own.

Don't run plain `drush literals:eval` against the demo data. Its default question set expects literals (like opening hours) that the demo recipe doesn't create, so it reports misses.

## Start over

```bash
ddev drush ev '$s=\Drupal::entityTypeManager()->getStorage("literal"); $s->delete($s->loadMultiple());'
ddev drush ev '\Drupal\block\Entity\Block::load("literals_demo_chat")?->delete();'
ddev drush pmu literals_chat literals_finder literals -y
ddev drush cr
```

Delete the literals before uninstalling. The module won't uninstall with literal content in place.

## Limits to know about

- **No fallback.** `literals_chat` answers from literals only. A question the finder can't answer ends at "I don't have that". For a chat model behind the finder, use AIM's `aim_chatbot_gate` processor instead.
- **Short or yes/no questions are weak.** Single words ("staff") and "do you have X?" often give an unclear match and return nothing.
- **Logging.** To see what the model chose, enable `ai_logging` with `prompt_logging` and `prompt_logging_output`. The watchdog `Find:` line shows the outcome but not the confidence.
- More detail and evidence: `notes/findings.md`.
