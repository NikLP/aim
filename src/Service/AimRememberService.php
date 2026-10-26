<?php

declare(strict_types=1);

namespace Drupal\aim\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Entity\AimFact;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The write path: remember, ingest, extract and batch-create aim facts.
 *
 * Split out of AimMemoryManager, which keeps loading, saving, guardrails,
 * access and the search plumbing. Every write still goes through the
 * manager's runGuardrails() and saveFact().
 */
class AimRememberService {

  /**
   * Constructs an AimRememberService object.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $manager
   *   The memory manager: guardrails, saving, access and logging.
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The settings reader.
   * @param \Drupal\aim\Service\AimGroundingChecker $groundingChecker
   *   The grounded check (ADR-0037).
   * @param \Drupal\aim\Service\AimTrustPolicy $trustPolicy
   *   The trust policy resolver (ADR-0037 addendum 2026-10-18).
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager, for extraction.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user, recorded as the fact's author.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The aim logger channel.
   * @param \Drupal\aim\AimScopeTypePluginManagerInterface $scopeAccessManager
   *   The scope type plugin manager, for default subjects.
   */
  public function __construct(
    protected AimMemoryManager $manager,
    protected AimSettings $settings,
    protected AimGroundingChecker $groundingChecker,
    protected AimTrustPolicy $trustPolicy,
    #[Autowire(service: 'ai.provider')]
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    #[Autowire(service: 'logger.channel.aim')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'plugin.manager.aim_scope_type')]
    protected AimScopeTypePluginManagerInterface $scopeAccessManager,
  ) {}

  /**
   * Reduces a caller's explicit trusted value to what it may set.
   *
   * FALSE is always honored (it only withholds). TRUE needs a permission
   * that could trust the fact by hand (AimTrustPolicy::canApprove()); from
   * anyone else it is ignored and the policy decides.
   *
   * @param bool|null $trusted
   *   The caller's explicit value.
   * @param string $scope
   *   The fact's scope ID.
   *
   * @return bool|null
   *   The value to honor, or NULL to let the trust policy decide.
   */
  protected function honoredTrusted(?bool $trusted, string $scope): ?bool {
    if ($trusted !== TRUE) {
      return $trusted;
    }
    if ($this->trustPolicy->canApprove($this->currentUser, $scope)) {
      return TRUE;
    }
    $this->logger->warning('Explicit trusted=TRUE ignored for scope @scope (uid @uid): no trust permission.', [
      '@scope' => $scope,
      '@uid' => $this->currentUser->id(),
    ]);
    return NULL;
  }

  /**
   * Field values for a new fact's trusted flag and grounding score.
   *
   * @param bool|null $explicit
   *   The honored explicit trusted value, or NULL.
   * @param string|null $channel
   *   The entry channel.
   * @param string $scope
   *   The fact's scope ID.
   * @param bool $checked
   *   Whether the grounded check ran.
   * @param float|null $score
   *   The check's score.
   *
   * @return array
   *   trusted, plus grounding_score when checked. The policy is resolved
   *   for the current user, the writer. An explicit value wins over the
   *   policy and the check; the score is still recorded.
   */
  protected function trustValues(?bool $explicit, ?string $channel, string $scope, bool $checked, ?float $score): array {
    $values = $this->trustPolicy->values($this->trustPolicy->resolve($channel, $scope, $this->currentUser), $checked, $score);
    if ($explicit !== NULL) {
      $values['trusted'] = $explicit;
    }
    return $values;
  }

  /**
   * Logs a fact's grounding verdict. IDs and the score only, no text.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The saved fact.
   */
  protected function logGrounding(AimFact $fact): void {
    $score = $fact->get('grounding_score')->value;
    $this->manager->logAudit('Grounding check for fact @id: score @score, cutoff @cutoff, mode @mode, trusted @trusted.', [
      '@id' => $fact->id(),
      '@score' => $score === NULL ? 'none (check failed or no model)' : round((float) $score, 3),
      '@cutoff' => $this->settings->getGroundingThreshold(),
      '@mode' => $this->settings->getGroundingMode(),
      '@trusted' => $fact->get('trusted')->value ? 'yes' : 'no',
    ]);
  }

