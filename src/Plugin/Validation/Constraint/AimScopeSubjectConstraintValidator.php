<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates AimScopeSubjectConstraint through the scope's plugin.
 */
final class AimScopeSubjectConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * Constructs an AimScopeSubjectConstraintValidator object.
   *
   * @param \Drupal\aim\AimScopeTypePluginManagerInterface $scopeTypeManager
   *   The scope type plugin manager, for the fact's scope plugin.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, to compare an edited fact's subject.
   */
  public function __construct(
    #[Autowire(service: 'plugin.manager.aim_scope_type')]
    protected AimScopeTypePluginManagerInterface $scopeTypeManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint): void {
    if (!$value instanceof FieldItemListInterface) {
      return;
    }
    $fact = $value->getEntity();
    $subject = (string) $value->value;
    if (!$fact->isNew()) {
      $original = $this->entityTypeManager->getStorage('aim_fact')->loadUnchanged($fact->id());
      if ($original && $subject === (string) $original->get('subject')->value) {
        return;
      }
    }

    $message = $this->scopeTypeManager->getTypePlugin($fact->bundle())?->validateSubject($subject);
    if ($message !== NULL) {
      $this->context->addViolation((string) $message);
    }
  }

}
