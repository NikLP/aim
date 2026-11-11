<?php

declare(strict_types=1);

namespace Drupal\aim\Drush\Commands;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\aim\AimStatusChecker;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Service\AimConsolidationService;
use Drupal\aim\Service\AimRecallService;
use Drupal\aim\Service\AimRememberService;
use Drupal\aim\Service\AimSettings;
use Drupal\user\UserInterface;
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
 * results or errors via $this->io(). The write commands run as the --uid
 * account, through the same create and trust checks as the web paths
 * (ADR-0065).
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
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher, for running a write as the --uid account.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
    protected AimConsolidationService $consolidation,
    protected AimRememberService $rememberService,
    protected AimRecallService $recallService,
    protected AimSettings $settings,
    protected AimStatusChecker $statusChecker,
    protected AccountSwitcherInterface $accountSwitcher,
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
   *
   * @return int
   *   The exit code: EXIT_FAILURE when a write or lookup was refused.
   */
  #[CLI\Command(name: 'aim:extract', aliases: ['aim-extract'])]
  #[CLI\Argument(name: 'file', description: 'Path to a text file to extract facts from.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID to use.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID to use.')]
  #[CLI\Option(name: 'source', description: 'Provenance tag stored on every created fact.')]
  #[CLI\Option(name: 'subject-uid', description: 'A uid of a real account to attach any scope=user candidate to. Omit to skip all scope=user candidates.')]
  #[CLI\Option(name: 'uid', description: 'Required. The uid of the account the write runs as: its permissions decide which scopes it may write and whether its facts start trusted.')]
  #[CLI\Option(name: 'index', description: 'Reindex the vector index immediately after saving.')]
  #[CLI\Usage(name: 'drush aim:extract notes.txt --uid=1', description: 'Extract facts from notes.txt as uid 1, using the default provider and model.')]
  public function extract(
    string $file,
    array $options = [
      'provider' => NULL,
      'model' => NULL,
      'source' => NULL,
      'subject-uid' => NULL,
      'uid' => NULL,
      'index' => FALSE,
    ],
  ): int {
    $writer = $this->writer($options);
    if ($writer === NULL) {
      return self::EXIT_FAILURE;
    }
    if (!is_readable($file)) {
      $this->io()->error("Cannot read file: $file");
      return self::EXIT_FAILURE;
    }
    $text = trim(file_get_contents($file));
    if ($text === '') {
      $this->io()->warning('File is empty, nothing to extract.');
      return self::EXIT_SUCCESS;
    }

    $provider = $this->resolveChatProvider($options, 'extraction');
    if ($provider === NULL) {
      return self::EXIT_FAILURE;
    }
    [$provider_id, $model_id] = $provider;

    $source = $options['source'] ?? ('extract:' . basename($file));
    $this->accountSwitcher->switchTo($writer);
    try {
      $result = $this->rememberService->ingestText($text, $source, $options['subject-uid'] ?: NULL, $provider_id, $model_id, $writer);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    if ($result['extracted'] === 0) {
      $this->io()->note('The model returned no facts worth remembering.');
      return self::EXIT_SUCCESS;
    }

    if ($result['skipped'] > 0) {
      $this->io()->warning("{$result['skipped']} user-scope fact(s) skipped: no --subject-uid was given to attach them to.");
    }

    if ($result['blocked'] > 0) {
      $this->io()->warning("{$result['blocked']} fact(s) blocked by a guardrail check.");
    }

    if ($result['denied'] > 0) {
      $this->io()->warning("{$result['denied']} fact(s) dropped: uid {$writer->id()} may not create facts in their scope.");
    }

    if (empty($result['created'])) {
      $this->io()->note('No facts were saved.');
      return self::EXIT_SUCCESS;
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
    return self::EXIT_SUCCESS;
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
   *
   * @return int
   *   The exit code: EXIT_FAILURE when a write or lookup was refused.
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
  #[CLI\Option(name: 'uid', description: 'Required. The uid of the account the write runs as: its permissions decide which scopes it may write and whether its facts start trusted.')]
  #[CLI\Usage(name: 'drush aim:remember "Prefers email over phone." --scope=user --subject=42 --uid=1', description: 'Save a plain prose fact about a user, by uid, as uid 1.')]
  #[CLI\Usage(name: 'drush aim:remember --file=facts.json --uid=1', description: 'Save every fact in facts.json in one bootstrap.')]
  #[CLI\Usage(name: 'drush aim:remember "The lobby mural was repainted in 2025." --scope=entity --target-type=node --target-id=42 --uid=1', description: 'Save a fact about a specific node.')]
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
      'uid' => NULL,
    ],
  ): int {
    $writer = $this->writer($options);
    if ($writer === NULL) {
      return self::EXIT_FAILURE;
    }

    if (!empty($options['file'])) {
      return $this->rememberBatch($options['file'], $writer);
    }

    if ($text === NULL || trim($text) === '') {
      $this->io()->error('Provide fact text, or --file with a JSON array of fact objects.');
      return self::EXIT_FAILURE;
    }

    if (empty($options['scope'])) {
      // No default here - which scopes exist depends on the site's enabled
      // aim_scope config entities, so core has no sound default to assume.
      $this->io()->error('--scope is required: one of ' . implode(', ', $this->memoryManager->allowedScopes()) . '.');
      return self::EXIT_FAILURE;
    }

    // One entry through the batch path, which checks the writer's create
    // permission per entry as the ingest form does.
    $entry = [
      'text' => $text,
      'target_type' => $options['target-type'],
      'target_id' => $options['target-id'],
    ] + array_intersect_key($options, array_flip(['scope', 'subject', 'source', 'category', 'asserted', 'expires']));
    $this->accountSwitcher->switchTo($writer);
    try {
      $result = $this->rememberService->rememberBatch([$entry], NULL, $writer);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    if (isset($result['errors'][0])) {
      $this->io()->error($result['errors'][0]);
      return self::EXIT_FAILURE;
    }

    $fact = $result['created'][0];
    $message = 'Created aim_fact ' . $fact->id() . '.';
    if ($fact->bundle() === 'case') {
      $message .= ' Case ID: ' . $fact->get('subject')->value . ' - pass this as --subject to add more facts to this case.';
    }
    if (!$fact->get('trusted')->value) {
      $message .= ' Untrusted: it waits for review before recall returns it.';
    }
    $this->io()->success($message);
    return self::EXIT_SUCCESS;
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
   * @param \Drupal\Core\Session\AccountInterface $writer
   *   The --uid account the facts are written as.
   *
   * @return int
   *   The exit code: EXIT_FAILURE when the file is unreadable or any entry
   *   was not saved.
   */
  private function rememberBatch(string $file, AccountInterface $writer): int {
    if (!is_readable($file)) {
      $this->io()->error("Cannot read file: $file");
      return self::EXIT_FAILURE;
    }

    $entries = json_decode(file_get_contents($file), TRUE);
    if (!is_array($entries)) {
      $this->io()->error('Expected --file to contain a JSON array of fact objects.');
      return self::EXIT_FAILURE;
    }

    $this->accountSwitcher->switchTo($writer);
    try {
      $result = $this->rememberService->rememberBatch($entries, NULL, $writer);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
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
    return $result['errors'] ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Runs a semantic query against the vector index and prints results.
   *
   * @param string $text
   *   The search text.
   * @param array $options
   *   Command options.
   *
   * @return int
   *   The exit code: EXIT_FAILURE when a write or lookup was refused.
   */
  #[CLI\Command(name: 'aim:recall', aliases: ['aim-recall'])]
  #[CLI\Argument(name: 'text', description: 'The search text.')]
  #[CLI\Option(name: 'scope', description: 'Restrict results to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'subject', description: 'Restrict results to one subject. Not used for scope=user.')]
  #[CLI\Option(name: 'subject-uid', description: 'Restrict results to one user, by uid or username. Only meaningful with scope=user.')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of results.')]
  #[CLI\Option(name: 'max-distance', description: 'Drop facts with a cosine distance above this. Omit for the raw nearest facts (used to calibrate the cutoff); the site default is aim.settings recall_max_distance.')]
  #[CLI\Option(name: 'include-untrusted', description: 'Also return untrusted facts, for reviewing what waits for review and is left out of ordinary recall() results (ADR-0065).')]
  #[CLI\Option(name: 'uid', description: 'Recall as this account (0 for anonymous): only the facts it may view, with literal tokens resolved as it. Omit to skip access checks and read literals as anonymous (restricted ones withhold their fact).')]
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Usage(name: 'drush aim:recall "email preference"', description: 'Search all facts for anything related to email preference.')]
  #[CLI\Usage(name: 'drush aim:recall "opening hours" --uid=5', description: 'Exactly what account 5 would get from chat or MCP, restricted literal values included only if it may see them.')]
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
      'uid' => NULL,
    ],
  ): int {
    // Without --uid Drush has no viewer: the search skips entity access and
    // literal tokens resolve as anonymous. With it, the whole recall runs as
    // that account, as chat and MCP do.
    $viewer = NULL;
    $uid = (string) ($options['uid'] ?? '');
    if ($uid !== '') {
      $viewer = $uid === '0' ? new AnonymousUserSession() : $this->memoryManager->resolveAccountByUid($uid);
      if (!$viewer || ($viewer instanceof UserInterface && $viewer->isBlocked())) {
        $this->io()->error("No active account with uid $uid.");
        return self::EXIT_FAILURE;
      }
      $this->accountSwitcher->switchTo($viewer);
    }
    try {
      $rows = $this->recallService->recall(
        $text,
        $options['scope'] ?: NULL,
        $options['subject'] ?: NULL,
        $options['subject-uid'] ?: NULL,
        (int) $options['limit'],
        ($options['max-distance'] ?? NULL) !== NULL && $options['max-distance'] !== '' ? (float) $options['max-distance'] : NULL,
        (bool) $options['include-untrusted'],
        accessCheck: $viewer !== NULL,
      );
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    finally {
      if ($viewer !== NULL) {
        $this->accountSwitcher->switchBack();
      }
    }

    if ($options['format'] === 'json') {
      $this->output()->writeln(json_encode($rows, JSON_PRETTY_PRINT));
      return self::EXIT_SUCCESS;
    }

    if ($viewer === NULL) {
      $this->io()->note('Recalled with no --uid: access checks are skipped and literal tokens resolve as anonymous, so facts naming a restricted literal are withheld. Pass --uid=<uid> to recall as an account.');
    }

    if (empty($rows)) {
      $this->io()->note('No facts found.');
      return self::EXIT_SUCCESS;
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
    return self::EXIT_SUCCESS;
  }

  /**
   * Reviews existing facts for near-duplicates and merges or retires them.
   *
   * Runs entirely against facts already in the store, independent of any
   * particular write path. Each fact is compared to its nearest vector
   * neighbors (up to aim.settings neighbor_limit) within the same
   * scope/subject and end date: identical text is retired with no model
   * call, any other pair within the comparison distance gets a
   * classification call (ADD/UPDATE/NOOP, from Mem0's vocabulary), and
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
   *
   * @return int
   *   The exit code: EXIT_FAILURE when a write or lookup was refused.
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
  ): int {
    $provider = $this->resolveChatProvider($options, 'consolidation');
    if ($provider === NULL) {
      return self::EXIT_FAILURE;
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
      return self::EXIT_FAILURE;
    }

    if (empty($result['rows'])) {
      $this->io()->note($result['has_facts'] ? 'No near-duplicate facts found.' : 'No facts to consolidate.');
      return self::EXIT_SUCCESS;
    }

    $this->io()->table(['Kept', 'Candidate', 'Score', 'Decision', 'Merged text'], $result['rows']);
    if (!empty($options['dry-run'])) {
      $this->io()->note(count($result['rows']) . ' decision(s) previewed, nothing saved. Omit --dry-run to apply.');
    }
    else {
      $this->io()->success(count($result['rows']) . ' decision(s) applied.');
    }
    return self::EXIT_SUCCESS;
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

  /**
   * Resolves --uid to the account a write runs as (ADR-0065).
   *
   * Required, and never anonymous: the account's permissions decide which
   * scopes it may write and whether its facts start trusted. This is
   * consistency, not authentication - anyone on the shell can name any uid.
   *
   * @param array $options
   *   Command options.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The account, or NULL after printing an error.
   */
  private function writer(array $options): ?AccountInterface {
    $uid = (string) ($options['uid'] ?? '');
    if ($uid === '' || $uid === '0') {
      $this->io()->error('--uid is required: the uid of the account this write runs as. Its permissions decide which scopes it may write and whether its facts start trusted.');
      return NULL;
    }
    $account = $this->memoryManager->resolveAccountByUid($uid);
    if (!$account || $account->isAnonymous() || ($account instanceof UserInterface && $account->isBlocked())) {
      $this->io()->error("No active account with uid $uid.");
      return NULL;
    }
    return $account;
  }

}
