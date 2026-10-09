<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Action;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Entity\AimFact;
use Drupal\aim\Service\AimMemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Soft-retires facts by stamping retired, like consolidation does.
 */
#[Action(
  id: 'aim_fact_retire',
  action_label: new TranslatableMarkup('Retire'),
  type: 'aim_fact',
)]
class RetireFactAction extends AimFactActionBase {

  /**
   * Constructs a RetireFactAction.
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
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AimMemoryManager $memoryManager, AccountProxyInterface $currentUser, protected TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $memoryManager, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('aim.memory_manager'), $container->get('current_user'), $container->get('datetime.time'));
  }

  /**
   * {@inheritdoc}
   */
  protected function apply(AimFact $fact): bool {
    if (!$fact->get('retired')->isEmpty()) {
      return FALSE;
    }
    $fact->set('retired', $this->time->getRequestTime());
    return TRUE;
  }

}
