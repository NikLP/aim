<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\aim\Service\AimSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which settings with no default in code must be set.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
final class AimRequiredSettingsTest extends AimKernelTestBase {

  /**
   * A chat-only site reads no decision-model threshold.
   */
  public function testChatOnlyNeedsNoThresholds(): void {
    $settings = $this->container->get('aim.settings_reader');
    $this->assertSame(['compare_distance', 'neighbor_limit', 'recall_max_distance'], $settings->requiredKeysInUse());

    // Dropping a threshold nothing reads breaks nothing.
    $this->config('aim.settings')->clear('activities.grounding.threshold')->clear('activities.verifier.threshold')->save();
    $this->assertSame(['compare_distance', 'neighbor_limit', 'recall_max_distance'], $settings->requiredKeysInUse());
  }

  /**
   * Each decision-model check adds the keys it reads.
   */
  public function testDecisionChecksNeedTheirKeys(): void {
    $model = ['backend' => 'decision', 'provider' => 'fake', 'model' => 'fake-model'];
    $config = $this->config('aim.settings');
    $config->set('activities.verifier', $model + $config->get('activities.verifier'))->save();
    $settings = $this->container->get('aim.settings_reader');
    $this->assertContains('activities.verifier.threshold', $settings->requiredKeysInUse());

    // The verifier's threshold is not read with the verifier switched off.
    $config->set('merge_verify', FALSE)->save();
    $this->assertNotContains('activities.verifier.threshold', $settings->requiredKeysInUse());

    $config->set('activities.grounding', $model + $config->get('activities.grounding'))->set('time_check_mode', 'flag')->save();
    $this->assertSame([
      'compare_distance',
      'neighbor_limit',
      'recall_max_distance',
      'activities.grounding.time_threshold',
      'activities.grounding.max_questions',
    ], $settings->requiredKeysInUse());

    $config->set('grounding_mode', 'demote')->save();
    $this->assertContains('activities.grounding.threshold', $settings->requiredKeysInUse());

    // A key in use that is missing fails loudly, naming the key.
    $config->clear('activities.grounding.threshold')->save();
    $this->expectExceptionMessage('aim.settings:activities.grounding.threshold is not set');
    $settings->getGroundingThreshold();
  }

  /**
   * Every key in use is one of the required keys.
   */
  public function testInUseKeysAreRequiredKeys(): void {
    $this->assertSame([], array_diff($this->container->get('aim.settings_reader')->requiredKeysInUse(), AimSettings::REQUIRED_KEYS));
  }

}
