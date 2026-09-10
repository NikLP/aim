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
 * Searches previously remembered site-scoped facts.
 *
 * Replaces the withdrawn AiAssistantAction-based aim_memory_action (removed
 * with the migration to ai_agents, see CLAUDE.md). Deliberately locked to
 * scope=site: locking recall as well as remember matters for privacy, not
 * only correctness - without it an anonymous demo visitor's question could
 * surface real scope=user facts about someone else back to them.
 */
#[FunctionCall(
  id: 'aim_chatbot:recall',
  function_name: 'aim_recall',
  name: 'Recall facts',
  description: 'Search previously remembered facts about this site for anything relevant to the current question.',
  group: 'aim_chatbot',
  context_definitions: [
    'text' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search text'),
      description: new TranslatableMarkup('What to search remembered facts for.'),
      required: TRUE,
    ),
  ],
)]
final class AimRecall extends FunctionCallBase implements ExecutableFunctionCallInterface, ContainerFactoryPluginInterface {

  private const FACT_SCOPE = 'site';

  private const RESULT_LIMIT = 5;

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
      $this->setOutput('No search text provided.');
      return;
    }

    try {
      $rows = $this->memoryManager->recall($text, self::FACT_SCOPE, NULL, NULL, self::RESULT_LIMIT);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->setOutput('Could not search memory: ' . $e->getMessage());
      return;
    }

    if (empty($rows)) {
      $this->setOutput('No relevant facts found.');
      return;
    }

    $lines = array_map(static fn (array $row): string => '- ' . $row['text'], $rows);
    $this->setOutput("Relevant facts:\n" . implode("\n", $lines));
  }

}
