<?php

declare(strict_types=1);

namespace Drupal\aim_chatbot\Plugin\ChatProcessor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\ChatProcessor;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\Event\AgentFinishedExecutionEvent;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai_chatbot\Plugin\ChatProcessor\AiAssistantApiProcessor;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\literals\Entity\Literal;
use Drupal\literals\LiteralReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Asks the literals finder before the assistant, so exact values are code.
 *
 * A question that clearly asks for one stored value (a phone number, a link)
 * is answered from that value with a fixed template and no chat model.
 * Anything else, or any finder problem, goes on to the assistant exactly as
 * the plain processor would send it, tools included. The gate uses its own,
 * stricter finder thresholds: a hit is not checked by the chat model's
 * gist check, so it must be clear. It reads the last message only.
 *
 * It also reports a failed model call. ai_agents swallows a provider error
 * and the plain processor then shows the visitor their own question back; here
 * the assistant's configured error message is shown and a warning logged.
 */
#[ChatProcessor(
  id: 'aim_chatbot_gate',
  label: new TranslatableMarkup('AIM gate, then AI Assistant API'),
  description: new TranslatableMarkup('Answers clear exact-value questions from the literals finder, and passes everything else to the AI Assistant.')
)]
class AimGateProcessor extends AiAssistantApiProcessor {

  /**
   * The literals finder, when literals_finder is enabled.
   *
   * A \Drupal\literals_finder\Finder\LiteralFinderInterface.
   */
  protected ?object $finder = NULL;

  /**
   * The literal reader.
   */
  protected LiteralReader $literalReader;

  /**
   * The memory manager, for audit lines.
   */
  protected AimMemoryManager $memoryManager;

  /**
   * The current user, whom the finder and reader answer as.
   */
  protected AccountInterface $currentUser;

  /**
   * The event dispatcher, to see an agent's failed model call.
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The aim logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->finder = $container->get('literals_finder.finder', ContainerInterface::NULL_ON_INVALID_REFERENCE);
    $instance->literalReader = $container->get('literals.reader');
    $instance->memoryManager = $container->get('aim.memory_manager');
    $instance->currentUser = $container->get('current_user');
    $instance->eventDispatcher = $container->get('event_dispatcher');
    $instance->logger = $container->get('logger.channel.aim');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'gate_match_threshold' => 0.8,
      'gate_choice_margin' => 0.4,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['gate_match_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Gate match threshold'),
      '#description' => $this->t('Chooser confidence needed for the gate to answer. Stricter than the finder default; below it the assistant answers.'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#default_value' => ($this->configuration + $this->defaultConfiguration())['gate_match_threshold'],
    ];
    $form['gate_choice_margin'] = [
      '#type' => 'number',
      '#title' => $this->t('Gate choice margin'),
      '#description' => $this->t('Lead the best literal needs over the runner-up for the gate to answer.'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
      '#default_value' => ($this->configuration + $this->defaultConfiguration())['gate_choice_margin'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function doExecute(): ChatOutput {
    $reply = $this->gate();
    if ($reply !== NULL) {
      return $reply;
    }
    return $this->guardModelErrors(fn (): ChatOutput => parent::doExecute());
  }

  /**
   * Runs the assistant, replacing a swallowed model failure with an error.
   *
   * @param callable $run
   *   Runs the assistant and returns its ChatOutput.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The assistant's reply, or the assistant's error message when the
   *   assistant's own agent could not get an answer from the model.
   */
  protected function guardModelErrors(callable $run): ChatOutput {
    $error = NULL;
    $listener = function (AgentFinishedExecutionEvent $event) use (&$error): void {
      // Only the assistant's own agent: a failed sub-agent can be recovered
      // from, and then the assistant still answers.
      $assistant = isset($this->aiAssistantRunner) ? $this->aiAssistantRunner->getAssistant() : NULL;
      if ($assistant && $event->getAgentId() !== (string) $assistant->get('ai_agent')) {
        return;
      }
      $agent = $event->getAgent();
      $message = method_exists($agent, 'getLastError') ? $agent->getLastError() : NULL;
      if ($message !== NULL && $message !== '') {
        $error = $message;
      }
    };
    $this->eventDispatcher->addListener(AgentFinishedExecutionEvent::EVENT_NAME, $listener);
    try {
      $output = $run();
    }
    finally {
      $this->eventDispatcher->removeListener(AgentFinishedExecutionEvent::EVENT_NAME, $listener);
    }
    if ($error === NULL) {
      return $output;
    }

    $this->logger->warning('Chat model call failed, visitor shown the error message, uid @uid: @error', [
      '@uid' => $this->currentUser->id(),
      '@error' => mb_substr($error, 0, 200),
    ]);
    $text = str_replace('[error_message]', '', (string) $this->aiAssistantRunner->getAssistant()->get('error_message'));
    return new ChatOutput(new ChatMessage('assistant', $text), $text, []);
  }

