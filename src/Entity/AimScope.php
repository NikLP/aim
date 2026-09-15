<?php

declare(strict_types=1);

namespace Drupal\aim\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Form\AimScopeForm;

/**
 * Defines the AIM scope config entity.
 *
 * Bundle of aim_fact. Named aim_scope, not aim_scope_type - aim_fact's
 * bundle field is itself called scope, not type (see CLAUDE.md's "Scope as
 * bundles" section for why type was rejected as a universal convention),
 * and each entity instance IS a scope (user/role/site/case), not a
 * category of one - the same reasoning core applies to taxonomy_vocabulary
 * over a hypothetical taxonomy_term_type.
 *
 * Still deliberately no field_ui_base_route on aim_fact - config-entity-ness
 * and Field UI exposure are independently controllable, and per-bundle
 * fields on aim_fact were tried and reverted once already (see CLAUDE.md's
 * "Scope as bundles" section). Payload is id/label only, matching what
 * hook_entity_bundle_info() already provided before this - a known,
 * accepted thinness, not an oversight.
 *
 * Add/edit/delete/collection links added 2026-09-15, not part of the
 * original conversion - required to keep entity.aim_fact.add_page from
 * crashing, see AimScopeForm's docblock. This also completes the "a scope
 * can be added through the admin UI with no code" claim CLAUDE.md already
 * made about this conversion.
 */
#[ConfigEntityType(
  id: 'aim_scope',
  label: new TranslatableMarkup('AIM scope'),
  label_collection: new TranslatableMarkup('AIM scopes'),
  label_singular: new TranslatableMarkup('scope'),
  label_plural: new TranslatableMarkup('scopes'),
  config_prefix: 'aim_scope',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => ConfigEntityListBuilder::class,
    'form' => [
      'add' => AimScopeForm::class,
      'edit' => AimScopeForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/config/aim/scopes/add',
    'edit-form' => '/admin/config/aim/scopes/{aim_scope}/edit',
    'delete-form' => '/admin/config/aim/scopes/{aim_scope}/delete',
    'collection' => '/admin/config/aim/scopes',
  ],
  admin_permission: 'administer aim memory',
  bundle_of: 'aim_fact',
  label_count: [
    'singular' => '@count scope',
    'plural' => '@count scopes',
  ],
  config_export: [
    'id',
    'label',
  ],
)]
class AimScope extends ConfigEntityBundleBase {

  /**
   * The machine name, e.g. "user", "role", "site", "case".
   */
  protected string $id;

  /**
   * Human-readable label, e.g. "User".
   */
  protected string $label;

  /**
   * Returns the permission machine name for viewing facts of this scope.
   */
  public function getViewPermission(): string {
    return 'view ' . $this->id() . ' aim facts';
  }

  /**
   * Returns the permission machine name for creating facts of this scope.
   */
  public function getCreatePermission(): string {
    return 'create ' . $this->id() . ' aim facts';
  }

}
