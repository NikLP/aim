<?php

declare(strict_types=1);

namespace Drupal\aim\Drush\Commands;

use Drupal\aim\AimStatusChecker;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Service\AimConsolidationService;
use Drupal\aim\Service\AimRecallService;
use Drupal\aim\Service\AimRememberService;
use Drupal\aim\Service\AimSettings;
use Drupal\aim\Service\AimTrustPolicy;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

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

  use AutowireTrait;

  /**
   * Constructs an AimCommands object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   * @param \Drupal\aim\Service\AimConsolidationService $consolidation
   *   The consolidation service, used by aim:consolidate.
   * @param \Drupal\aim\Service\AimRememberService $rememberService
   *   The remember service, used by the write commands.
   * @param \Drupal\aim\Service\AimRecallService $recallService
   *   The recall service, used by aim:recall.
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The settings reader.
   * @param \Drupal\aim\AimStatusChecker $statusChecker
   *   The status checker, used by aim:status.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
    protected AimConsolidationService $consolidation,
    protected AimRememberService $rememberService,
    protected AimRecallService $recallService,
    protected AimSettings $settings,
    protected AimStatusChecker $statusChecker,
  ) {
    parent::__construct();
  }

  /**
   * Extracts candidate memory facts from a text file and saves them.
   *
   * @param string $file
   *   Path to a text file, resolved from the Drupal root.
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'aim:extract', aliases: ['aim-extract'])]
  #[CLI\Argument(name: 'file', description: 'Path to a text file to extract facts from.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID to use.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID to use.')]
  #[CLI\Option(name: 'source', description: 'Provenance tag stored on every created fact.')]
  #[CLI\Option(name: 'subject-uid', description: 'A uid of a real account to attach any scope=user candidate to. Omit to skip all scope=user candidates.')]
  #[CLI\Option(name: 'index', description: 'Reindex the vector index immediately after saving.')]
  #[CLI\Usage(name: 'drush aim:extract notes.txt', description: 'Extract facts from notes.txt using the default provider and model.')]
  public function extract(
    string $file,
    array $options = [
      'provider' => NULL,
      'model' => NULL,
      'source' => NULL,
      'subject-uid' => NULL,
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

    $provider = $this->resolveChatProvider($options, 'extraction');
    if ($provider === NULL) {
      return;
    }
    [$provider_id, $model_id] = $provider;

    $source = $options['source'] ?? ('extract:' . basename($file));
    try {
      $result = $this->rememberService->ingestText($text, $source, $options['subject-uid'] ?: NULL, $provider_id, $model_id, AimTrustPolicy::CHANNEL_DRUSH);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return;
    }
    if ($result['extracted'] === 0) {
      $this->io()->note('The model returned no facts worth remembering.');
      return;
    }

    if ($result['skipped'] > 0) {
      $this->io()->warning("{$result['skipped']} user-scope fact(s) skipped: no --subject-uid was given to attach them to.");
    }

    if ($result['blocked'] > 0) {
      $this->io()->warning("{$result['blocked']} fact(s) blocked by a guardrail check.");
    }

    if (empty($result['created'])) {
      $this->io()->note('No facts were saved.');
      return;
    }

    $memoryManager = $this->memoryManager;
    $rows = array_map(static fn (AimFact $fact): array => [
      $fact->id(),
      $fact->bundle(),
      $memoryManager->scopeRequiresAccount($fact->bundle()) ? $fact->get('user')->target_id : $fact->get('subject')->value,
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
   * that reasoning as part of its own conversation) hands it. Pass --file
   * instead of $text to save several facts (each with its own scope/
   * subject/etc.) in one command invocation, one Drupal bootstrap.
   *
   * @param string|null $text
   *   The fact text, one short statement. Omit when using --file.
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'aim:remember', aliases: ['aim-remember'])]
  #[CLI\Argument(name: 'text', description: 'The fact text, one short statement. Omit when using --file.')]
  #[CLI\Option(name: 'scope', description: 'One of user, role, site, case.')]
  #[CLI\Option(name: 'subject', description: 'Who or what the fact is about. For scope=user, a uid of a real account.')]
  #[CLI\Option(name: 'source', description: 'Provenance tag for this fact.')]
  #[CLI\Option(name: 'category', description: 'Comma-separated term name(s) from the aim_category vocabulary.')]
  #[CLI\Option(name: 'asserted', description: 'When this fact became true in reality, if different from now.')]
  #[CLI\Option(name: 'expires', description: 'Calendar date (YYYY-MM-DD) the fact stops being true; it is retired after that day.')]
  #[CLI\Option(name: 'target-type', description: 'The referenced entity type ID, for scope=entity (e.g. node). Ignored for every other scope.')]
  #[CLI\Option(name: 'target-id', description: 'The referenced entity ID, for scope=entity. Ignored for every other scope.')]
  #[CLI\Option(name: 'file', description: 'Path to a JSON file of fact objects, saved in one bootstrap instead of $text/the other options.')]
  #[CLI\Usage(name: 'drush aim:remember "Prefers email over phone." --scope=user --subject=42', description: 'Save a plain prose fact about a user, by uid.')]
  #[CLI\Usage(name: 'drush aim:remember --file=facts.json', description: 'Save every fact in facts.json in one bootstrap.')]
  #[CLI\Usage(name: 'drush aim:remember "The lobby mural was repainted in 2025." --scope=entity --target-type=node --target-id=42', description: 'Save a fact about a specific node.')]
  public function remember(
    ?string $text = NULL,
    array $options = [
      'scope' => NULL,
      'subject' => NULL,
      'source' => NULL,
      'category' => NULL,
      'asserted' => NULL,
      'expires' => NULL,
      'target-type' => NULL,
      'target-id' => NULL,
      'file' => NULL,
    ],
  ): void {
    if (!empty($options['file'])) {
      $this->rememberBatch($options['file']);
      return;
    }

    if ($text === NULL || trim($text) === '') {
      $this->io()->error('Provide fact text, or --file with a JSON array of fact objects.');
      return;
    }

    if (empty($options['scope'])) {
      // No default here - which scopes exist depends entirely on which
      // aim_scope_* submodules are installed (ADR-0026), so core has no
      // sound default to assume.
      $this->io()->error('--scope is required: one of ' . implode(', ', $this->memoryManager->allowedScopes()) . '.');
      return;
    }

    try {
      [$category, $asserted] = $this->rememberService->parseFactFields($options);
      $fact = $this->rememberService->remember($text, $options['scope'], $options['subject'], $options['source'], $category, $asserted, NULL, $options['target-type'], $options['target-id'], expires: $this->rememberService->parseExpires($options), channel: AimTrustPolicy::CHANNEL_DRUSH);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    $message = 'Created aim_fact ' . $fact->id() . '.';
    if ($fact->bundle() === 'case') {
      $message .= ' Case ID: ' . $fact->get('subject')->value . ' - pass this as --subject to add more facts to this case.';
    }
    $this->io()->success($message);
  }

  /**
   * Saves every fact object in a JSON file, one bootstrap for the batch.
   *
   * One bad entry is reported and skipped rather than aborting the rest of
   * the file (see AimRememberService::rememberBatch()).
   *
   * @param string $file
   *   Path to a JSON file containing an array of fact objects. Each object
   *   uses the same field names as aim:remember's own options (text,
   *   scope, subject, source, category, asserted, target_type,
   *   target_id); category may be a JSON array instead of a
   *   comma-separated string.
   */
  private function rememberBatch(string $file): void {
    if (!is_readable($file)) {
      $this->io()->error("Cannot read file: $file");
      return;
    }

    $entries = json_decode(file_get_contents($file), TRUE);
    if (!is_array($entries)) {
      $this->io()->error('Expected --file to contain a JSON array of fact objects.');
      return;
    }

    $result = $this->rememberService->rememberBatch($entries, NULL, AimTrustPolicy::CHANNEL_DRUSH);
    foreach ($result['errors'] as $i => $message) {
      $this->io()->warning("Entry $i: $message" . (str_starts_with($message, 'missing') ? ', skipped.' : ''));
    }
    foreach ($result['created'] as $i => $fact) {
      $entry_message = "Entry $i: created aim_fact " . $fact->id() . '.';
      if ($fact->bundle() === 'case') {
        $entry_message .= ' Case ID: ' . $fact->get('subject')->value . '.';
      }
      $this->io()->success($entry_message);
    }

    $this->io()->note('Created ' . count($result['created']) . ' of ' . count($entries) . ' fact(s).');
  }

  /**
   * Runs a semantic query against the vector index and prints results.
   *
   * @param string $text
   *   The search text.
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'aim:recall', aliases: ['aim-recall'])]
  #[CLI\Argument(name: 'text', description: 'The search text.')]
  #[CLI\Option(name: 'scope', description: 'Restrict results to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'subject', description: 'Restrict results to one subject. Not used for scope=user.')]
  #[CLI\Option(name: 'subject-uid', description: 'Restrict results to one user, by uid or username. Only meaningful with scope=user.')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of results.')]
  #[CLI\Option(name: 'max-distance', description: 'Drop facts with a cosine distance above this. Omit for the raw nearest facts (used to calibrate the cutoff); the site default is aim.settings recall_max_distance.')]
  #[CLI\Option(name: 'include-untrusted', description: 'Also return untrusted facts, for reviewing what aim.settings:default_trusted left out of ordinary recall() results (ADR-0002).')]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Usage(name: 'drush aim:recall "email preference"', description: 'Search all facts for anything related to email preference.')]
  #[CLI\Usage(name: 'drush aim:recall "email preference" --max-distance=0.48', description: 'Only facts within 0.48 cosine distance, as the chatbot and MCP tool do by default.')]
  public function recall(
    string $text,
    array $options = [
      'scope' => NULL,
      'subject' => NULL,
      'subject-uid' => NULL,
      'limit' => 10,
      'max-distance' => NULL,
      'include-untrusted' => FALSE,
      'format' => 'table',
    ],
  ): void {
    try {
      $rows = $this->recallService->recall(
        $text,
        $options['scope'] ?: NULL,
        $options['subject'] ?: NULL,
        $options['subject-uid'] ?: NULL,
        (int) $options['limit'],
        ($options['max-distance'] ?? NULL) !== NULL && $options['max-distance'] !== '' ? (float) $options['max-distance'] : NULL,
        (bool) $options['include-untrusted'],
        accessCheck: FALSE,
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
      $row['trusted'] ? 'true' : 'false',
      gmdate('Y-m-d', (int) ($row['asserted'] ?: $row['created'])),
    ], $rows);
    $this->io()->table(['ID', 'Distance', 'Scope', 'Subject', 'Text', 'Source', 'Trusted', 'Date'], $table_rows);
  }

  /**
   * Reviews existing facts for near-duplicates and merges or retires them.
   *
   * Runs entirely against facts already in the store, independent of any
   * particular write path. Each fact is compared to its nearest vector
   * neighbors (up to aim.settings neighbor_limit) within the same
   * scope/subject and end date: identical text is retired with no model
   * call, any other pair within the comparison distance gets a
   * classification call (ADD/UPDATE/RETIRE/NOOP, Mem0's vocabulary), and
   * anything farther is left alone at zero cost. Retiring a fact sets its
   * `retired` field rather than deleting it, to keep an audit trail.
   *
   * The comparison distance defaults to the live aim.settings
   * compare_distance (/admin/config/aim/settings); --compare-distance
   * overrides it for one invocation. Re-check it with `drush aim:recall`'s
   * distance column whenever the embeddings model changes.
   *
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'aim:consolidate', aliases: ['aim-consolidate'])]
  #[CLI\Option(name: 'scope', description: 'Restrict the sweep to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID for the classification call.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID for the classification call.')]
  #[CLI\Option(name: 'compare-distance', description: 'Distance at or below which a neighbor is compared. Defaults to the live aim.settings value.')]
  #[CLI\Option(name: 'dry-run', description: 'Print decisions without saving anything.')]
  #[CLI\Usage(name: 'drush aim:consolidate --dry-run', description: 'Preview consolidation decisions across every scope without changing anything.')]
  public function consolidate(
    array $options = [
      'scope' => NULL,
      'provider' => NULL,
      'model' => NULL,
      'compare-distance' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    $provider = $this->resolveChatProvider($options, 'consolidation');
    if ($provider === NULL) {
      return;
    }
    [$provider_id, $model_id] = $provider;

    // NULL (the default) follows the live aim.settings value
    // (/admin/config/aim/settings) rather than a hardcoded default, same
    // posture as resolveChatProvider() following ai.settings.
    $compare_distance = $options['compare-distance'] !== NULL ? (float) $options['compare-distance'] : $this->settings->getCompareDistance();

    try {
      $result = $this->consolidation->consolidate(
        $options['scope'] ?: NULL,
        $provider_id,
        $model_id,
        $compare_distance,
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

    $this->io()->table(['Kept', 'Candidate', 'Score', 'Decision', 'Merged text'], $result['rows']);
    if (!empty($options['dry-run'])) {
      $this->io()->note(count($result['rows']) . ' decision(s) previewed, nothing saved. Omit --dry-run to apply.');
    }
    else {
      $this->io()->success(count($result['rows']) . ' decision(s) applied.');
    }
  }

  /**
   * Retires every live fact whose expires date has passed (ADR-0057).
   *
   * Meant for a crontab entry, next to the consolidation queue runner.
   */
  #[CLI\Command(name: 'aim:expire', aliases: ['aim-expire'])]
  #[CLI\Usage(name: 'drush aim:expire', description: 'Retire every live fact whose expires date has passed.')]
  public function expire(): void {
    $count = $this->memoryManager->retireExpired();
    $this->io()->success($count . ' expired fact(s) retired.');
  }

  /**
   * Reports on the database-side state the vector search feature depends on.
   *
   * `config:status` only diffs config, not runtime DB state - a fact can
   * fall out of the vector index, the provider shim can stop being swapped
   * in, an existing collection table keeps whatever HNSW M it was built
   * with regardless of what the code now ships. This checks those directly
   * instead of walking them by hand, replacing step 6 of DEVELOPING.md's
   * "Upgrading an existing site".
   *
   * @return int
   *   0 if every check passed, 1 if any failed (DrushCommands::EXIT_*).
   */
  #[CLI\Command(name: 'aim:status', aliases: ['aim-status'])]
  #[CLI\Usage(name: 'drush aim:status', description: 'Check vector index parity, the provider shim, HNSW tuning and the recall cutoff.')]
  public function status(): int {
    $checks = $this->statusChecker->checkAll();

    $this->io()->table(['Check', 'Status', 'Detail'], array_map(
      static fn (array $check): array => [$check['label'], $check['ok'] ? 'OK' : 'FAIL', $check['detail']],
      $checks,
    ));

    $failed = count(array_filter($checks, static fn (array $check): bool => !$check['ok']));
    if ($failed > 0) {
      $this->io()->error("$failed of " . count($checks) . ' check(s) failed.');
      return self::EXIT_FAILURE;
    }

    $this->io()->success('All checks passed.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Resolves --provider/--model options, falling back to the activity's model.
   *
   * Deliberately does not hardcode a fallback provider/model: a literal
   * default here would be exactly the kind of value that already had to be
   * hand-edited twice this project when the site's working provider
   * changed. Resolving the activity's configured model (which itself falls
   * back to `ai.settings`' default chat provider) means these commands
   * automatically follow the settings form.
   *
   * @param array $options
   *   The command options array, read for 'provider' and 'model'.
   * @param string $activity
   *   The activity whose configured model is the fallback: extraction or
   *   consolidation.
   *
   * @return array|null
   *   A [provider_id, model_id] pair, or NULL if neither option was given
   *   and no default chat provider is configured (an error has already
   *   been printed to the user in that case).
   */
  protected function resolveChatProvider(array $options, string $activity): ?array {
    if (!empty($options['provider']) && !empty($options['model'])) {
      return [$options['provider'], $options['model']];
    }

    $default = $this->settings->getModelFor($activity);
    if ($default === NULL) {
      $this->io()->error('No --provider/--model given, and no default chat provider is configured. Set one at /admin/config/ai/settings, or pass --provider and --model explicitly.');
      return NULL;
    }

    return [$default['provider_id'], $default['model_id']];
  }

}