  /**
   * Creates an aim_fact directly, no chat call.
   *
   * Unlike extractFacts(), this does not decide what is worth remembering -
   * it stores exactly what the caller (typically an agent that already did
   * that reasoning) hands it.
   *
   * @param string $text
   *   The fact text, one short statement.
   * @param string $scope
   *   One of user, role, site, case.
   * @param string|null $subject
   *   Who or what the fact is about. For scope=user, a uid of a real
   *   account on this site. Ignored for site scope. For scope=case, an
   *   existing case ID to continue - omit to start a new case, which mints
   *   one and stamps it onto the created fact.
   * @param string|null $source
   *   Provenance tag for this fact.
   * @param string[] $category
   *   Category term names to resolve against the aim_category vocabulary.
   *   A name with no matching term is skipped, not created.
   * @param int|null $asserted
   *   When this fact became true in reality, as a Unix timestamp, if known
   *   and different from now. NULL means "same as created".
   * @param bool|null $trusted
   *   Explicit trusted value for this fact, bypassing the trust policy.
   *   NULL (the default) lets the policy decide (AimTrustPolicy) - the
   *   right fallback for every LLM/human-mixed caller the draft-to-trusted
   *   gate exists for. FALSE is always honored. TRUE is honored only for a
   *   caller who could trust the fact by hand (administer aim, review aim
   *   facts or the scope's trust permission), and is otherwise ignored;
   *   unlike the policy's start, it is never demoted by the grounded check.
   * @param string|null $targetType
   *   The referenced entity's type ID, for scope=entity (e.g. node).
   *   Ignored for every other scope.
   * @param string|null $targetId
   *   The referenced entity's ID, for scope=entity. Ignored for every
   *   other scope.
   * @param string|null $sourceText
   *   The text this fact was written from (a visitor's message, a
   *   document), for the grounded check (ADR-0037). Checked, never stored.
   *   NULL leaves the fact unchecked.
   * @param int|null $expires
   *   When this fact stops being true, as a Unix timestamp (ADR-0057).
   *   retireExpired() retires it once this passes. NULL means no end date.
   *   Must be in the future.
   * @param string|null $channel
   *   The entry channel (an AimTrustPolicy::CHANNEL_* constant) the trust
   *   policy keys on. Named by the calling code, never by $source. NULL
   *   falls to the scope rule and the default.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The created fact.
   *
   * @throws \InvalidArgumentException
   *   If scope is invalid, scope=user and subject does not resolve to a real
   *   account, or the text fails a guardrail check.
   */
  public function remember(string $text, string $scope, ?string $subject = NULL, ?string $source = NULL, array $category = [], ?int $asserted = NULL, ?bool $trusted = NULL, ?string $targetType = NULL, ?string $targetId = NULL, ?string $sourceText = NULL, ?int $expires = NULL, ?string $channel = NULL): AimFact {
    $allowed = $this->manager->allowedScopes();
    if (!in_array($scope, $allowed, TRUE)) {
      throw new \InvalidArgumentException('Invalid scope "' . $scope . '", expected one of: ' . implode(', ', $allowed));
    }

    $values = [
      'scope' => $scope,
      'text' => $text,
      'source' => $source,
    ];

    if ($this->manager->scopeRequiresAccount($scope)) {
      // A fact in a scope that requires a real account (ADR-0007, the
      // user scope's requires_account setting) has to be about one:
      // subject is a genuine entity_reference (user), not a free-text string
      // that merely happens to hold a uid - that drift is exactly what let
      // "1" and "Nik" address the same person without ever matching.
      if (empty($subject)) {
        throw new \InvalidArgumentException('subject is required for scope=' . $scope . ': a uid of a real account on this site.');
      }
      $account = $this->manager->resolveAccountByUid($subject);
      if (!$account) {
        throw new \InvalidArgumentException('No user account found for subject "' . $subject . '". A scope=' . $scope . ' fact must be about a real account.');
      }
      $values['user'] = $account->id();
      $values['subject'] = '';
    }
    elseif (empty($subject)) {
      // No subject given: ask this scope's access plugin (if any) for a
      // default instead of leaving the field empty - e.g. the case plugin
      // mints a new case ID.
      $plugin = $this->scopeAccessManager->getTypePlugin($scope);
      $values['subject'] = $plugin?->defaultSubject() ?? '';
    }
    else {
      $values['subject'] = $subject;
    }

    if (!empty($category)) {
      $values['category'] = $this->resolveCategoryTerms($category);
    }

    if ($asserted !== NULL) {
      $values['asserted'] = $asserted;
    }

    if ($expires !== NULL) {
      if ($expires <= $this->time->getRequestTime()) {
        throw new \InvalidArgumentException('Invalid expires ' . gmdate('Y-m-d', $expires) . ', it is already past.');
      }
      $values['expires'] = $expires;
    }

    $grounded = $sourceText !== NULL && $this->settings->getGroundingMode() !== 'off';
    $score = $grounded ? $this->groundingChecker->checkGrounding($sourceText, [$text])[0] : NULL;
    $values += $this->trustValues($this->honoredTrusted($trusted, $scope), $channel, $scope, $grounded, $score);

    if ($targetType !== NULL) {
      $values['target_type'] = $targetType;
    }

    if ($targetId !== NULL) {
      $values['target_id'] = $targetId;
    }

    /** @var \Drupal\aim\Entity\AimFact $entity */
    $entity = $this->entityTypeManager->getStorage('aim_fact')->create($values);
    try {
      $this->manager->saveFact($entity);
    }
    catch (\InvalidArgumentException $e) {
      // The violation message can quote the rejected text, so it is not
      // logged.
      $this->logger->warning('Write rejected for scope @scope (uid @uid): failed validation or a guardrail.', [
        '@scope' => $scope,
        '@uid' => $this->currentUser->id(),
      ]);
      throw $e;
    }

    $this->manager->logAudit('Fact @id written (scope @scope, subject @subject, uid @uid, trusted @trusted).', [
      '@id' => $entity->id(),
      '@scope' => $scope,
      '@subject' => $values['user'] ?? $values['subject'] ?? '',
      '@uid' => $this->currentUser->id(),
      '@trusted' => $entity->get('trusted')->value ? 'yes' : 'no',
    ]);
    if ($grounded) {
      $this->logGrounding($entity);
    }

    return $entity;
  }

