<?php

declare(strict_types=1);

namespace Drupal\Tests\aim\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\aim\EventSubscriber\AimEmbeddingCacheSubscriber;
use PHPUnit\Framework\Attributes\Group;

/**
 * Query-time embeddings are cached; index-time ones never are (ADR-0017).
 */
#[Group('aim')]
class AimEmbeddingCacheSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   */
  protected AimEmbeddingCacheSubscriber $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    $config = $this->createStub(ConfigFactoryInterface::class);
    $config->method('get')->willReturn($this->createStub(ImmutableConfig::class));
    $this->subscriber = new AimEmbeddingCacheSubscriber(
      new MemoryBackend($time),
      $time,
      $this->createStub(LoggerChannelInterface::class),
      $config,
    );
  }

  /**
   * A second identical call is answered from the cache.
   */
  public function testSecondIdenticalQueryHits(): void {
    $this->subscriber->setActive(TRUE);
    $this->store('ollama', 'nomic', 'hello', [0.1, 0.2]);

    $pre = $this->pre('ollama', 'nomic', 'hello');
    $this->subscriber->onPreGenerateResponse($pre);
    $forced = $pre->getForcedOutputObject();
    $this->assertInstanceOf(EmbeddingsOutput::class, $forced);
    $this->assertSame([0.1, 0.2], $forced->getNormalized());
  }

  /**
   * Nothing is read or written unless the manager activated the cache.
   */
  public function testInactiveIsIgnored(): void {
    $this->store('ollama', 'nomic', 'hello', [0.1]);
    $this->assertNull($this->lookup('ollama', 'nomic', 'hello'), 'inactive: the write was skipped');
    $this->subscriber->setActive(FALSE);

    $this->subscriber->setActive(TRUE);
    $this->store('ollama', 'nomic', 'hello', [0.1]);
    $this->subscriber->setActive(FALSE);
    $pre = $this->pre('ollama', 'nomic', 'hello');
    $this->subscriber->onPreGenerateResponse($pre);
    $this->assertNull($pre->getForcedOutputObject(), 'inactive: the read was skipped');
  }

  /**
   * A bypassed cache neither reads nor writes, even when active.
   */
  public function testBypassed(): void {
    $this->subscriber->setActive(TRUE);
    $this->subscriber->setBypassed(TRUE);
    $this->store('ollama', 'nomic', 'hello', [0.1]);
    $this->subscriber->setBypassed(FALSE);
    $this->assertNull($this->lookup('ollama', 'nomic', 'hello'));
  }

  /**
   * A different model, provider or text misses; surrounding space does not.
   */
  public function testKeyIdentity(): void {
    $this->subscriber->setActive(TRUE);
    $this->store('ollama', 'nomic', 'hello', [0.1]);

    $this->assertNull($this->lookup('ollama', 'other-model', 'hello'));
    $this->assertNull($this->lookup('hosted', 'nomic', 'hello'));
    $this->assertNull($this->lookup('ollama', 'nomic', 'goodbye'));
    $this->assertSame([0.1], $this->lookup('ollama', 'nomic', '  hello '));
  }

  /**
   * Only text embeddings are cached.
   */
  public function testNonEmbeddingsAndEmptyInputIgnored(): void {
    $this->subscriber->setActive(TRUE);
    $this->subscriber->onPostGenerateResponse($this->post('ollama', 'nomic', 'hello', [0.1], 'chat'));
    $this->assertNull($this->lookup('ollama', 'nomic', 'hello'));

    $this->store('ollama', 'nomic', '', [0.1]);
    $this->assertNull($this->lookup('ollama', 'nomic', ''));
  }

  /**
   * Stores an embedding through the post event.
   */
  protected function store(string $provider, string $model, string $text, array $vector): void {
    $this->subscriber->onPostGenerateResponse($this->post($provider, $model, $text, $vector));
  }

  /**
   * Looks an embedding up through the pre event.
   *
   * @return array|null
   *   The cached vector, or NULL on a miss.
   */
  protected function lookup(string $provider, string $model, string $text): ?array {
    $this->subscriber->setActive(TRUE);
    $pre = $this->pre($provider, $model, $text);
    $this->subscriber->onPreGenerateResponse($pre);
    return $pre->getForcedOutputObject()?->getNormalized();
  }

  /**
   * Builds a pre-generate event.
   */
  protected function pre(string $provider, string $model, string $text): PreGenerateResponseEvent {
    return new PreGenerateResponseEvent('thread', $provider, 'embeddings', [], new EmbeddingsInput($text), $model);
  }

  /**
   * Builds a post-generate event.
   */
  protected function post(string $provider, string $model, string $text, array $vector, string $operation = 'embeddings'): PostGenerateResponseEvent {
    return new PostGenerateResponseEvent('thread', $provider, $operation, [], new EmbeddingsInput($text), $model, new EmbeddingsOutput($vector, NULL, []));
  }

}
