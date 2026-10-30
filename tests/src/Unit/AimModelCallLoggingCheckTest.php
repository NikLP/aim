<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aim\AimStatusChecker;
use Drupal\aim\Backend\ActivityBackendInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The aim:status logging check reads ai_logging's settings as it does.
 *
 * ADR-0060: off passes, on must record every aim tag, the replies and 90 days.
 */
#[Group('aim')]
class AimModelCallLoggingCheckTest extends UnitTestCase {

  /**
   * Each settings shape gives the expected result.
   *
   * @param array $settings
   *   The ai_logging.settings values, over a correctly set up site's.
   * @param bool $ok
   *   Whether the check should pass.
   * @param string $detail
   *   Text the detail should contain.
   */
  #[DataProvider('settingsProvider')]
  public function testCheck(array $settings, bool $ok, string $detail): void {
    $settings += [
      'prompt_logging' => TRUE,
      'prompt_logging_output' => TRUE,
      'prompt_logging_tags' => implode(',', ActivityBackendInterface::LOGGED_TAGS) . ',literals_choose',
      'prompt_logging_excluded_tags' => '',
      'prompt_logging_max_age' => 90,
    ];
    // The check reads only config, and the VDB provider manager is final
    // (no test double), so build the checker without its constructor.
    $class = new \ReflectionClass(AimStatusChecker::class);
    $checker = $class->newInstanceWithoutConstructor();
    $class->getProperty('configFactory')->setValue($checker, $this->getConfigFactoryStub(['ai_logging.settings' => $settings]));

    $result = (new \ReflectionMethod($checker, 'checkModelCallLogging'))->invoke($checker);

    $this->assertSame($ok, $result['ok'], $result['detail']);
    $this->assertStringContainsString($detail, $result['detail']);
  }

  /**
   * Settings shapes and their expected results.
   */
  public static function settingsProvider(): array {
    return [
      'set up' => [[], TRUE, 'all 6 aim tags recorded, kept 90 day(s)'],
      'off' => [['prompt_logging' => FALSE, 'prompt_logging_tags' => ''], TRUE, 'off'],
      'empty tag list records everything' => [
        ['prompt_logging_tags' => '', 'prompt_logging_max_age' => 0],
        TRUE,
        'on for every model call, kept with no age limit',
      ],
      'tags spaced and cased' => [
        ['prompt_logging_tags' => ' AIM_EXTRACT, aim_consolidate ,aim_consolidate_verify,aim_consolidate_merge,aim_ground,ai_agents_aim_chatbot'],
        TRUE,
        'all 6',
      ],
      'tag missing' => [
        ['prompt_logging_tags' => 'aim_extract,aim_ground'],
        FALSE,
        'not recorded: aim_consolidate, aim_consolidate_verify',
      ],
      'tag excluded' => [['prompt_logging_excluded_tags' => 'aim_ground'], FALSE, 'not recorded: aim_ground'],
      'replies off' => [['prompt_logging_output' => NULL], FALSE, 'replies are not recorded'],
      'short retention' => [['prompt_logging_max_age' => 30], FALSE, 'kept 30 day(s)'],
    ];
  }

}
