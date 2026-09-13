<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;

/**
 * Runs a real semantic query against aim_vector_index.
 *
 * Same read path as drush aim:recall. Gated on "read aim memory" -
 * separate from AimRemember's "store aim memory" permission, since a
 * caller able to search facts should not automatically be able to write
 * them, or vice versa. Unlike aim_chatbot's aim_chatbot:recall, this is
 * not locked to scope=site - see AimRemember's docblock for why a Tool
 * API caller (a real, authenticated Drupal account) is a different trust
 * level from an anonymous chat visitor. scope=user omitting subject_uid
 * defaults to the calling account rather than every user's facts,
 * matching AimRemember's same default and keeping "recall my facts" the
 * ergonomic no-argument case for scope=user.
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
    'subject_uid' => new InputDefinition(
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
  ],
  output_definitions: [
    'results' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('The matching facts, formatted as a list.'),
    ),
  ],
)]
final class AimRecall extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $subjectUid = $values['subject_uid'] ?? NULL;
    if (($values['scope'] ?? NULL) === 'user' && empty($subjectUid)) {
      $subjectUid = (string) $this->currentUser->id();
    }

    try {
      $rows = \Drupal::service('aim.memory_manager')->recall(
        $values['text'],
        $values['scope'] ?? NULL,
        $values['subject'] ?? NULL,
        $subjectUid,
        (int) ($values['limit'] ?? 10),
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

    $lines = array_map(static fn (array $row): string => '- ' . $row['text'], $rows);
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
