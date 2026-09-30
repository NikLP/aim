<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\Action;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Entity\AimFact;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Soft-retires facts by stamping expires, like consolidation does.
 */
#[Action(
  id: 'aim_fact_retire',
  action_label: new TranslatableMarkup('Retire'),
  type: 'aim_fact',
)]
class RetireFactAction extends AimFactActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a RetireFactAction.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('datetime.time'));
  }

  /**
   * {@inheritdoc}
   */
  protected function apply(AimFact $fact): bool {
    if (!$fact->get('expires')->isEmpty()) {
      return FALSE;
    }
    $fact->set('expires', $this->time->getRequestTime());
    return TRUE;
  }

}
