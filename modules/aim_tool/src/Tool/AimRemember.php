<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Tool;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Service\AimGroundingChecker;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim\Service\AimRememberService;
use Drupal\aim\Service\AimTrustPolicy;
use Drupal\tool\Attribute\ToolInput;
use Drupal\tool\Attribute\ToolMethod;
use Drupal\tool\Attribute\ToolOutput;
use Drupal\tool\ExecutableResult;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * The aim_remember tool: saves aim_facts, the same path as aim:remember.
 *
 * Unlike aim_chatbot's aim_chatbot:remember, this is not locked to
 * scope=site: a Tool API caller here is a real, authenticated Drupal
 * account (gated on the "remember aim facts" permission below - separate
 * from AimRecall's "recall aim facts", not the single blanket
 * administer aim the admin UI uses, since write and read are
 * different trust levels for a non-admin caller), not an anonymous chat
 * visitor, so scope/subject are caller-supplied - the same
 * shape ADR-0006 established for the drush CLI adapter, over Tool API/MCP
 * transport instead of a shell. See ADR-0013 for why this is a separate
 * tool from the chatbot's rather than a shared one.
 *
 * "remember aim facts" only gates use of this tool at all - rememberOne()
 * additionally checks the real per-scope "create {scope} aim facts"
 * permission for each entry's actual scope (AimMemoryManager::
 * checkCreateAccess()), so the same permission the admin UI's entity add
 * form enforces per bundle also binds this write path, not just the flat
 * permission below. It stays per entry rather than in a #[ToolMethod]
 * access method: that runs before the tool, so one entry in a scope the
 * caller cannot write would deny the whole list instead of being reported
 * and skipped.
 *
 * One shape: facts, a list of one or more entries. A single-fact form
 * beside it duplicated every field and the two drifted (2026-10-25). One
 * call saves the whole list in one MCP round trip and one Drupal bootstrap,
 * mirroring aim:remember's own --file option. Bad entries are reported and
 * skipped; the rest are saved.
 *
 * source is not an input: provenance is the channel's to record, not the
 * caller's to claim, so every fact gets tool:aim_remember (the account that
 * wrote it is in uid).
 *
 * An entry's scope_fields (ADR-0028 piece 4) carries the extra fields a
 * scope type declares via getBaseFieldDefinitions() (piece 1), today only
 * aim_scope_entity's target_type/target_id. AimToolHooks::toolInfoAlter()
 * fills its properties from the installed scopes' types and drops it from
 * the schema when none declares any; Tool API's input refiners cannot reach
 * a list entry, so this is per site, not per entry. rememberOne() reads
 * target_type/target_id by name, matching AimRememberService::remember()'s
 * two fixed parameters - a scope type adding a third field would need that
 * signature widened too; not built, see BACKLOG.md.
 *
 * A #[ToolMethod] service (ADR-0044). facts carries a full definition in
 * #[ToolInput]: a list of maps has no PHP type to infer it from.
 */
final class AimRemember {

  /**
   * The source recorded on every fact this tool saves.
   */
  const SOURCE = 'tool:aim_remember';

