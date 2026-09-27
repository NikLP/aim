<?php

declare(strict_types=1);

namespace Drupal\aim\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\ai\AiVdbProviderPluginManager;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Vdb\AimMariaDBProvider;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
   * @param \Drupal\Core\Database\Connection $database
   *   The default database connection, used by aim:status to query the
   *   aim_fact and aim_facts tables directly.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used by aim:status to read the live HNSW and
   *   recall cutoff settings.
   * @param \Drupal\ai\AiVdbProviderPluginManager $vdbProviders
   *   The VDB provider plugin manager, used by aim:status to confirm
   *   AimMariaDBProvider is still swapped in.
   */
  public function __construct(
    protected AimMemoryManager $memoryManager,
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'ai.vdb_provider')]
    protected AiVdbProviderPluginManager $vdbProviders,
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

    $provider = $this->resolveChatProvider($options);
    if ($provider === NULL) {
      return;
    }
    [$provider_id, $model_id] = $provider;

    $facts = $this->memoryManager->extractFacts($text, $provider_id, $model_id);
    if (empty($facts)) {
      $this->io()->note('The model returned no facts worth remembering.');
      return;
    }

    $source = $options['source'] ?? ('extract:' . basename($file));
    try {
      $result = $this->memoryManager->createFactsFromCandidates($facts, $source, $options['subject-uid'] ?: NULL);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
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

    $rows = array_map(static fn (AimFact $fact): array => [
      $fact->id(),
      $fact->bundle(),
      $fact->bundle() === 'user' ? $fact->get('subject_uid')->target_id : $fact->get('subject')->value,
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
  #[CLI\Option(name: 'state', description: 'Optional boolean flag value: true or false. Omit for facts with no boolean shape.')]
  #[CLI\Option(name: 'category', description: 'Comma-separated term name(s) from the aim_category vocabulary.')]
  #[CLI\Option(name: 'asserted', description: 'When this fact became true in reality, if different from now.')]
  #[CLI\Option(name: 'file', description: 'Path to a JSON file of fact objects, saved in one bootstrap instead of $text/the other options.')]
  #[CLI\Usage(name: 'drush aim:remember "Prefers email over phone." --scope=user --subject=42', description: 'Save a plain prose fact about a user, by uid.')]
  #[CLI\Usage(name: 'drush aim:remember --file=facts.json', description: 'Save every fact in facts.json in one bootstrap.')]
  public function remember(
    ?string $text = NULL,
    array $options = [
      'scope' => 'site',
      'subject' => NULL,
      'source' => NULL,
      'state' => NULL,
      'category' => NULL,
      'asserted' => NULL,
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

    try {
      [$state, $category, $asserted] = $this->parseRememberFields($options);
      $fact = $this->memoryManager->remember($text, $options['scope'], $options['subject'], $options['source'], $state, $category, $asserted);
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
   * Mirrors createFactsFromCandidates()'s per-item resilience: one bad
   * entry (missing text, invalid scope, a rejected guardrail) is reported
   * and skipped rather than aborting the rest of the file.
   *
   * @param string $file
   *   Path to a JSON file containing an array of fact objects. Each object
   *   uses the same field names as aim:remember's own options (text,
   *   scope, subject, source, state, category, asserted); category may be
   *   a JSON array instead of a comma-separated string.
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

    $created = 0;
    foreach ($entries as $i => $entry) {
      if (empty($entry['text'])) {
        $this->io()->warning("Entry $i: missing text, skipped.");
        continue;
      }

      try {
        [$state, $category, $asserted] = $this->parseRememberFields($entry);
        $fact = $this->memoryManager->remember(
          $entry['text'],
          $entry['scope'] ?? 'site',
          $entry['subject'] ?? NULL,
          $entry['source'] ?? NULL,
          $state,
          $category,
          $asserted,
        );
      }
      catch (\InvalidArgumentException $e) {
        $this->io()->warning("Entry $i: " . $e->getMessage());
        continue;
      }

      $entry_message = "Entry $i: created aim_fact " . $fact->id() . '.';
      if ($fact->bundle() === 'case') {
        $entry_message .= ' Case ID: ' . $fact->get('subject')->value . '.';
      }
      $this->io()->success($entry_message);
      $created++;
    }

    $this->io()->note("Created $created of " . count($entries) . ' fact(s).');
  }

  /**
   * Parses aim:remember's shared state/category/asserted fields.
   *
   * @param array $fields
   *   Raw state/category/asserted values, as given on the command line or
   *   decoded from a --file entry.
   *
   * @return array
   *   [$state, $category, $asserted], typed as AimMemoryManager::remember()
   *   expects them.
   *
   * @throws \InvalidArgumentException
   *   If state or asserted cannot be parsed.
   */
  private function parseRememberFields(array $fields): array {
    $state = NULL;
    if (($fields['state'] ?? NULL) !== NULL) {
      $state = filter_var($fields['state'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($state === NULL) {
        throw new \InvalidArgumentException('Invalid state "' . $fields['state'] . '", expected true or false.');
      }
    }

    $category = [];
    if (!empty($fields['category'])) {
      $category = is_array($fields['category']) ? $fields['category'] : explode(',', $fields['category']);
      $category = array_map('trim', $category);
    }

    $asserted = NULL;
    if (($fields['asserted'] ?? NULL) !== NULL) {
      $asserted = strtotime($fields['asserted']);
      if ($asserted === FALSE) {
        throw new \InvalidArgumentException('Invalid asserted "' . $fields['asserted'] . '", expected a date strtotime() can parse.');
      }
    }

    return [$state, $category, $asserted];
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
  #[CLI\Option(name: 'format', description: 'Output format: table or json.')]
  #[CLI\Usage(name: 'drush aim:recall "email preference"', description: 'Search all facts for anything related to email preference.')]
  #[CLI\Usage(name: 'drush aim:recall "email preference" --max-distance=0.45', description: 'Only facts within 0.45 cosine distance, as the chatbot and MCP tool do by default.')]
  public function recall(
    string $text,
    array $options = [
      'scope' => NULL,
      'subject' => NULL,
      'subject-uid' => NULL,
      'limit' => 10,
      'max-distance' => NULL,
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
        ($options['max-distance'] ?? NULL) !== NULL && $options['max-distance'] !== '' ? (float) $options['max-distance'] : NULL,
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
    $this->io()->table(['ID', 'Distance', 'Scope', 'Subject', 'Text', 'Source', 'State'], $table_rows);
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
   * The two thresholds default to the live aim.settings config
   * (/admin/config/aim/settings), not a hardcoded value - --auto-threshold/
   * --ambiguous-threshold below only override that for one invocation. The
   * shipped defaults were picked empirically against this site's real fact
   * data, not carried over from Mem0's or Hindsight's own models.
   * Recalibrated 2026-09-09 against
   * amazeeio__mistral-embed (a genuine near-duplicate pair scored ~0.02, a
   * distinct fact about the same subject or an unrelated fact both scored
   * ~0.17-0.27) - the original 0.35/0.65 pair was calibrated against
   * titan-embed-text-v2:0 and was never re-checked after that model was
   * discontinued and swapped mid-session; it sat entirely above the range
   * mistral-embed actually produces, so every pair looked like an
   * auto-duplicate. Re-check with `drush aim:recall`'s score column
   * whenever the embeddings model changes, not just as real fact volume
   * grows.
   *
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'aim:consolidate', aliases: ['aim-consolidate'])]
  #[CLI\Option(name: 'scope', description: 'Restrict the sweep to one scope: user, role, site, case.')]
  #[CLI\Option(name: 'provider', description: 'The AI provider plugin ID to use for ambiguous cases.')]
  #[CLI\Option(name: 'model', description: 'The chat model ID to use for ambiguous cases.')]
  #[CLI\Option(name: 'auto-threshold', description: 'Score at or below which a neighbor is retired automatically, no LLM call. Defaults to the live aim.settings value.')]
  #[CLI\Option(name: 'ambiguous-threshold', description: 'Score at or below which an ambiguous neighbor gets a classification call. Defaults to the live aim.settings value.')]
  #[CLI\Option(name: 'dry-run', description: 'Print decisions without saving anything.')]
  #[CLI\Usage(name: 'drush aim:consolidate --dry-run', description: 'Preview consolidation decisions across every scope without changing anything.')]
  public function consolidate(
    array $options = [
      'scope' => NULL,
      'provider' => NULL,
      'model' => NULL,
      'auto-threshold' => NULL,
      'ambiguous-threshold' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    $provider = $this->resolveChatProvider($options);
    if ($provider === NULL) {
      return;
    }
    [$provider_id, $model_id] = $provider;

    // NULL (the default) follows the live aim.settings value
    // (/admin/config/aim/settings) rather than a hardcoded default, same
    // posture as resolveChatProvider() following ai.settings.
    $auto_threshold = $options['auto-threshold'] !== NULL ? (float) $options['auto-threshold'] : $this->memoryManager->getAutoThreshold();
    $ambiguous_threshold = $options['ambiguous-threshold'] !== NULL ? (float) $options['ambiguous-threshold'] : $this->memoryManager->getAmbiguousThreshold();

    try {
      $result = $this->memoryManager->consolidate(
        $options['scope'] ?: NULL,
        $provider_id,
        $model_id,
        $auto_threshold,
        $ambiguous_threshold,
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

  /**
   * Benchmarks recall latency at increasing fact counts.
   *
   * Generates synthetic facts in batches up to each requested checkpoint,
   * reindexes, then times a batch of recall() calls at that fact count -
   * see CLAUDE.md's AI dependency map and ADR-0010's open question 5
   * (retrieval latency asserted safe by reasoning about SQL cost, never
   * actually benchmarked). Generation bypasses guardrails and the
   * consolidation queue (see AimMemoryManager::generateBenchmarkFacts()),
   * so the only real cost here is one embedding-API call per generated
   * fact, at reindex time - no reasoning/LLM calls anywhere in this
   * command, cost is predictable up front.
   *
   * @param array $options
   *   Command options.
   */
  #[CLI\Command(name: 'aim:benchmark', aliases: ['aim-benchmark'])]
  #[CLI\Option(name: 'scope', description: 'Which scope pool to generate facts into: site, role, case, or user.')]
  #[CLI\Option(name: 'checkpoints', description: 'Comma-separated cumulative fact counts to measure at.')]
  #[CLI\Option(name: 'queries', description: 'How many timed recall() calls to run at each checkpoint.')]
  #[CLI\Option(name: 'cleanup', description: 'Delete every fact this run created once the benchmark finishes.')]
  #[CLI\Usage(name: 'drush aim:benchmark --checkpoints=50,200,500 --cleanup', description: 'Generate up to 500 site-scope facts in three steps, timing recall() at each, then remove them all.')]
  public function benchmark(
    array $options = [
      'scope' => 'site',
      'checkpoints' => '50,200,500',
      'queries' => 10,
      'cleanup' => FALSE,
    ],
  ): void {
    $scope = $options['scope'];
    $allowed = $this->memoryManager->allowedScopes();
    if (!in_array($scope, $allowed, TRUE)) {
      $this->io()->error('Invalid --scope "' . $scope . '", expected one of: ' . implode(', ', $allowed) . '.');
      return;
    }

    $checkpoints = array_unique(array_map('intval', explode(',', (string) $options['checkpoints'])));
    sort($checkpoints);
    $queryCount = max(1, (int) $options['queries']);
    $runTag = 'benchmark:' . date('Ymd-His');

    $subjectUids = [];
    if ($scope === 'user') {
      $subjectUids = $this->memoryManager->sampleUserIds();
      if (empty($subjectUids)) {
        $this->io()->error('No real user accounts found to benchmark scope=user against (a user-scope fact must reference a real account, ADR-0007).');
        return;
      }
      $this->io()->note('Distributing generated facts across ' . count($subjectUids) . ' real account(s).');
    }

    $sampleQueries = [
      'email preference', 'billing question', 'mobile app issue',
      'onboarding process', 'dashboard feedback', 'support ticket follow-up',
      'notification settings', 'integration request',
    ];

    $this->io()->note("Run tag: $runTag. Bypasses guardrails and consolidation (synthetic text needs neither) - the only real cost is one embedding-API call per fact at reindex time.");

    $rows = [];
    $createdSoFar = 0;
    foreach ($checkpoints as $target) {
      if ($target > $createdSoFar) {
        $this->memoryManager->generateBenchmarkFacts($scope, $target - $createdSoFar, $runTag, $subjectUids);
        $createdSoFar = $target;
      }

      $indexStart = microtime(TRUE);
      $indexed = $this->memoryManager->reindex();
      $indexMs = (int) round((microtime(TRUE) - $indexStart) * 1000);

      $subjectUid = $scope === 'user' ? (string) $subjectUids[array_rand($subjectUids)] : NULL;
      $timings = [];
      for ($i = 0; $i < $queryCount; $i++) {
        $start = microtime(TRUE);
        try {
          $this->memoryManager->recall($sampleQueries[$i % count($sampleQueries)], $scope, NULL, $subjectUid, 10);
        }
        catch (\InvalidArgumentException | \RuntimeException $e) {
          $this->io()->error($e->getMessage());
          return;
        }
        $timings[] = (microtime(TRUE) - $start) * 1000;
      }
      sort($timings);
      $avg = (int) round(array_sum($timings) / count($timings));
      $p95 = (int) round($timings[(int) floor(0.95 * (count($timings) - 1))]);

      $rows[] = [$createdSoFar, $indexed ?? 'n/a', $indexMs, $avg, $p95];
    }

    $this->io()->table(['Facts', 'Indexed this batch', 'Reindex ms', 'Recall avg ms', 'Recall p95 ms'], $rows);

    if (!empty($options['cleanup'])) {
      $deleted = $this->memoryManager->deleteBenchmarkFacts($runTag);
      $this->memoryManager->reindex();
      $this->io()->success("Cleaned up $deleted benchmark fact(s).");
    }
    else {
      $this->io()->note("Benchmark facts left in place, tagged source=\"$runTag\". Remove them later with: drush aim:benchmark-cleanup $runTag");
    }
  }

  /**
   * Deletes every fact created by a previous aim:benchmark run.
   *
   * @param string $runTag
   *   The run tag printed by aim:benchmark, e.g. benchmark:20260910-141500.
   */
  #[CLI\Command(name: 'aim:benchmark-cleanup', aliases: ['aim-benchmark-cleanup'])]
  #[CLI\Argument(name: 'runTag', description: 'The run tag printed by aim:benchmark.')]
  public function benchmarkCleanup(string $runTag): void {
    $deleted = $this->memoryManager->deleteBenchmarkFacts($runTag);
    if ($deleted === 0) {
      $this->io()->note('No facts found tagged "' . $runTag . '".');
      return;
    }
    $this->memoryManager->reindex();
    $this->io()->success("Deleted $deleted fact(s), reindexed.");
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
    $checks = [
      $this->checkIndexParity(),
      $this->checkOrphanVectorRows(),
      $this->checkProviderShim(),
      $this->checkHnswTuning(),
      $this->checkRecallCutoff(),
    ];

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
   * Checks that every live fact has exactly one row in the vector index.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkIndexParity(): array {
    $row = $this->database->query('SELECT
      (SELECT COUNT(*) FROM {aim_fact} WHERE expires IS NULL AND text IS NOT NULL) AS live,
      (SELECT COUNT(*) FROM {aim_facts}) AS vector_rows')->fetchAssoc();
    $live = (int) $row['live'];
    $vectorRows = (int) $row['vector_rows'];
    $ok = $live === $vectorRows;

    return [
      'label' => 'Index parity',
      'ok' => $ok,
      'detail' => $ok
        ? "$live live fact(s), $vectorRows vector row(s)"
        : "$live live fact(s) but $vectorRows vector row(s) - run: drush sapi-i aim_vector_index",
    ];
  }

  /**
   * Checks for vector rows that no longer point at a real aim_fact.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkOrphanVectorRows(): array {
    $orphans = (int) $this->database->query("SELECT COUNT(*) FROM {aim_facts} v
      LEFT JOIN {aim_fact} f ON v.drupal_entity_id = CONCAT('entity:aim_fact/', f.id, ':en')
      WHERE f.id IS NULL")->fetchField();

    return [
      'label' => 'Orphan vector rows',
      'ok' => $orphans === 0,
      'detail' => $orphans === 0 ? 'none' : "$orphans orphan row(s) - search_api self-heals these on the next query that returns one, or run: drush sapi-i aim_vector_index",
    ];
  }

  /**
   * Checks that the mariadb VDB provider plugin still resolves to the shim.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkProviderShim(): array {
    $class = get_class($this->vdbProviders->createInstance('mariadb'));
    $ok = $class === AimMariaDBProvider::class;

    return [
      'label' => 'Provider shim',
      'ok' => $ok,
      'detail' => $ok ? $class : "expected " . AimMariaDBProvider::class . ", got $class - check AimHooks::vdbProviderInfoAlter() ran (drush cr)",
    ];
  }

  /**
   * Checks the collection table's build-time M and the server's ef_search.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkHnswTuning(): array {
    // MariaDB drops the VECTOR KEY's M=/DISTANCE= suffix from SHOW CREATE
    // TABLE under this connection's own sql_mode ('ANSI,TRADITIONAL', set
    // by Drupal's mysql driver) - present under ANSI or TRADITIONAL alone,
    // only their combination hides it (checked against 2026-09-27 live
    // data). ANSI_QUOTES alone keeps it and still double-quotes
    // identifiers, matching what {aim_facts} substitutes to here, so swap
    // to that for this one query and restore afterward.
    $originalMode = (string) $this->database->query('SELECT @@SESSION.sql_mode')->fetchField();
    $this->database->query('SET SESSION sql_mode = :mode', [':mode' => 'ANSI_QUOTES']);
    try {
      $createTable = (string) $this->database->query('SHOW CREATE TABLE {aim_facts}')->fetchField(1);
    }
    finally {
      $this->database->query('SET SESSION sql_mode = :mode', [':mode' => $originalMode]);
    }
    $m = preg_match('/VECTOR KEY[^\n]*["`]M["`]=\'?(\d+)/', $createTable, $matches) ? (int) $matches[1] : NULL;
    $efSearch = $this->configFactory->get('search_api.server.aim_vector')->get('backend_config.database_settings.mhnsw_ef_search');

    $problems = [];
    if ($m !== AimMariaDBProvider::HNSW_M) {
      $problems[] = $m === NULL
        ? "M not found in aim_facts' VECTOR KEY"
        : "M=$m, expected " . AimMariaDBProvider::HNSW_M . ' - rebuild the index (DEVELOPING.md "Tuning vector search accuracy")';
    }
    if ($efSearch === NULL) {
      $problems[] = 'backend_config.database_settings.mhnsw_ef_search is unset on search_api.server.aim_vector, MariaDB defaults to 20';
    }

    return [
      'label' => 'HNSW tuning',
      'ok' => empty($problems),
      'detail' => empty($problems) ? "M=$m, ef_search=$efSearch" : implode('; ', $problems),
    ];
  }

  /**
   * Checks that recall_max_distance is a real per-site value, not a fallback.
   *
   * @return array
   *   ['label' => string, 'ok' => bool, 'detail' => string].
   */
  private function checkRecallCutoff(): array {
    $value = $this->configFactory->get('aim.settings')->get('recall_max_distance');
    $ok = $value !== NULL;

    return [
      'label' => 'Recall cutoff configured',
      'ok' => $ok,
      'detail' => $ok
        ? (string) $value
        : 'not set, silently using the in-code default ' . AimMemoryManager::DEFAULT_RECALL_MAX_DISTANCE . ' - recalibrate for this site\'s dataset (ADR-0019)',
    ];
  }

  /**
   * Resolves --provider/--model options, falling back to the site default.
   *
   * Deliberately does not hardcode a fallback provider/model: a literal
   * default here would be exactly the kind of value that already had to be
   * hand-edited twice this project when the site's working provider
   * changed. Resolving `ai.settings`' own default chat provider instead
   * means these commands automatically follow it.
   *
   * @param array $options
   *   The command options array, read for 'provider' and 'model'.
   *
   * @return array|null
   *   A [provider_id, model_id] pair, or NULL if neither option was given
   *   and no default chat provider is configured (an error has already
   *   been printed to the user in that case).
   */
  protected function resolveChatProvider(array $options): ?array {
    if (!empty($options['provider']) && !empty($options['model'])) {
      return [$options['provider'], $options['model']];
    }

    $default = $this->memoryManager->getDefaultChatProvider();
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      $this->io()->error('No --provider/--model given, and no default chat provider is configured. Set one at /admin/config/ai/settings, or pass --provider and --model explicitly.');
      return NULL;
    }

    return [$default['provider_id'], $default['model_id']];
  }

}