  /**
   * Answers from the finder when it clearly names one value.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput|null
   *   The reply, or NULL to hand the question on to the assistant.
   */
  protected function gate(): ?ChatOutput {
    // A processor built without saved settings has no defaults merged in.
    $settings = $this->configuration + $this->defaultConfiguration();
    if ($this->finder === NULL) {
      return NULL;
    }
    $input = $this->getInput();
    $question = $input ? trim($this->convertInputToUserMessage($input)->getMessage()) : '';
    if ($question === '') {
      return NULL;
    }

    try {
      $result = $this->finder->find($question, $this->currentUser, NULL, [
        'match_threshold' => (float) $settings['gate_match_threshold'],
        'choice_margin' => (float) $settings['gate_choice_margin'],
      ]);
    }
    catch (\Throwable $e) {
      $this->memoryManager->logAudit('Gate pass (finder threw @class), uid @uid.', [
        '@class' => get_class($e),
        '@uid' => $this->currentUser->id(),
      ]);
      return NULL;
    }
    if ($result->outcome !== 'match' || !$result->literals) {
      $this->memoryManager->logAudit('Gate pass (@outcome, @reason), uid @uid.', [
        '@outcome' => $result->outcome,
        '@reason' => $result->reason ?: '-',
        '@uid' => $this->currentUser->id(),
      ]);
      return NULL;
    }

    $resolved = $this->literalReader->resolveFound('match', $result->literals, $this->currentUser);
    $item = reset($resolved['items']);
    if (!$item) {
      // The finder matched, but this visitor may not read the value.
      $this->memoryManager->logAudit('Gate pass (match not readable), uid @uid.', [
        '@uid' => $this->currentUser->id(),
      ]);
      return NULL;
    }
    $key = (string) array_key_first($resolved['items']);
    $text = $this->template($key, $item->label, $item->value, $item->kind);

    $this->memoryManager->logAudit('Gate hit: literal @key via @tier, uid @uid.', [
      '@key' => $key,
      '@tier' => $result->tier,
      '@uid' => $this->currentUser->id(),
    ]);

    // Keep the thread whole: the question and this reply, as the plain
    // processor would have stored them.
    $this->initializeRunner();
    $this->aiAssistantRunner->setUserMessage(new UserMessage($question));
    $this->aiAssistantRunner->setAssistantMessage($text);

    return new ChatOutput(new ChatMessage('assistant', $text), $text, []);
  }

  /**
   * Builds the fixed reply: label, value, and what was matched.
   *
   * @param string $key
   *   The literal key.
   * @param string $label
   *   The value's label.
   * @param string $value
   *   The exact value.
   * @param string $kind
   *   The value kind (url, phone, email, text).
   *
   * @return string
   *   Markdown for the chat widget.
   */
  protected function template(string $key, string $label, string $value, string $kind): string {
    $shown = $kind === 'url' && preg_match('#^https?://#i', $value) ? '[' . $value . '](' . $value . ')' : $value;
    $gist = Literal::load($key)?->getGist() ?? '';
    $text = '**' . $label . '**: ' . $shown;
    if ($gist !== '') {
      $text .= "\n\n_" . $gist . '_';
    }
    return $text;
  }

}
