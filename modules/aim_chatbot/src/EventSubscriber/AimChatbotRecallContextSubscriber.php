<?php

declare(strict_types=1);

namespace Drupal\aim_chatbot\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Event\AgentStartedExecutionEvent;
use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Service\AimRecallService;
use Drupal\aim\Service\AimSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Recalls site facts for each visitor message, in code, before the model.
 *
 * Off unless the agent has the aim_chatbot "recall_context" third-party
 * setting. When on, the facts for the visitor's last message (and for it
 * together with the visitor's message before, so a follow-up such as "and
 * on Sundays?" still finds its topic) are added to the agent's system
 * prompt. The system prompt is rebuilt on every loop and never stored in the
 * chat history, so the facts are there for this turn only: the grounded
 * check and the next turn see the conversation, not memory's own text. The
 * recall tool stays available for a search the facts do not cover. Recall
 * runs as the current account and resolves [literal:key] tokens, as the tool
 * does.
 */
final class AimChatbotRecallContextSubscriber implements EventSubscriberInterface {

  private const FACT_SCOPE = 'site';

  private const RESULT_LIMIT = 5;

  /**
   * The recalled block per agent runner, so recall runs once a turn.
   *
   * @var array<string, string>
   */
  protected array $blocks = [];

  /**
   * The block for the system prompt about to be built: agent ID and text.
   *
   * @var array{0: string, 1: string}|null
   */
  protected ?array $pending = NULL;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\aim\Service\AimRecallService $recallService
   *   The recall service.
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The settings reader.
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The memory manager, for audit lines.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user, for audit lines.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AimRecallService $recallService,
    protected AimSettings $settings,
    protected AimMemoryManager $memoryManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentStartedExecutionEvent::EVENT_NAME => 'onAgentStarted',
      BuildSystemPromptEvent::EVENT_NAME => 'onBuildSystemPrompt',
    ];
  }

  /**
   * Recalls for the visitor's message, once per agent run.
   *
   * @param \Drupal\ai_agents\Event\AgentStartedExecutionEvent $event
   *   The event, fired at the start of every agent loop.
   */
  public function onAgentStarted(AgentStartedExecutionEvent $event): void {
    $this->pending = NULL;
    $agentId = $event->getAgentId();
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $agent */
    $agent = $this->entityTypeManager->getStorage('ai_agent')->load($agentId);
    if (!$agent || !$agent->getThirdPartySetting('aim_chatbot', 'recall_context', FALSE)) {
      return;
    }
    // The runner ID is the thread's, the same on every turn: recall again on
    // each turn's first loop.
    $runnerId = $event->getAgentRunnerId();
    if ($event->getLoopCount() === 0 || !isset($this->blocks[$runnerId])) {
      // On the first loop the agent has not yet copied its input into the
      // chat history (that happens after this event), so read the input.
      $history = $event->getChatHistory();
      if (self::visitorMessages($history)[0] === NULL) {
        try {
          $history = $event->getAgent()->getChatInput()->getMessages();
        }
        catch (\Error) {
          // A task-driven agent has no chat input.
        }
      }
      $this->blocks[$runnerId] = $this->recallBlock($history, $agentId);
    }
    $this->pending = [$agentId, $this->blocks[$runnerId]];
  }

  /**
   * Adds the recalled facts to the system prompt being built.
   *
   * @param \Drupal\ai_agents\Event\BuildSystemPromptEvent $event
   *   The event, fired right after the loop's started event.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    if ($this->pending === NULL || $this->pending[0] !== $event->getAgentId()) {
      return;
    }
    $event->setSystemPrompt(rtrim($event->getSystemPrompt()) . "\n\n" . $this->pending[1]);
    $this->pending = NULL;
  }

  /**
   * Recalls for the visitor's last message and builds the prompt block.
   *
   * @param array $history
   *   The agent's chat history, oldest first.
   * @param string $agentId
   *   The agent ID, for the audit line.
   *
   * @return string
   *   The block for the system prompt.
   */
  protected function recallBlock(array $history, string $agentId): string {
    [$last, $previous] = self::visitorMessages($history);
    $queries = array_filter([$last, $previous !== NULL && $last !== NULL ? $previous . "\n" . $last : NULL]);
    $rows = [];
    try {
      foreach ($queries as $query) {
        foreach ($this->recallService->recall($query, self::FACT_SCOPE, NULL, NULL, self::RESULT_LIMIT, $this->settings->getRecallMaxDistance(), FALSE, $this->settings->getRecallGap()) as $row) {
          $id = (string) $row['id'];
          if (!isset($rows[$id]) || $row['score'] < $rows[$id]['score']) {
            $rows[$id] = $row;
          }
        }
      }
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->memoryManager->logAudit('Recall context failed (@class) for agent @agent, uid @uid.', [
        '@class' => get_class($e),
        '@agent' => $agentId,
        '@uid' => $this->currentUser->id(),
      ]);
      return 'Memory could not be searched for this message. Use the recall tool if a question needs remembered facts.';
    }
    uasort($rows, fn (array $a, array $b): int => $a['score'] <=> $b['score']);
    $rows = array_slice($rows, 0, self::RESULT_LIMIT, TRUE);
    $this->memoryManager->logAudit('Recall context: @count fact(s) (@ids) for agent @agent, uid @uid.', [
      '@count' => count($rows),
      '@ids' => implode(',', array_keys($rows)) ?: '-',
      '@agent' => $agentId,
      '@uid' => $this->currentUser->id(),
    ]);
    if (!$rows) {
      return "Memory was searched for the visitor's latest message: no relevant facts found.";
    }
    $lines = array_map(fn (array $row): string => $this->recallService->formatFactLine($row), $rows);
    return "Memory was searched for the visitor's latest message. Relevant facts:\n" . implode("\n", $lines)
      . "\nAnswer from these facts. Use the recall tool only if they do not cover what the visitor asked.";
  }

  /**
   * Finds the visitor's last message and the one before it.
   *
   * @param array $history
   *   The agent's chat history, oldest first.
   *
   * @return array{0: ?string, 1: ?string}
   *   The last and the previous visitor message, NULL where there is none.
   */
  public static function visitorMessages(array $history): array {
    $found = [];
    foreach (array_reverse($history) as $message) {
      if ($message instanceof ChatMessage && $message->getRole() === 'user' && trim($message->getText()) !== '') {
        $found[] = trim($message->getText());
        if (count($found) === 2) {
          break;
        }
      }
    }
    return [$found[0] ?? NULL, $found[1] ?? NULL];
  }

}
