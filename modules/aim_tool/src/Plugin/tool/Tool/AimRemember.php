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
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;

/**
 * Saves an aim_fact directly, the same write path as drush aim:remember.
 *
 * Unlike aim_chatbot's aim_chatbot:remember, this is not locked to
 * scope=site: a Tool API caller here is a real, authenticated Drupal
 * account (gated on the "store aim memory" permission below - separate
 * from AimRecall's "read aim memory", not the single blanket
 * administer aim memory the admin UI uses, since write and read are
 * different trust levels for a non-admin caller), not an anonymous chat
 * visitor, so scope/subject are caller-supplied - the same
 * shape ADR-0006 established for the drush CLI adapter, over Tool API/MCP
 * transport instead of a shell. See ADR-0013 for why this is a separate
 * plugin from the chatbot's rather than a shared one.
 *
 * Pass facts instead of text/scope/subject/source to save several facts in
 * one call - one MCP round trip and one Drupal bootstrap for the whole
 * batch, mirroring aim:remember's own --file option and for the same
 * reason: an MCP tools/call is its own HTTP request, so an agent asked to
 * "remember ten things" would otherwise cost ten bootstraps regardless of
 * transport.
 *
 * ToolBase's constructor is final (no constructor DI for extra services);
 * AimMemoryManager is fetched via the service container in doExecute()
 * instead, the same pattern aim_eca's plugins already use for the same
 * reason (see CLAUDE.md's ECA integration section).
 */
#[Tool(
  id: 'aim_remember',
  label: new TranslatableMarkup('Remember a fact'),
  description: new TranslatableMarkup('Saves a short, atomic fact directly, exactly as given - no LLM call to decide what is worth remembering. Pass facts instead of text/scope to save several facts in one call.'),
  operation: ToolOperation::Write,
  input_definitions: [
    'text' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Fact text'),
      description: new TranslatableMarkup('The fact to remember, as a short atomic statement. Omit when using facts.'),
      required: FALSE,
    ),
    'scope' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Scope'),
      description: new TranslatableMarkup('One of user, role, site, case. Defaults to site if omitted.'),
      required: FALSE,
    ),
    'subject' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Subject'),
      description: new TranslatableMarkup('Who or what the fact is about. For scope=user, a uid of a real account; omit to default to the calling account. Not used for scope=site.'),
      required: FALSE,
    ),
    'source' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source'),
      description: new TranslatableMarkup('Provenance tag for this fact.'),
      required: FALSE,
    ),
    'facts' => new ListInputDefinition(
      label: new TranslatableMarkup('Facts'),
      description: new TranslatableMarkup('Several facts to save in one call instead of text/scope/subject/source above - use this whenever more than one fact needs saving, instead of calling this tool repeatedly. Each entry is an object: {text (required, the fact statement), scope (optional, one of user/role/site/case, defaults to site), subject (optional), source (optional)}.'),
      required: FALSE,
      item_definition: new MapInputDefinition(
        label: new TranslatableMarkup('Fact'),
        description: new TranslatableMarkup('One fact.'),
        property_definitions: [
          'text' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Fact text'),
            description: new TranslatableMarkup('The fact to remember, as a short atomic statement. An entry missing this is skipped rather than failing the whole batch, so this is not enforced as required here.'),
            required: FALSE,
          ),
          'scope' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Scope'),
            description: new TranslatableMarkup('One of user, role, site, case. Defaults to site if omitted.'),
            required: FALSE,
          ),
          'subject' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Subject'),
            description: new TranslatableMarkup('Who or what the fact is about. For scope=user, a uid of a real account; omit to default to the calling account.'),
            required: FALSE,
          ),
          'source' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Source'),
            description: new TranslatableMarkup('Provenance tag for this fact.'),
            required: FALSE,
          ),
        ],
      ),
    ),
  ],
  output_definitions: [
    'fact_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Fact ID'),
      description: new TranslatableMarkup('The ID of the created aim_fact. Only set for a single-fact call.'),
      required: FALSE,
    ),
    'results' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('Per-fact outcome, one line each. Only set for a facts batch call.'),
      required: FALSE,
    ),
    'case_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Case ID'),
      description: new TranslatableMarkup('For scope=case: the case ID this fact was saved under - the one just given, or a newly minted one if none was given. Pass it as subject on later calls to add to the same case. Only set for a single-fact call.'),
      required: FALSE,
    ),
  ],
)]
final class AimRemember extends ToolBase {

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    if (!empty($values['facts'])) {
      return $this->doExecuteBatch($values['facts']);
    }

