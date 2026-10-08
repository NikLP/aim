<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Service\AimMemoryManager;
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
 * Same read path as drush aim:recall. Gated on "read aim memory" -
 * separate from AimRemember's "store aim memory" permission, since a
 * caller able to search facts should not automatically be able to write
 * them, or vice versa. Unlike aim_chatbot's aim_chatbot:recall, this is
 * not locked to scope=site - see AimRemember's docblock for why a Tool
 * API caller (a real, authenticated Drupal account) is a different trust
 * level from an anonymous chat visitor. scope=user omitting user
 * defaults to the calling account rather than every user's facts,
 * matching AimRemember's same default and keeping "recall my facts" the
 * ergonomic no-argument case for scope=user.
 *
 * "read aim memory" only gates use of this tool at all - the real
 * per-scope "view {scope} aim facts" permission still applies per result:
 * AimMemoryManager::executeSearchQuery() runs this query as the calling
 * account (never bypasses access) whenever it is a real authenticated
 * user, so ai_search's own per-result entity access check
 * (SearchApiAiSearchBackend::checkEntityAccess()) filters out any fact the
 * caller can't actually view, including their own scope=user facts if
 * they hold no "view user aim facts" permission - no separate filtering
 * needed in this plugin.
 *
 * Drops matches past aim.settings:recall_max_distance by default (the
 * caller may pass its own max_distance), so an off-topic query returns
 * "No relevant facts found." rather than the nearest unrelated facts
 * (ADR-0019). The chatbot's recall does the same.
 */
#[Tool(
  id: 'aim_recall',
  label: new TranslatableMarkup('Recall facts'),
  description: new TranslatableMarkup('Searches previously remembered facts for anything semantically relevant to a query.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'text' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search text'),
      description: new TranslatableMarkup('What to search remembered facts for.'),
    ),
    'scope' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Scope'),
      description: new TranslatableMarkup('Restrict results to one scope: user, role, site, case.'),
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
      description: new TranslatableMarkup('Maximum number of results.'),
      required: FALSE,
      default_value: 10,
    ),
    'max_distance' => new InputDefinition(
      data_type: 'float',
      label: new TranslatableMarkup('Maximum distance'),
      description: new TranslatableMarkup('Drop facts less similar than this (cosine distance: 0 is identical, lower is stricter, 2 disables the cutoff). Omit to use the site default, which filters out unrelated facts.'),
      required: FALSE,
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
   * The aim memory manager.
   */
  protected AimMemoryManager $memoryManager;

  /**
   * {@inheritdoc}
   *
   * See AimRemember for why create() rather than the (final) constructor.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->memoryManager = $container->get('aim.memory_manager');
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
      $rows = $this->memoryManager->recall(
        $values['text'],
        $values['scope'] ?? NULL,
        $values['subject'] ?? NULL,
        $userFilter,
        (int) ($values['limit'] ?? 10),
        isset($values['max_distance']) ? (float) $values['max_distance'] : $this->memoryManager->getRecallMaxDistance(),
      );
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Could not search memory: @message', ['@message' => $e->getMessage()]),
        NULL,
      );
    }

    // A literal is site-wide, so it fits an unscoped or site recall that is
    // not narrowed to one subject or user.
    if (in_array($values['scope'] ?? NULL, [NULL, 'site'], TRUE) && empty($values['subject']) && empty($userFilter)) {
      $rows = array_merge($this->memoryManager->recallLiterals($values['text'], $rows), $rows);
    }

    if (empty($rows)) {
      return ExecutableResult::success(
        new TranslatableMarkup('No relevant facts found.'),
        ['results' => ''],
      );
    }

    $lines = array_map(fn (array $row): string => $this->memoryManager->formatFactLine($row), $rows);
    return ExecutableResult::success(
      new TranslatableMarkup('Found @count relevant fact(s).', ['@count' => count($rows)]),
      ['results' => implode("\n", $lines)],
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'read aim memory');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
