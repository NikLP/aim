<?php

declare(strict_types=1);

namespace Drupal\aim_chatbot\EventSubscriber;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Event\AgentToolPreExecuteEvent;
use Drupal\aim_chatbot\Plugin\AiFunctionCall\AimRemember;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hands the remember tool the conversation it is saving a fact from.
 *
 * The grounded check (ADR-0037) needs the words a fact came from, and a
 * function call only gets its own arguments. So just before the tool runs,
 * this reads the calling agent's chat history and passes on the visitor's
 * last message plus the assistant turn before it: the persona waits for the
 * visitor to confirm, and "Yes, that's right" alone states nothing
 * (ADR-0037's 2026-10-10 eval).
 */
final class AimChatbotSourceTextSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [AgentToolPreExecuteEvent::EVENT_NAME => 'onToolPreExecute'];
  }

  /**
   * Passes the conversation to aim_chatbot's remember tool.
   *
   * @param \Drupal\ai_agents\Event\AgentToolPreExecuteEvent $event
   *   The event, fired before any agent tool runs.
   */
  public function onToolPreExecute(AgentToolPreExecuteEvent $event): void {
    $tool = $event->getTool();
    if ($tool instanceof AimRemember) {
      $tool->setSourceText(self::sourceText($event->getAgent()->getChatHistory()));
    }
  }

  /**
   * Builds the source text from a chat history.
   *
   * @param array $history
   *   The agent's chat history, oldest first.
   *
   * @return string|null
   *   "Assistant: ...\nVisitor: ..." (the assistant line only when one comes
   *   just before the visitor's last message), or NULL when the history has
   *   no visitor message.
   */
  public static function sourceText(array $history): ?string {
    $visitor = NULL;
    $assistant = NULL;
    foreach (array_reverse($history) as $message) {
      if (!$message instanceof ChatMessage || trim($message->getText()) === '') {
        continue;
      }
      if ($visitor === NULL) {
        // Skip this turn's tool calls and results until the visitor's
        // message.
        if ($message->getRole() === 'user') {
          $visitor = trim($message->getText());
        }
        continue;
      }
      if ($message->getRole() === 'assistant') {
        $assistant = trim($message->getText());
      }
      if (in_array($message->getRole(), ['assistant', 'user'], TRUE)) {
        break;
      }
    }
    if ($visitor === NULL) {
      return NULL;
    }
    return ($assistant !== NULL ? 'Assistant: ' . $assistant . "\n" : '') . 'Visitor: ' . $visitor;
  }

}
