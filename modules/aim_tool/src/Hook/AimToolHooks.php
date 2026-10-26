<?php

declare(strict_types=1);

namespace Drupal\aim_tool\Hook;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Service\AimMemoryManager;
use Drupal\aim_tool\AimScopeGuide;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for the aim_tool module.
 */
class AimToolHooks {

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\aim_tool\AimScopeGuide $scopeGuide
   *   The scope guide.
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager, for the installed scopes.
   * @param \Drupal\aim\AimScopeTypePluginManagerInterface $scopeTypeManager
   *   The scope type plugin manager, for each scope's extra fields.
   * @param \Drupal\Core\Plugin\CachedDiscoveryClearerInterface $pluginCacheClearer
   *   The plugin cache clearer.
   */
  public function __construct(
    private readonly AimScopeGuide $scopeGuide,
    private readonly AimMemoryManager $memoryManager,
    #[Autowire(service: 'plugin.manager.aim_scope_type')]
    private readonly AimScopeTypePluginManagerInterface $scopeTypeManager,
    #[Autowire(service: 'plugin.cache_clearer')]
    private readonly CachedDiscoveryClearerInterface $pluginCacheClearer,
  ) {}

  /**
   * Implements hook_tool_info_alter().
   *
   * Fits the schema to the site rather than a hardcoded list: the installed
   * scopes and what each is for go into the scope inputs (recall the
   * one-line list, remember's facts entries the full guide, since choosing
   * a scope matters when writing), and a facts entry's scope_fields gets
   * the fields the installed scope types declare, or is dropped when none
   * declares any.
   *
   * @param array $definitions
   *   The tool definitions, keyed by tool ID.
   */
  #[Hook('tool_info_alter')]
  public function toolInfoAlter(array &$definitions): void {
    if (($definitions['aim_recall'] ?? NULL) instanceof ToolDefinition) {
      $definitions['aim_recall']->getInputDefinition('scope')->setDescription(
        'Optional. Leave empty to search every scope you can see, or restrict to one: ' . $this->scopeGuide->scopeList() . ". Search by topic; you don't need a case ID or role name to find their facts.",
      );
    }
    if (($definitions['aim_brief'] ?? NULL) instanceof ToolDefinition) {
      $definitions['aim_brief']->getInputDefinition('scope')->setDescription(
        'Optional. Leave empty for every scope, or list one: ' . $this->scopeGuide->scopeList() . '.',
      );
    }
    if (!($definitions['aim_remember'] ?? NULL) instanceof ToolDefinition) {
      return;
    }
    $list = $definitions['aim_remember']->getInputDefinition('facts');
    $entry = $list instanceof ListInputDefinition ? $list->getItemDefinition() : NULL;
    if (!$entry instanceof MapInputDefinition) {
      return;
    }
    $properties = $entry->getPropertyDefinitions();
    $properties['scope']->setDescription(
      "One of the scopes below; ask if none clearly fits, or if the role or case is unclear.\n" . $this->scopeGuide->guide(),
    );
    $scopeFields = $this->scopeFieldInputs();
    if ($scopeFields && $properties['scope_fields'] instanceof MapInputDefinition) {
      $properties['scope_fields']->setPropertyDefinitions($scopeFields);
    }
    elseif (!$scopeFields) {
      unset($properties['scope_fields']);
    }
    $entry->setPropertyDefinitions($properties);
  }

  /**
   * Implements hook_aim_scope_insert(), _update() and _delete().
   *
   * The scope descriptions above are built into cached plugin definitions,
   * so a scope added outside a module install (the scope admin form) needs
   * the caches cleared to show up.
   */
  #[Hook('aim_scope_insert')]
  #[Hook('aim_scope_update')]
  #[Hook('aim_scope_delete')]
  public function scopeChanged(): void {
    $this->pluginCacheClearer->clearCachedDefinitions();
  }

  /**
   * The extra fields the installed scopes' types declare, as tool inputs.
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface[]
   *   Inputs keyed by field name; empty when no installed scope has any.
   */
  private function scopeFieldInputs(): array {
    $inputs = [];
    foreach ($this->memoryManager->allowedScopes() as $scope) {
      foreach ($this->scopeTypeManager->getTypePlugin($scope)?->getBaseFieldDefinitions() ?? [] as $name => $field) {
        $inputs[$name] ??= $this->toolInputFromBaseField($field);
      }
    }
    return $inputs;
  }

  /**
   * Translates one aim_fact base field into a Tool API input definition.
   *
   * Only 'string' fields exist among any shipped scope type's
   * getBaseFieldDefinitions() today (target_type/target_id) - this covers
   * the field types actually in use, not every Field API type.
   *
   * @param \Drupal\Core\Field\BaseFieldDefinition $field
   *   The base field definition, as returned by an AimScopeTypeInterface
   *   plugin's getBaseFieldDefinitions().
   *
   * @return \Drupal\tool\TypedData\InputDefinitionInterface
   *   The equivalent Tool API input definition.
   */
  private function toolInputFromBaseField(BaseFieldDefinition $field): InputDefinitionInterface {
    $dataType = match ($field->getType()) {
      'boolean' => 'boolean',
      'integer' => 'integer',
      default => 'string',
    };
    return new InputDefinition(
      data_type: $dataType,
      label: $field->getLabel(),
      description: $field->getDescription() ?? '',
      required: $field->isRequired(),
    );
  }

}
