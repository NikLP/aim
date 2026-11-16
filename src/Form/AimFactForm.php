<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\aim\AimScopeTypePluginManagerInterface;
use Drupal\aim\Service\AimRememberService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Add and edit form for a fact.
 *
 * Core's form with no "Create new revision" checkbox: a text change always
 * saves a new revision and nothing else does (AimHooks::factPresave(),
 * ADR-0064). The edit form says when to revise and when to add a new fact
 * instead (decision 2), and offers the revision log message. Which subject
 * fields show is the scope plugin's (AimHooks::entityFormDisplayAlter());
 * on the edit form they are read-only, since a fact about someone or
 * something else is a new fact. Expires is a day, not a time (the last
 * day the fact is true, in the site timezone, ADR-0057), so it is a plain
 * date element here, read and written through AimRememberService like
 * every other write path, rather than core's date-and-time widget.
 */
class AimFactForm extends ContentEntityForm {

  /**
   * The scope type plugin manager, for the subject field names.
   */
  protected AimScopeTypePluginManagerInterface $scopeTypeManager;

  /**
   * The remember service, for expires as a day in the site timezone.
   */
  protected AimRememberService $rememberService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->scopeTypeManager = $container->get('plugin.manager.aim_scope_type');
    $form->rememberService = $container->get('aim.remember');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {
    parent::prepareEntity();
    // Like core's revision UI: the log starts empty for the next revision.
    // factPresave() puts the current one back when the text is unchanged.
    if (!$this->entity->isNew()) {
      $this->entity->setRevisionLogMessage(NULL);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $expires = $this->entity->get('expires')->value;
    $form['expires_day'] = [
      '#type' => 'date',
      '#title' => $this->t('Expires'),
      '#description' => $this->t('The last day this fact is true, in the site timezone. It is retired once that day has ended. Leave empty if it has no end.'),
      '#default_value' => $expires ? $this->rememberService->formatDay((int) $expires) : NULL,
      '#weight' => 35,
    ];
    if ($this->entity->isNew()) {
      $form['revision_log_message']['#access'] = FALSE;
      return $form;
    }
    $fixed = FALSE;
    foreach ($this->scopeTypeManager->getSubjectFieldNames() as $name) {
      if (isset($form[$name])) {
        $form[$name]['#disabled'] = TRUE;
        $fixed = TRUE;
      }
    }
    if ($fixed) {
      $form['subject_fixed'] = [
        '#type' => 'item',
        '#description' => $this->t('Who or what this fact is about is fixed once it is saved. For someone or something else, add a new fact.'),
        '#weight' => 25,
      ];
    }
    if (isset($form['text']['widget'][0]['value'])) {
      $form['text']['widget'][0]['value']['#description'] = $this->t('Correct the wording of the same fact here: the old wording is kept as a revision. If what is true has changed, add a new fact instead and retire this one.');
    }
    if (isset($form['revision_log_message']['widget'][0]['value'])) {
      $form['revision_log_message']['widget'][0]['value']['#description'] = $this->t('Why the wording changed. Saved only with a change to the text: other changes do not make a revision.');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $day = (string) $form_state->getValue('expires_day');
    $expires = $this->rememberService->parseExpires(['expires' => $day]);
    // A day already past is refused only as a new value: an expired fact
    // the sweep has not reached yet can still be edited.
    if ($expires !== NULL && $day !== $this->currentDay() && $this->rememberService->isPast($expires)) {
      $form_state->setErrorByName('expires_day', $this->t('Expires is already past (today is @today).', [
        '@today' => $this->rememberService->today(),
      ]));
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $entity = parent::buildEntity($form, $form_state);
    $entity->set('expires', $this->rememberService->parseExpires(['expires' => (string) $form_state->getValue('expires_day')]));
    return $entity;
  }

  /**
   * Returns the saved fact's expires day.
   *
   * @return string|null
   *   The day, YYYY-MM-DD, or NULL for a new fact or one with no end.
   */
  protected function currentDay(): ?string {
    $value = $this->entity->isNew() ? NULL : $this->entity->get('expires')->value;
    return $value ? $this->rememberService->formatDay((int) $value) : NULL;
  }

}
