<?php

declare(strict_types=1);

namespace Drupal\aim\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Caches query-time embeddings on drupal/ai's provider events (ADR-0017).
 *
 * A query's text always embeds to the same vector for a given provider,
 * model and configuration - repeat work whenever a query recurs, and every
 * consolidation sweep after the first re-embeds every unchanged fact's own
 * text as a query (AimMemoryManager::findNearestNeighbor()). No aim code
 * ever holds a query embedding itself - ai_search's backend embeds
 * ->keys() internally - so the only place to intercept it is here, on the
 * provider events drupal/ai dispatches around every embeddings() call.
 *
 * Only active while AimMemoryManager::executeSearchQuery() is running a
 * query (the toggle below), which excludes index-time embeds (fact text
 * being embedded for storage, not as a query) without any tag guessing -
 * query-time and index-time calls carry the same operation type and tag
 * set otherwise.
 */
class AimEmbeddingCacheSubscriber implements EventSubscriberInterface {

  // Cache entry lifetime. The mapping (provider, model, configuration,
  // text) -> vector is deterministic and needs no invalidation; the TTL
  // exists only so the bin cannot grow without bound.
  const TTL = 604800;

  /**
   * Toggled by AimMemoryManager::executeSearchQuery(), around one query.
   *
   * FALSE outside that window, so index-time embeds are never read from
   * or written to this cache.
   *
   * @var bool
   */
  protected bool $active = FALSE;

  /**
   * Forces every hook to no-op regardless of $active.
   *
   * For aim:benchmark's bypass option (decision 6) - without it, the same
   * handful of sample queries repeating across --queries iterations would
   * silently turn into cache hits and the latency numbers would stop
   * being comparable to earlier measurements.
   *
   * @var bool
   */
  protected bool $bypassed = FALSE;

  /**
   * Start time of a cache miss's provider call, keyed by request thread ID.
   *
   * Used to log embed time on the matching post-event; a hit never
   * populates this, since no provider call is made.
   *
   * @var array<string, float>
   */
  protected array $pendingTimers = [];

  /**
   * Constructs an AimEmbeddingCacheSubscriber.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The embeddings cache bin.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The aim logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, read for aim.settings:log_verbose, which gates
   *   this class's debug logging.
   */
  public function __construct(
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected LoggerChannelInterface $logger,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Logs at debug level, if aim.settings:log_verbose is on.
   *
   * @param string $message
   *   The log message, with placeholders.
   * @param array $context
   *   The placeholder values.
   */
  protected function logVerbose(string $message, array $context): void {
    if ($this->configFactory->get('aim.settings')->get('log_verbose')) {
      $this->logger->debug($message, $context);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => 'onPreGenerateResponse',
      PostGenerateResponseEvent::EVENT_NAME => 'onPostGenerateResponse',
    ];
  }

  /**
   * Toggles caching for the duration of one aim search query.
   *
   * @param bool $active
   *   TRUE while a query built by aim is executing.
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }

  /**
   * Forces every hook to no-op regardless of setActive(), for benchmarking.
   *
   * @param bool $bypassed
   *   TRUE to disable caching outright until set back to FALSE.
   */
  public function setBypassed(bool $bypassed): void {
    $this->bypassed = $bypassed;
  }

  /**
   * Serves a cached vector, or records a miss's start time.
   *
   * @param \Drupal\ai\Event\PreGenerateResponseEvent $event
   *   The pre-generate event.
   */
  public function onPreGenerateResponse(PreGenerateResponseEvent $event): void {
    if (!$this->active || $this->bypassed || $event->getOperationType() !== 'embeddings') {
      return;
    }
    $input = $event->getInput();
    if (!$input instanceof EmbeddingsInput || $input->getPrompt() === '') {
      // Not a text embedding (e.g. an image input) - nothing to key on.
      return;
    }

    $key = $this->buildCacheKey($event->getProviderId(), $event->getModelId(), $event->getConfiguration(), $input->getPrompt());
    $cached = $this->cache->get($key);
    if ($cached === FALSE) {
      $this->pendingTimers[$event->getRequestThreadId()] = microtime(TRUE);
      $this->logVerbose('Embedding cache miss for %provider/%model.', [
        '%provider' => $event->getProviderId(),
        '%model' => $event->getModelId(),
      ]);
      return;
    }

    $this->logVerbose('Embedding cache hit for %provider/%model.', [
      '%provider' => $event->getProviderId(),
      '%model' => $event->getModelId(),
    ]);
    $event->setForcedOutputObject(new EmbeddingsOutput($cached->data, NULL, ['aim_cache_hit' => TRUE]));
  }

  /**
   * Stores a miss's resulting vector.
   *
   * Never runs for a hit: ProviderProxy returns the pre-event's forced
   * output object before the provider is called, so PostGenerateResponseEvent
   * is never dispatched for that call.
   *
   * @param \Drupal\ai\Event\PostGenerateResponseEvent $event
   *   The post-generate event.
   */
  public function onPostGenerateResponse(PostGenerateResponseEvent $event): void {
    if (!$this->active || $this->bypassed || $event->getOperationType() !== 'embeddings') {
      return;
    }
    $input = $event->getInput();
    if (!$input instanceof EmbeddingsInput || $input->getPrompt() === '') {
      return;
    }
    $output = $event->getOutput();
    if (!$output instanceof EmbeddingsOutput) {
      return;
    }

    $threadId = $event->getRequestThreadId();
    if (isset($this->pendingTimers[$threadId])) {
      $embedMs = (int) round((microtime(TRUE) - $this->pendingTimers[$threadId]) * 1000);
      unset($this->pendingTimers[$threadId]);
      $this->logVerbose('Embedding call for %provider/%model took @ms ms.', [
        '%provider' => $event->getProviderId(),
        '%model' => $event->getModelId(),
        '@ms' => $embedMs,
      ]);
    }

    $key = $this->buildCacheKey($event->getProviderId(), $event->getModelId(), $event->getConfiguration(), $input->getPrompt());
    $this->cache->set($key, $output->getNormalized(), $this->time->getRequestTime() + self::TTL);
  }

  /**
   * Builds the cache key for one query-time embedding.
   *
   * Includes the model ID, so a model change needs no invalidation - old
   * entries simply age out under the TTL and are never read again. The
   * full configuration array is used rather than a single "dimensions"
   * key, since that key's name varies by provider plugin; whatever
   * output-affecting settings a provider stores there are covered either
   * way. Text case is preserved, since models can be case-sensitive.
   *
   * @param string $providerId
   *   The AI provider plugin ID.
   * @param string $modelId
   *   The model ID.
   * @param array $configuration
   *   The provider's configuration at call time.
   * @param string $text
   *   The query text.
   *
   * @return string
   *   The cache key.
   */
  protected function buildCacheKey(string $providerId, string $modelId, array $configuration, string $text): string {
    return 'aim_embedding:' . hash('sha256', $providerId . '|' . $modelId . '|' . serialize($configuration) . '|' . trim($text));
  }

}
