<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\ConfigAction;

use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\AiProviderPluginManager;
use Drupal\search_api\ServerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Points a vector server at the site's default AI models.
 *
 * The module ships search_api.server.aim_vector with no model IDs: any site
 * can have any provider. This fills the empty ones from the AI module's
 * defaults (/admin/config/ai/settings), the embeddings engine at its real
 * vector size, then re-saves the server's indexes so the collection table is
 * made at that size. A value already set is left alone: changing the engine
 * under an existing table breaks it (fixed-width VECTOR column). Run by a
 * recipe (`aimUseDefaultModels: []`, ahead of any action that saves the
 * index) and by aim_install().
 */
#[ConfigAction(
  id: 'aimUseDefaultModels',
  admin_label: new TranslatableMarkup('Use the default AI models on a vector server'),
  entity_types: ['search_api_server'],
)]
final class UseDefaultModels implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs a UseDefaultModels object.
   *
   * @param \Drupal\Core\Config\ConfigManagerInterface $configManager
   *   The config manager, to load the server by config name.
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager, for the defaults and the vector size.
   */
  public function __construct(
    private readonly ConfigManagerInterface $configManager,
    private readonly AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get(ConfigManagerInterface::class),
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function apply(string $configName, mixed $value): void {
    $server = $this->configManager->loadConfigEntityByName($configName);
    if (!$server instanceof ServerInterface) {
      throw new ConfigActionException(sprintf('%s is not a Search API server.', $configName));
    }
    $config = $server->getBackendConfig();
    $changed = FALSE;

    if (empty($config['embeddings_engine'])) {
      $default = $this->aiProvider->getDefaultProviderForOperationType('embeddings') ?? [];
      if (empty($default['provider_id']) || empty($default['model_id'])) {
        throw new ConfigActionException(sprintf('The vector server "%s" has no embeddings engine and the site has no default embeddings model: set one at /admin/config/ai/settings first.', $server->id()));
      }

      try {
        // A real call, so this also fails when the provider is unreachable.
        $size = $this->aiProvider->createInstance($default['provider_id'])->embeddingsVectorSize($default['model_id']);
      }
      catch (\Throwable $e) {
        throw new ConfigActionException(sprintf('Could not get a vector from the default embeddings model %s__%s: %s', $default['provider_id'], $default['model_id'], $e->getMessage()), 0, $e);
      }

      $config['embeddings_engine'] = $default['provider_id'] . '__' . $default['model_id'];
      // The model's own size, so no dimensions are sent with each call.
      $config['embeddings_engine_configuration']['set_dimensions'] = FALSE;
      $config['embeddings_engine_configuration']['dimensions'] = $size;
      $changed = TRUE;
    }

    // Optional: the embedding strategy counts tokens with it, and falls back
    // to a generic tokenizer without one.
    $chat = $this->aiProvider->getDefaultProviderForOperationType('chat') ?? [];
    if (empty($config['chat_model']) && !empty($chat['provider_id']) && !empty($chat['model_id'])) {
      $config['chat_model'] = $chat['provider_id'] . '__' . $chat['model_id'];
      $changed = TRUE;
    }

    if ($changed) {
      $server->setBackendConfig($config)->save();
    }
    // ai_vdb_provider_mariadb creates the table only on an index update.
    foreach ($server->getIndexes() as $index) {
      $index->save();
    }
  }

}
