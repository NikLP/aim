<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_site_agent\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\aim\Entity\AimScope;
use Drupal\aim_site_agent\Hook\AimSiteAgentHooks;
use Drupal\site_agent\Entity\ToolCallInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The approval card of an aim_remember call lists its facts.
 */
#[Group('aim')]
class AimSiteAgentCardTest extends UnitTestCase {

  /**
   * Only an aim_remember card renders with the suggestion.
   */
  public function testSuggestion(): void {
    $hooks = $this->hooks([]);
    $suggestions = [];
    $hooks->themeSuggestionsSiteAgentCardAlter($suggestions, ['tool_id' => 'aim_recall']);
    $this->assertSame([], $suggestions);
    $hooks->themeSuggestionsSiteAgentCardAlter($suggestions, ['tool_id' => 'aim_remember']);
    $this->assertSame([AimSiteAgentHooks::SUGGESTION], $suggestions);
    $this->assertSame('site_agent_card', $hooks->theme()[AimSiteAgentHooks::SUGGESTION]['base hook']);
  }

  /**
   * Each fact becomes its text and one line of details; the source shows.
   */
  public function testFacts(): void {
    $hooks = $this->hooks([
      'facts' => [
        ['text' => 'The user prefers bullet points.', 'scope' => 'user', 'expires' => 'open-ended'],
        ['text' => 'The user is on leave.', 'scope' => 'user', 'subject' => '7', 'expires' => '2026-12-31'],
        ['text' => 'The launch is in January.', 'scope' => 'case', 'subject' => 'sci-fi'],
        [
          'text' => 'The page needs a new photo.',
          'scope' => 'entity',
          'scope_fields' => ['target_type' => 'node', 'target_id' => '5'],
        ],
        ['text' => 'Unknown scope.', 'scope' => 'nope', 'subject' => '99'],
      ],
      'source_text' => 'I like bullet points.',
    ]);
    $variables = ['tool_id' => 'aim_remember', 'record' => 1, 'description' => 'For the model.'];
    $hooks->preprocessSiteAgentCard($variables);

    $this->assertSame('From: "I like bullet points."', (string) $variables['description']);
    $this->assertSame([
      'The user prefers bullet points.' => 'scope: User - about: the account saving it - expires: open-ended',
      'The user is on leave.' => 'scope: User - about: Robin (uid 7) - expires: 2026-12-31',
      'The launch is in January.' => 'scope: Case - subject: sci-fi',
      'The page needs a new photo.' => 'scope: Entity - target_type: node - target_id: 5',
      'Unknown scope.' => 'scope: nope - subject: 99',
    ], array_column($variables['facts'], 'details', 'text'));
  }

  /**
   * Another tool's card, or a call with no facts, is left alone.
   */
  public function testOtherCardsUntouched(): void {
    $hooks = $this->hooks(['text' => 'opening hours']);
    foreach (['aim_recall', 'aim_remember'] as $tool_id) {
      $variables = ['tool_id' => $tool_id, 'record' => 1, 'description' => 'For the model.'];
      $hooks->preprocessSiteAgentCard($variables);
      $this->assertSame(['tool_id' => $tool_id, 'record' => 1, 'description' => 'For the model.'], $variables);
    }
  }

  /**
   * Builds the hooks over a call with the given arguments.
   *
   * @param array $arguments
   *   The call's arguments.
   *
   * @return \Drupal\aim_site_agent\Hook\AimSiteAgentHooks
   *   The hooks, with the user, case and entity scopes and account 7.
   */
  private function hooks(array $arguments): AimSiteAgentHooks {
    $record = $this->createMock(ToolCallInterface::class);
    $record->method('getArguments')->willReturn($arguments);

    $scopes = [];
    foreach (['user' => 'User', 'case' => 'Case', 'entity' => 'Entity'] as $id => $label) {
      $scope = $this->createMock(AimScope::class);
      $scope->method('label')->willReturn($label);
      $scope->method('get')->willReturnMap([['plugin', $id]]);
      $scopes[$id] = $scope;
    }
    $account = $this->createMock(UserInterface::class);
    $account->method('getDisplayName')->willReturn('Robin');

    $storages = [
      'site_agent_tool_call' => [1 => $record],
      'aim_scope' => $scopes,
      'user' => [7 => $account],
    ];
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(function (string $entity_type_id) use ($storages) {
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturnCallback(fn ($id) => $storages[$entity_type_id][$id] ?? NULL);
      return $storage;
    });

    $hooks = new AimSiteAgentHooks($entity_type_manager);
    $hooks->setStringTranslation($this->getStringTranslationStub());
    return $hooks;
  }

}
