<?php

declare(strict_types=1);

namespace Drupal\aim\Drush\Commands;

use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush front end for aim's memory store.
 *
 * All the actual logic - extraction, remember/recall, consolidation - lives
 * in \Drupal\aim\Service\AimMemoryManager, shared with any other caller
 * (a Tool API plugin, an ECA action, a future Form). These commands only
 * handle CLI-specific concerns: parsing option strings, and printing
 * results or errors via $this->io().
 */
final class AimCommands extends DrushCommands {

  /**
   * Constructs an AimCommands object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aim.memory_manager'),
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

    $facts = $this->memoryManager->extractFacts($text, $options['provider'], $options['model']);
    if (empty($facts)) {
      $this->io()->note('The model returned no facts worth remembering.');
      return;
    }

    $source = $options['source'] ?? ('extract:' . basename($file));
    $result = $this->memoryManager->createFactsFromCandidates($facts, $source);

    if ($result['skipped'] > 0) {
      $this->io()->warning("{$result['skipped']} user-scope fact(s) skipped: the model's subject did not resolve to a real account on this site.");
    }

    if (empty($result['created'])) {
      $this->io()->note('No facts were saved.');
      return;
    }

    $rows = array_map(static fn (AimFact $fact): array => [
      $fact->id(),
      $fact->get('scope')->value,
      $fact->get('scope')->value === 'user' ? $fact->get('subject_uid')->target_id : $fact->get('subject')->value,
      $fact->get('text')->value,
    ], $result['created']);

    $this->io()->table(['ID', 'Scope', 'Subject', 'Text'], $rows);
    $this->io()->success(count($rows) . ' fact(s) created from ' . $file . '.');

    if (!empty($options['index'])) {
      $indexed = $this->memoryManager->reindex();
      if ($indexed !== NULL) {
        $this->io()->success("Indexed $indexed item(s).");
      }
    }
    else {
      $this->io()->note('Not indexed yet, next cron run will pick these up. Pass --index to do it now.');
    }
  }

  /**
   * Creates an aim_fact directly, no chat call.
   *
   * Unlike aim:extract, this does not decide what is worth remembering -
   * it stores exactly what the caller (typically an agent that already did
   * that reasoning as part of its own conversation) hands it.
   *
   * @param string $text
   *   The fact text, one short statement.
   * @param array $options
   *   Command options.
   *
   * @command aim:remember
   * @aliases aim-remember
   *
   * @option scope One of user, role, site, case.
   * @option subject Who or what the fact is about. For scope=user, a uid or username of a real account on this site. Empty for site scope.
   * @option source Provenance tag for this fact.
   * @option state Optional boolean flag value: true or false. Omit for facts with no boolean shape.
   *
   * @usage drush aim:remember "Prefers email over phone." --scope=user --subject=42
   *   Save a plain prose fact about a user, by uid or username.
   * @usage drush aim:remember "Opted out of marketing email." --scope=user --subject=42 --state=true
   *   Save a fact that is itself a boolean flag.
   */
  #[CLI\Command(name: 'aim:remember', aliases: ['aim-remember'])]
  #[CLI\Argument(name: 'text', description: 'The fact text, one short statement.')]
  #[CLI\Option(name: 'scope', description: 'One of user, role, site, case.')]
  #[CLI\Option(name: 'subject', description: 'Who or what the fact is about. For scope=user, a uid or username of a real account.')]
  #[CLI\Option(name: 'source', description: 'Provenance tag for this fact.')]
  #[CLI\Option(name: 'state', description: 'Optional boolean flag value: true or false. Omit for facts with no boolean shape.')]
  #[CLI\Usage(name: 'drush aim:remember "Prefers email over phone." --scope=user --subject=42', description: 'Save a plain prose fact about a user, by uid or username.')]
  public function remember(
    string $text,
    array $options = [
      'scope' => 'site',
      'subject' => NULL,
      'source' => NULL,
      'state' => NULL,
    ],
  ): void {
    $state = NULL;
    if ($options['state'] !== NULL) {
      $state = filter_var($options['state'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($state === NULL) {
        $this->io()->error('Invalid --state "' . $options['state'] . '", expected true or false.');
        return;
      }
    }

    try {
      $fact = $this->memoryManager->remember($text, $options['scope'], $options['subject'], $options['source'], $state);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    $this->io()->success('Created aim_fact ' . $fact->id() . '.');
  }

  /**
   * Runs a semantic query against the vector index and prints results.
   *
   * @param string $text
   *   The search text.
   * @param array $options
   *   Command options.
   *
   * @command aim:recall
   * @aliases aim-recall
   *
   * @option scope Restrict results to one scope: user, role, site, case.
   * @option subject Restrict results to one subject. Not used for scope=user, see subject-uid.
   * @option subject-uid Restrict results to one user, by uid or username. Only meaningful with scope=user.
   * @option limit Maximum number of results.
   * @option format Output format: table or json.
   *
   * @usage drush aim:recall "email preference"
   *   Search all facts for anything related to email preference.
   * @usage drush aim:recall "email preference" --scope=user --subject-uid=42 --format=json
   *   Search scoped to one user, machine-readable output.
   */
  #[CLI\Command(name: 'aim:recall', aliases: ['aim-recall'])]
  #[CLI\Argument(name: 'text', description: 'The search text.')]
  #[CLI\Option(name: 'scope', description: 'Restrict results to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'subject', description: 'Restrict results to one subject. Not used for scope=user.')]
  #[CLI\Option(name: 'subject-uid', description: 'Restrict results to one user, by uid or username. Only meaningful with scope=user.')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of results.')]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Usage(name: 'drush aim:recall "email preference"', description: 'Search all facts for anything related to email preference.')]
  public function recall(
    string $text,
    array $options = [
      'scope' => NULL,
      'subject' => NULL,
      'subject-uid' => NULL,
      'limit' => 10,
      'format' => 'table',
    ],
  ): void {
    try {
      $rows = $this->memoryManager->recall(
        $text,
        $options['scope'] ?: NULL,
        $options['subject'] ?: NULL,
        $options['subject-uid'] ?: NULL,
        (int) $options['limit'],
      );
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode($rows, JSON_PRETTY_PRINT));
      return;
    }

    if (empty($rows)) {
      $this->io()->note('No facts found.');
      return;
    }

    $table_rows = array_map(static fn (array $row): array => [
      $row['id'],
      $row['score'],
      $row['scope'],
      $row['subject'],
      $row['text'],
      $row['source'],
      $row['state'] === NULL ? '' : ($row['state'] ? 'true' : 'false'),
    ], $rows);
    $this->io()->table(['ID', 'Score', 'Scope', 'Subject', 'Text', 'Source', 'State'], $table_rows);
  }

  /**
   * Reviews existing facts for near-duplicates and merges or retires them.
   *
   * Runs entirely against facts already in the store, independent of any
   * particular write path (see CLAUDE.md's consolidation design notes).
   * Each fact is compared to its nearest vector neighbor within the same
   * scope/subject: an obvious near-duplicate is retired automatically, an
   * ambiguous case gets a single classification call (ADD/UPDATE/DELETE/
   * NOOP, Mem0's vocabulary), and anything past the ambiguous threshold is
   * left alone at zero cost. Retiring a fact sets its `expires` field
   * rather than deleting it, to keep an audit trail - a hard DELETE only
   * happens when the model explicitly says the candidate should not exist
   * as a memory at all.
   *
   * The two threshold defaults were picked empirically against this site's
   * real fact data and the amazeeio titan-embed-text-v2:0 embeddings on
   * 2026-09-09 (a genuine near-duplicate pair scored ~0.34-0.47, a distinct
   * fact about the same subject scored ~0.56-0.60), not carried over from
   * Mem0's or Hindsight's own models. Re-check with `drush aim:recall`'s
   * score column as real fact volume grows.
   *
   * @param array $options
   *   Command options.
   *
   * @command aim:consolidate
   * @aliases aim-consolidate
   *
   * @option scope Restrict the sweep to one scope: user, role, site, case.
   * @option provider The AI provider plugin ID to use for ambiguous cases.
   * @option model The chat model ID to use for ambiguous cases.
   * @option auto-threshold Score at or below which a neighbor is retired automatically, no LLM call.
   * @option ambiguous-threshold Score at or below which an ambiguous neighbor gets a classification call. Above this, facts are left alone.
   * @option dry-run Print decisions without saving anything.
   *
   * @usage drush aim:consolidate --dry-run
   *   Preview consolidation decisions across every scope, changing nothing.
   * @usage drush aim:consolidate --scope=user
   *   Consolidate only user-scoped facts.
   */
  #[CLI\Command(name: 'aim:consolidate', aliases: ['aim-consolidate'])]
  #[CLI\Option(name: 'scope', description: 'Restrict the sweep to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID to use for ambiguous cases.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID to use for ambiguous cases.')]
  #[CLI\Option(name: 'auto-threshold', description: 'Score at or below which a neighbor is retired automatically, no LLM call.')]
  #[CLI\Option(name: 'ambiguous-threshold', description: 'Score at or below which an ambiguous neighbor gets a classification call.')]
  #[CLI\Option(name: 'dry-run', description: 'Print decisions without saving anything.')]
  #[CLI\Usage(name: 'drush aim:consolidate --dry-run', description: 'Preview consolidation decisions across every scope without changing anything.')]
  public function consolidate(
    array $options = [
      'scope' => NULL,
      'provider' => 'amazeeio',
      'model' => 'claude-4-5-sonnet',
      'auto-threshold' => 0.35,
      'ambiguous-threshold' => 0.65,
      'dry-run' => FALSE,
    ],
  ): void {
    try {
      $result = $this->memoryManager->consolidate(
        $options['scope'] ?: NULL,
        $options['provider'],
        $options['model'],
        (float) $options['auto-threshold'],
        (float) $options['ambiguous-threshold'],
        !empty($options['dry-run']),
      );
    }
    catch (\RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    if (empty($result['rows'])) {
      $this->io()->note($result['has_facts'] ? 'No near-duplicate facts found.' : 'No facts to consolidate.');
      return;
    }

    $this->io()->table(['Kept', 'Candidate', 'Score', 'Decision'], $result['rows']);
    if (!empty($options['dry-run'])) {
      $this->io()->note(count($result['rows']) . ' decision(s) previewed, nothing saved. Omit --dry-run to apply.');
    }
    else {
      $this->io()->success(count($result['rows']) . ' decision(s) applied.');
    }
  }

}
