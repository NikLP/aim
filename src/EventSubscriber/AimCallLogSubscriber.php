<?php

declare(strict_types=1);

namespace Drupal\aim\EventSubscriber;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds a run ID and the call's duration to what ai_logging records.
 *
 * The ai_logging module stores one ai_log row per aim_* provider call, with
 * provider, model, tokens and prompt/response text (ADR-0060). It has no
 * duration or run grouping, so this subscriber times the call between
 * drupal/ai's Pre and Post events and writes both into the event's debug
 * data, which ai_logging keeps in the row's extra_data. It runs ahead of
 * ai_logging's own Post subscriber (priority 10 against its default 0) so
 * the data is there when the row is built. A call that throws dispatches no
 * Post event and leaves no row.
 */
class AimCallLogSubscriber implements EventSubscriberInterface {

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
   * Constructs an AimCallLogSubscriber.
   *
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator, for default run IDs.
   */
  public function __construct(
    protected UuidInterface $uuid,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerateResponse', 10],
      PostGenerateResponseEvent::EVENT_NAME => ['onPostGenerateResponse', 10],
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
    if ($this->isAimCall($event->getTags())) {
      $this->pendingTimers[$event->getRequestThreadId()] = microtime(TRUE);
    }
  }

  /**
   * Adds the run ID and duration to a finished aim_* call.
   *
   * @param \Drupal\ai\Event\PostGenerateResponseEvent $event
   *   The post-generate event.
   */
  public function onPostGenerateResponse(PostGenerateResponseEvent $event): void {
    $threadId = $event->getRequestThreadId();
    if (!isset($this->pendingTimers[$threadId])) {
      return;
    }
    $started = $this->pendingTimers[$threadId];
    unset($this->pendingTimers[$threadId]);
    $event->setDebugData('aim_run_id', $this->getRunId());
    $event->setDebugData('aim_duration_ms', (int) round((microtime(TRUE) - $started) * 1000));
  }

  /**
   * Tells whether a call carries an aim_* tag.
   *
   * @param array $tags
   *   The call's tags.
   *
   * @return bool
   *   TRUE for a call aim owns.
   */
  protected function isAimCall(array $tags): bool {
    foreach ($tags as $tag) {
      if (is_string($tag) && str_starts_with($tag, 'aim_')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
