<?php

declare(strict_types=1);

namespace Drupal\aim_chatbot\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\aim\Service\AimMemoryManager;
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
  ],
)]
final class AimRemember extends FunctionCallBase implements ExecutableFunctionCallInterface, ContainerFactoryPluginInterface {

  private const FACT_SCOPE = 'site';

  /**
   * The aim memory manager.
   */
  protected AimMemoryManager $memoryManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->memoryManager = $container->get('aim.memory_manager');
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
    
    try {
      $fact = $this->memoryManager->remember($text, self::FACT_SCOPE, NULL, 'chatbot:aim_chatbot', NULL);
      $this->setOutput('Saved fact ' . $fact->id() . ': ' . $text);
    }
    catch (\InvalidArgumentException $e) {
      $this->setOutput('Could not save fact: ' . $e->getMessage());
    }
  }

}
