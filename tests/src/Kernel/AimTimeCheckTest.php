<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimScope;
use Drupal\aim\Exception\AimTimeBoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The time check at write time (ADR-0057 addendum).
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimTimeCheckTest extends AimKernelTestBase {

  use UserCreationTrait;

  /**
   * A candidate only true for a period.
   */
  protected const BOUND = 'The reading room is closed until 16 October 2030.';

  /**
   * A standing candidate.
   */
  protected const STANDING = 'Nik is allergic to peanuts.';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('aim.backend.decision', FakeDecisionBackend::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $settings = $this->config('aim.settings')->get();
    $settings['activities']['grounding'] = [
      'backend' => 'decision',
      'provider' => 'fake',
      'model' => 'fake-model',
      'threshold' => 0.7,
    ];
    $settings['time_check_mode'] = 'flag';
    $this->config('aim.settings')->setData($settings)->save();
    $this->config('system.date')->set('timezone.default', 'Pacific/Auckland')->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    $this->setUpCurrentUser([], ['trust site aim facts']);

    $this->backend()->timeScores = [self::BOUND => 0.9, self::STANDING => 0.1];
  }

  /**
   * A caller that can be asked gets a retry error, not a saved fact.
   */
  public function testToolStyleCallerIsAskedForTheDate(): void {
    try {
      $this->service()->remember(self::BOUND, 'site', NULL, 'test', askForDate: TRUE);
      $this->fail('A time-bound fact with no expires should have been refused.');
    }
    catch (AimTimeBoundException $e) {
      $this->assertStringContainsString('open-ended', $e->getMessage());
    }
    $this->assertSame(0, (int) $this->entityCount());

    // Either answer saves it: an end date, or an explicit open end.
    $dated = $this->service()->remember(self::BOUND, 'site', NULL, 'test', expires: strtotime('2031-01-01'), askForDate: TRUE);
    $this->assertTrue((bool) $dated->get('trusted')->value);
    $open = $this->service()->remember(self::BOUND . ' Again.', 'site', NULL, 'test', openEnded: TRUE, askForDate: TRUE);
    $this->assertTrue((bool) $open->get('trusted')->value);
    $this->assertSame(2, (int) $this->entityCount());
  }

  /**
   * Flag mode saves a time-bound fact untrusted where no one can be asked.
   */
  public function testFlagSavesUntrusted(): void {
    $this->assertFalse((bool) $this->service()->remember(self::BOUND, 'site', NULL, 'test')->get('trusted')->value);
    $this->assertTrue((bool) $this->service()->remember(self::STANDING, 'site', NULL, 'test')->get('trusted')->value);
  }

  /**
   * Reject mode refuses it there instead.
   */
  public function testRejectRefuses(): void {
    $this->config('aim.settings')->set('time_check_mode', 'reject')->save();
    $this->expectException(AimTimeBoundException::class);
    $this->service()->remember(self::BOUND, 'site', NULL, 'test');
  }

  /**
   * Extraction flags or blocks a time-bound candidate, in one call.
   */
  public function testExtractionCandidates(): void {
    $facts = [
      ['scope' => 'site', 'subject' => '', 'text' => self::BOUND],
      ['scope' => 'site', 'subject' => '', 'text' => self::STANDING],
    ];
    $result = $this->service()->createFactsFromCandidates($facts, 'test');
    $this->assertSame(1, $this->backend()->calls);
    $this->assertFalse((bool) $result['created'][0]->get('trusted')->value);
    $this->assertTrue((bool) $result['created'][1]->get('trusted')->value);

    $this->config('aim.settings')->set('time_check_mode', 'reject')->save();
    $this->backend()->timeScores[self::BOUND . ' Again.'] = 0.9;
    $again = ['scope' => 'site', 'subject' => '', 'text' => self::BOUND . ' Again.'];
    $result = $this->service()->createFactsFromCandidates([$again], 'test');
    $this->assertSame(1, $result['blocked']);
    $this->assertSame([], $result['created']);
  }

  /**
   * With grounding on, the time question shares the grounding call.
   */
  public function testSharesTheGroundingCall(): void {
    $this->config('aim.settings')->set('grounding_mode', 'record')->save();
    $this->backend()->scores = [self::BOUND => 0.95];
    $fact = $this->service()->remember(self::BOUND, 'site', NULL, 'test', sourceText: 'The reading room is closed until 16 October 2030.');
    $this->assertSame(1, $this->backend()->calls);
    $this->assertEqualsWithDelta(0.95, (float) $fact->get('grounding_score')->value, 0.001);
    $this->assertFalse((bool) $fact->get('trusted')->value);
  }

  /**
   * Off, or no model, or a failed check, changes nothing.
   */
  public function testOffNoModelAndFailureChangeNothing(): void {
    $this->config('aim.settings')->set('time_check_mode', 'off')->save();
    $this->assertTrue((bool) $this->service()->remember(self::BOUND, 'site', NULL, 'test', askForDate: TRUE)->get('trusted')->value);
    $this->assertSame(0, $this->backend()->calls);

    $this->config('aim.settings')->set('time_check_mode', 'flag')->set('activities.grounding.model', '')->save();
    $this->assertTrue((bool) $this->service()->remember(self::BOUND . ' 2.', 'site', NULL, 'test', askForDate: TRUE)->get('trusted')->value);

    $this->config('aim.settings')->set('activities.grounding.model', 'fake-model')->save();
    $this->backend()->fail = TRUE;
    $this->assertTrue((bool) $this->service()->remember(self::BOUND . ' 3.', 'site', NULL, 'test', askForDate: TRUE)->get('trusted')->value);
  }

  /**
   * Expires is the last day, inclusive, in the site timezone.
   */
  public function testExpiresIsEndOfSiteDay(): void {
    $service = $this->service();
    $end = $service->parseExpires(['expires' => '2031-03-05']);
    $local = (new \DateTimeImmutable('@' . $end))->setTimezone(new \DateTimeZone('Pacific/Auckland'));
    $this->assertSame('2031-03-05 23:59:59', $local->format('Y-m-d H:i:s'));
    $this->assertSame('2031-03-05', $service->formatDay($end));
    $this->assertNull($service->parseExpires(['expires' => 'open-ended']));
    $this->assertTrue($service->isOpenEnded(['expires' => 'Open-ended']));
    $this->assertFalse($service->isOpenEnded(['expires' => '2031-03-05']));
  }

  /**
   * Counts the saved facts.
   *
   * @return int
   *   The count.
   */
  protected function entityCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('aim_fact')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Returns the remember service.
   *
   * @return \Drupal\aim\Service\AimRememberService
   *   The service.
   */
  protected function service() {
    return $this->container->get('aim.remember');
  }

  /**
   * Returns the fake decision backend.
   *
   * @return \Drupal\Tests\aim\Kernel\FakeDecisionBackend
   *   The fake.
   */
  protected function backend(): FakeDecisionBackend {
    return $this->container->get('aim.backend.decision');
  }

}
