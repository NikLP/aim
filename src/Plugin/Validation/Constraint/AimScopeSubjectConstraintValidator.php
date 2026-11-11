<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Entity\AimFact;
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
    if (!$value instanceof AimFact) {
      return;
    }
    if (!$value->isNew() && !$this->subjectChanged($value)) {
      return;
    }

    $message = $this->scopeTypeManager->getTypePlugin($value->bundle())?->validateSubject($value);
    if ($message !== NULL) {
      $this->context->addViolation((string) $message);
    }
  }

  /**
   * Whether any subject field differs from the saved fact.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   A saved fact being validated.
   *
   * @return bool
   *   TRUE if a subject field changed, or the saved fact can't be loaded.
   */
  private function subjectChanged(AimFact $fact): bool {
    $original = $this->entityTypeManager->getStorage('aim_fact')->loadUnchanged($fact->id());
    if (!$original) {
      return TRUE;
    }
    foreach ($this->scopeTypeManager->getSubjectFieldNames() as $name) {
      if ($fact->get($name)->getString() !== $original->get($name)->getString()) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
