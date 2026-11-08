<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_chatbot\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\Event\AgentFinishedExecutionEvent;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim_chatbot\Plugin\ChatProcessor\AimGateProcessor;
use Drupal\literals\LiteralReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The chat gate: when it answers, when it hands on, and model failures.
 */
#[Group('aim')]
class AimGateProcessorTest extends UnitTestCase {

  /**
   * The audit lines the processor wrote.
   *
   * @var string[]
   */
  protected array $audit = [];

  /**
   * The runner mock.
   */
  protected AiAssistantApiRunner&MockObject $runner;

  /**
   * Builds a processor with its collaborators faked.
   *
   * @param object|null $finder
   *   The finder, or NULL for none.
   * @param array|null $resolved
   *   What the literal reader returns for a match.
   * @param string $question
   *   The visitor's message.
   *
   * @return \Drupal\aim_chatbot\Plugin\ChatProcessor\AimGateProcessor&\PHPUnit\Framework\MockObject\MockObject
   *   The processor.
   */
  protected function processor(?object $finder, ?array $resolved = NULL, string $question = 'What is the phone number?'): AimGateProcessor&MockObject {
    $processor = $this->getMockBuilder(AimGateProcessor::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getInput', 'convertInputToUserMessage', 'initializeRunner', 'template'])
      ->getMock();
    $processor->method('getInput')->willReturn(new ChatInput([new ChatMessage('user', $question)]));
    $processor->method('convertInputToUserMessage')->willReturn(new UserMessage($question));
    $processor->method('template')->willReturnCallback(fn (string $key, string $label, string $value): string => "**$label**: $value");

    $reader = $this->createMock(LiteralReader::class);
    $reader->method('resolveFound')->willReturn($resolved ?? ['items' => []]);

    $manager = $this->createMock(AimMemoryManager::class);
    $manager->method('logAudit')->willReturnCallback(function (string $message) {
      $this->audit[] = $message;
    });

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(5);

    $this->runner = $this->createMock(AiAssistantApiRunner::class);

    $this->set($processor, 'finder', $finder);
    $this->set($processor, 'literalReader', $reader);
    $this->set($processor, 'memoryManager', $manager);
    $this->set($processor, 'currentUser', $account);
    $this->set($processor, 'aiAssistantRunner', $this->runner);
    $this->set($processor, 'configuration', []);
    return $processor;
  }

  /**
   * Sets a protected property.
   */
  protected function set(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionProperty($object, $property);
    $reflection->setValue($object, $value);
  }

  /**
   * Calls the protected gate().
   */
  protected function gate(AimGateProcessor $processor): ?ChatOutput {
    return (new \ReflectionMethod($processor, 'gate'))->invoke($processor);
  }

  /**
   * Builds a finder returning a fixed result, or throwing.
   */
  protected function finder(string $outcome, array $literals = [], bool $throws = FALSE): object {
    return new class($outcome, $literals, $throws) {

      /**
       * Constructs the fake finder.
       */
      public function __construct(
        private string $outcome,
        private array $literals,
        private bool $throws,
      ) {}

      /**
       * Returns the fixed result.
       */
      public function find(): object {
        if ($this->throws) {
          throw new \RuntimeException('finder down');
        }
        return (object) [
          'outcome' => $this->outcome,
          'literals' => $this->literals,
          'reason' => 'test',
          'tier' => 'test',
        ];
      }

    };
  }

  /**
   * Without literals_finder the gate is a pass-through.
   */
  public function testNoFinderPassesOn(): void {
    $this->assertNull($this->gate($this->processor(NULL)));
  }

  /**
   * An empty message is handed on without asking the finder.
   */
  public function testEmptyMessagePassesOn(): void {
    $this->assertNull($this->gate($this->processor($this->finder('match', ['a']), NULL, '  ')));
  }

  /**
   * A finder error never breaks the chat.
   */
  public function testFinderErrorPassesOn(): void {
    $this->assertNull($this->gate($this->processor($this->finder('match', [], TRUE))));
    $this->assertStringContainsString('finder threw', $this->audit[0]);
  }

  /**
   * Anything but a clear match is handed on.
   */
  public function testNoMatchPassesOn(): void {
    foreach (['none', 'ambiguous'] as $outcome) {
      $this->assertNull($this->gate($this->processor($this->finder($outcome, ['a']))));
    }
    // A "match" with no literals is no match either.
    $this->assertNull($this->gate($this->processor($this->finder('match', []))));
  }

  /**
   * A match the visitor may not read is handed on, never shown.
   */
  public function testUnreadableMatchPassesOn(): void {
    $processor = $this->processor($this->finder('match', ['staff_phone']), ['items' => []]);
    $this->assertNull($this->gate($processor));
    $this->assertStringContainsString('not readable', $this->audit[0]);
  }

  /**
   * A readable clear match is answered from the template and stored.
   */
  public function testClearMatchAnswersFromTemplate(): void {
    $item = (object) ['label' => 'Phone', 'value' => '01632 960001', 'kind' => 'phone'];
    $processor = $this->processor($this->finder('match', ['library_phone']), ['items' => ['library_phone' => $item]]);
    $this->runner->expects($this->once())->method('setUserMessage');
    $this->runner->expects($this->once())->method('setAssistantMessage')->with('**Phone**: 01632 960001');

    $output = $this->gate($processor);
    $this->assertSame('**Phone**: 01632 960001', $output->getNormalized()->getText());
    $this->assertStringContainsString('Gate hit', $this->audit[0]);
  }

  /**
   * Builds the agent-finished event an agent dispatches after a model call.
   */
  protected function finishedEvent(string $agentId, ?string $error): AgentFinishedExecutionEvent {
    $agent = $this->createMock(AiAgentEntityWrapper::class);
    $agent->method('getLastError')->willReturn($error);
    return new AgentFinishedExecutionEvent(
      $agent,
      '',
      $agentId,
      '',
      [],
      new ChatOutput(new ChatMessage('assistant', ''), NULL, NULL),
      1,
      'runner',
    );
  }

  /**
   * Runs guardModelErrors() with an event dispatched during the run.
   */
  protected function guard(?AgentFinishedExecutionEvent $event, ?LoggerInterface $logger = NULL): ChatOutput {
    $processor = $this->processor(NULL);
    $dispatcher = new EventDispatcher();
    $assistant = new class() {

      /**
       * Returns the agent ID or the error message.
       */
      public function get(string $key): string {
        return $key === 'ai_agent' ? 'aim_chatbot' : 'Sorry, something went wrong.';
      }

    };
    $this->runner->method('getAssistant')->willReturn($assistant);
    $this->set($processor, 'eventDispatcher', $dispatcher);
    $this->set($processor, 'logger', $logger ?? $this->createMock(LoggerInterface::class));

    $normal = new ChatOutput(new ChatMessage('assistant', 'Normal reply'), 'Normal reply', []);
    return (new \ReflectionMethod($processor, 'guardModelErrors'))->invoke($processor, function () use ($dispatcher, $event, $normal) {
      if ($event) {
        $dispatcher->dispatch($event, AgentFinishedExecutionEvent::EVENT_NAME);
      }
      return $normal;
    });
  }

  /**
   * A reply with no failure passes through untouched.
   */
  public function testHealthyReplyPassesThrough(): void {
    $this->assertSame('Normal reply', $this->guard($this->finishedEvent('aim_chatbot', NULL))->getNormalized()->getText());
    $this->assertSame('Normal reply', $this->guard(NULL)->getNormalized()->getText());
  }

  /**
   * A failed model call shows the error message, not the echoed question.
   */
  public function testModelFailureShowsErrorMessageAndLogs(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $output = $this->guard($this->finishedEvent('aim_chatbot', 'Connection refused'), $logger);
    $this->assertSame('Sorry, something went wrong.', $output->getNormalized()->getText());
  }

  /**
   * A failed sub-agent does not hide the assistant's own reply.
   */
  public function testSubAgentFailureIsIgnored(): void {
    $this->assertSame('Normal reply', $this->guard($this->finishedEvent('some_sub_agent', 'Timeout'))->getNormalized()->getText());
  }

}
