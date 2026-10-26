<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Tool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Service\AimRecallService;
use Drupal\aim_tool\AimScopeGuide;
use Drupal\tool\Attribute\ToolInput;
use Drupal\tool\Attribute\ToolMethod;
use Drupal\tool\Attribute\ToolOutput;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * The aim_brief tool: the newest facts the caller can see, per scope.
 *
 * A briefing at the start of work, which has no query to search by:
 * aim_recall is semantic, so "tell me everything" finds only whatever
 * happens to be nearest, or nothing past the distance cutoff. This lists
 * by date instead (AimRecallService::recent()), trusted facts only, each
 * checked for view access as the caller. Gated on "recall aim facts", the
 * same read permission as aim_recall.
 *
 * Per scope, a scope that requires an account lists the caller's own
 * facts and role lists the caller's roles' facts; every other installed
 * scope lists its newest facts the caller may view. Results have the same
 * shape as aim_recall's (AimRecall::toResult()), grouped by scope in
 * AimScopeGuide's order (site first), newest first within each.
 */
final class AimBrief {

  /**
   * The most facts per scope one call returns.
   */
  const MAX_LIMIT = 10;

  /**
   * Constructs an AimBrief tool service.
   *
   * @param \Drupal\aim\Service\AimRecallService $recallService
   *   The recall service.
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The memory manager, for which scopes require an account.
   * @param \Drupal\aim_tool\AimScopeGuide $scopeGuide
   *   The scope guide, for the installed scopes in order.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The calling account.
   */
  public function __construct(
    private readonly AimRecallService $recallService,
    private readonly AimMemoryManager $memoryManager,
    private readonly AimScopeGuide $scopeGuide,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Lists the newest facts the caller can see, per scope.
   *
   * @param string|null $scope
   *   Optional. List one scope only; leave empty for every scope.
   * @param int $limit
   *   How many facts at most per scope (1 to 10, default 5).
   *
   * @return \Drupal\tool\ExecutableResult
   *   The facts, or a failure for an unknown scope.
   */
  #[ToolMethod(
    id: 'aim_brief',
    label: new TranslatableMarkup('Brief on memory'),
    description: new TranslatableMarkup('Lists the newest remembered facts you can see in each scope (the site, your roles, yourself, ongoing work), with no search text. Call it once at the start of work to get oriented; use aim_recall to search for anything specific.'),
    operation: ToolOperation::Read,
    permission: 'recall aim facts',
  )]
  #[ToolOutput(
    name: 'results',
    definition: new ListOutputDefinition(
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('The facts, grouped by scope, newest first in each. Empty when nothing is recorded.'),
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
  public function brief(
    ?string $scope = NULL,
    #[ToolInput(constraints: ['Range' => ['min' => 1, 'max' => self::MAX_LIMIT]])]
    int $limit = 5,
  ): ExecutableResult {
    $installed = $this->scopeGuide->scopeIds();
    if (!empty($scope) && !in_array($scope, $installed, TRUE)) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Unknown scope "@scope".', ['@scope' => $scope]),
        NULL,
      );
    }

    $rows = [];
    foreach (!empty($scope) ? [$scope] : $installed as $id) {
      $user = NULL;
      $subjects = NULL;
      if ($this->memoryManager->scopeRequiresAccount($id)) {
        if ($this->currentUser->isAnonymous()) {
          continue;
        }
        $user = $this->currentUser;
      }
      elseif ($id === 'role') {
        $subjects = $this->currentUser->getRoles();
      }
      array_push($rows, ...$this->recallService->recent($id, $subjects, $user, $limit));
    }

    if (empty($rows)) {
      return ExecutableResult::success(
        new TranslatableMarkup('No facts recorded yet.'),
        ['results' => []],
      );
    }
    return ExecutableResult::success(
      new TranslatableMarkup('Listed @count fact(s).', ['@count' => count($rows)]),
      ['results' => array_map(AimRecall::toResult(...), $rows)],
    );
  }

}
