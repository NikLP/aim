<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Tool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Service\AimRecallService;
use Drupal\aim\Service\AimSettings;
use Drupal\tool\Attribute\ToolInput;
use Drupal\tool\Attribute\ToolMethod;
use Drupal\tool\Attribute\ToolOutput;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

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
   * @param \Drupal\aim\Service\AimSettings $settings
   *   The settings reader.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The calling account.
   */
  public function __construct(
    private readonly AimRecallService $recallService,
    private readonly AimSettings $settings,
    private readonly AccountInterface $currentUser,
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
   *   The matching facts, or a failure when the search could not run.
   */
  #[ToolMethod(
    id: 'aim_recall',
    label: new TranslatableMarkup('Recall facts'),
    description: new TranslatableMarkup('Searches previously remembered facts for anything semantically relevant to a query.'),
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
    if ($scope === 'user' && empty($user)) {
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

    if (empty($rows)) {
      return ExecutableResult::success(
        new TranslatableMarkup('No relevant facts found.'),
        ['results' => []],
      );
    }

    $results = array_map(fn (array $row): array => [
      'text' => $row['text'],
      'scope' => $row['scope'],
      'subject' => $row['subject'] !== NULL ? (string) $row['subject'] : '',
      'date' => gmdate('Y-m-d', (int) (!empty($row['asserted']) ? $row['asserted'] : $row['created'])),
      'date_kind' => !empty($row['asserted']) ? 'true_since' : 'recorded',
    ], $rows);
    return ExecutableResult::success(
      new TranslatableMarkup('Found @count relevant fact(s).', ['@count' => count($rows)]),
      ['results' => $results],
    );
  }

}
