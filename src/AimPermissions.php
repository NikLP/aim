<?php

declare(strict_types=1);

namespace Drupal\aim;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\BundlePermissionHandlerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\aim\Entity\AimScope;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides dynamic per-scope permissions for installed AimScope entities.
 *
 * Called as a permission_callbacks entry in aim.permissions.yml.
 * aim_fact's four scopes are real aim_scope config entities (see
 * CLAUDE.md's "Scope as a config entity" section), so
 * BundlePermissionHandlerTrait::generatePermissions() can be used directly,
 * same as node's NodePermissions and this codebase's own
 * AnnotationsPermissions - each generated permission carries its
 * AimScope as a config dependency, so deleting a scope removes the grant
 * from every role automatically instead of leaving a stale permission
 * string behind.
 *
 * Generates two permissions per scope:
 *
 *   view {scope} aim facts
 *   create {scope} aim facts
 */
class AimPermissions implements ContainerInjectionInterface {

  use BundlePermissionHandlerTrait;
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Returns per-scope view/create permissions.
   *
   * @return array
   *   Permission definitions keyed by machine name.
   *
   * @see \Drupal\user\PermissionHandlerInterface::getPermissions()
   */
  public function permissions(): array {
    if (!$this->entityTypeManager->hasDefinition('aim_scope')) {
      return [];
    }

    return $this->generatePermissions(
      $this->entityTypeManager->getStorage('aim_scope')->loadMultiple(),
      [$this, 'buildPermissions'],
    );
  }

  /**
   * Returns a list of permissions for a given scope.
   *
   * @param \Drupal\aim\Entity\AimScope $scope
   *   The scope.
   *
   * @return array
   *   An associative array of permission names and definitions.
   */
  protected function buildPermissions(AimScope $scope): array {
    $params = ['%label' => $scope->label()];

    return [
      $scope->getViewPermission() => [
        'title' => $this->t('%label: view AIM facts', $params),
      ],
      $scope->getCreatePermission() => [
        'title' => $this->t('%label: create AIM facts', $params),
      ],
    ];
  }

}
