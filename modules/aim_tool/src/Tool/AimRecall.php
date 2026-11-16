<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Tool;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Service\AimRecallService;
use Drupal\aim\Service\AimSettings;
use Drupal\literals\LiteralLookup;
use Drupal\tool\Attribute\ToolInput;
use Drupal\tool\Attribute\ToolMethod;
use Drupal\tool\Attribute\ToolOutput;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The aim_recall tool: a semantic query against aim_vector_index.
 *
 * Same read path as drush aim:recall. Gated on "recall aim facts" -
 * separate from AimRemember's "remember aim facts" permission, since a
 * caller able to search facts should not automatically be able to write
 * them, or vice versa. The one recall tool for chat and MCP (ADR-0062), not
 * locked to any scope: what a caller gets is what it may view. scope=user
 * omitting user
 * defaults to the calling account rather than every user's facts,
 * matching AimRemember's same default and keeping "recall my facts" the
 * ergonomic no-argument case for scope=user.
 *
 * "recall aim facts" only gates use of this tool at all - the real
 * per-scope "view {scope} aim facts" permission still applies per result:
 * AimMemoryManager::executeSearchQuery() runs this query as the calling
 * account and never bypasses access (only Drush and consolidation do), so
 * ai_search's own per-result entity access check
 * (SearchApiAiSearchBackend::checkEntityAccess()) filters out any fact the
 * caller can't actually view, including their own scope=user facts if
 * they hold no "view user aim facts" permission - no separate filtering
 * needed here.
 *
 * Drops matches past aim.settings:recall_max_distance, so an off-topic
 * query returns "No relevant facts found." rather than the nearest
 * unrelated facts (ADR-0019). There is no max_distance input: a remote
 * caller could pass 2 and turn the cutoff off (drush aim:recall
 * --max-distance stays for tuning).
 *
 * A #[ToolMethod] service (ADR-0044), not a #[Tool] plugin class: Tool API
 * builds the inputs from recall()'s parameters and @param text, and
 * AimToolHooks::toolInfoAlter() replaces scope's with the installed scopes.
 *
 * Results are structured, one {text, scope, subject, date, date_kind}
 * object per fact, which the MCP bridge publishes as
 * outputSchema/structuredContent: the scope and subject are there for the
 * caller to act on (add to a case by its ID, aim a role fact), and typed
 * fields can't be misread the way a formatted line can. date_kind says
 * which date it is, as formatFactLine()'s "true since"/"recorded" does.
 *
 * Exact values come back too (ADR-0061's addendum): with literals_finder
 * enabled and aim.settings:recall_literals on, the query also goes to
 * literals.lookup as a question, as the calling account, and a match (or
 * each candidate of an ambiguous one) is returned in a sibling literals
 * list. A chat model asked for a phone number calls recall and never picks
 * a second tool, which it does poorly (ADR-0061 section 2). The finder's
 * own thresholds apply. A lookup that cannot run leaves the facts alone and
 * changes the message, so "nothing found" never stands for "not checked".
 */
final class AimRecall {

  /**
   * The most results one call returns, so a caller can't pull the store.
   */
  const MAX_LIMIT = 25;

  /**
   * Constructs an AimRecall tool service.
   *
   * @param \Drupal\aim\Service\AimRecallService $recallService
   *   The recall service.
   * @param \Drupal\aim\Service\AimMemoryManager $manager
   *   The memory manager, to ask whether a scope requires an account.
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The settings reader.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The calling account.
   * @param \Drupal\literals\LiteralLookup $literalLookup
   *   The shared literal lookup, for exact values.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, to tell whether the finder is enabled.
   * @param \Psr\Log\LoggerInterface $logger
   *   The aim logger channel.
   */
  public function __construct(
    private readonly AimRecallService $recallService,
    private readonly AimMemoryManager $manager,
    private readonly AimSettings $settings,
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'literals.lookup')]
    private readonly LiteralLookup $literalLookup,
    private readonly ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'logger.channel.aim')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Searches remembered facts.
   *
   * @param string $text
   *   What to search remembered facts for.
   * @param string|null $scope
   *   Optional. Restrict results to one scope; leave empty to search every
   *   scope you can see.
   * @param string|null $subject
   *   Restrict results to one subject. Not used for scope=user.
   * @param string|null $user
   *   Restrict results to one user, by uid or username. Only meaningful
   *   with scope=user; omit to default to the calling account.
   * @param int $limit
   *   How many facts at most (1 to 25, default 10). Each is one short
   *   object: use fewer for a quick check, more for a broad question.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The matching facts and exact values, or a failure when the search
   *   could not run.
   */
  #[ToolMethod(
    id: 'aim_recall',
    label: new TranslatableMarkup('Recall facts'),
    description: new TranslatableMarkup('Searches previously remembered facts for anything semantically relevant to a query, and the exact values kept for the site (phone numbers, web addresses, names) for one that answers it.'),
    operation: ToolOperation::Read,
    permission: 'recall aim facts',
  )]
  #[ToolOutput(
    name: 'results',
    definition: new ListOutputDefinition(
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('The matching facts, nearest first. Empty when nothing relevant is recorded.'),
      required: FALSE,
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Fact'),
        description: new TranslatableMarkup('One fact.'),
        property_definitions: [
          'text' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Text'), description: new TranslatableMarkup('The fact.')),
          'scope' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Scope'), description: new TranslatableMarkup('Who the fact is true for: one of the scopes listed on the scope input.')),
          'subject' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Subject'), description: new TranslatableMarkup('Who or what it is about, as the scope says: the role machine name, the user uid, the case ID (pass it as subject to aim_remember to add to the same case). Empty for site.'), required: FALSE),
          'date' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Date'), description: new TranslatableMarkup('YYYY-MM-DD, the date date_kind names.')),
          'date_kind' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Date kind'), description: new TranslatableMarkup('true_since: the fact has been true since date. recorded: when it became true is not known; date is when it was recorded.')),
        ],
      ),
    ),
  )]
  #[ToolOutput(
    name: 'literals',
    definition: new ListOutputDefinition(
      label: new TranslatableMarkup('Exact values'),
      description: new TranslatableMarkup('Exact values that may answer the query. Give a value exactly as written, never reworded or reformatted. Check its gist fits what was asked first. Empty when none fits or none was looked up.'),
      required: FALSE,
      item_definition: new MapOutputDefinition(
        label: new TranslatableMarkup('Exact value'),
        description: new TranslatableMarkup('One exact value.'),
        property_definitions: [
          'key' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Key'), description: new TranslatableMarkup('The literal key, as [literal:key] in a fact names it.')),
          'value' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Value'), description: new TranslatableMarkup('The exact value. Give it as written.')),
          'label' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Label'), description: new TranslatableMarkup('A human label for the value, usable as link text.'), required: FALSE),
          'kind' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Kind'), description: new TranslatableMarkup('What the value is: url, phone, email or text.')),
          'gist' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Gist'), description: new TranslatableMarkup('What this value is, in simple language terms. Say so if it does not fit what was asked.'), required: FALSE),
          'match' => new OutputDefinition(data_type: 'string', label: new TranslatableMarkup('Match'), description: new TranslatableMarkup('match: the one value that answers the query. ambiguous: one of several that could; if the question does not settle which, ask the person.')),
        ],
      ),
    ),
  )]
  public function recall(
    #[ToolInput(label: new TranslatableMarkup('Search text'))]
    string $text,
    ?string $scope = NULL,
    ?string $subject = NULL,
    #[ToolInput(label: new TranslatableMarkup('Subject account'))]
    ?string $user = NULL,
    #[ToolInput(constraints: ['Range' => ['min' => 1, 'max' => self::MAX_LIMIT]])]
    int $limit = 10,
  ): ExecutableResult {
    if ($scope !== NULL && empty($user) && $this->manager->scopeRequiresAccount($scope)) {
      $user = (string) $this->currentUser->id();
    }

    try {
      $rows = $this->recallService->recall(
        $text,
        $scope,
        $subject,
        $user,
        $limit,
        $this->settings->getRecallMaxDistance(),
        FALSE,
        $this->settings->getRecallGap(),
      );
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Could not search memory: @message', ['@message' => $e->getMessage()]),
        NULL,
      );
    }

    [$literals, $checked] = $this->lookupLiterals($text);
    $values = [
      'results' => array_map(self::toResult(...), $rows),
      'literals' => $literals,
    ];

    if ($rows && $literals) {
      $message = new TranslatableMarkup('Found @facts relevant fact(s) and @literals exact value(s).', [
        '@facts' => count($rows),
        '@literals' => count($literals),
      ]);
    }
    elseif ($rows) {
      $message = new TranslatableMarkup('Found @count relevant fact(s).', ['@count' => count($rows)]);
    }
    elseif ($literals) {
      $message = new TranslatableMarkup('Found @count exact value(s).', ['@count' => count($literals)]);
    }
    elseif ($checked) {
      $message = new TranslatableMarkup('No relevant facts or exact values found.');
    }
    else {
      $message = new TranslatableMarkup('No relevant facts found.');
    }
    // A lookup that could not run is never a "none": the caller must not say
    // an exact value is not recorded.
    if ($checked === FALSE) {
      $message = new TranslatableMarkup('@message Exact values (phone numbers, web addresses, names) could not be checked right now: do not say one is not recorded; say it cannot be checked at the moment.', ['@message' => $message]);
    }
    return ExecutableResult::success($message, $values);
  }

  /**
   * Asks literals.lookup for exact values that answer the query.
   *
   * @param string $text
   *   The recall query, passed as the question.
   *
   * @return array
   *   The literal results (key, value, label, kind, gist, match), and
   *   whether the lookup ran: TRUE when it ran, FALSE when it could not
   *   (finder error or no model), NULL when it is off or not installed.
   */
  private function lookupLiterals(string $text): array {
    if (!$this->settings->isRecallLiteralsOn() || !$this->moduleHandler->moduleExists('literals_finder')) {
      return [[], NULL];
    }
    try {
      // literals.lookup routes the question to the finder, checks the
      // viewer's access and resolves the value, and audits the call (mode,
      // outcome, keys, uid) on the literals channel.
      $found = $this->literalLookup->lookup(['question' => $text], $this->currentUser, 'aim_recall');
      $outcome = $found['success'] ? $found['values']['outcome'] : LiteralLookup::UNAVAILABLE;
      if ($outcome === LiteralLookup::MATCH) {
        return [[self::toLiteral($found['values'], 'match')], TRUE];
      }
      if ($outcome === LiteralLookup::AMBIGUOUS) {
        // The candidates carry keys and gists, not values: read each by key,
        // access-checked again, so the calling model can judge which fits.
        $literals = [];
        foreach (array_filter(explode(',', $found['values']['key'])) as $key) {
          $candidate = $this->literalLookup->lookup(['key' => $key], $this->currentUser, 'aim_recall');
          if ($candidate['success'] && $candidate['values']['outcome'] === LiteralLookup::MATCH) {
            $literals[] = self::toLiteral($candidate['values'], 'ambiguous');
          }
        }
        return [$literals, TRUE];
      }
      if ($outcome === LiteralLookup::UNAVAILABLE) {
        $this->logger->warning('aim_recall: the literal lookup could not run, facts returned without exact values, uid @uid.', ['@uid' => $this->currentUser->id()]);
        return [[], FALSE];
      }
      return [[], TRUE];
    }
    catch (\Exception $e) {
      $this->logger->warning('aim_recall: the literal lookup failed (@class), facts returned without exact values, uid @uid.', [
        '@class' => get_class($e),
        '@uid' => $this->currentUser->id(),
      ]);
      return [[], FALSE];
    }
  }

  /**
   * Turns a matched literals.lookup result into one literal result.
   *
   * @param array $values
   *   The lookup values: key, value, label, kind and gist.
   * @param string $match
   *   Either "match", or "ambiguous" for one of several candidates.
   *
   * @return array
   *   The key, value, label, kind, gist and match.
   */
  private static function toLiteral(array $values, string $match): array {
    return [
      'key' => $values['key'],
      'value' => $values['value'],
      'label' => $values['label'],
      'kind' => $values['kind'],
      'gist' => $values['gist'],
      'match' => $match,
    ];
  }

  /**
   * Turns a recall row into one structured result, as aim_brief's too.
   *
   * @param array $row
   *   A row from AimRecallService::recall() or recent().
   *
   * @return array
   *   The text, scope, subject, date and date_kind of the fact.
   */
  public static function toResult(array $row): array {
    return [
      'text' => $row['text'],
      'scope' => $row['scope'],
      'subject' => $row['subject'] !== NULL ? (string) $row['subject'] : '',
      'date' => gmdate('Y-m-d', (int) (!empty($row['asserted']) ? $row['asserted'] : $row['created'])),
      'date_kind' => !empty($row['asserted']) ? 'true_since' : 'recorded',
    ];
  }

}
