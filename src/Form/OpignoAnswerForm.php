<?php

namespace Drupal\opigno_module\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\opigno_group_manager\Entity\OpignoGroupManagedContent;
use Drupal\opigno_group_manager\OpignoGroupContext;
use Drupal\opigno_module\Entity\OpignoActivity;
use Drupal\opigno_learning_path\LearningPathContent;
use Drupal\opigno_module\Entity\OpignoAnswer;
use Drupal\opigno_module\Entity\OpignoModule;

/**
 * Form controller for Answer edit forms.
 *
 * @ingroup opigno_module
 */
class OpignoAnswerForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /* @var $entity \Drupal\opigno_module\Entity\OpignoAnswer */
    $form = parent::buildForm($form, $form_state);
    // Hide revision_log_message field.
    unset($form['revision_log_message']);
    $entity = $this->entity;
    $activity = $entity->getActivity();
    /** @var \Drupal\opigno_module\Entity\OpignoModule $module */
    $module = $entity->getModule();
    $form['activity'] = [
      '#type' => 'label',
      '#title' => $activity->value,
    ];
    $form['module'] = [
      '#type' => 'label',
      '#title' => $module->value,
    ];
    // Backwards navigation.
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => [
        '::backwardsNavigation',
      ],
    ];
    // Check for enabled option.
    // Also check that user already has at least 1 answered activity.
    // Check that user is not on the first activity in the module.
    $activity_link_type = OpignoGroupContext::getActivityLinkType();
    $attempt = $module->getModuleActiveAttempt($this->currentUser(), $activity_link_type);
    if ($attempt !== NULL) {
      $activities = $module->getModuleActivities();
      $first_activity = reset($activities);
      $first_activity = $first_activity !== FALSE
        ? OpignoActivity::load($first_activity->id)
        : NULL;
      $current_activity = \Drupal::routeMatch()->getParameter('opigno_activity');

      $has_first_activity = $first_activity !== NULL;
      $has_current_activity = $current_activity !== NULL;

      $is_on_first_activity = $has_first_activity
        && $has_current_activity
        && $first_activity->id() === $current_activity->id();
      // Disable back navigation for first content first activity.
      $cid = OpignoGroupContext::getCurrentGroupContentId();
      if ($cid) {
        $content = OpignoGroupManagedContent::load($cid);
        $parents = $content->getParentsLinks();
        if (!$module->getBackwardsNavigation()
          || (empty($parents) && $is_on_first_activity)
          ||  $this->currentUser()->id() === 0) {
          $form['actions']['back']['#attributes']['disabled'] = TRUE;
        }
      }
    }
    else {
      $form['actions']['back']['#access'] = FALSE;
      $form['actions']['submit']['#access'] = FALSE;
    }

    /* @var $answer_service \Drupal\opigno_module\ActivityAnswerManager */
    $answer_service = \Drupal::service('plugin.manager.activity_answer');
    $answer_activity_type = $activity->getType();
    if ($answer_service->hasDefinition($answer_activity_type)) {
      $answer_instance = $answer_service->createInstance($answer_activity_type);
      $answer_instance->answeringForm($form);
    }
    // Remove 'delete' button.
    unset($form['actions']['delete']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = &$this->entity;
    $activity = $entity->getActivity();
    $module = $entity->getModule();
    $attempt = $module->getModuleActiveAttempt($this->currentUser());
    $moduleHandler = \Drupal::service('module_handler');
    $activities = $module->getModuleActivities();

    if ($attempt !== NULL) {
      $attempt->setLastActivity($activity);
      $entity->setUserModuleStatus($attempt);
      // Check if answer should be evaluated or not.
      // Make it possible to modify answer object before save.
      /* @var $answer_service \Drupal\opigno_module\ActivityAnswerManager */
      $answer_service = \Drupal::service('plugin.manager.activity_answer');
      $answer_activity_type = $activity->getType();
      if ($answer_service->hasDefinition($answer_activity_type)) {
        $answer_instance = $answer_service->createInstance($answer_activity_type);
        // Evaluation status.
        $evaluated_status = $answer_instance->evaluatedOnSave($activity) ? 1 : 0;
        // Answer score.
        if ($activity->hasField('opigno_evaluation_method') && $activity->get('opigno_evaluation_method')->value) {
          $score = 0;
        }
        else {
          $score = $answer_instance->getScore($entity);
        }

        // Calculate score for skills system if activity not included in the current module.
        // Activity type H5P.
        if ($moduleHandler->moduleExists('opigno_skills_system') && $module->getSkillsActive() == 1 && $activity->getType() == 'opigno_h5p') {
          $h5p_score = $form_state->getValue('score');
          $percent_score = ($h5p_score / 1.234) - 32.17;
          $score = round($percent_score * 10);
          if ($score < 0) $score = 0;
        }

        $entity->setScore(round($score));
      }
      // Set evaluation status.
      if (isset($evaluated_status)) {
        $entity->setEvaluated($evaluated_status);
      }
      // $entity->save();
      $attempt->save();
    }

    if ($moduleHandler->moduleExists('opigno_skills_system')) {
      // Set skill ID.
      $skill_id = $activity->getSkillId();
      if (!empty($skill_id)) {
        $entity->setSkillId($skill_id);
      }

      // Set skill level.
      $skill_level = $activity->getSkillLevel();
      if (!empty($skill_level)) {
        $entity->setSkillLevel($skill_level);
      }
    }

    $status = parent::save($form, $form_state);

    switch ($status) {
      case SAVED_NEW:
      case SAVED_UPDATED:
        break;

      default:
        \Drupal::messenger()->addMessage($this->t('Saved the %label Answer.', [
          '%label' => $entity->id(),
        ]));
    }

    if ($activity->getType() == 'opigno_scorm') {
      $form_state->set('scorm_answer', $entity);
    }

    $args = ['opigno_module' => $module->id()];
    $current_group = \Drupal::routeMatch()->getParameter('group');
    if ($current_group) {
      $args['group'] = $current_group->id();
    }
    else {
      $args['group'] = OpignoGroupContext::getCurrentGroupId();
    }
    // Query param is used to detect if we go to take page
    // from submitted answer.
    $form_state->setRedirect('opigno_module.take_module', $args, ['query' => ['continue' => TRUE]]);

    // Calculate skills statistic.
    if ($moduleHandler->moduleExists('opigno_skills_system') && !empty($skill_level) && !empty($skill_id)) {
      $db_connection = \Drupal::service('database');
      $uid = $this->currentUser()->id();

      $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
      $skill_entity = $term_storage->load($skill_id);

      if ($skill_entity != NULL) {
        $minimum_score = $skill_entity->get('field_minimum_score')->getValue()[0]['value'];
        $minimum_answers = $skill_entity->get('field_minimum_count_of_answers')->getValue()[0]['value'];

        $skill_level = $activity->getSkillLevel();

        // Get initial level. This is equal to the count of levels.
        $initial_level = count($skill_entity->get('field_level_names')->getValue());
        $initial_level = $initial_level === 0 ? 1 : $initial_level;

        // Get current user's skills.
        $query = $db_connection
          ->select('opigno_skills_statistic', 'o_s_s');
        $query->fields('o_s_s', ['tid', 'score', 'progress', 'stage']);
        $query->condition('o_s_s.uid', $uid);
        $query->condition('o_s_s.tid', $skill_id);
        $user_skills = $query
          ->execute()
          ->fetchAllAssoc('tid');

        // Get current level of user's skill.
        if (isset($user_skills[$skill_id]) && $user_skills[$skill_id]->stage == $skill_level) {
          $current_stage = $user_skills[$skill_id]->stage;
        }
        elseif (isset($skill_level) || !$module->getSkillsActive()) {
          $current_stage = $skill_level;
        }
        else {
          $current_stage = $initial_level;
        }

        $stage = $current_stage;

        // Get last user's answers on questions for current skill.
        $query = $db_connection->select('opigno_answer_field_data', 'o_a_f_d');
        $query->addExpression('MAX(o_a_f_d.score)', 'score');
        $query->addExpression('MAX(o_a_f_d.changed)', 'changed');
        $query->addExpression('MAX(o_a_f_d.skill_level)', 'skill_level');
        $query->addField('o_a_f_d', 'activity');
        $query->condition('o_a_f_d.user_id', $uid)
          ->condition('o_a_f_d.skills_mode', $skill_id);

        $all_answers = $query
          ->groupBy('o_a_f_d.activity')
          ->orderBy('changed', 'DESC')
          ->execute()
          ->fetchAllAssoc('activity');

        $activity_ids = array_keys($all_answers);

        // Get max score of questions for skill.
        $query = $db_connection->select('opigno_module_relationship', 'o_m_r');
        $query->fields('o_m_r', ['child_id', 'max_score']);
        $query->condition('o_m_r.parent_id', $module->id());
        $query->condition('o_m_r.parent_vid', $module->getRevisionId());
        $query->condition('o_m_r.child_id', $activity_ids, 'IN');

        $max_scores = $query
          ->execute()
          ->fetchAllAssoc('child_id');

        // Get last user's answers for each level of skill.
        $answers = [];
        $answer_count_for_levels = [];

        // Check the level of skill.
        while ($current_stage > 0) {
          $count_answers_for_stage = 0;
          $avg_score = 0;

          foreach ($all_answers as $key => $answer) {
            if ($answer->skill_level == $current_stage) {
              $answers[$answer->activity] = $answer;
              $count_answers_for_stage++;

              if (!isset($max_scores[$key]->max_score)) {
                $max_scores[$key]->max_score = 10;
              }
              $avg_score += $answer->score / $max_scores[$key]->max_score;

              if ($count_answers_for_stage >= $minimum_answers) {
                $answer_count_for_levels[$current_stage]['access'] = TRUE;
                $avg_score = round($avg_score / $minimum_answers * 100);
                $answer_count_for_levels[$current_stage]['avg_score'] = $avg_score;
                if ($current_stage > $stage) {
                  $stage = $current_stage;
                }
                break;
              }
            }
          }

          $current_stage--;
        }

        // Get average score and current progress of skill.
        $avg_score = 0;
        $current_progress = 0;
        $count_of_levels = $initial_level;
//      $current_progress = round (count($answers) / $minimum_answers / $count_of_levels * 100);

        foreach ($answers as $key => $answer) {
          if (!isset($max_scores[$key]->max_score)) {
            $max_scores[$key]->max_score = 10;
          }
          $avg_score += $answer->score / $max_scores[$key]->max_score;
        }

        $avg_score = round($avg_score / count($answers) * 100);

        // Update user's skill statistic.
        $keys = [
          'tid' => $skill_id,
          'uid' => $uid,
        ];

        // Check if the user is ready to level-up the skill.
        if (!empty($user_skills) || $minimum_answers == 1) {
          if (!empty($answer_count_for_levels[$stage])
            && $answer_count_for_levels[$stage]['access'] == TRUE
            && $answer_count_for_levels[$stage]['avg_score'] >= $minimum_score) {
            $stage--;
          }
          elseif (isset($user_skills[$skill_id])) {
            $stage = $user_skills[$skill_id]->stage;
          }
        }
        else {
          $stage = $initial_level;
        }

        // Set current progress
        $current_progress = 100 - (round($stage / $initial_level * 100));

        $fields = [
          'score' => $avg_score,
          'progress' => $current_progress,
          'stage' => $stage,
        ];

        $query = \Drupal::database()
          ->merge('opigno_skills_statistic')
          ->keys($keys)
          ->fields($fields)
          ->execute();

        // Check if user successfully finished skills tree.
        $target_skill = $module->getTargetSkill();
        $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
        $skills_tree = array_reverse($term_storage->loadTree('skills', $target_skill));

        // Get current user's skills.
        $uid = $this->currentUser()->id();
        $query = $db_connection
          ->select('opigno_skills_statistic', 'o_s_s');
        $query->fields('o_s_s', ['tid', 'score', 'progress', 'stage']);
        $query->condition('o_s_s.uid', $uid);
        $user_skills = $query
          ->execute()
          ->fetchAllAssoc('tid');

        // Set default successfully finished this skills tree for user.
        // If the system finds any skill which is not successfully finished then change this variable to FALSE.
        $successfully_finished = TRUE;
        $sum_score = 0;

        foreach ($skills_tree as $key => $skill) {
          $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
          $skill_entity = $term_storage->load($skill->tid);
          $minimum_score = $skill_entity->get('field_minimum_score')->getValue()[0]['value'];

          if (!isset($user_skills[$skill->tid])) {
            $successfully_finished = FALSE;
          }
          else {
            $sum_score += $user_skills[$skill->tid]->score;

            // Check if the skill was successfully finished.
            if ($minimum_score > $user_skills[$skill->tid]->score || $user_skills[$skill->tid]->progress < 100) {
              $successfully_finished = FALSE;
            }
          }
        }

        $gid = OpignoGroupContext::getCurrentGroupId();

        $uid = $this->currentUser()->id();

        $steps = LearningPathContent::getAllStepsOnlyModules($gid, $uid);

        // Search for parent.
        $current_step = [];
        foreach ($steps as $step) {
          if ($cid = $step['cid']) {
            $current_step = $step;
            break;
          }
        }

        $activities_from_module = $module->getModuleActivities();
        $count_of_activities = count($activities_from_module);
        $activity_ids = array_keys($activities_from_module);

        $query = $db_connection->select('opigno_answer_field_data', 'o_a_f_d');
        $query->leftJoin('opigno_module_relationship', 'o_m_r', 'o_a_f_d.activity = o_m_r.child_id');
        $query->addExpression('MAX(o_a_f_d.score)', 'score');
        $query->addExpression('MAX(o_a_f_d.changed)', 'changed');
        $query->addExpression('MAX(o_a_f_d.skill_level)', 'skill_level');
        $query->addField('o_a_f_d', 'activity');
        $query->condition('o_a_f_d.user_id', $uid)
          ->condition('o_a_f_d.module', $module->id());

        if (!$module->getModuleSkillsGlobal()) {
          $query->condition('o_a_f_d.activity', $activity_ids, 'IN');
        }

        $query->condition('o_a_f_d.user_module_status', $attempt->id())
          ->condition('o_m_r.max_score', '', '<>')
          ->groupBy('o_a_f_d.activity')
          ->orderBy('changed', 'DESC');

        $answers = $query->execute()->fetchAllAssoc('activity');
        $count_of_answers = count($answers);
        $progress = round($count_of_answers / $count_of_activities * 100);

        $activity_ids = array_keys($answers);
        $sum_score = 0;

        // Get max score of activities.
        if (!empty($activity_ids)) {
          $query = $db_connection->select('opigno_module_relationship', 'o_m_r');
          $query->fields('o_m_r', ['child_id', 'max_score']);
          $query->condition('o_m_r.parent_id', $module->id());
          $query->condition('o_m_r.parent_vid', $module->getRevisionId());
          $query->condition('o_m_r.child_id', $activity_ids, 'IN');

          $max_scores = $query
            ->execute()
            ->fetchAllAssoc('child_id');
        }

        foreach ($answers as $key => $answer) {
          if (!isset($max_scores[$key]->max_score)) {
            $max_scores[$key]->max_score = 10;
          }
          $sum_score += $answer->score / $max_scores[$key]->max_score;
        }

        $avg_score = $sum_score / $count_of_answers * 100;
        $current_step['best score'] = $avg_score;
        $current_step['current attempt score'] = $avg_score;
        $last_activity_id = end($activities)->id;

        if ($module->getSkillsActive() || $activity->id() == $last_activity_id) {
          if ($successfully_finished == TRUE) {
            $_SESSION['successfully_finished'] = TRUE;
          }

          $attempt->setScore($avg_score);
          $attempt->setMaxScore(100);
          $attempt->save();
        }
      }
    }

    // Send email to the module managers.
    $this->sendEmailToManager($module, $activity, $entity, $current_group);
  }

  /**
   * Send Email to manager.
   */
  public function sendEmailToManager($module, $activity, $answer, $learning_path) {
    if (!$module instanceof OpignoModule ||
      !$activity instanceof OpignoActivity ||
      !$answer instanceof OpignoAnswer ||
      !$learning_path instanceof Group) {
      return;
    }
    $config = \Drupal::config('opigno_learning_path.learning_path_settings');
    $student_activity_notify = $config->get('opigno_learning_path_student_does_activity_notify');
    $student_activity = $config->get('opigno_learning_path_student_does_activity');
    if (!$activity->evaluationMethodManual() || empty($student_activity_notify) || empty($student_activity)) {
      return;
    }
    // Load managers and send email to each.
    $managers = $learning_path->getMembers('learning_path-user_manager');
    $student = \Drupal::currentUser();
    $global_config = \Drupal::config('system.site');
    $options = ['absolute' => TRUE];
    $url = Url::fromRoute('view.opigno_score_modules.opigno_not_evaluated', [], $options);
    $link = Link::fromTextAndUrl(t('link'), $url)->toString();

    foreach ($managers as $manager) {
      $user = $manager->getUser();
      $email = $user->getEmail();
      $lang = $user->getPreferredLangcode();

      $params = [];
      $params['subject'] = t('@sitename Module review', ['@sitename' => $global_config->get('name')]);
      $student_activity = str_replace('[sitename]', $global_config->get('name'), $student_activity);
      $student_activity = str_replace('[user]', $student->getAccountName(), $student_activity);
      $student_activity = str_replace('[manager]', $user->getAccountName(), $student_activity);
      $student_activity = str_replace('[link]', $link, $student_activity);
      $student_activity = str_replace('[module]', $module->getName(), $student_activity);
      $params['message'] = $student_activity;
      \Drupal::service('plugin.manager.mail')->mail('opigno_learning_path', 'opigno_learning_path_managers_notify', $email, $lang, $params);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function backwardsNavigation(array $form, FormStateInterface $form_state) {
    $entity = &$this->entity;
    $module = $entity->getModule();
    $activity = $entity->getActivity();
    $attempt = $module->getModuleActiveAttempt($this->currentUser());

    $activities = $module->getModuleActivities();
    if (key($activities) != $activity->id()) {
      // Set last activity only if current activity is not first.
      $attempt->setLastActivity($activity);
      $attempt->save();
    };

    $args = ['opigno_module' => $module->id()];
    $current_group = \Drupal::routeMatch()->getParameter('group');
    if ($current_group) {
      $args['group'] = $current_group->id();
    }
    // Query param is used to detect if we used backwards navigation button.
    $form_state->setRedirect('opigno_module.take_module', $args, ['query' => ['backwards' => TRUE]]);
  }

  /**
   * {@inheritdoc}
   */
  public function backToTraining(array $form, FormStateInterface $form_state) {
    $group = \Drupal::routeMatch()->getParameter('group');
    $form_state->setRedirect('entity.group.canonical', ['group' => $group->id()]);
  }

}
