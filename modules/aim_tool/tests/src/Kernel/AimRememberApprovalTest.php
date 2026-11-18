<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_tool\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\aim\Kernel\FakeDecisionBackend;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Entity\AimScope;
use Drupal\site_agent\Tool\SiteAgentInvokerCapability;
use Drupal\tool\Tool\Invoker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a call a person approved skips the grounded check (ADR-0067).
 */
#[Group('aim')]
#[RunTestsInSeparateProcesses]
class AimRememberApprovalTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The fact the model wrote, reworded from the source.
   */
  protected const TEXT = 'Sam is allergic to peanuts.';

  /**
   * The words it came from.
   */
  protected const SOURCE = "I'm allergic to peanuts.";

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
    'serialization',
    'ai',
    'search_api',
    'literals',
    'tool',
    'aim',
    'aim_tool',
  ];

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
    if (!enum_exists(SiteAgentInvokerCapability::class)) {
      $this->markTestSkipped('Needs site_agent with the PersonApproved capability (ADR-0067 patch).');
    }
    $this->installEntitySchema('user');
    $this->installEntitySchema('aim_fact');
    $path = $this->container->get('extension.list.module')->getPath('aim');
    $settings = Yaml::decode(file_get_contents($path . '/config/install/aim.settings.yml'));
    $settings['activities']['grounding'] = [
      'backend' => 'decision',
      'provider' => 'fake',
      'model' => 'fake-model',
      'threshold' => 0.7,
    ] + $settings['activities']['grounding'];
    $settings['grounding_mode'] = 'demote';
    $this->config('aim.settings')->setData($settings)->save();
    AimScope::create(['id' => 'site', 'label' => 'Site'])->save();
    // Uid 1 bypasses every access check; keep it out of the way.
    $this->createUser([], 'root');
    $this->container->get('current_user')->setAccount($this->createUser([
      'remember aim facts',
      'create site aim facts',
      'trust site aim facts',
    ]));
    /** @var \Drupal\Tests\aim\Kernel\FakeDecisionBackend $backend */
    $backend = $this->container->get('aim.backend.decision');
    $backend->scores = [self::TEXT => 0.1];
  }

  /**
   * An approved call saves trusted with no score; an unapproved one demotes.
   */
  public function testApprovedCallSkipsGrounding(): void {
    $approved = $this->rememberAs(new Invoker('site_agent', SiteAgentInvokerCapability::PersonApproved));
    $this->assertTrue((bool) $approved->get('trusted')->value);
    $this->assertNull($approved->get('grounding_score')->value);

    $unapproved = $this->rememberAs(new Invoker('site_agent'));
    $this->assertFalse((bool) $unapproved->get('trusted')->value);
    $this->assertEqualsWithDelta(0.1, (float) $unapproved->get('grounding_score')->value, 0.001);
  }

  /**
   * Saves the fact through aim_remember with the given invoker.
   */
  protected function rememberAs(Invoker $invoker): AimFact {
    $this->container->get('plugin.manager.tool')->createInstance('aim_remember', [], $invoker)
      ->setInputValue('facts', [['text' => self::TEXT, 'scope' => 'site']])
      ->setInputValue('source_text', self::SOURCE)
      ->execute();
    $facts = AimFact::loadMultiple();
    $fact = end($facts);
    $this->assertInstanceOf(AimFact::class, $fact);
    return $fact;
  }

}
