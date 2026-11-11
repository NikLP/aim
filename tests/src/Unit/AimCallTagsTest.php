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
 * prompt_logging_tags is silently absent from ai_log.
 * ActivityBackendInterface::LOGGED_TAGS is the list; this test keeps it in
 * step with the tags the code uses.
 */
#[Group('aim')]
class AimCallTagsTest extends UnitTestCase {

  /**
   * Every aim_ tag in the code appears in the installer's logging list.
   */
  public function testEveryCallTagIsLogged(): void {
    $root = dirname(__DIR__, 3);

    $listed = ActivityBackendInterface::LOGGED_TAGS;

    // Inline tags: any chat() or embeddings() call that names its own tag
    // rather than going through ChatBackend::TAGS. Scans all of src/ so a
    // call moved between services is still found.
    $used = array_values(ActivityBackendInterface::TAGS);
    $inline = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      preg_match_all("/(?:chat|embeddings)\\([^;]*\\['(aim_[a-z_]+)'\\]\\)/", file_get_contents($file->getPathname()), $found);
      $inline = array_merge($inline, $found[1]);
    }
    // Guards the scan itself: it matched nothing for a while after the
    // services were split, and the test still passed.
    foreach (['aim_extract', 'aim_consolidate', 'aim_consolidate_verify', 'aim_consolidate_merge'] as $tag) {
      $this->assertContains($tag, $inline, "The scan no longer finds the inline $tag call.");
    }
    $used = array_unique(array_merge($used, $inline));

    $this->assertNotEmpty($used);
    $this->assertSame([], array_values(array_diff($used, $listed)), 'Tags used by model calls but missing from the logging list.');
  }

}
