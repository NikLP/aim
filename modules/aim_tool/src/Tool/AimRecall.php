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

/**
 * The aim_recall tool: a semantic query against aim_vector_index.
 *
 * Same read path as drush aim:recall. Gated on "recall aim facts" -
 * separate from AimRemember's "remember aim facts" permission, since a
 * caller able to search facts should not automatically be able to write
 * them, or vice versa. Unlike aim_chatbot's aim_chatbot:recall, this is
 * not locked to scope=site - see AimRemember's docblock for why a Tool
 * API caller (a real, authenticated Drupal account) is a different trust
 * level from an anonymous chat visitor. scope=user omitting user
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
 * unrelated facts (ADR-0019). The chatbot's recall does the same. There is
 * no max_distance input: a remote caller could pass 2 and turn the cutoff
 * off (drush aim:recall --max-distance stays for tuning).
 *
 * A #[ToolMethod] service (ADR-0044), not a #[Tool] plugin class: Tool API
 * builds the inputs from recall()'s parameters and @param text, and
 * AimToolHooks::toolInfoAlter() replaces scope's with the installed scopes.
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
   *   How many facts at most (1 to 25, default 10). Each is one short line:
   *   use fewer for a quick check, more for a broad question.
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
    data_type: 'string',
    description: new TranslatableMarkup('The matching facts, formatted as a list.'),
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
        ['results' => ''],
      );
    }

    $lines = array_map(fn (array $row): string => $this->recallService->formatFactLine($row), $rows);
    return ExecutableResult::success(
      new TranslatableMarkup('Found @count relevant fact(s).', ['@count' => count($rows)]),
      ['results' => implode("\n", $lines)],
    );
  }

}
