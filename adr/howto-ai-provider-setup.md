# Set up AI providers (SubQ, amazee.ai, Ollama) on a DDEV Drupal 11 site

For someone who knows Drupal and composer. Covers the three providers the Literals and AIM demos use, and the traps hit setting them up on a clean site.

Last verified 2026-10-19 on DDEV, Drupal 11.4, PHP 8.4, `drupal/ai` 1.6.x-dev, `ai_provider_typesafeai` ^1.1, `ai_provider_amazeeio`, `ai_provider_ollama` 1.2.0-rc3. Done with `drush`/`ddev` commands and a Key per secret. Nothing here was done through the admin UI, so the UI screens may differ slightly.

## Which provider does what

| Provider | Used for | Models seen |
| --- | --- | --- |
| TypeSafe/SubQ (`ai_provider_typesafeai`) | Decision, text classification, moderation. **No chat, no embeddings.** | `subq-snap-preview` |
| amazee.ai (`ai_provider_amazeeio`) | Chat (all chat variants), also vision and translate | `claude-5-sonnet`, `claude-5-opus`, `claude-4-*-opus`, `mistral-small-latest`, ... |
| Ollama (`ai_provider_ollama`) | Embeddings (local) | `nomic_embed_text_latest` |

- **Literals demo** needs only a Decision provider (SubQ).
- **AIM demo** needs Chat (amazee.ai), Embeddings (Ollama), and the Decision/Moderation/Text classification defaults set to SubQ if you want them.

## 0. Prerequisites

```bash
ddev composer require drupal/ai drupal/key drupal/ai_provider_typesafeai drupal/ai_provider_amazeeio drupal/ai_provider_ollama
ddev drush en ai ai_provider_typesafeai ai_provider_amazeeio ai_provider_ollama key -y
```

- `drush si minimal` followed by a recipe does **not** enable `key`. Enable it yourself.
- Enabling `ai_provider_amazeeio` also creates keys `amazeeio_ai` and `amazeeio_ai_database` automatically (see below).
- Plain keys on disk: on this site they are files in `keys/` (`subq.key`, `typesafe.key`, `amazee_api.key`, `amazee_mgmt.key`), mounted in the container at `/var/www/html/keys/`. Keep this folder out of git.

### Key files and trailing newlines

Every key file ends in a newline. A trailing newline in the stored value breaks the HTTP header. Use the Key module's **File** provider with **strip_line_breaks** on:

```bash
ddev drush ev '\Drupal\key\Entity\Key::create([
  "id" => "subq", "label" => "SubQ",
  "key_type" => "authentication", "key_type_settings" => [],
  "key_provider" => "file",
  "key_provider_settings" => ["file_location" => "/var/www/html/keys/subq.key", "strip_line_breaks" => TRUE],
  "key_input" => "none",
])->save();'
```

Check the length without printing the secret: `echo strlen(\Drupal\key\Entity\Key::load("subq")->getKeyValue());`

## 1. TypeSafe / SubQ (Decision)

Gotchas:
- **Two different key files.** `subq.key` is the SubQ key. `typesafe.key` is for TypeSafe's `jev` models. With the wrong one you get HTTP 401 (`authentication_error`) or the models list shows `jev-latest, jev-preview` instead of `subq-snap-preview`.
- **`host` is empty by default.** Set it to `https://api.subq.ai/v1`, or you hit the wrong endpoint.
- The models list is cached (`models_cache_ttl` 86400). `ddev drush cr` after changing key or host.

```bash
ddev drush cset ai_provider_typesafeai.settings api_key subq -y
ddev drush cset ai_provider_typesafeai.settings host https://api.subq.ai/v1 -y
ddev drush cr
ddev drush ev '$i=\Drupal::service("ai.provider")->createInstance("typesafeai"); echo implode(", ", array_keys($i->getConfiguredModels("decision")));'
```

Expected: `subq-snap-preview`.

Defaults (the operation types SubQ supports):

```bash
ddev drush ev '$d=\Drupal::configFactory()->getEditable("ai.settings");
foreach(["decision","moderation","text_classification"] as $o) $d->set("default_providers.$o",["provider_id"=>"typesafeai","model_id"=>"subq-snap-preview"]);
$d->save();'
```

On the aim site SubQ is also patched (`listModels()` expects an OpenAI-shaped response). That patch is not applied here and listing worked anyway with the right key and host.

## 2. amazee.ai (Chat)

Gotchas:
- **The provider creates an empty `amazeeio_ai` key.** It is a `config` key provider with no value, so you get `Failed to initialize amazee.ai client: Could not load the amazee.ai AI API key`. Switch that same key to the File provider (do not create a second key, the settings point at `amazeeio_ai`).
- The `amazeeio_ai_database` key (vector DB password) is also created empty. Not needed for chat or for AIM, which uses Ollama and MariaDB for vectors.
- The management key (`amazee_mgmt.key`) is only for the provider's UI sign-up flow. Not needed once you have an API key.
- The LLM host is region specific. On this account it is `https://llm.de-eu101.amazee.ai`, and the sign-up/management host is `https://api.amazee.ai`.

