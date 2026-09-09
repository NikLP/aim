<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Service\AimMemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Consolidates one newly-written aim_fact against its nearest neighbor.
 *
 * Decision 4 (CLAUDE.md, "Consolidation" phase 2): consolidation runs
 * unattended via Queue API, not on the live request path. Deliberately
 * carries no `cron` key - processing this queue during Drupal's own
 * hook_cron would mix consolidation's LLM-call cost into general site
 * cron, exactly what decision 4 says not to do. It is only drained by a
 * dedicated crontab entry running `drush queue:run aim_consolidate`; see
 * CLAUDE.md for the exact line.
 */
#[QueueWorker(
  id: 'aim_consolidate',
  title: new TranslatableMarkup('AIM: consolidate a fact'),
)]
final class AimConsolidateQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs an AimConsolidateQueueWorker object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $pluginId
   *   The plugin ID.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    mixed $pluginDefinition,
    protected AimMemoryManager $memoryManager,
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('aim.memory_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $fact_id = (int) $data;
    $fact = $this->memoryManager->loadFact($fact_id);
    if (!$fact || !$fact->get('expires')->isEmpty()) {
      // Already retired (by an earlier item in this same run finding it as
      // its own neighbor), or deleted since being enqueued. Nothing to do.
      return;
    }

    // index_directly is deliberately off (CLAUDE.md, "Vector search is
    // working end to end") - a fact is not searchable the moment it is
    // saved. Without this, two facts written close together would each be
    // asked to consolidate before the other is indexed, and would never
    // find each other as neighbors - confirmed live 2026-09-09 with a
    // genuinely near-duplicate test pair that missed each other entirely
    // for exactly this reason. Indexing here, inline, is the specific
    // queue-worker-driven exception CLAUDE.md's vector search notes already
    // anticipated for index_directly - this runs off the live request path
    // like any other queue item, so the embedding-call cost belongs here,
    // not on a web request.
    $this->memoryManager->reindex();

    $provider = $this->memoryManager->getDefaultChatProvider();
    if (empty($provider['provider_id']) || empty($provider['model_id'])) {
      // No default chat provider configured. Throwing leaves the item in
      // the queue for the next run rather than silently dropping it -
      // matches the CLI command's own refusal to guess a provider, just
      // surfaced as a retry instead of a one-shot error.
      throw new \RuntimeException('No default chat provider configured; cannot consolidate fact ' . $fact_id . '.');
    }

    $this->memoryManager->consolidateFact(
      $fact,
      $provider['provider_id'],
      $provider['model_id'],
      AimMemoryManager::DEFAULT_AUTO_THRESHOLD,
      AimMemoryManager::DEFAULT_AMBIGUOUS_THRESHOLD,
    );
  }

}
