<?php

declare(strict_types=1);

namespace Drupal\aim\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\AimScopeListBuilder;
use Drupal\aim\Form\AimScopeDeleteForm;
use Drupal\aim\Form\AimScopeForm;

/**
 * Defines the AIM scope config entity, the bundle of aim_fact.
 *
 * The aim module ships five (site, role, user, case, entity) in
 * config/install. A site switches one off with `status`, refused while it
 * has facts, and can add more, e.g. a second scope sharing a plugin with
 * different settings (ADR-0063). `description` is the "which scope when"
 * wording models get (AimScopeGuide), `weight` the order scopes are listed
 * in. No field_ui_base_route on aim_fact, deliberately: see DEVELOPING.md.
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
    'status' => 'status',
    'weight' => 'weight',
  ],
  handlers: [
    'list_builder' => AimScopeListBuilder::class,
    'form' => [
      'add' => AimScopeForm::class,
      'edit' => AimScopeForm::class,
      'delete' => AimScopeDeleteForm::class,
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
  admin_permission: 'administer aim',
  bundle_of: 'aim_fact',
  label_count: [
    'singular' => '@count scope',
    'plural' => '@count scopes',
  ],
  config_export: [
    'id',
    'label',
    'status',
    'description',
    'weight',
    'plugin',
    'settings',
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
   * What the scope is for and what its subject is, for a model choosing one.
   */
  protected string $description = '';

  /**
   * Where the scope is listed, lightest first.
   */
  protected int $weight = 0;

  /**
   * The AimScopeType plugin ID this instance uses, if any.
   *
   * Not required to equal $id (ADR-0028 piece 3): two scopes can share a
   * plugin, each with its own $settings. NULL for a plain scope (site,
   * role) with no behavior of its own.
   */
  protected ?string $plugin = NULL;

  /**
   * Instance-level settings for $plugin, if any.
   *
   * Shape is up to the plugin, which gets them as its configuration (see
   * AimScopeTypeInterface::defaultSettings()/buildSettingsForm()).
   */
  protected array $settings = [];

  /**
   * Returns the "which scope when" wording.
   */
  public function getDescription(): string {
    return $this->description;
  }

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

  /**
   * Returns the permission machine name for trusting/untrusting facts.
   */
  public function getTrustPermission(): string {
    return 'trust ' . $this->id() . ' aim facts';
  }

}
