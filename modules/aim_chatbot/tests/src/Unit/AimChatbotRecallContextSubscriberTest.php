<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_chatbot\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\aim_chatbot\EventSubscriber\AimChatbotRecallContextSubscriber;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which visitor messages recall-in-code searches memory for.
 */
#[Group('aim')]
class AimChatbotRecallContextSubscriberTest extends UnitTestCase {

  /**
   * The last visitor message and the one before, skipping other roles.
   */
  public function testFollowUpCarriesThePreviousQuestion(): void {
    $history = [
      new ChatMessage('user', 'Do you have 3D printers?'),
      new ChatMessage('assistant', 'Yes, two, after a 20-minute induction.'),
      new ChatMessage('user', '  How long does the induction take? '),
      new ChatMessage('tool', 'Relevant facts: ...'),
    ];
    $this->assertSame(
      ['How long does the induction take?', 'Do you have 3D printers?'],
      AimChatbotRecallContextSubscriber::visitorMessages($history),
    );
  }

  /**
   * A first message has no previous one; no visitor message gives NULLs.
   */
  public function testEdges(): void {
    $this->assertSame(['Hello'], array_filter(AimChatbotRecallContextSubscriber::visitorMessages([
      new ChatMessage('user', 'Hello'),
    ])));
    $this->assertSame([NULL, NULL], AimChatbotRecallContextSubscriber::visitorMessages([
      new ChatMessage('assistant', 'Hi.'),
      new ChatMessage('user', ' '),
    ]));
  }

}
