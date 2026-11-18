<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Kernel;

use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\aim\Service\AimMemoryManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deleting a fact releases the retired facts that pointed at it.
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimFactDeleteTest extends AimKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
  }

  /**
   * Predecessors stay retired, lose the reference, and record the ID.
   */
  public function testDeleteReleasesPredecessors(): void {
    $merged = $this->fact('Merged.');
    $other = $this->fact('Other.');
    $a = $this->fact('A.', $merged, '{"decision":"UPDATE"}');
    $b = $this->fact('B.', $merged);
    $c = $this->fact('C.', $other, '{"decision":"NOOP"}');

    $merged->delete();

    $a = $this->reload($a);
    $this->assertNotEmpty($a->get('retired')->value, 'still retired');
    $this->assertTrue($a->get('superseded_by')->isEmpty());
    $this->assertSame(['decision' => 'UPDATE', 'successor_deleted' => (int) $merged->id()], json_decode($a->get('superseded_by_reason')->value, TRUE));
    $this->assertSame(['successor_deleted' => (int) $merged->id()], json_decode($this->reload($b)->get('superseded_by_reason')->value, TRUE), 'a missing reason starts fresh');
    $this->assertCount(0, $a->validate()->getByField('superseded_by'), 'no missing-reference violation');
    $this->assertSame($other->id(), $this->reload($c)->get('superseded_by')->target_id, 'unrelated fact untouched');
  }

  /**
   * A predecessor deleted in the same bulk delete is not saved again.
   */
  public function testBulkDeleteWithPredecessor(): void {
    $merged = $this->fact('Merged.');
    $a = $this->fact('A.', $merged);
    $b = $this->fact('B.', $merged);

    $storage = $this->container->get('entity_type.manager')->getStorage('aim_fact');
    $storage->delete([$merged, $a]);

    $storage->resetCache();
    $this->assertNull($storage->load($a->id()));
    $this->assertTrue($this->reload($b)->get('superseded_by')->isEmpty());
    $this->assertSame([], $this->container->get(AimMemoryManager::class)->supersededBy([(int) $merged->id()]));
  }

  /**
   * Creates a fact, retired in favor of $successor if given.
   */
  protected function fact(string $text, ?AimFact $successor = NULL, ?string $reason = NULL): AimFact {
    $fact = AimFact::create(['scope' => 'site', 'text' => $text, 'uid' => 1]);
    if ($successor) {
      $fact->set('retired', 1000);
      $fact->set('superseded_by', $successor->id());
      $fact->set('superseded_by_reason', $reason);
    }
    $fact->save();
    return $fact;
  }

  /**
   * Reloads a fact from storage.
   */
  protected function reload(AimFact $fact): AimFact {
    $this->container->get('entity_type.manager')->getStorage('aim_fact')->resetCache([$fact->id()]);
    return AimFact::load($fact->id());
  }

}
