<?php

namespace Drupal\opigno_module\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\Entity\File;

/**
 * Form controller for Activity edit forms.
 *
 * @ingroup opigno_module
 */
class OpignoActivityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /* @var $entity \Drupal\opigno_module\Entity\OpignoActivity */
    $form = parent::buildForm($form, $form_state);
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $values = $form_state->getValues();
    $auto_skills = FALSE;

    // Add wrapper for ajax.
    $form['#prefix'] = '<div id="activity-wrapper">';
    $form['#suffix'] = '</div>';

    $moduleHandler = \Drupal::service('module_handler');
    $is_new = $this->getEntity()->isNew();
    $in_skills_system = FALSE;

    $activity = $this->getEntity();
    $skill_id = $activity->getSkillId();
    $params = \Drupal::routeMatch()->getParameters();

    // Check if we create/edit activity in the learning path management.
    if (!empty($params->get('opigno_module'))) {
      $module_id = $params->get('opigno_module')->id();
      $module = \Drupal::entityTypeManager()->getStorage('opigno_module')->load($module_id);
      $in_skills_system = $module->getSkillsActive();
    }

    if ($skill_id) {
      $default_manual = TRUE;
      $parents = $term_storage->loadAllParents($skill_id);
      $parents_ids = array_keys($parents);
    }
    else {
      $default_manual = FALSE;
      $parents_ids = [];
    }

    // Hide field 'auto_skills' for all existing activities.
    if (!$is_new || !$moduleHandler->moduleExists('opigno_skills_system') || (isset($module) && !$in_skills_system)) {
      $form['auto_skills']['#access'] = FALSE;
      $auto_skills = $this->getEntity()->get('auto_skills')->getValue()[0]['value'];
    }
    else {
      $form['auto_skills']['widget']['value']['#ajax'] = [
        'method' => 'replace',
        'effect' => 'fade',
        'callback' => '::autoSkillsAjax',
        'wrapper' => 'activity-wrapper',
      ];

      $form['usage_activity']['widget']['#default_value'] = 'global';
    }

    // Check if we creating new activity in skills module.
    if (isset($module) && $in_skills_system && $is_new) {
      $form['auto_skills']['#access'] = FALSE;
      $form['auto_skills']['widget']['value']['#default_value'] = TRUE;
      $auto_skills = TRUE;
    }

    if (!empty($values['auto_skills']['value'])) {
      $auto_skills = $values['auto_skills']['value'];
    }

    // Add 'manual skills management' for activities which is not in the skills system.
    if ((!isset($module_id) || !$module->getSkillsActive())
        && ($is_new || !$auto_skills)) {
      $form['manual_skills_management'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Assign a skill to this activity'),
        '#default_value' => $default_manual,
        '#weight' => 1,
        '#ajax' => [
          'method' => 'replace',
          'effect' => 'fade',
          'callback' => '::autoSkillsAjax',
          'wrapper' => 'activity-wrapper',
        ]
      ];
    }

    // Get list of skills trees.
    $target_skills = $term_storage->loadTree('skills', 0, 1);
    $default_target_skill = FALSE;
    $options = [];

    if ($target_skills) {
      $default_target_skill = $target_skills[0]->tid;
    }

    foreach ($target_skills as $row) {
      $options [$row->tid] = $row->name;
      if (in_array($row->tid, $parents_ids)) {
        $default_target_skill = $row->tid;
      }
    }

    if ($in_skills_system && !$is_new) {
      $default_target_skill = $module->getTargetSkill();
    }
    else {
      $form['manual_management_tree'] = [
        '#type' => 'select',
        '#title' => $this->t('Choose skills tree'),
        '#options' => $options,
        '#weight' => 1,
        '#default_value' => $default_target_skill,
        '#ajax' => [
          'event' => 'change',
          'callback' => '::autoSkillsAjax',
          'wrapper' => 'activity-wrapper',
        ],
      ];

      if ($default_target_skill) {
        $form['manual_management_tree']['#default_value'] = $default_target_skill;
      }
    }

    $form['skills_list']['widget']['#ajax'] = [
      'event' => 'change',
      'callback' => '::autoSkillsAjax',
      'wrapper' => 'activity-wrapper',
    ];

    $target_skill = $form_state->getValue('manual_management_tree');

    // Get list of skills.
    if (!empty($target_skill)) {
      $form['skills_list']['widget']['#options'] = $this->_getSkillsFromTree($target_skill, $form['skills_list']['widget']['#options']);
    }
    else {
      $form['skills_list']['widget']['#options'] = $this->_getSkillsFromTree($default_target_skill, $form['skills_list']['widget']['#options']);
    }

    if (isset($values['skills_list'][0])) {
      $selected_skill = $term_storage->load($values['skills_list'][0]['target_id']);

      $form['skill_level']['widget']['#default_value'][0] = '0';
      $default_skill_level = $values['skill_level'];
      $default_skill_level[0]['value'] = 'Level 1';
      $form_state->setValue('skill_level', $default_skill_level);
    }
    elseif (isset($form['skills_list']['widget']['#default_value'][0])) {
      $selected_skill = $term_storage->load($form['skills_list']['widget']['#default_value'][0]);
    }

    // Remove default options for skill levels except first option.
    $form['skill_level']['widget']['#options'] = [
      1 => $this->t('Level 1'),
    ];

    // Get level names.
    if (isset($selected_skill)) {
      $levels = $selected_skill->get('field_level_names');

      if (isset($levels)) {
        $levels = $levels->getValue();
      }
    }

    if (!empty($levels)) {
      $form['skill_level']['widget']['#options'] = [];

      foreach ($levels as $key => $level) {
        $form['skill_level']['widget']['#options'] += [$key + 1 => $level['value']];
      }
    }

    // Hide fields if needed.
    if (!$auto_skills && ((isset($values['manual_skills_management']) && $values['manual_skills_management'] == 0)
        || (!$activity->getSkillId() && !isset($values['manual_skills_management'])))) {
      $form['manual_management_tree']['#access'] = FALSE;
      $form['skill_level']['#access'] = FALSE;
      $form['skills_list']['#access'] = FALSE;
      $form['usage_activity']['#access'] = FALSE;
    }
    elseif ($auto_skills && $is_new) {
      $form['manual_skills_management']['#access'] = FALSE;
    }
    elseif ((isset($values['manual_skills_management']) || $values['manual_skills_management'] == 1)) {
      $form['usage_activity']['#access'] = FALSE;
    }

    // Disable revision message.
    // $form['revision_log_message']['#access'] = FALSE;

    return $form;
  }

  /**
   * Get needed skills by target skill.
   */
  public function _getSkillsFromTree($target_skill, array  $term_options) {
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $skills_from_tree = $term_storage->loadTree('skills',$target_skill);
    $options = [];

    foreach ($skills_from_tree as $row) {
      $options[$row->tid] = $row->name;
    }

    foreach ($term_options as $key => $option) {
      if (array_key_exists($key, $options)) {
        $term_options[$key] = $options[$key];
      }
      elseif ($key != '_none') {
        unset ($term_options[$key]);
      }
    }

    return $term_options;
  }

  /**
   * Ajax form submit.
   */
  public function autoSkillsAjax(array &$form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $activity = &$this->entity;
    // Get URL parameters.
    $params = \Drupal::request()->query->all();

    // Save Activity entity.
    $status = parent::save($form, $form_state);

    // Reset usage of activity for activities which not in skills modules.
    $values = $form_state->getValues();
    if ($values['auto_skills']['value'] == 0) {
      $activity->set('usage_activity', 'local');

      if ($values['manual_skills_management'] == 0) {
        $activity->setSkillId(NULL);
      }

      $activity->save();
    }

    // Update video filename.
    if (!empty($values['field_video'][0]['fids'])) {
      $fid = $values['field_video'][0]['fids'][0];
      $file = File::load($fid);
      $this->renameFile($file);
    }

    switch ($status) {
      case SAVED_NEW:
        if (isset($params['module_id']) && !empty($params['module_id'] && $params['module_vid'])) {
          $opigno_module = \Drupal::entityTypeManager()->getStorage('opigno_module')->load($params['module_id']);
          $opigno_module_obj = \Drupal::service('opigno_module.opigno_module');
          $opigno_module_obj->activitiesToModule([$activity], $opigno_module);
        }
        \Drupal::messenger()->addMessage($this->t('Created the %label Activity.', [
          '%label' => $activity->label(),
        ]));

        break;

      default:
        \Drupal::messenger()->addMessage($this->t('Saved the %label Activity.', [
          '%label' => $activity->label(),
        ]));
    }
    $form_state->setRedirect('entity.opigno_activity.canonical', ['opigno_activity' => $activity->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $moduleHandler = \Drupal::service('module_handler');
    $values = $form_state->getValues();

    if ($moduleHandler->moduleExists('opigno_skills_system')
        && isset($values['manual_skill_management']) && $values['manual_skill_management'] == FALSE) {
      unset($values['skills_list'][0]);
      unset($values['skill_level'][0]);
      $form_state->setValues($values);
    }
  }

  /**
   * Change filename.
   */
  protected function renameFile(&$file) {
    if (!empty($file)) {
      $stream_wrapper = StreamWrapperManager::getScheme($file->getFileUri());
      $filename = $file->getFilename();
      $filename_new = preg_replace('/[^a-zA-Z0-9-_\.]/', '-', $filename);
      $file->setFilename($filename_new);
      $file->save();
      \Drupal::service('file.repository')->move($file, $stream_wrapper . '://' . $filename_new);
    }
  }

}