    if (empty($values['text'])) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Provide text (and scope), or facts for a batch.'),
        NULL,
      );
    }

    $fact = $this->rememberOne($values);
    if (isset($fact['error'])) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Could not save fact: @message', ['@message' => $fact['error']]),
        NULL,
      );
    }

    if ($fact['bundle'] === 'case') {
      return ExecutableResult::success(
        new TranslatableMarkup('Created aim_fact @id. Case ID: @case_id - pass this as subject on later calls to add to the same case.', [
          '@id' => $fact['id'],
          '@case_id' => $fact['subject'],
        ]),
        ['fact_id' => $fact['id'], 'case_id' => $fact['subject']],
      );
    }

    return ExecutableResult::success(
      new TranslatableMarkup('Created aim_fact @id.', ['@id' => $fact['id']]),
      ['fact_id' => $fact['id']],
    );
  }

  /**
   * Saves every entry in a facts batch, one bootstrap for the whole call.
   *
   * Mirrors drush aim:remember --file's per-entry resilience: one bad
   * entry is reported and skipped rather than aborting the rest.
   *
   * @param array $facts
   *   Fact entries, each shaped like doExecute()'s own text/scope/subject/
   *   source inputs.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The batch result, with a per-entry summary in the results output.
   */
  private function doExecuteBatch(array $facts): ExecutableResult {
    $lines = [];
    $created = 0;
    foreach ($facts as $i => $entry) {
      if (empty($entry['text'])) {
        $lines[] = "Entry $i: missing text, skipped.";
        continue;
      }

      $fact = $this->rememberOne($entry);
      if (isset($fact['error'])) {
        $lines[] = "Entry $i: " . $fact['error'];
        continue;
      }

      $line = "Entry $i: created aim_fact {$fact['id']}.";
      if ($fact['bundle'] === 'case') {
        $line .= " Case ID: {$fact['subject']}.";
      }
      $lines[] = $line;
      $created++;
    }

    return ExecutableResult::success(
      new TranslatableMarkup('Created @created of @total fact(s).', [
        '@created' => $created,
        '@total' => count($facts),
      ]),
      ['results' => implode("\n", $lines)],
    );
  }

  /**
   * Saves one fact, resolving scope=user's default subject.
   *
   * @param array $fields
   *   Text/scope/subject/source, as given on a single call or one facts
   *   entry.
   *
   * @return array
   *   ['id' => int, 'bundle' => string, 'subject' => string] on success, or
   *   ['error' => string] on failure.
   */
  private function rememberOne(array $fields): array {
    $scope = $fields['scope'] ?? 'site';
    $subject = $fields['subject'] ?? NULL;
    if ($scope === 'user' && empty($subject)) {
      $subject = (string) $this->currentUser->id();
    }

    try {
      $fact = \Drupal::service('aim.memory_manager')->remember(
        $fields['text'],
        $scope,
        $subject,
        $fields['source'] ?? 'tool:aim_remember',
        NULL,
      );
    }
    catch (\InvalidArgumentException $e) {
      return ['error' => $e->getMessage()];
    }

    return [
      'id' => (int) $fact->id(),
      'bundle' => $fact->bundle(),
      'subject' => $fact->get('subject')->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $access = AccessResult::allowedIfHasPermission($account, 'store aim memory');
    return $return_as_object ? $access : $access->isAllowed();
  }

}
