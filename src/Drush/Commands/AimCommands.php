<?php

declare(strict_types=1);

namespace Drupal\aim\Drush\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface as SearchApiQueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read/write access to aim's memory store: extraction, and a direct CLI pair.
 *
 * Aim:extract is the "interactive PoC" phase of the build order (CLAUDE.md
 * decision 6): a single on-demand command doing extraction via a real chat
 * call, not an unattended queue worker. The source text itself is never
 * stored, only the facts it yields.
 *
 * aim:remember and aim:recall are the direct pair for a caller that has
 * already decided what is worth remembering (typically an agent doing its
 * own reasoning) - no chat call, straight reads/writes against aim_fact and
 * its vector index.
 */
final class AimCommands extends DrushCommands {

  /**
   * Constructs an AimCommands object.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher, used to run access-checked queries as a
   *   privileged account since drush has no logged-in user of its own.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, used to stamp facts retired by consolidation.
   */
  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
    protected TimeInterface $time,
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
      $container->get('account_switcher'),
      $container->get('datetime.time'),
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
    $skipped = 0;
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    foreach ($facts as $fact) {
      $values = [
        'text' => $fact['text'],
        'source' => $source,
      ];

      if ($fact['scope'] === 'user') {
        // A user-scope fact has to be about a real account (subject_uid),
        // same rule as aim:remember - the model can only return a name it
        // read from the source text, which may not resolve to one.
        $account = !empty($fact['subject']) ? $this->resolveAccount((string) $fact['subject']) : NULL;
        if (!$account) {
          $skipped++;
          continue;
        }
        $values['scope'] = 'user';
        $values['subject_uid'] = $account->id();
        $values['subject'] = '';
        $display_subject = $account->id();
      }
      else {
        $values['scope'] = $fact['scope'];
        $values['subject'] = $fact['subject'] ?? '';
        $display_subject = $values['subject'];
      }

      $entity = $storage->create($values);
      $entity->save();
      $rows[] = [$entity->id(), $values['scope'], $display_subject, $fact['text']];
    }

    if ($skipped > 0) {
      $this->io()->warning("$skipped user-scope fact(s) skipped: the model's subject did not resolve to a real account on this site.");
    }

