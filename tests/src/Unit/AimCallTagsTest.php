<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aim\Backend\ActivityBackendInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every tag aim puts on a model call is one ai_logging records (ADR-0060).
 *
 * The logging module matches tags exactly, so a call with a tag missing from
 * prompt_logging_tags is silently absent from ai_log. The installer's list is
 * the source of truth for fresh sites; this test keeps it in step with the
 * tags the code uses.
 */
#[Group('aim')]
class AimCallTagsTest extends UnitTestCase {

  /**
   * Every aim_ tag in the code appears in the installer's logging list.
   */
  public function testEveryCallTagIsLogged(): void {
    $root = dirname(__DIR__, 3);

    $install = file_get_contents($root . '/aim.install');
    $this->assertSame(1, preg_match("/\\\$tags = \\[([^\\]]+)\\];/", $install, $match), 'Found the installer tag list.');
    preg_match_all("/'(aim_[a-z_]+)'/", $match[1], $listed);

    $used = array_values(ActivityBackendInterface::TAGS);
    $manager = file_get_contents($root . '/src/Service/AimMemoryManager.php');
    preg_match_all("/(?:chat|embeddings)\\([^;]*\\['(aim_[a-z_]+)'\\]\\)/", $manager, $inline);
    $used = array_unique(array_merge($used, $inline[1]));

    $this->assertNotEmpty($used);
    $this->assertSame([], array_values(array_diff($used, $listed[1])), 'Tags used by model calls but missing from the logging list.');
  }

}
