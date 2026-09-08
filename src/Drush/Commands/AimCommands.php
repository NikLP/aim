<?php

declare(strict_types=1);

namespace Drupal\aim\Drush\Commands;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extraction prototype: text in, candidate aim_fact entities out.
 *
 * This is the "interactive PoC" phase of the build order (CLAUDE.md decision
 * 6): a single on-demand command doing extraction via a real chat call, not
 * an unattended queue worker. The source text itself is never stored, only
 * the facts it yields.
 */
final class AimCommands extends DrushCommands {

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai.provider'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Extracts candidate memory facts from a text file and saves them.
   *
   * @param string $file
   *   Path to a text file, resolved from the Drupal root.
   * @param array $options
   *   Command options.
   *
   * @command aim:extract
   * @aliases aim-extract
   *
   * @option provider The AI provider plugin ID to use.
   * @option model The chat model ID to use.
   * @option source Provenance tag stored on every created fact.
   * @option index Reindex the vector index immediately after saving.
   *
   * @usage drush aim:extract notes.txt
   *   Extract facts from notes.txt using the default provider and model.
   * @usage drush aim:extract notes.txt --provider=openai --model=gpt-4o --index
   *   Use a different provider and reindex immediately.
   */
  #[CLI\Command(name: 'aim:extract', aliases: ['aim-extract'])]
  #[CLI\Argument(name: 'file', description: 'Path to a text file to extract facts from.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID to use.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID to use.')]
  #[CLI\Option(name: 'source', description: 'Provenance tag stored on every created fact.')]
  #[CLI\Option(name: 'index', description: 'Reindex the vector index immediately after saving.')]
  #[CLI\Usage(name: 'drush aim:extract notes.txt', description: 'Extract facts from notes.txt using the default provider and model.')]
  public function extract(
    string $file,
    array $options = [
      'provider' => 'amazeeio',
      'model' => 'claude-4-5-sonnet',
      'source' => NULL,
      'index' => FALSE,
    ],
  ): void {
    if (!is_readable($file)) {
      $this->io()->error("Cannot read file: $file");
      return;
    }
    $text = trim(file_get_contents($file));
    if ($text === '') {
      $this->io()->warning('File is empty, nothing to extract.');
      return;
    }

    $facts = $this->extractFacts($text, $options['provider'], $options['model']);
    if (empty($facts)) {
      $this->io()->note('The model returned no facts worth remembering.');
      return;
    }

    $source = $options['source'] ?? ('extract:' . basename($file));
    $rows = [];
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    foreach ($facts as $fact) {
      $entity = $storage->create([
        'scope' => $fact['scope'],
        'subject' => $fact['subject'] ?? '',
        'text' => $fact['text'],
        'source' => $source,
      ]);
      $entity->save();
      $rows[] = [$entity->id(), $fact['scope'], $fact['subject'] ?? '', $fact['text']];
    }

    $this->io()->table(['ID', 'Scope', 'Subject', 'Text'], $rows);
    $this->io()->success(count($rows) . ' fact(s) created from ' . $file . '.');

    if (!empty($options['index'])) {
      $index = $this->entityTypeManager->getStorage('search_api_index')->load('aim_vector_index');
      if ($index instanceof IndexInterface) {
        $indexed = $index->indexItems();
        $this->io()->success("Indexed $indexed item(s).");
      }
    }
    else {
      $this->io()->note('Not indexed yet, next cron run will pick these up. Pass --index to do it now.');
    }
  }

  /**
   * Calls the configured chat provider and returns structured facts.
   *
   * @param string $text
   *   The source text to extract facts from.
   * @param string $provider_id
   *   The AI provider plugin ID.
   * @param string $model_id
   *   The chat model ID.
   *
   * @return array
   *   A list of ['scope' => ..., 'subject' => ..., 'text' => ...] arrays.
   */
  protected function extractFacts(string $text, string $provider_id, string $model_id): array {
    $prompt = <<<PROMPT
      Extract every discrete, atomic fact worth remembering long-term from
      the text below. Each fact must be one short, self-contained sentence.
      Do not invent facts the text does not support. If nothing is worth
      remembering, return an empty facts array.

      For each fact, classify its scope:
      - "user": specific to one named person.
      - "role": true for everyone holding a particular role.
      - "site": about the site or organization itself, not one person.
      - "case": tied to a specific support case or tracked issue.

      Also give a short "subject": for "user" scope, the person's name or
      identifier; for "role", the role name; for "case", a case identifier;
      for "site", leave it empty.

      Text:
      """
      $text
      """
      PROMPT;

    $schema = new StructuredOutputSchema(
      name: 'aim_extracted_facts',
      description: 'Atomic facts extracted from source text.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'properties' => [
          'facts' => [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'properties' => [
                'scope' => [
                  'type' => 'string',
                  'enum' => ['user', 'role', 'site', 'case'],
                ],
                'subject' => ['type' => 'string'],
                'text' => ['type' => 'string'],
              ],
              'required' => ['scope', 'subject', 'text'],
            ],
          ],
        ],
        'required' => ['facts'],
      ],
    );

    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $input->setChatStructuredJsonSchema($schema);

    $provider = $this->aiProvider->createInstance($provider_id);
    $output = $provider->chat($input, $model_id, ['aim_extract']);
    $response_text = $output->getNormalized()->getText();

    $decoded = json_decode($response_text, TRUE);
    if (!is_array($decoded) || !isset($decoded['facts']) || !is_array($decoded['facts'])) {
      throw new \RuntimeException("Model response was not the expected JSON shape: $response_text");
    }

    return $decoded['facts'];
  }

}