    if (empty($rows)) {
      $this->io()->note('No facts were saved.');
      return;
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
    $allowed_scopes = ['user', 'role', 'site', 'case'];
    if (!in_array($options['scope'], $allowed_scopes, TRUE)) {
      $this->io()->error('Invalid --scope "' . $options['scope'] . '", expected one of: ' . implode(', ', $allowed_scopes));
      return;
    }

    $values = [
      'scope' => $options['scope'],
      'text' => $text,
      'source' => $options['source'],
    ];

    if ($options['scope'] === 'user') {
      // A user-scope fact has to be about a real account: subject is a
      // genuine entity_reference (subject_uid), not a free-text string
      // that merely happens to hold a uid - that drift is exactly what let
      // "1" and "Nik" address the same person without ever matching.
      if (empty($options['subject'])) {
        $this->io()->error('--subject is required for --scope=user: a uid or username of a real account on this site.');
        return;
      }
      $account = $this->resolveAccount((string) $options['subject']);
      if (!$account) {
        $this->io()->error('No user account found for --subject "' . $options['subject'] . '". A user-scope fact must be about a real account.');
        return;
      }
      $values['subject_uid'] = $account->id();
      $values['subject'] = '';
    }
    else {
      $values['subject'] = $options['subject'] ?? '';
    }

    if ($options['state'] !== NULL) {
      $state = filter_var($options['state'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if ($state === NULL) {
        $this->io()->error('Invalid --state "' . $options['state'] . '", expected true or false.');
        return;
      }
      $values['state'] = $state;
    }

    $entity = $this->entityTypeManager->getStorage('aim_fact')->create($values);
    $entity->save();

    $this->io()->success('Created aim_fact ' . $entity->id() . '.');
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
    $index = $this->loadVectorIndex();
    if (!$index) {
      $this->io()->error('The aim_vector_index search index does not exist.');
      return;
    }

    $filter_account = NULL;
    if (!empty($options['subject-uid'])) {
      $filter_account = $this->resolveAccount((string) $options['subject-uid']);
      if (!$filter_account) {
        $this->io()->error('No user account found for --subject-uid "' . $options['subject-uid'] . '".');
        return;
      }
    }

    $query = $index->query()->keys($text);
    if (!empty($options['scope'])) {
      $query->addCondition('scope', $options['scope']);
    }
    if (!empty($options['subject'])) {
      $query->addCondition('subject', $options['subject']);
    }
    // subject_uid is not an indexed attribute, so this is a post-filter
    // below rather than a query condition here; over-fetch to compensate.
    $query->range(0, $filter_account ? (int) $options['limit'] * 5 : (int) $options['limit']);

    try {
      $results = $this->executeAsAdmin($query);
    }
    catch (\RuntimeException $e) {
      $this->io()->error($e->getMessage());
      return;
    }

    $rows = [];
    foreach ($results as $result) {
      $fact = $result->getOriginalObject()->getValue();
      // `expires` is not an indexed attribute, so a retired fact still
      // matches the vector query; filter it out here instead.
      if (!$fact->get('expires')->isEmpty()) {
        continue;
      }
      if ($filter_account && (int) $fact->get('subject_uid')->target_id !== (int) $filter_account->id()) {
        continue;
      }
      $rows[] = [
        'id' => $fact->id(),
        'score' => $result->getScore(),
        'scope' => $fact->get('scope')->value,
        'subject' => $fact->get('scope')->value === 'user' ? $fact->get('subject_uid')->target_id : $fact->get('subject')->value,
        'text' => $fact->get('text')->value,
        'source' => $fact->get('source')->value,
        'state' => $fact->get('state')->value,
      ];
      if (count($rows) >= (int) $options['limit']) {
        break;
      }
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
    $index = $this->loadVectorIndex();
    if (!$index) {
      $this->io()->error('The aim_vector_index search index does not exist.');
      return;
    }

    $fact_storage = $this->entityTypeManager->getStorage('aim_fact');
    $query = $fact_storage->getQuery()->accessCheck(FALSE)->sort('id')->notExists('expires');
    if (!empty($options['scope'])) {
      $query->condition('scope', $options['scope']);
    }
    $ids = $query->execute();

    if (empty($ids)) {
      $this->io()->note('No facts to consolidate.');
      return;
    }

    $auto_threshold = (float) $options['auto-threshold'];
    $ambiguous_threshold = (float) $options['ambiguous-threshold'];
    $dry_run = !empty($options['dry-run']);

    $handled = [];
    $rows = [];

    foreach ($fact_storage->loadMultiple($ids) as $fact) {
      if (isset($handled[$fact->id()])) {
        continue;
      }

      try {
        $neighbor_result = $this->findNearestNeighbor($index, $fact, $handled);
      }
      catch (\RuntimeException $e) {
        $this->io()->error($e->getMessage());
        return;
      }
      if ($neighbor_result === NULL) {
        continue;
      }
      [$neighbor, $score] = $neighbor_result;
      if ($score > $ambiguous_threshold) {
        continue;
      }

      // Lower id is the established fact, higher id the newer restatement
      // being evaluated against it.
      $kept = $fact->id() < $neighbor->id() ? $fact : $neighbor;
      $candidate = $fact->id() < $neighbor->id() ? $neighbor : $fact;

      if ($score <= $auto_threshold) {
        $decision = 'NOOP';
        $merged_text = NULL;
      }
      else {
        [$decision, $merged_text] = $this->classifyPair($kept, $candidate, $options['provider'], $options['model']);
      }

      $handled[$kept->id()] = TRUE;
      $handled[$candidate->id()] = TRUE;
      $rows[] = [$kept->id(), $candidate->id(), round($score, 3), $decision];

      if ($dry_run) {
        continue;
      }

      switch ($decision) {
        case 'UPDATE':
          $kept->set('text', $merged_text);
          $kept->save();
          $candidate->set('expires', $this->time->getRequestTime());
          $candidate->set('related', [$kept->id()]);
          $candidate->save();
          break;

        case 'NOOP':
          $candidate->set('expires', $this->time->getRequestTime());
          $candidate->set('related', [$kept->id()]);
          $candidate->save();
          break;

        case 'DELETE':
          $candidate->delete();
          break;

        case 'ADD':
          // Genuinely distinct facts, nothing to change.
          break;
      }
    }

    if (empty($rows)) {
      $this->io()->note('No near-duplicate facts found.');
      return;
    }

    $this->io()->table(['Kept', 'Candidate', 'Score', 'Decision'], $rows);
    if ($dry_run) {
      $this->io()->note(count($rows) . ' decision(s) previewed, nothing saved. Omit --dry-run to apply.');
    }
    else {
      $this->io()->success(count($rows) . ' decision(s) applied.');
    }
  }

  /**
   * Resolves a uid or username to a real user account.
   *
   * @param string $value
   *   A numeric uid, or an account name.
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The matching account, or NULL if none exists.
   */
  protected function resolveAccount(string $value): ?AccountInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    if (ctype_digit($value)) {
      $account = $storage->load((int) $value);
      return $account instanceof AccountInterface ? $account : NULL;
    }
    $accounts = $storage->loadByProperties(['name' => $value]);
    $account = reset($accounts);
    return $account instanceof AccountInterface ? $account : NULL;
  }

  /**
   * Loads the aim_vector_index search index.
   *
   * @return \Drupal\search_api\IndexInterface|null
   *   The index, or NULL if it does not exist.
   */
  protected function loadVectorIndex(): ?IndexInterface {
    $index = $this->entityTypeManager->getStorage('search_api_index')->load('aim_vector_index');
    return $index instanceof IndexInterface ? $index : NULL;
  }

  /**
   * Runs a search_api query as user 1, since drush has no logged-in user.
   *
   * Drush runs as the anonymous user by default, which has no view access
   * to aim_fact, so the AI Search backend's per-result entity access check
   * would silently drop every match. Run the query as user 1 instead of
   * bypassing access outright, so this stays subject to whatever real
   * permission eventually governs aim_fact (CLAUDE.md decision 3).
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query to execute.
   *
   * @return \Drupal\search_api\Query\ResultSetInterface
   *   The query results.
   */
  protected function executeAsAdmin(SearchApiQueryInterface $query): ResultSetInterface {
    $admin = $this->entityTypeManager->getStorage('user')->load(1);
    if (!$admin instanceof AccountInterface) {
      throw new \RuntimeException('User 1 does not exist, no account to run this query as.');
    }
    $this->accountSwitcher->switchTo($admin);
    try {
      return $query->execute();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Finds the closest indexed neighbor to a fact, excluding handled ids.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The vector index to query.
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact to find a neighbor for.
   * @param array $handled
   *   Fact IDs already consumed by a decision this run, keyed by ID.
   *
   * @return array|null
   *   A [neighbor fact, score] pair, or NULL if no eligible neighbor exists.
   */
  protected function findNearestNeighbor(IndexInterface $index, AimFact $fact, array $handled): ?array {
    $scope = $fact->get('scope')->value;
    $is_user_scope = $scope === 'user';

    $query = $index->query()->keys($fact->get('text')->value);
    $query->addCondition('scope', $scope);
    $subject = $fact->get('subject')->value;
    // subject_uid is not an indexed attribute (see the gotcha this method's
    // docblock references), so user scope cannot be narrowed at the index
    // level - post-filter after fetching a wider result set instead.
    if (!$is_user_scope && $subject !== NULL && $subject !== '') {
      $query->addCondition('subject', $subject);
    }
    $query->range(0, $is_user_scope ? 20 : 5);

    $subject_uid = $is_user_scope ? $fact->get('subject_uid')->target_id : NULL;

    $results = $this->executeAsAdmin($query);
    foreach ($results as $result) {
      $candidate = $result->getOriginalObject()->getValue();
      if ((int) $candidate->id() === (int) $fact->id() || isset($handled[$candidate->id()])) {
        continue;
      }
      // The vector index does not know about `expires` (it is not an
      // indexed attribute), so an already-retired fact would otherwise
      // keep resurfacing as a neighbor on every future run.
      if (!$candidate->get('expires')->isEmpty()) {
        continue;
      }
      if ($is_user_scope) {
        $candidate_subject_uid = $candidate->get('subject_uid')->target_id;
        if ($subject_uid === NULL || $candidate_subject_uid === NULL || (int) $candidate_subject_uid !== (int) $subject_uid) {
          continue;
        }
      }
      return [$candidate, (float) $result->getScore()];
    }
    return NULL;
  }

  /**
   * Asks the chat provider to classify a candidate against a kept fact.
   *
   * @param \Drupal\aim\Entity\AimFact $kept
   *   The established fact being compared against.
   * @param \Drupal\aim\Entity\AimFact $candidate
   *   The newer fact being evaluated against it.
   * @param string $provider_id
   *   The AI provider plugin ID.
   * @param string $model_id
   *   The chat model ID.
   *
   * @return array
   *   A [decision, merged_text] pair. Decision is one of ADD, UPDATE,
   *   DELETE, NOOP. merged_text is only meaningful for UPDATE.
   */
  protected function classifyPair(AimFact $kept, AimFact $candidate, string $provider_id, string $model_id): array {
    $kept_text = $kept->get('text')->value;
    $candidate_text = $candidate->get('text')->value;

    $prompt = <<<PROMPT
      Two memory facts about the same scope and subject were flagged as
      possibly related. Decide what to do with the candidate fact relative
      to the existing one.

      Existing fact: "$kept_text"
      Candidate fact: "$candidate_text"

      Choose one decision:
      - "ADD": the two facts are genuinely different and both should be
        kept as-is.
      - "UPDATE": the candidate refines, corrects, or supersedes the
        existing fact (e.g. a changed preference). Provide a single merged
        statement in merged_text that replaces the existing fact's text.
      - "NOOP": the candidate simply restates the existing fact with no
        new information. The existing fact stands unchanged and the
        candidate is redundant.
      - "DELETE": the candidate fact should not exist as a memory at all
        (e.g. nonsensical or clearly erroneous). Use sparingly.

      For any decision other than UPDATE, set merged_text to the existing
      fact's text unchanged.
      PROMPT;

    $schema = new StructuredOutputSchema(
      name: 'aim_consolidation_decision',
      description: 'Consolidation decision for a candidate fact against an existing one.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'properties' => [
          'decision' => [
            'type' => 'string',
            'enum' => ['ADD', 'UPDATE', 'DELETE', 'NOOP'],
          ],
          'merged_text' => ['type' => 'string'],
        ],
        'required' => ['decision', 'merged_text'],
      ],
    );

    $input = new ChatInput([new ChatMessage('user', $prompt)]);
    $input->setChatStructuredJsonSchema($schema);

    $provider = $this->aiProvider->createInstance($provider_id);
    $output = $provider->chat($input, $model_id, ['aim_consolidate']);
    $response_text = $output->getNormalized()->getText();

    $decoded = json_decode($response_text, TRUE);
    if (!is_array($decoded) || !isset($decoded['decision'])) {
      throw new \RuntimeException("Model response was not the expected JSON shape: $response_text");
    }

    return [$decoded['decision'], $decoded['merged_text'] ?? NULL];
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
