<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\InputDefinitionRefinerInterface;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
 * "store aim memory" only gates use of this tool at all - rememberOne()
 * additionally checks the real per-scope "create {scope} aim facts"
 * permission for the candidate's actual scope (AimMemoryManager::
 * checkCreateAccess()), so the same permission the admin UI's entity add
 * form enforces per bundle also binds this write path, not just the flat
 * permission below.
 *
 * Pass facts instead of text/scope/subject/source to save several facts in
 * one call - one MCP round trip and one Drupal bootstrap for the whole
 * batch, mirroring aim:remember's own --file option and for the same
 * reason: an MCP tools/call is its own HTTP request, so an agent asked to
 * "remember ten things" would otherwise cost ten bootstraps regardless of
 * transport.
 *
 * ToolBase's constructor is final (no constructor DI for extra services),
 * but its create() is not - so AimMemoryManager is injected by overriding
 * create() and setting a property after parent::create(), the same pattern
 * aim_chatbot's FunctionCall plugins use, rather than a \Drupal::service()
 * call at execution time.
 *
 * scope_fields (ADR-0028 piece 4) replaces what used to be static
 * target_type/target_id inputs: an unconstrained map, refined per call via
 * InputDefinitionRefinerInterface once scope is known, gaining whichever
 * properties that scope's AimScopeTypeInterface plugin declares via
 * getBaseFieldDefinitions() (piece 1) - so the advertised schema reflects
 * whichever scope submodules are actually installed, not a hardcoded
 * scope=entity shape. A facts batch entry's own scope_fields stays
 * unrefined (an unconstrained map) regardless - Tool API's refiner
 * mechanism narrows a named top-level input from other top-level inputs'
 * values, it has no per-list-item equivalent, so a batch entry's scope
 * value can't drive its own scope_fields the way the single-call inputs
 * do. rememberOne() still only reads target_type/target_id by name out of
 * scope_fields, matching the only two field-bearing base fields any
 * shipped scope type declares today (AimMemoryManager::remember() itself
 * still takes them as two fixed parameters, not a generic bag) - a future
 * scope type adding a third field would need remember()'s own signature
 * widened too, not just this file; not built, see TODO.md.
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
      description: new TranslatableMarkup('Required when passing text (not needed inside facts, where each entry has its own). One of the scopes actually installed on this site - typically user, role, site, or case, but ask if unsure which are enabled here.'),
      required: FALSE,
    ),
    'subject' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Subject'),
      description: new TranslatableMarkup('Who or what the fact is about. For a scope requiring a real account (e.g. user), a uid of a real account; omit to default to the calling account. Not used for scope=site.'),
      required: FALSE,
    ),
    'source' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source'),
      description: new TranslatableMarkup('Provenance tag for this fact.'),
      required: FALSE,
    ),
    'scope_fields' => new MapInputDefinition(
      label: new TranslatableMarkup('Scope fields'),
      description: new TranslatableMarkup('Extra fields the chosen scope needs, if any - e.g. target_type/target_id for scope=entity. Ask if unsure what a given scope expects here; omit entirely for a scope with none.'),
      required: FALSE,
    ),
    'source_text' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Source text'),
      description: new TranslatableMarkup('The exact words the fact (or every fact in facts) came from: what the person said or the passage you read, quoted verbatim, not a summary. Used to check each fact is actually stated there; never stored. Omit when there is no such text.'),
      required: FALSE,
    ),
    'facts' => new ListInputDefinition(
      label: new TranslatableMarkup('Facts'),
      description: new TranslatableMarkup('Several facts to save in one call instead of text/scope/subject/source above - use this whenever more than one fact needs saving, instead of calling this tool repeatedly. Each entry is an object: {text (required, the fact statement), scope (required, one of the scopes actually installed on this site), subject (optional), source (optional), scope_fields (optional, extra fields the entry scope needs, e.g. target_type/target_id for scope=entity)}.'),
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
          'scope_fields' => new MapInputDefinition(
            label: new TranslatableMarkup('Scope fields'),
            description: new TranslatableMarkup('Extra fields the entry scope needs, if any - e.g. target_type/target_id for scope=entity. Not refined per entry the way the single-call scope_fields input is - see the docblock above.'),
            required: FALSE,
          ),
        ],
      ),
    ),
  ],
  input_definition_refiners: [
    'scope_fields' => ['scope'],
  ],
  output_definitions: [
    'fact_id' => new OutputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Fact ID'),
      description: new TranslatableMarkup('The ID of the created aim_fact. Only set for a single-fact call.'),
      required: FALSE,
    ),
    'results' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('Per-fact outcome, one line each. Only set for a facts batch call.'),
      required: FALSE,
    ),
    'case_id' => new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Case ID'),
      description: new TranslatableMarkup('For scope=case: the case ID this fact was saved under - the one just given, or a newly minted one if none was given. Pass it as subject on later calls to add to the same case. Only set for a single-fact call.'),
      required: FALSE,
    ),
  ],
)]
final class AimRemember extends ToolBase implements InputDefinitionRefinerInterface {

  /**
   * The aim memory manager.
   */
  protected AimMemoryManager $memoryManager;

