<?php

declare(strict_types=1);

namespace Drupal\Tests\aim_site_agent\Unit;

use Drupal\Component\Plugin\Definition\PluginDefinitionInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\aim\Service\AimRememberService;
use Drupal\aim_site_agent\Prompt\MemorySection;
use Drupal\site_agent\Entity\ToolsetInterface;
use Drupal\site_agent\Toolset\ToolsetEntry;
use PHPUnit\Framework\Attributes\Group;

/**
 * Site Agent's prompt gets aim's live values only when aim's tools are offered.
 */
#[Group('aim')]
class MemorySectionTest extends UnitTestCase {

  /**
   * A toolset with an aim tool gets the section; one without gets nothing.
   */
  public function testBuild(): void {
    $remember = $this->createMock(AimRememberService::class);
    $remember->method('today')->willReturn('Wednesday 2026-11-18');
    $tools = $this->createMock(PluginManagerInterface::class);
    $tools->method('getDefinition')->willReturnCallback(fn (string $id): ?PluginDefinitionInterface => match ($id) {
      'aim_recall' => $this->definition('aim_tool'),
      'entity_load' => $this->definition('tool'),
      default => NULL,
    });
    $section = new MemorySection($remember, $tools);

    $this->assertSame('', $section->build([], [], ''));
    $this->assertSame('', $section->build([$this->toolset(['entity_load', 'gone'])], [], ''));
    $this->assertSame(
      MemorySection::HEADING . "\n\n- Today: Wednesday 2026-11-18 (site timezone)",
      $section->build([$this->toolset(['entity_load']), $this->toolset(['aim_recall'])], [], ''),
    );
  }

  /**
   * Builds a tool definition from a provider.
   */
  private function definition(string $provider): PluginDefinitionInterface {
    $definition = $this->createMock(PluginDefinitionInterface::class);
    $definition->method('getProvider')->willReturn($provider);
    return $definition;
  }

  /**
   * Builds a toolset with one entry per tool.
   *
   * @param list<string> $tool_ids
   *   The tool IDs.
   */
  private function toolset(array $tool_ids): ToolsetInterface {
    $toolset = $this->createMock(ToolsetInterface::class);
    $toolset->method('getEntries')->willReturn(array_map(
      static fn (string $id): ToolsetEntry => ToolsetEntry::fromConfig(['tools' => [['id' => $id]]]),
      $tool_ids,
    ));
    return $toolset;
  }

}
