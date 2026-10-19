<?php

declare(strict_types=1);

namespace Drupal\aim_chatbot\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Service\AimRememberService;
use Drupal\aim\Service\AimTrustPolicy;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Saves a short, atomic site-scoped fact worth remembering long-term.
 *
 * Replaces the withdrawn AiAssistantAction-based aim_memory_action (removed
 * with the migration to ai_agents, see CLAUDE.md). Deliberately locked to
 * scope=site, same reasoning as its predecessor: a chat visitor is not
 * resolved to a real Drupal account, and scope=user facts require one
 * (CLAUDE.md, "User-scope facts now require a real account").
 */
#[FunctionCall(
  id: 'aim_chatbot:remember',
  function_name: 'aim_remember',
  name: 'Remember a fact',
  description: 'Save a short, atomic fact worth remembering long-term about this site.',
  group: 'aim_chatbot',
  context_definitions: [
    'text' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Fact text'),
      description: new TranslatableMarkup('The fact to remember, as a short atomic statement.'),
      required: TRUE,
    ),
    'expires' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Expires'),
      description: new TranslatableMarkup('Only when the fact has a stated end: the calendar date it stops being true, as YYYY-MM-DD (for example 2026-10-16 for "until Friday 16 October"). Work it out from today\'s date. Omit for a fact with no end.'),
      required: FALSE,
    ),
  ],
)]
final class AimRemember extends FunctionCallBase implements ExecutableFunctionCallInterface, ContainerFactoryPluginInterface {

  private const FACT_SCOPE = 'site';

  /**
   * The aim memory manager.
   */
  protected AimMemoryManager $memoryManager;

  /**
   * The remember service.
   */
  protected AimRememberService $rememberService;

  /**
   * The current user, whose permission to add facts is checked.
   */
  protected AccountInterface $currentUser;

  /**
   * The conversation the fact comes from, for the grounded check.
   */
  protected ?string $sourceText = NULL;

  /**
   * Sets the conversation the fact comes from.
   *
   * Set by AimChatbotSourceTextSubscriber just before the tool runs.
   *
   * @param string|null $sourceText
   *   The visitor's last message with the assistant turn before it, or NULL.
   */
  public function setSourceText(?string $sourceText): void {
    $this->sourceText = $sourceText;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->memoryManager = $container->get('aim.memory_manager');
    $instance->rememberService = $container->get('aim.remember');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $text = trim((string) $this->getContextValue('text'));
    if ($text === '') {
      $this->setOutput('No fact text provided, nothing saved.');
      return;
    }

    // The same per-scope permission the admin form and the Tool API enforce:
    // being able to chat is not being allowed to change what the site
    // remembers. Say so plainly so the assistant can tell the visitor.
    if (!$this->memoryManager->checkCreateAccess(self::FACT_SCOPE, $this->currentUser)->isAllowed()) {
      $this->setOutput('Not saved: this visitor is not allowed to add facts to the library\'s memory. Tell them plainly that you cannot save things for them.');
      return;
    }

    try {
      // Whether a visitor's say-so is approved site knowledge is the trust
      // policy's call for the chat channel (shipped: hold, so the fact waits
      // untrusted for a reviewer and one chat cannot change what others are
      // told).
      $fact = $this->rememberService->remember($text, self::FACT_SCOPE, NULL, 'chatbot:aim_chatbot', sourceText: $this->sourceText, expires: $this->rememberService->parseExpires(['expires' => $this->getContextValue('expires')]), channel: AimTrustPolicy::CHANNEL_CHAT);
      $this->setOutput('Saved fact ' . $fact->id() . ($fact->get('trusted')->value ? ' (shared with other visitors)' : ' (waiting for staff review; it is not shared with other visitors until approved)') . ': ' . $text);
    }
    catch (\InvalidArgumentException $e) {
      $this->setOutput('Could not save fact: ' . $e->getMessage());
    }
  }

}
