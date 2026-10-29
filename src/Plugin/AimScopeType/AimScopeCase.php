<?php

declare(strict_types=1);

namespace Drupal\aim\Plugin\AimScopeType;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aim\Attribute\AimScopeType;
use Drupal\aim\Entity\AimFact;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Ongoing work: a case ID per piece of work (resolveSubject()).
 *
 * The first fact names the case ("Site redesign" becomes
 * case-site-redesign, with the scope's prefix setting) or, with no
 * subject, mints an ID. A subject some fact of the scope already has is
 * kept as it is, so an ID from before a prefix change still adds to its
 * case.
 *
 * Its checkViewAccess() stays neutral: case-scope access control is unbuilt.
 */
#[AimScopeType(
  id: 'case',
  label: new TranslatableMarkup('Case: one piece of ongoing work'),
)]
class AimScopeCase extends PluginBase implements AimScopeTypeInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service, used to mint a new case ID.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service, used to turn a case name into an ID.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to find an existing case ID.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected UuidInterface $uuid,
    protected TransliterationInterface $transliteration,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('uuid'),
      $container->get('transliteration'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkViewAccess(AimFact $fact, AccountInterface $account): AccessResultInterface {
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  public function resolveSubject(string $subject, string $scopeId): string {
    $prefix = (string) ($this->configuration['prefix'] ?? $this->defaultSettings()['prefix']);
    $mint = fn (): string => $prefix . substr($this->uuid->generate(), 0, 8);
    if ($subject === '') {
      return $mint();
    }

    $existing = $this->entityTypeManager->getStorage('aim_fact')->getQuery()
      ->accessCheck(FALSE)
      ->condition('scope', $scopeId)
      ->condition('subject', $subject)
      ->range(0, 1)
      ->execute();
    if ($existing) {
      return $subject;
    }

    $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '-', mb_strtolower($this->transliteration->transliterate($subject, 'en', ''))), '-');
    if ($slug === '') {
      return $mint();
    }
    $id = str_starts_with($slug, $prefix) ? $slug : $prefix . $slug;
    return rtrim(substr($id, 0, 64), '-');
  }

  /**
   * {@inheritdoc}
   */
  public function getBaseFieldDefinitions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings(): array {
    return [
      'prefix' => 'case-',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, FormStateInterface $form_state, array $settings): array {
    $settings += $this->defaultSettings();
    $form['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Case ID prefix'),
      '#description' => $this->t('Starts every new case ID, e.g. "case-" gives case-site-redesign for a case named "Site redesign", or case-1a2b3c4d when no name is given. Lowercase letters, digits, "-" and "_" only; may be empty. Existing case IDs keep their prefix.'),
      '#default_value' => $settings['prefix'],
      '#maxlength' => 32,
      '#size' => 16,
      '#element_validate' => [[static::class, 'validatePrefix']],
    ];
    return $form;
  }

  /**
   * Rejects a prefix a case name's ID could not start with.
   *
   * @param array $element
   *   The prefix element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validatePrefix(array &$element, FormStateInterface $form_state): void {
    if (!preg_match('/^[a-z0-9_-]*$/', (string) $element['#value'])) {
      $form_state->setError($element, new TranslatableMarkup('The case ID prefix may only hold lowercase letters, digits, "-" and "_".'));
    }
  }

}
