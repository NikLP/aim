<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * What recall() asks the index for, and what it drops afterwards.
 *
 * The search backend is FakeVectorSearch, which applies the query's
 * conditions to the stored facts and ranks them by a score the test sets, so
 * the conditions recall() builds (scope, subject, user, trusted) and the
 * filtering it does itself (retired, distance, gap, limit) are tested without
 * a vector store.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimRecallFilterTest extends KernelTestBase {

  use FakeVectorSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'key',
    'taxonomy',
    'views',
    'ai',
    'search_api',
    'search_api_test',
    'literals',
    'aim',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('literal');
    $this->installEntitySchema('aim_fact');

    $path = $this->container->get('extension.list.module')->getPath('aim');
    $this->config('aim.settings')
      ->setData(Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml')))
      ->save();

    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    AimScope::create(['id' => 'case', 'label' => 'Case'])->save();
    AimScope::create([
      'id' => 'user',
      'label' => 'User',
      'settings' => ['requires_account' => TRUE],
    ])->save();

    $this->installFakeVectorIndex();

    // Uid 1 bypasses every access check; keep it out of the way.
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
  }

  /**
   * Untrusted facts are left out unless asked for.
   */
  public function testUntrustedExcludedByDefault(): void {
    $this->fact('Trusted.');
    $this->fact('Untrusted.', ['trusted' => FALSE]);

    $this->assertSame(['Trusted.'], $this->texts());
    $this->assertEqualsCanonicalizing(['Trusted.', 'Untrusted.'], $this->texts(includeUntrusted: TRUE));
  }

  /**
   * Retired facts are skipped even if the index returns them.
   */
  public function testRetiredSkipped(): void {
    $this->fact('Live.');
    $this->fact('Gone.', ['retired' => 1000]);

    $this->assertSame(['Live.'], $this->texts());
    $this->assertSame(['Live.'], $this->texts(includeUntrusted: TRUE));
  }

  /**
   * A past-due fact is not recalled, even before the expiry sweep retires it.
   */
  public function testExpiredNotRecalledBeforeSweep(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->fact('Live.');
    $this->fact('Later.', ['expires' => $now + 86400]);
    $this->fact('Past due.', ['expires' => $now - 100]);

    $this->assertEqualsCanonicalizing(['Live.', 'Later.'], $this->texts());
  }

  /**
   * Scope and subject narrow the results.
   */
  public function testScopeAndSubjectFilters(): void {
    $this->fact('Site fact.');
    $this->fact('Case A.', ['scope' => 'case', 'subject' => 'case-a']);
    $this->fact('Case B.', ['scope' => 'case', 'subject' => 'case-b']);

    $this->assertEqualsCanonicalizing(['Site fact.', 'Case A.', 'Case B.'], $this->texts(scope: NULL));
    $this->assertSame(['Site fact.'], $this->texts(scope: 'site'));
    $this->assertEqualsCanonicalizing(['Case A.', 'Case B.'], $this->texts(scope: 'case'));
    $this->assertSame(['Case B.'], $this->texts(scope: 'case', subject: 'case-b'));
  }

  /**
   * The user filter takes a uid or a username, reports the uid as subject.
   */
  public function testUserFilter(): void {
    $alex = User::create(['name' => 'alex', 'status' => 1]);
    $alex->save();
    $sam = User::create(['name' => 'sam', 'status' => 1]);
    $sam->save();
    $this->fact('About Alex.', ['scope' => 'user', 'user' => $alex->id()]);
    $this->fact('About Sam.', ['scope' => 'user', 'user' => $sam->id()]);

    $rows = $this->recall(scope: 'user', subjectUid: (string) $alex->id());
    $this->assertSame(['About Alex.'], array_column($rows, 'text'));
    $this->assertSame((int) $alex->id(), (int) $rows[0]['subject']);

    $this->assertSame(['About Sam.'], $this->texts(scope: 'user', subjectUid: 'sam'));

    $this->expectException(\InvalidArgumentException::class);
    $this->recall(scope: 'user', subjectUid: '9999');
  }

  /**
   * Results come back best score first and stop at the limit.
   */
  public function testOrderAndLimit(): void {
    $far = $this->fact('Far.');
    $near = $this->fact('Near.');
    $mid = $this->fact('Mid.');
    $this->setScores([$far->id() => 0.4, $near->id() => 0.1, $mid->id() => 0.2]);

    $this->assertSame(['Near.', 'Mid.', 'Far.'], $this->texts());
    $this->assertSame(['Near.', 'Mid.'], $this->texts(limit: 2));
  }

  /**
   * The user filter widens the index request but still honors the limit.
   */
  public function testUserFilterHonorsLimit(): void {
    $alex = User::create(['name' => 'alex', 'status' => 1]);
    $alex->save();
    foreach (['One.', 'Two.', 'Three.'] as $text) {
      $this->fact($text, ['scope' => 'user', 'user' => $alex->id()]);
    }
    $this->assertCount(2, $this->recall(scope: 'user', subjectUid: (string) $alex->id(), limit: 2));
  }

  /**
   * Max distance drops anything farther away; the boundary is kept.
   */
  public function testMaxDistance(): void {
    $a = $this->fact('Close.');
    $b = $this->fact('Edge.');
    $c = $this->fact('Far.');
    $this->setScores([$a->id() => 0.2, $b->id() => 0.48, $c->id() => 0.49]);

    $this->assertSame(['Close.', 'Edge.'], $this->texts(maxDistance: 0.48));
    $this->assertCount(3, $this->recall());
  }

  /**
   * The gap keeps only results within it of the best one.
   */
  public function testGap(): void {
    $a = $this->fact('Best.');
    $b = $this->fact('Near best.');
    $c = $this->fact('Too far.');
    $this->setScores([$a->id() => 0.1, $b->id() => 0.2, $c->id() => 0.5]);

    $this->assertSame(['Best.', 'Near best.'], $this->texts(gap: 0.15));
    $this->assertCount(3, $this->recall());
  }

  /**
   * Saves a trusted site fact unless the values say otherwise.
   *
   * @param string $text
   *   The fact text.
   * @param array $values
   *   Field values overriding the defaults.
   *
   * @return \Drupal\aim\Entity\AimFact
   *   The saved fact.
   */
  protected function fact(string $text, array $values = []): AimFact {
    $fact = AimFact::create($values + [
      'scope' => 'site',
      'text' => $text,
      'trusted' => TRUE,
      'uid' => 1,
    ]);
    $fact->save();
    return $fact;
  }

  /**
   * Calls recall() with test defaults.
   *
   * @return array
   *   The rows.
   */
  protected function recall(?string $scope = NULL, ?string $subject = NULL, ?string $subjectUid = NULL, int $limit = 10, ?float $maxDistance = NULL, bool $includeUntrusted = FALSE, ?float $gap = NULL): array {
    return $this->container->get('aim.recall')->recall('anything', $scope, $subject, $subjectUid, $limit, $maxDistance, $includeUntrusted, $gap, FALSE);
  }

  /**
   * Calls recall() and returns the texts.
   *
   * @return string[]
   *   The recalled texts, best first.
   */
  protected function texts(?string $scope = NULL, ?string $subject = NULL, ?string $subjectUid = NULL, int $limit = 10, ?float $maxDistance = NULL, bool $includeUntrusted = FALSE, ?float $gap = NULL): array {
    return array_column($this->recall($scope, $subject, $subjectUid, $limit, $maxDistance, $includeUntrusted, $gap), 'text');
  }

}
