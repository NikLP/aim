<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AiAssistantAction;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\ai_assistant_api\Attribute\AiAssistantAction;
use Drupal\ai_assistant_api\Base\AiAssistantActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an AI Assistant remember and recall aim's site-scoped memory.
 *
 * Deliberately locked to scope=site on both read and write, not a general
 * front end onto aim_fact: a chat visitor is not resolved to a real Drupal
 * account here, and scope=user facts require one (CLAUDE.md, "User-scope
 * facts now require a real account"). Locking recall to scope=site also
 * matters for privacy, not only correctness - without it an anonymous demo
 * visitor's question could surface real scope=user facts about someone
 * else back to them. Per-visitor identity binding is a separate, unbuilt
 * question, out of scope for this action.
 */
#[AiAssistantAction(
  id: 'aim_memory_action',
  label: new TranslatableMarkup('AIM Memory Actions'),
)]
final class AimMemoryAction extends AiAssistantActionBase {

  private const FACT_SCOPE = 'site';

  /**
   * Constructs an AimMemoryAction object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store factory.
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   */
  public function __construct(
    array $configuration,
    PrivateTempStoreFactory $tempStoreFactory,
    protected AimMemoryManager $memoryManager,
  ) {
    parent::__construct($configuration, $tempStoreFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $container->get('tempstore.private'),
      $container->get('aim.memory_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function listActions(): array {
    return [
      'aim_remember' => [
        'id' => 'aim_remember',
        'plugin' => 'aim_memory_action',
        'label' => new TranslatableMarkup('Remember a fact'),
        'description' => new TranslatableMarkup('Save a short, atomic fact worth remembering long-term about this site.'),
      ],
      'aim_recall' => [
        'id' => 'aim_recall',
        'plugin' => 'aim_memory_action',
        'label' => new TranslatableMarkup('Recall facts'),
        'description' => new TranslatableMarkup('Search previously remembered facts about this site for anything relevant to the current question.'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function listContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function provideFewShotLearningExample(): array {
    return [
      [
        'description' => 'The user states something worth remembering about the site for later, e.g. a policy or a fact about the organization.',
        'schema' => [
          'actions' => [
            [
              'action' => 'aim_remember',
              'plugin' => 'aim_memory_action',
              'text' => 'The support desk is staffed 9-5 UK time.',
            ],
          ],
        ],
      ],
      [
        'description' => 'The user asks a question that might already be answered by something remembered about the site.',
        'schema' => [
          'actions' => [
            [
              'action' => 'aim_recall',
              'plugin' => 'aim_memory_action',
              'text' => 'support desk hours',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function triggerAction(string $action_id, $parameters = []): void {
    $text = trim((string) ($parameters['text'] ?? ''));
    switch ($action_id) {
      case 'aim_remember':
        $this->rememberAction($text);
        break;

      case 'aim_recall':
        $this->recallAction($text);
        break;
    }
  }

  /**
   * Saves a site-scoped fact.
   *
   * @param string $text
   *   The fact text.
   */
  protected function rememberAction(string $text): void {
    if ($text === '') {
      $this->setOutputContext('aim_memory', 'No fact text provided, nothing saved.');
      return;
    }
    try {
      $fact = $this->memoryManager->remember($text, self::FACT_SCOPE, NULL, 'chatbot:' . $this->assistant->id(), NULL);
      $this->setOutputContext('aim_memory', 'Saved fact ' . $fact->id() . ': ' . $text);
    }
    catch (\InvalidArgumentException $e) {
      $this->setOutputContext('aim_memory', 'Could not save fact: ' . $e->getMessage());
    }
  }

  /**
   * Searches site-scoped facts.
   *
   * @param string $text
   *   The search text.
   */
  protected function recallAction(string $text): void {
    if ($text === '') {
      $this->setOutputContext('aim_memory', 'No search text provided.');
      return;
    }
    try {
      $rows = $this->memoryManager->recall($text, self::FACT_SCOPE, NULL, NULL, 5);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->setOutputContext('aim_memory', 'Could not search memory: ' . $e->getMessage());
      return;
    }
    if (empty($rows)) {
      $this->setOutputContext('aim_memory', 'No relevant facts found.');
      return;
    }
    $lines = array_map(static fn (array $row): string => '- ' . $row['text'], $rows);
    $this->setOutputContext('aim_memory', "Relevant facts:\n" . implode("\n", $lines));
  }

}