  /**
   * The aim scope type plugin manager.
   */
  protected AimScopeTypePluginManagerInterface $scopeTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->memoryManager = $container->get('aim.memory_manager');
    $instance->scopeTypeManager = $container->get('plugin.manager.aim_scope_type');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The scope_fields input starts as an unconstrained map (see the
   * #[Tool] attribute above) - once scope is known, this adds whichever
   * properties that scope's AimScopeTypeInterface plugin declares via
   * getBaseFieldDefinitions() (ADR-0028 piece 1), translating each
   * BaseFieldDefinition into an InputDefinition. A scope with no plugin, or
   * whose plugin declares no fields (every scope but entity, today), leaves
   * scope_fields unconstrained - still a valid, permissive map, not an
   * error.
   */
  public function refineInputDefinition(string $name, InputDefinitionInterface $definition, array $values): InputDefinitionInterface {
    if ($name !== 'scope_fields' || empty($values['scope']) || !$definition instanceof MapInputDefinition) {
      return $definition;
    }

    $plugin = $this->scopeTypeManager->getTypePlugin((string) $values['scope']);
    $properties = [];
    foreach ($plugin?->getBaseFieldDefinitions() ?? [] as $fieldName => $fieldDefinition) {
      $properties[$fieldName] = $this->toolInputFromBaseField($fieldDefinition);
    }
    if ($properties) {
      $definition->setPropertyDefinitions($properties);
    }
    return $definition;
  }

  /**
   * Translates one aim_fact base field into a Tool API input definition.
   *
   * Only 'string' fields exist among any shipped scope type's
   * getBaseFieldDefinitions() today (target_type/target_id) - this covers
   * the field types actually in use, not every Field API type.
   *
   * @param \Drupal\Core\Field\BaseFieldDefinition $fieldDefinition
   *   The base field definition, as returned by an AimScopeTypeInterface
   *   plugin's getBaseFieldDefinitions().
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The equivalent Tool API input definition.
   */
  private function toolInputFromBaseField(BaseFieldDefinition $fieldDefinition): InputDefinitionInterface {
    $dataType = match ($fieldDefinition->getType()) {
      'boolean' => 'boolean',
      'integer' => 'integer',
      default => 'string',
    };
    return new InputDefinition(
      data_type: $dataType,
      label: $fieldDefinition->getLabel(),
      description: $fieldDefinition->getDescription() ?? '',
      required: $fieldDefinition->isRequired(),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $sourceText = ($values['source_text'] ?? '') !== '' ? (string) $values['source_text'] : NULL;
    if (!empty($values['facts'])) {
      return $this->doExecuteBatch($values['facts'], $sourceText);
    }

    if (empty($values['text'])) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Provide text (and scope), or facts for a batch.'),
        NULL,
      );
    }

    $fact = $this->rememberOne($values, $sourceText);
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
   * @param string|null $sourceText
   *   The text every entry came from, or NULL.
   *
   * @return \Drupal\tool\ExecutableResult
   *   The batch result, with a per-entry summary in the results output.
   */
  private function doExecuteBatch(array $facts, ?string $sourceText = NULL): ExecutableResult {
    $lines = [];
    $created = 0;
    if ($sourceText !== NULL) {
      // One grounding call for the whole batch; each remember() below then
      // finds its score already fetched.
      $this->memoryManager->checkGrounding($sourceText, array_filter(array_map(fn ($entry) => (string) ($entry['text'] ?? ''), $facts)));
    }
    foreach ($facts as $i => $entry) {
      if (empty($entry['text'])) {
        $lines[] = "Entry $i: missing text, skipped.";
        continue;
      }

      $fact = $this->rememberOne($entry, $sourceText);
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
   * Saves one fact, resolving a user-account-requiring scope's default subject.
   *
   * @param array $fields
   *   Text/scope/subject/source/scope_fields, as given on a single call or
   *   one facts entry.
   * @param string|null $sourceText
   *   The text the fact came from, for the grounded check, or NULL.
   *
   * @return array
   *   ['id' => int, 'bundle' => string, 'subject' => string] on success, or
   *   ['error' => string] on failure.
   */
  private function rememberOne(array $fields, ?string $sourceText = NULL): array {
    $scope = $fields['scope'] ?? NULL;
    $scopeFields = $fields['scope_fields'] ?? [];
    if (empty($scope)) {
      // No default here - which scopes exist depends entirely on which
      // aim_scope_* submodules are installed (ADR-0026), so this tool has
      // no sound default to assume.
      return ['error' => 'Scope is required: one of ' . implode(', ', $this->memoryManager->allowedScopes()) . '.'];
    }
    $subject = $fields['subject'] ?? NULL;
    if ($this->memoryManager->scopeRequiresAccount($scope) && empty($subject)) {
      $subject = (string) $this->currentUser->id();
    }

    // checkAccess() below only checks the flat "store aim memory"
    // permission, gating use of this tool at all - it does not know the
    // per-candidate scope (especially for a facts batch, where each entry
    // can have its own). Check the real per-scope "create {scope} aim
    // facts" permission here too (AimFactAccessControlHandler, "administer
    // aim memory" as bypass), the same one the admin UI's entity add form
    // already enforces per bundle.
    if (!$this->memoryManager->checkCreateAccess($scope, $this->currentUser)->isAllowed()) {
      return ['error' => 'No permission to create ' . $scope . '-scope facts.'];
    }

    try {
      $fact = $this->memoryManager->remember(
        $fields['text'],
        $scope,
        $subject,
        $fields['source'] ?? 'tool:aim_remember',
        NULL,
        [],
        NULL,
        NULL,
        $scopeFields['target_type'] ?? NULL,
        $scopeFields['target_id'] ?? NULL,
        $sourceText,
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
