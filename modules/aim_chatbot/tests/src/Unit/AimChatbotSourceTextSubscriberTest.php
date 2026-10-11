<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_chatbot\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\aim_chatbot\EventSubscriber\AimChatbotSourceTextSubscriber;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which part of the conversation the grounded check is given.
 */
#[Group('aim')]
class AimChatbotSourceTextSubscriberTest extends UnitTestCase {

  /**
   * The visitor's last message, with the assistant turn just before it.
   */
  public function testConfirmationCarriesThePreviousTurn(): void {
    $history = [
      new ChatMessage('user', 'Code Club has moved.'),
      new ChatMessage('assistant', 'Shall I note that Code Club has moved to Wednesdays at 4pm?'),
      new ChatMessage('user', "Yes, that's right."),
      // This turn's tool call and result come after the visitor's message.
      new ChatMessage('assistant', ''),
      new ChatMessage('tool', 'Saved fact 12.'),
    ];
    $this->assertSame(
      "Assistant: Shall I note that Code Club has moved to Wednesdays at 4pm?\nVisitor: Yes, that's right.",
      AimChatbotSourceTextSubscriber::sourceText($history),
    );
  }

  /**
   * A first message has no assistant turn; no visitor message gives NULL.
   */
  public function testEdges(): void {
    $this->assertSame('Visitor: The photocopier is broken.', AimChatbotSourceTextSubscriber::sourceText([
      new ChatMessage('user', 'The photocopier is broken.'),
    ]));
    // Two visitor messages in a row: only the last, no older assistant turn.
    $this->assertSame('Visitor: Second.', AimChatbotSourceTextSubscriber::sourceText([
      new ChatMessage('assistant', 'Hello.'),
      new ChatMessage('user', 'First.'),
      new ChatMessage('user', 'Second.'),
    ]));
    $this->assertNull(AimChatbotSourceTextSubscriber::sourceText([new ChatMessage('assistant', 'Hello.')]));
  }

}
