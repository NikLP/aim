<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for bulk actions that change one field on an aim_fact.
 *
 * Saves through the entity API so search_api's index tracking (or
 * index_directly) picks the change up, which is what pulls a retired fact
 * out of the vector index via aim_exclude_retired (ADR-0022).
 */
abstract class AimFactActionBase extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs an action plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\aim\Service\AimMemoryManager $memoryManager
   *   The aim memory manager, used for audit logging.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user, recorded in the audit log.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected AimMemoryManager $memoryManager, protected AccountProxyInterface $currentUser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('aim.memory_manager'), $container->get('current_user'));
  }

  /**
   * Applies this action's change to the fact.
   *
   * @param \Drupal\aim\Entity\AimFact $fact
   *   The fact to change.
   *
   * @return bool
   *   TRUE if the fact changed and needs saving.
   */
  abstract protected function apply(AimFact $fact): bool;

  /**
   * Returns the entity access operation this action requires.
   *
   * @return string
   *   'update' by default; the trust/untrust actions use 'trust'.
   */
  protected function accessOperation(): string {
    return 'update';
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof AimFact && $this->apply($entity)) {
      $entity->save();
      $this->memoryManager->logAudit('Action @action applied to fact @id (scope @scope, uid @uid).', [
        '@action' => $this->getPluginId(),
        '@id' => $entity->id(),
        '@scope' => $entity->bundle(),
        '@uid' => $this->currentUser->id(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $object->access($this->accessOperation(), $account, TRUE);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