  /**
   * Constructs an AimRemember tool service.
   *
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager.
   * @param \Drupal\aim\Service\AimRememberService $rememberService
   *   The remember service.
   * @param \Drupal\aim\Service\AimGroundingChecker $groundingChecker
   *   The grounded check.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The calling account.
   */
  public function __construct(
    private readonly AimMemoryManager $memoryManager,
    private readonly AimRememberService $rememberService,
    private readonly AimGroundingChecker $groundingChecker,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Saves facts.
   *
   * @param array $facts
   *   The facts to save, described by the #[ToolInput] definition.
   * @param string|null $source_text
   *   The exact words every fact in facts came from: what the person said
   *   or the passage you read, quoted verbatim, not a summary. Used to
   *   check each fact is actually stated there; never stored. Omit when
   *   there is no such text.
   *
   * @return \Drupal\tool\ExecutableResult
   *   One line per entry, or a failure when facts is empty.
   */
  #[ToolMethod(
    id: 'aim_remember',
    label: new TranslatableMarkup('Remember facts'),
    description: new TranslatableMarkup('Saves one or more short, atomic facts exactly as given - no LLM call decides what is worth remembering. Put every fact in facts, one entry each, even a single one.'),
    operation: ToolOperation::Write,
    destructive: FALSE,
    permission: 'remember aim facts',
  )]
  #[ToolOutput(
    name: 'results',
    definition: new OutputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Results'),
      description: new TranslatableMarkup('Per-fact outcome, one line each: the new fact ID, the case ID for scope=case (pass it as subject to add to the same case), and whether the fact waits for review or has an end date.'),
      required: FALSE,
    ),
  )]
  public function remember(
    #[ToolInput(definition: new ListInputDefinition(
      label: new TranslatableMarkup('Facts'),
      description: new TranslatableMarkup('The facts to save, one entry each. Save everything worth keeping from one exchange in one call.'),
      required: TRUE,
      constraints: ['Count' => ['min' => 1]],
      item_definition: new MapInputDefinition(
        label: new TranslatableMarkup('Fact'),
        description: new TranslatableMarkup('One fact.'),
        property_definitions: [
          'text' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Fact text'),
            description: new TranslatableMarkup('The fact, as a short atomic statement with calendar dates ("closed until Friday 16 October 2026"), never relative ones ("until Friday"), which go stale.'),
            required: TRUE,
          ),
          'scope' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Scope'),
            // Replaced with the installed scopes and what each is for by
            // AimToolHooks::toolInfoAlter().
            description: new TranslatableMarkup('One of the scopes installed on this site.'),
            required: TRUE,
          ),
          'subject' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Subject'),
            description: new TranslatableMarkup('Who or what the fact is about, as the scope says. For a scope about a person (user), a uid of a real account; omit to default to the calling account.'),
            required: FALSE,
          ),
          'expires' => new InputDefinition(
            data_type: 'string',
            label: new TranslatableMarkup('Expires'),
            description: new TranslatableMarkup('Only when the fact has a stated end: the calendar date it stops being true, as YYYY-MM-DD. The fact is retired after that date. Omit for a fact with no end.'),
            required: FALSE,
          ),
          'scope_fields' => new MapInputDefinition(
            label: new TranslatableMarkup('Scope fields'),
            // Filled from the installed scope types, or removed when none
            // declares a field, by AimToolHooks::toolInfoAlter().
            description: new TranslatableMarkup('Extra fields the scope needs, if any - e.g. target_type/target_id for scope=entity. Omit for a scope with none.'),
            required: FALSE,
          ),
        ],
      ),
    ))]
    array $facts,
    ?string $source_text = NULL,
  ): ExecutableResult {
    if (empty($facts)) {
      return ExecutableResult::failure(
        new TranslatableMarkup('Provide facts: a list of one or more {text, scope} entries.'),
        NULL,
      );
    }
    $sourceText = ($source_text ?? '') !== '' ? $source_text : NULL;

    $lines = [];
    $created = 0;
    if ($sourceText !== NULL) {
      // One grounding call for the whole list; each remember() below then
      // finds its score already fetched.
      $this->groundingChecker->checkGrounding($sourceText, array_filter(array_map(fn ($entry) => (string) ($entry['text'] ?? ''), $facts)));
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
      $lines[] = $line . $this->statusNote($fact);
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
   * What happens to a saved fact next, when that is not "recall finds it".
   *
   * A held fact waits for review and recall skips it until then, so a
   * caller told only "Created" would report it as known. Mirrors
   * aim_chatbot's AimRemember reply.
   *
   * @param array $fact
   *   A successful rememberOne() result.
   *
   * @return string
   *   Sentences starting with a space, or an empty string.
   */
  private function statusNote(array $fact): string {
    $note = '';
    if (!$fact['trusted']) {
      $note .= ' Waiting for staff review: recall will not return it until it is approved.';
    }
    if ($fact['expires']) {
      $note .= ' It retires after ' . gmdate('Y-m-d', $fact['expires']) . '.';
    }
    return $note;
  }

  /**
   * Saves one fact, resolving a user-account-requiring scope's default subject.
   *
   * @param array $fields
   *   One facts entry: text/scope/subject/expires/scope_fields.
   * @param string|null $sourceText
   *   The text the fact came from, for the grounded check, or NULL.
   *
   * @return array
   *   ['id' => int, 'bundle' => string, 'subject' => string, 'trusted' =>
   *   bool, 'expires' => int|null] on success, or ['error' => string] on
   *   failure.
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

    // The tool's declared permission only gates use of this tool at all -
    // it does not know each entry's scope. Check the real per-scope "create
    // {scope} aim facts" permission here too (AimFactAccessControlHandler,
    // "administer aim" as bypass), the same one the admin UI's entity add
    // form already enforces per bundle.
    if (!$this->memoryManager->checkCreateAccess($scope, $this->currentUser)->isAllowed()) {
      return ['error' => 'No permission to create ' . $scope . '-scope facts.'];
    }

    try {
      $fact = $this->rememberService->remember(
        $fields['text'],
        $scope,
        $subject,
        self::SOURCE,
        [],
        NULL,
        NULL,
        $scopeFields['target_type'] ?? NULL,
        $scopeFields['target_id'] ?? NULL,
        $sourceText,
        $this->rememberService->parseExpires($fields),
        AimTrustPolicy::CHANNEL_MCP,
      );
    }
    catch (\InvalidArgumentException $e) {
      return ['error' => $e->getMessage()];
    }

    return [
      'id' => (int) $fact->id(),
      'bundle' => $fact->bundle(),
      'subject' => $fact->get('subject')->value,
      'trusted' => (bool) $fact->get('trusted')->value,
      'expires' => $fact->get('expires')->value ? (int) $fact->get('expires')->value : NULL,
    ];
  }

}
