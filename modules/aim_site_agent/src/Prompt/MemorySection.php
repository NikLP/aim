<?php

declare(strict_types=1);

namespace Drupal\aim_site_agent\Prompt;

use Drupal\Component\Plugin\Definition\PluginDefinitionInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\aim\Service\AimRememberService;
use Drupal\site_agent\Prompt\PromptSectionInterface;

/**
 * Gives Site Agent's model aim's live values, when aim's tools are offered.
 *
 * Values only: guidance on using them belongs in the hand-edited Memory
 * toolset prompt, which refers to this section by its heading. Today the one
 * value is today's date: a save works out dates and expires from it, and the
 * model has no clock. It is the date aim's writes use
 * (AimRememberService::today(), the site timezone), so the chat and the save
 * agree. aim_brief names today too, but only once called, and that reply
 * stays in the history after midnight.
 *
 * Site Agent builds the prompt before every model call, so the values are
 * never stale in its own chat. A remote client's instructions get the section
 * too, read once when it connects.
 */
final class MemorySection implements PromptSectionInterface {

  /**
   * The section's heading, which the Memory toolset prompt names.
   */
  public const HEADING = '## Memory: live values';

  /**
   * The module whose tools the section is for.
   */
  private const PROVIDER = 'aim_tool';

  /**
   * Constructs a MemorySection.
   *
   * @param \Drupal\aim\Service\AimRememberService $rememberService
   *   The remember service, for today's date.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $toolManager
   *   The Tool API plugin manager, for each tool's provider.
   */
  public function __construct(
    private readonly AimRememberService $rememberService,
    private readonly PluginManagerInterface $toolManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array $toolsets, array $route_context, string $task): string {
    if (!$this->offersAimTools($toolsets)) {
      return '';
    }
    return self::HEADING . "\n\n- Today: " . $this->rememberService->today() . " (site timezone)";
  }

  /**
   * Checks whether any of the toolsets holds one of aim's tools.
   *
   * @param array<\Drupal\site_agent\Entity\ToolsetInterface> $toolsets
   *   The toolsets.
   *
   * @return bool
   *   TRUE when a tool's definition names aim_tool as its provider.
   */
  private function offersAimTools(array $toolsets): bool {
    foreach ($toolsets as $toolset) {
      foreach ($toolset->getEntries() as $entry) {
        foreach ($entry->getToolIds() as $tool_id) {
          $definition = $this->toolManager->getDefinition($tool_id, FALSE);
          if ($definition instanceof PluginDefinitionInterface && $definition->getProvider() === self::PROVIDER) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

}
