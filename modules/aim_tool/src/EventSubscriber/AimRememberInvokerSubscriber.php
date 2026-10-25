<?php

declare(strict_types=1);

namespace Drupal\aim_tool\EventSubscriber;

use Drupal\aim_tool\Tool\AimRemember;
use Drupal\tool\Event\ToolInputTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Tells aim_remember who is calling it, so it can pick its trust channel.
 *
 * A #[ToolMethod] service never sees the Tool API invoker (ADR-0062,
 * decision 2), but every input set on a tool carries it: when aim_remember's
 * required facts input is set, this hands the invoker to the service for
 * the call that follows. Delete it once Tool API passes the invoker to the
 * method itself.
 */
final class AimRememberInvokerSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\aim_tool\Tool\AimRemember $remember
   *   The aim_remember tool service.
   */
  public function __construct(
    private readonly AimRemember $remember,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ToolInputTransformEvent::class => 'onInputTransform'];
  }

  /**
   * Passes the invoker on when aim_remember's facts input is set.
   *
   * @param \Drupal\tool\Event\ToolInputTransformEvent $event
   *   The event, fired for every input set on any tool.
   */
  public function onInputTransform(ToolInputTransformEvent $event): void {
    if ($event->getInputName() === 'facts' && $event->getTool()->getPluginId() === 'aim_remember') {
      $this->remember->setInvoker($event->getInvoker());
    }
  }

}
