<?php

declare(strict_types=1);

namespace Drupal\aim\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add and edit form for a fact.
 *
 * Core's form with no "Create new revision" checkbox: a text change always
 * saves a new revision and nothing else does (AimHooks::factPresave(),
 * ADR-0064). The edit form says when to revise and when to add a new fact
 * instead (decision 2), and offers the revision log message.
 */
class AimFactForm extends ContentEntityForm {

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
    if ($this->entity->isNew()) {
      $form['revision_log_message']['#access'] = FALSE;
      return $form;
    }
    if (isset($form['text']['widget'][0]['value'])) {
      $form['text']['widget'][0]['value']['#description'] = $this->t('Correct the wording of the same fact here: the old wording is kept as a revision. If what is true has changed, add a new fact instead and retire this one.');
    }
    if (isset($form['revision_log_message']['widget'][0]['value'])) {
      $form['revision_log_message']['widget'][0]['value']['#description'] = $this->t('Why the wording changed. Saved only with a change to the text: other changes do not make a revision.');
    }
    return $form;
  }

}
