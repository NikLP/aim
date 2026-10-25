<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Service\AimRecallService;
use Drupal\aim\Service\AimSettings;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a real semantic query against aim_vector_index.
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
 * needed in this plugin.
 *
 * Drops matches past aim.settings:recall_max_distance, so an off-topic
 * query returns "No relevant facts found." rather than the nearest
 * unrelated facts (ADR-0019). The chatbot's recall does the same. There is
 * no max_distance input: a remote caller could pass 2 and turn the cutoff
 * off (drush aim:recall --max-distance stays for tuning).
 */
#[Tool(
  id: 'aim_recall',
  label: new TranslatableMarkup('Recall facts'),
  description: new TranslatableMarkup('Searches previously remembered facts for anything semantically relevant to a query.'),
  operation: ToolOperation::Read,
  permission: 'recall aim facts',
  input_definitions: [
    'text' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search text'),
      description: new TranslatableMarkup('What to search remembered facts for.'),
    ),
    'scope' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Scope'),
      // Replaced with the installed scopes by AimToolHooks::toolInfoAlter().
      description: new TranslatableMarkup('Optional. Restrict results to one scope; leave empty to search every scope you can see.'),
      required: FALSE,
    ),
    'subject' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Subject'),
      description: new TranslatableMarkup('Restrict results to one subject. Not used for scope=user.'),
      required: FALSE,
    ),
    'user' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Subject account'),
      description: new TranslatableMarkup('Restrict results to one user, by uid or username. Only meaningful with scope=user; omit to default to the calling account.'),
      required: FALSE,
    ),
    'limit' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('How many facts at most (1 to 25, default 10). Each is one short line: use fewer for a quick check, more for a broad question.'),
      required: FALSE,
      default_value: 10,
      constraints: ['Range' => ['min' => 1, 'max' => self::MAX_LIMIT]],
    ),
  ],
  output_definitions: [
    'results' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('The matching facts, formatted as a list.'),
    ),
  ],
)]
final class AimRecall extends ToolBase {

  /**
   * The most results one call returns, so a caller can't pull the store.
   */
  const MAX_LIMIT = 25;

  /**
   * The settings reader.
   */
  protected AimSettings $settings;

  /**
   * The recall service.
   */
  protected AimRecallService $recallService;

  /**
   * {@inheritdoc}
   *
   * See AimRemember for why create() rather than the (final) constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->settings = $container->get('aim.settings_reader');
    $instance->recallService = $container->get('aim.recall');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $userFilter = $values['user'] ?? NULL;
    if (($values['scope'] ?? NULL) === 'user' && empty($userFilter)) {
      $userFilter = (string) $this->currentUser->id();
    }

    try {
      $rows = $this->recallService->recall(
        $values['text'],
        $values['scope'] ?? NULL,
        $values['subject'] ?? NULL,
        $userFilter,
        max(1, min(self::MAX_LIMIT, (int) ($values['limit'] ?? 10))),
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

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'recall aim facts');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