  /**
   * Resolves category names against the aim_category vocabulary.
   *
   * Admin-curated, not model-invented (CLAUDE.md's "Ideas raised" section):
   * a name with no matching term is silently skipped rather than creating
   * one on the fly, same posture as resolveAccount() not fabricating an
   * account.
   *
   * @param string[] $names
   *   Category term names to resolve.
   *
   * @return int[]
   *   The matching term IDs. Names with no match are omitted.
   */
  protected function resolveCategoryTerms(array $names): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = [];
    foreach ($names as $name) {
      $name = trim($name);
      if ($name === '') {
        continue;
      }
      $terms = $storage->loadByProperties(['vid' => 'aim_category', 'name' => $name]);
      if ($terms) {
        $tids[] = (int) reset($terms)->id();
      }
    }
    return $tids;
  }

  /**
   * Creates aim_fact entities from candidate facts, e.g. from extractFacts().
   *
   * @param array $facts
   *   A list of ['scope' => ..., 'subject' => ..., 'text' => ...] arrays.
   * @param string $source
   *   Provenance tag stored on every created fact.
   * @param string|null $subjectUid
   *   A uid of a real account to attach to any candidate the model
   *   classifies as scope=user. See ADR-0011: extraction never
   *   resolves scope=user against the model's own freeform subject text -
   *   a document naming someone is no guarantee that person has an account
   *   on this site. NULL means no such account was supplied, so every
   *   scope=user candidate is skipped.
   * @param bool|null $trusted
   *   Explicit trusted value applied to every fact created from $facts,
   *   bypassing aim.settings:default_trusted. See remember()'s own
   *   $trusted docblock - NULL is the right default here too, since this
   *   method's callers are extraction paths the draft-to-trusted gate
   *   exists for.
   * @param string|null $sourceText
   *   The text the candidates were extracted from, for the grounded check
   *   (ADR-0037): one call for every candidate. Never stored. NULL leaves
   *   the facts unchecked.
   * @param string|null $channel
   *   The entry channel for the trust policy.
   * @param \Drupal\Core\Session\AccountInterface|null $writer
   *   An account whose "create {scope} aim facts" permission each candidate
   *   is checked against (the ingest form's user), or NULL for no check
   *   (Drush, which runs as anonymous).
   *
   * @return array
   *   An array with keys 'created' (\Drupal\aim\Entity\AimFact[]), 'skipped'
   *   (int, the number of user-scope candidates skipped because no
   *   $subjectUid was supplied), 'blocked' (int, the number of candidates a
   *   guardrail rejected, per decision 7) and 'denied' (int, the number in
   *   a scope $writer may not create facts in).
   *
   * @throws \InvalidArgumentException
   *   If $subjectUid is given but does not resolve to a real account.
   */
  public function createFactsFromCandidates(array $facts, string $source, ?string $subjectUid = NULL, ?bool $trusted = NULL, ?string $sourceText = NULL, ?string $channel = NULL, ?AccountInterface $writer = NULL): array {
    $storage = $this->entityTypeManager->getStorage('aim_fact');
    $created = [];
    $skipped = 0;
    $blocked = 0;
    $denied = 0;

    $subjectAccount = NULL;
    if (!empty($subjectUid)) {
      $subjectAccount = $this->manager->resolveAccountByUid($subjectUid);
      if (!$subjectAccount) {
        throw new \InvalidArgumentException('No user account found for subject-uid "' . $subjectUid . '". A user-scope fact must be about a real account.');
      }
    }

    $grounded = $sourceText !== NULL && $this->settings->getGroundingMode() !== 'off';
    $scores = $grounded ? $this->groundingChecker->checkGrounding($sourceText, array_column($facts, 'text')) : [];

    foreach (array_values($facts) as $i => $fact) {
      // The model picks each candidate's scope, so the writer's per-scope
      // permission is checked per candidate, as aim_remember does per entry.
      if ($writer && !$this->manager->checkCreateAccess($fact['scope'], $writer)->isAllowed()) {
        $denied++;
        continue;
      }
      $values = [
        'text' => $fact['text'],
        'source' => $source,
      ];

      if ($this->manager->scopeRequiresAccount($fact['scope'])) {
        // Never resolve the model's own freeform subject text against the
        // accounts table (ADR-0011) - a source document naming someone is
        // no guarantee that person is this site's user. Only a caller-
        // supplied $subjectAccount, resolved once above, can attach a
        // candidate in such a scope to a real account.
        if (!$subjectAccount) {
          $skipped++;
          continue;
        }
        $values['scope'] = $fact['scope'];
        $values['user'] = $subjectAccount->id();
        $values['subject'] = '';
      }
      else {
        $values['scope'] = $fact['scope'];
        $values['subject'] = $fact['subject'] ?? '';
      }

      $values += $this->trustValues($this->honoredTrusted($trusted, $fact['scope']), $channel, $fact['scope'], $grounded, $scores[$i] ?? NULL);

      $entity = $storage->create($values);
      try {
        // Guardrail check runs inside save() now, via hook_aim_fact_presave()
        // - still per-candidate, since a rejected save throws before the
        // loop's next iteration. One rejected fact should not abort the
        // rest of an extraction batch, the same posture already taken for
        // an unresolvable user-scope subject above. saveFact() unwraps the
        // EntityStorageException SqlContentEntityStorage::save() wraps a
        // presave hook's exception in, back to the \InvalidArgumentException
        // this catch expects - see saveFact()'s own docblock.
        $this->manager->saveFact($entity);
      }
      catch (\InvalidArgumentException) {
        $blocked++;
        $this->logger->warning('Extracted candidate rejected for scope @scope (uid @uid): failed validation or a guardrail.', [
          '@scope' => $fact['scope'],
          '@uid' => $this->currentUser->id(),
        ]);
        continue;
      }
      $created[] = $entity;
      $this->manager->logAudit('Fact @id written from extraction (scope @scope, subject @subject, uid @uid, trusted @trusted).', [
        '@id' => $entity->id(),
        '@scope' => $fact['scope'],
        '@subject' => $values['user'] ?? $values['subject'],
        '@uid' => $this->currentUser->id(),
        '@trusted' => $entity->get('trusted')->value ? 'yes' : 'no',
      ]);
      if ($grounded) {
        $this->logGrounding($entity);
      }
    }

    if ($skipped > 0) {
      $this->manager->logVerbose('Extraction skipped @skipped user-scope candidates: no subject-uid supplied.', ['@skipped' => $skipped]);
    }

    if ($denied > 0) {
      $this->manager->logAudit('Extraction dropped @denied candidates in scopes uid @uid may not create facts in.', [
        '@denied' => $denied,
        '@uid' => $writer?->id(),
      ]);
    }
    return ['created' => $created, 'skipped' => $skipped, 'blocked' => $blocked, 'denied' => $denied];
  }

  /**
   * Extracts facts from prose with a model and saves them.
   *
   * Shared by aim:extract and the ingest form (ADR-0016, mode 1).
   *
   * @param string $text
   *   The document text. Sent whole in one prompt, no chunking.
   * @param string $source
   *   Provenance tag stored on every created fact.
   * @param string|null $subjectUid
   *   A uid of a real account to attach scope=user candidates to. NULL skips
   *   them.
   * @param string|null $providerId
   *   The chat provider, or NULL for the extraction activity's configured one.
   * @param string|null $modelId
   *   The chat model, or NULL for the extraction activity's configured one.
   * @param string|null $channel
   *   The entry channel for the trust policy.
   * @param \Drupal\Core\Session\AccountInterface|null $writer
   *   The account to check per-scope create access for, or NULL for none;
   *   see createFactsFromCandidates().
   *
   * @return array
   *   The createFactsFromCandidates() result plus 'extracted' (int, how many
   *   candidates the model returned).
   *
   * @throws \InvalidArgumentException
   *   If $subjectUid does not resolve to a real account.
   * @throws \RuntimeException
   *   If no provider/model is given and none is configured.
   */
  public function ingestText(string $text, string $source, ?string $subjectUid = NULL, ?string $providerId = NULL, ?string $modelId = NULL, ?string $channel = NULL, ?AccountInterface $writer = NULL): array {
    if (empty($providerId) || empty($modelId)) {
      $default = $this->settings->getModelFor('extraction');
      if ($default === NULL) {
        throw new \RuntimeException('No extraction model is configured. Set one at /admin/config/ai/settings or in the AIM settings.');
      }
      $providerId = $default['provider_id'];
      $modelId = $default['model_id'];
    }

    $facts = $this->extractFacts(trim($text), $providerId, $modelId);
    if (empty($facts)) {
      return ['created' => [], 'skipped' => 0, 'blocked' => 0, 'denied' => 0, 'extracted' => 0];
    }
    $result = $this->createFactsFromCandidates($facts, $source, $subjectUid ?: NULL, NULL, trim($text), $channel, $writer);
    $result['extracted'] = count($facts);
    return $result;
  }

  /**
   * Saves a list of fact entries through remember(), one failure at a time.
   *
   * Shared by aim:remember --file and the ingest form's JSON and one-fact-
   * per-line modes. One bad entry (missing text or scope, an invalid value,
   * a rejected guardrail) is recorded and skipped, never aborting the rest.
   *
   * @param array $entries
   *   Fact objects with the keys text, scope, subject, source,
   *   category (string or array), asserted, target_type, target_id. Unknown
   *   keys are ignored.
   * @param string|null $defaultSource
   *   The source for entries that name none.
   * @param string|null $channel
   *   The entry channel for the trust policy.
   * @param \Drupal\Core\Session\AccountInterface|null $writer
   *   An account whose "create {scope} aim facts" permission each entry is
   *   checked against (the ingest form's user), or NULL for no check (Drush,
   *   which runs as anonymous). A denied entry is an error, not a write.
   *
   * @return array
   *   An array with keys 'created' (\Drupal\aim\Entity\AimFact[] keyed by
   *   entry index) and 'errors' (string messages keyed by entry index).
   */
  public function rememberBatch(array $entries, ?string $defaultSource = NULL, ?string $channel = NULL, ?AccountInterface $writer = NULL): array {
    $created = [];
    $errors = [];
    foreach ($entries as $i => $entry) {
      if (!is_array($entry) || empty($entry['text'])) {
        $errors[$i] = 'missing text';
        continue;
      }
      if (empty($entry['scope'])) {
        $errors[$i] = 'missing scope';
        continue;
      }
      if ($writer && !$this->manager->checkCreateAccess((string) $entry['scope'], $writer)->isAllowed()) {
        $errors[$i] = 'No permission to create ' . $entry['scope'] . '-scope facts.';
        continue;
      }
      try {
        [$category, $asserted] = $this->parseFactFields($entry);
        $created[$i] = $this->remember(
          $entry['text'],
          $entry['scope'],
          $entry['subject'] ?? NULL,
          $entry['source'] ?? $defaultSource,
          $category,
          $asserted,
          NULL,
          $entry['target_type'] ?? NULL,
          $entry['target_id'] ?? NULL,
          expires: $this->parseExpires($entry),
          channel: $channel,
        );
      }
      catch (\InvalidArgumentException $e) {
        $errors[$i] = $e->getMessage();
      }
    }
    return ['created' => $created, 'errors' => $errors];
  }

  /**
   * Parses the category/asserted fields of a fact entry.
   *
   * @param array $fields
   *   Raw values, from a command line or a decoded JSON entry.
   *
   * @return array
   *   [$category, $asserted], typed as remember() expects them.
   *
   * @throws \InvalidArgumentException
   *   If asserted cannot be parsed.
   */
  public function parseFactFields(array $fields): array {
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

    return [$category, $asserted];
  }

  /**
   * Parses the expires field of a fact entry.
   *
   * @param array $fields
   *   A fact entry or command options, possibly with an expires date.
   *
   * @return int|null
   *   A Unix timestamp (end of that day, UTC), or NULL when not given.
   *
   * @throws \InvalidArgumentException
   *   If expires cannot be parsed.
   */
  public function parseExpires(array $fields): ?int {
    $value = $fields['expires'] ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $parsed = strtotime((string) $value . ' 23:59:59 UTC');
    if ($parsed === FALSE) {
      throw new \InvalidArgumentException('Invalid expires "' . $value . '", expected a calendar date such as 2026-10-16.');
    }
    return $parsed;
  }

  /**
   * Calls the configured chat provider and returns structured facts.
   *
   * The instruction prompt is aim.settings:extraction_prompt (admin-
   * editable at /admin/config/aim/settings), not hardcoded - only the
   * requested output shape below (the facts/scope/subject/text schema)
   * stays code-defined, since it's parsed by PHP downstream.
   *
   * @param string $text
   *   The source text to extract facts from.
   * @param string $providerId
   *   The AI provider plugin ID.
   * @param string $modelId
   *   The chat model ID.
   *
   * @return array
   *   A list of ['scope' => ..., 'subject' => ..., 'text' => ...] arrays.
   */
  public function extractFacts(string $text, string $providerId, string $modelId): array {
    $prompt = strtr($this->configFactory->get('aim.settings')->get('extraction_prompt'), [
      '{text}' => $text,
    ]);

    $schema = new StructuredOutputSchema(
      name: 'aim_extracted_facts',
      description: 'Atomic facts extracted from source text.',
      strict: TRUE,
      json_schema: [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => [
          'facts' => [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'additionalProperties' => FALSE,
              'properties' => [
                'scope' => [
                  'type' => 'string',
                  // The live aim_scope bundles, not a literal list, so a
                  // scope added as config is extractable with no code
                  // change (see CLAUDE.md's "Scope as a config entity").
                  'enum' => $this->manager->allowedScopes(),
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

    $provider = $this->aiProvider->createInstance($providerId);
    $output = $provider->chat($input, $modelId, ['aim_extract']);
    $response_text = $output->getNormalized()->getText();

    $decoded = json_decode($response_text, TRUE);
    if (!is_array($decoded) || !isset($decoded['facts']) || !is_array($decoded['facts'])) {
      throw new \RuntimeException("Model response was not the expected JSON shape: $response_text");
    }

    $this->manager->logVerbose('Extraction returned @count candidate facts (provider @provider, model @model, uid @uid).', [
      '@count' => count($decoded['facts']),
      '@provider' => $providerId,
      '@model' => $modelId,
      '@uid' => $this->currentUser->id(),
    ]);

    return $decoded['facts'];
  }

}
