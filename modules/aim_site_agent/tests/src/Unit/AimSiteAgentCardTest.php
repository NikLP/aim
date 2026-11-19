<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_site_agent\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\aim\Entity\AimScope;
use Drupal\aim\Service\AimRememberService;
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
        ['text' => 'Closed on Mondays.', 'scope' => 'case', 'expires' => '2024-06-30'],
        ['text' => 'Closed soon.', 'scope' => 'case', 'expires' => 'unknown'],
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
      'Closed on Mondays.' => 'scope: Case - expires: 2024-06-30 (already past, today is Monday 2026-11-16; the save will fail)',
      'Closed soon.' => 'scope: Case - expires: unknown (not a date, the save will fail)',
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
   * An aim_remember result card lists only its facts, trusted or waiting.
   */
  public function testResultCard(): void {
    $items = [
      ['target' => 'ai_log:788', 'status' => 'Published', 'version' => '', 'rows' => []],
      [
        'target' => 'aim_fact:3',
        'status' => 'Published',
        'version' => '9',
        'rows' => [['label' => 'Text']],
        'view_url' => '/f/3',
      ],
      ['target' => 'aim_fact:4', 'status' => 'Published', 'version' => '', 'rows' => [], 'view_url' => '/f/4'],
    ];
    $variables = [
      'record' => 1,
      'heading' => '3 items written',
      'status' => 'None of these is a draft.',
      'items' => $items,
      'more' => '',
      'publish_url' => '/publish',
    ];
    $this->hooks([], 'aim_remember', [3 => TRUE, 4 => FALSE])->preprocessSiteAgentItemsResultCard($variables);
    $this->assertSame(['aim_fact:3', 'aim_fact:4'], array_column($variables['items'], 'target'));
    $this->assertSame(['Trusted', 'Waiting for review'], array_map('strval', array_column($variables['items'], 'status')));
    $this->assertSame(['', ''], array_column($variables['items'], 'version'));
    $this->assertSame([[], []], array_column($variables['items'], 'rows'));
    $this->assertSame([NULL, NULL], array_column($variables['items'], 'view_url'));

    // An aim administrator keeps the View links.
    $admin = ['record' => 1, 'items' => $items];
    $this->hooks([], 'aim_remember', [3 => TRUE, 4 => FALSE], TRUE)->preprocessSiteAgentItemsResultCard($admin);
    $this->assertSame(['/f/3', '/f/4'], array_column($admin['items'], 'view_url'));
    $this->assertSame('2 facts saved', (string) $variables['heading']);
    $this->assertStringContainsString('waiting for review', (string) $variables['status']);
    $this->assertNull($variables['publish_url']);

    // Another tool's card is left alone.
    $other = ['record' => 1, 'heading' => '3 items written', 'items' => $items];
    $copy = $other;
    $this->hooks([], 'entity_save')->preprocessSiteAgentItemsResultCard($other);
    $this->assertSame($copy, $other);
  }

  /**
   * Builds the hooks over a call with the given arguments.
   *
   * @param array $arguments
   *   The call's arguments.
   * @param string $tool_id
   *   The call's tool.
   * @param array<int, bool> $facts
   *   The facts saved, as whether each is trusted, keyed by id.
   * @param bool $admin
   *   Whether the viewing account may administer aim.
   *
   * @return \Drupal\aim_site_agent\Hook\AimSiteAgentHooks
   *   The hooks, with the user, case and entity scopes and account 7.
   */
  private function hooks(array $arguments, string $tool_id = 'aim_remember', array $facts = [], bool $admin = FALSE): AimSiteAgentHooks {
    $record = $this->createMock(ToolCallInterface::class);
    $record->method('getArguments')->willReturn($arguments);
    $record->method('getToolId')->willReturn($tool_id);

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
      'aim_fact' => array_map(function (bool $trusted): FieldableEntityInterface {
        $field = $this->createMock(FieldItemListInterface::class);
        $field->method('getString')->willReturn($trusted ? '1' : '0');
        $fact = $this->createMock(FieldableEntityInterface::class);
        $fact->method('get')->willReturnMap([['trusted', $field]]);
        return $fact;
      }, $facts),
    ];
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->willReturnCallback(function (string $entity_type_id) use ($storages) {
      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('load')->willReturnCallback(fn ($id) => $storages[$entity_type_id][$id] ?? NULL);
      return $storage;
    });

    // The real service's date handling, with today pinned to 2026-11-16.
    $remember = $this->getMockBuilder(AimRememberService::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['isPast', 'today', 'siteTimeZone'])
      ->getMock();
    $today = strtotime('2026-11-16 12:00:00 UTC');
    $remember->method('isPast')->willReturnCallback(fn (int $expires) => $expires <= $today);
    $remember->method('today')->willReturn('Monday 2026-11-16');
    $remember->method('siteTimeZone')->willReturn(new \DateTimeZone('UTC'));

    $viewer = $this->createMock(AccountInterface::class);
    $viewer->method('hasPermission')->willReturnCallback(fn (string $permission): bool => $admin && $permission === 'administer aim');

    $hooks = new AimSiteAgentHooks($entity_type_manager, $remember, $viewer);
    $hooks->setStringTranslation($this->getStringTranslationStub());
    return $hooks;
  }

}
