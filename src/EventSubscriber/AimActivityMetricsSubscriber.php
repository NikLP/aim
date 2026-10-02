<?php

declare(strict_types=1);

namespace Drupal\aim\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Writes one aim_activity_metrics row per aim_* provider call.
 *
 * Times the call between drupal/ai's Pre and Post events and reads token
 * usage off the output. Stores activity, run ID, provider, model, tokens
 * and milliseconds only, never prompt or fact text. A call that throws
 * dispatches no Post event and leaves no row.
 */
class AimActivityMetricsSubscriber implements EventSubscriberInterface {

  /**
   * Start time of each tagged call, keyed by request thread ID.
   *
   * @var array<string, float>
   */
  protected array $pendingTimers = [];

  /**
   * The current run ID, generated on first use unless set.
   *
   * @var string|null
   */
  protected ?string $runId = NULL;

  /**
   * Constructs an AimActivityMetricsSubscriber.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator, for default run IDs.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected UuidInterface $uuid,
  ) {}

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
   * Groups the rows that follow under one run ID, e.g. one benchmark replay.
   *
   * @param string|null $runId
   *   The run ID, or NULL to generate a fresh one on the next call.
   */
  public function setRunId(?string $runId): void {
    $this->runId = $runId;
  }

  /**
   * Gets the current run ID, generating one if none is set.
   *
   * @return string
   *   The run ID.
   */
  public function getRunId(): string {
    return $this->runId ??= $this->uuid->generate();
  }

  /**
   * Starts the timer for an aim_* call.
   *
   * @param \Drupal\ai\Event\PreGenerateResponseEvent $event
   *   The pre-generate event.
   */
  public function onPreGenerateResponse(PreGenerateResponseEvent $event): void {
    if ($this->activityOf($event->getTags()) !== NULL) {
      $this->pendingTimers[$event->getRequestThreadId()] = microtime(TRUE);
    }
  }

  /**
   * Writes the row for a finished aim_* call.
   *
   * @param \Drupal\ai\Event\PostGenerateResponseEvent $event
   *   The post-generate event.
   */
  public function onPostGenerateResponse(PostGenerateResponseEvent $event): void {
    $activity = $this->activityOf($event->getTags());
    $threadId = $event->getRequestThreadId();
    if ($activity === NULL || !isset($this->pendingTimers[$threadId])) {
      return;
    }
    $ms = (int) round((microtime(TRUE) - $this->pendingTimers[$threadId]) * 1000);
    unset($this->pendingTimers[$threadId]);

    $output = $event->getOutput();
    $usage = is_object($output) && method_exists($output, 'getTokenUsage') ? $output->getTokenUsage() : NULL;
    $this->database->insert('aim_activity_metrics')->fields([
      'created' => $this->time->getRequestTime(),
      'run_id' => $this->getRunId(),
      'activity' => $activity,
      'operation_type' => $event->getOperationType(),
      'provider' => $event->getProviderId(),
      'model' => $event->getModelId(),
      'input_tokens' => $usage instanceof TokenUsageDto ? $usage->input : NULL,
      'output_tokens' => $usage instanceof TokenUsageDto ? $usage->output : NULL,
      'duration_ms' => $ms,
    ])->execute();
  }

  /**
   * Finds the aim_* tag on a call.
   *
   * @param array $tags
   *   The call's tags.
   *
   * @return string|null
   *   The first tag starting with aim_, or NULL for a call aim does not own.
   */
  protected function activityOf(array $tags): ?string {
    foreach ($tags as $tag) {
      if (is_string($tag) && str_starts_with($tag, 'aim_')) {
        return $tag;
      }
    }
    return NULL;
  }

}