```bash
ddev drush ev '$k=\Drupal\key\Entity\Key::load("amazeeio_ai");
$k->set("key_provider","file")->set("key_provider_settings",["file_location"=>"/var/www/html/keys/amazee_api.key","strip_line_breaks"=>TRUE])->set("key_input","none")->save();'
ddev drush cset ai_provider_amazeeio.settings api_key amazeeio_ai -y
ddev drush cset ai_provider_amazeeio.settings host https://llm.de-eu101.amazee.ai -y
ddev drush cset ai_provider_amazeeio.settings amazee_host https://api.amazee.ai -y
ddev drush cr
ddev drush ev 'echo implode(", ", array_slice(array_keys(\Drupal::service("ai.provider")->createInstance("amazeeio")->getConfiguredModels("chat")),0,6));'
```

Expected: a list starting `claude-5-opus, claude-4-5-sonnet, ...`.

Defaults:

```bash
ddev drush ev '$d=\Drupal::configFactory()->getEditable("ai.settings");
foreach(["chat","chat_with_complex_json","chat_with_image_vision","chat_with_structured_response","chat_with_tools"] as $o)
  $d->set("default_providers.$o",["provider_id"=>"amazeeio","model_id"=>"claude-5-sonnet"]);
$d->save();'
```

Keeping the key out of the database: the aim site does it with a `key.config_override` (`key_id: amazeeio_ai`, `config_name: ai_provider_amazeeio.settings`, `config_item: api_key`) so the setting resolves to the Key at runtime. The amazee.ai pages below describe pre-provisioning keys without the UI, which is the proper way to avoid the empty-key step.

Links:
- Pre-provisioning keys without using the UI: https://project.pages.drupalcode.org/ai_provider_amazeeio/advanced-configuration/#pre-provisioning-keys-without-using-the-ui
- amazee.ai Drupal guide: https://docs.amazee.ai/guides/cms-frameworks/drupal/

(I have not read these two pages, only noted them. Check them before relying on them for the config-override step above.)

## 3. Ollama (Embeddings)

Needs Ollama running on the **host** with the `nomic-embed-text` model pulled (`ollama pull nomic-embed-text`). From the DDEV container the host is `host.docker.internal`.

Gotchas:
- **`host_name` is empty by default.** Then `/admin/reports/dblog` fills with `Failed to get models from Ollama: URI must include a scheme and host`. Set `host_name` with the scheme and `port` separately.
- The module lists every model from `/api/tags` for every operation type, including embedding-only models under Chat. See `notes/findings.md` (2026-10-17). Ignore the Chat entry for `nomic-embed-text`.
- Model id uses underscores: `nomic_embed_text_latest`, not `nomic-embed-text:latest`.

```bash
ddev exec curl -s http://host.docker.internal:11434/api/tags | head -c 200   # is Ollama reachable?
ddev drush cset ai_provider_ollama.settings host_name http://host.docker.internal -y
ddev drush cset ai_provider_ollama.settings port 11434 -y
ddev drush ev '$d=\Drupal::configFactory()->getEditable("ai.settings"); $d->set("default_providers.embeddings",["provider_id"=>"ollama","model_id"=>"nomic_embed_text_latest"])->save();'
ddev drush cr
ddev drush ev 'echo implode(", ", array_keys(\Drupal::service("ai.provider")->createInstance("ollama")->getConfiguredModels("embeddings")));'
```

Expected: `nomic_embed_text_latest`.

The AIM module ships a recipe (`recipes/aim_ollama_local`) that does the Ollama part. Its own README says it is not verified end to end.

## 4. Check everything

```bash
ddev drush cget ai.settings default_providers
ddev drush ws --count=10 --severity=Error
```

Expected: no errors in the watchdog, a provider/model on every operation type you need. Errors logged before you set a host are harmless; check the timestamps.

## Order that works

1. composer require, `drush en` (including `key`).
2. Keys on disk to File-provider Keys (strip line breaks).
3. Per-provider settings (key, host), `drush cr`.
4. `ai.settings` default providers.
5. Verify each provider as above, then apply a demo recipe.

Doing the recipe first doesn't work: the AIM demo indexes facts as it imports them (`index_directly`), which needs embeddings, and its chat persona needs a chat model.

## Not covered

- The provider admin screens (`/admin/config/ai/providers/...`) and `/admin/config/ai/settings`. Everything above sets the same config through drush.
- Production key handling (environment variables, real secret stores).
- Rate limits and costs on the hosted providers.
