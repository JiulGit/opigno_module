<?php

namespace Drupal\opigno_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\opigno_module\Controller\ExternalPackageController;

/**
 * Add External package form.
 */
class AddExternalPackageForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'add_external_package_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $mode = NULL) {
    $is_ppt = ($mode && $mode == 'ppt') ? TRUE : FALSE;
    if ($is_ppt) {
      $form_state->set('mode', $mode);
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
    ];

    $form['package'] = [
      '#title' => $this->t('Package'),
      '#type' => 'file',
      '#description' => !$is_ppt ? $this->t('Here you can upload external package. Allowed extensions: zip h5p') : $this->t('Here you can upload PowerPoint presentation file. Allowed extensions: ppt pptx'),
    ];

    $ajax_id = "ajax-form-entity-external-package";
    $form['#attributes']['class'][] = $ajax_id;
    $form['#attached']['library'][] = 'opigno_module/ajax_form';

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#ajax' => [
        'callback' => 'Drupal\opigno_module\Controller\ExternalPackageController::ajaxFormExternalPackageCallback',
        'wrapper' => $ajax_id,
        'effect' => 'fade',
      ],
    ];

    $form['actions']['submit']['#submit'][] = 'Drupal\opigno_module\Controller\ExternalPackageController::ajaxFormExternalPackageFormSubmit';

    $form['ajax_form_entity'] = [
      '#type' => 'hidden',
      '#value' => [
        'view_mode' => 'default',
        'reload' => TRUE,
        'content_selector' => ".$ajax_id",
        'form_selector' => ".$ajax_id",
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validation is optional.
    $file_field = "package";
    $storage = $form_state->getStorage();
    $is_ppt = (isset($storage['mode']) && $storage['mode'] == 'ppt') ? TRUE : FALSE;
    if (empty($_FILES['files']['name'][$file_field])) {
      // Only need to validate if the field actually has a file.
      $form_state->setError(
        $form['package'],
        $this->t("The file was not uploaded.")
      );
    }

    // Prepare folder.
    $temporary_file_path = !$is_ppt ? 'public://external_packages' : 'public://' . ExternalPackageController::getPptConversionDir();
    \Drupal::service('file_system')->prepareDirectory($temporary_file_path, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY);

    // Prepare file validators.
    $extensions = !$is_ppt ? ['h5p zip'] : ['ppt pptx'];
    $validators = [
      'file_validate_extensions' => $extensions,
    ];
    // Validate file.
    if ($is_ppt) {
      $ppt_dir = ExternalPackageController::getPptConversionDir();
      $file_default_scheme = \Drupal::config('system.file')->get('default_scheme');
      $public_files_real_path = \Drupal::service('file_system')->realpath($file_default_scheme . "://");
      $ppt_dir_real_path = $public_files_real_path . '/' . $ppt_dir;

      $file = file_save_upload($file_field, $validators, $temporary_file_path, NULL, FileSystemInterface::EXISTS_REPLACE);

      // Rename uploaded file - remove special chars.
      $file_new = $file[0];
      $filename = $file_new->getFilename();
      $filename_new = preg_replace('/[^a-zA-Z0-9-_\.]/', '-', $filename);
      $file_new->setFilename($filename_new);
      $file_new->setFileUri($temporary_file_path . '/' . $filename_new);
      $file_new->save();
      rename($ppt_dir_real_path . '/' . $filename, $ppt_dir_real_path . '/' . $filename_new);

      if (!empty($file_new)) {
        // Actions on ppt(x) file upload.
        $ppt_dir = ExternalPackageController::getPptConversionDir();
        $file_default_scheme = \Drupal::config('system.file')->get('default_scheme');
        $public_files_real_path = \Drupal::service('file_system')->realpath($file_default_scheme . "://");
        $ppt_dir_real_path = $public_files_real_path . '/' . $ppt_dir;

        $this->logger('ppt_converter')->notice('$ppt_dir_real_path: ' . $ppt_dir_real_path);

        $images = ExternalPackageController::convertPptSlidesToImages($file_new, $ppt_dir_real_path);

        if ($images) {

          \Drupal::logger('ppt_converter')->notice('$images: <pre><code>' . print_r($images, TRUE) . '</code></pre>');

          // Create H5P package in 'sites/default/files/external_packages_ppt'.
          ExternalPackageController::createH5pCoursePresentationPackage($images, $ppt_dir_real_path, $form_state->getValue('name'));
        }

        if (file_exists($temporary_file_path . '/ppt-content-import.h5p')) {
          // Replace form uploaded file with converted h5p content file.
          $file_new = File::load($file[0]->id());
          $file_new->setFilename('ppt-content-import.h5p');
          $file_new->setFileUri($temporary_file_path . '/ppt-content-import.h5p');
          $file_new->setMimeType('application/octet-stream');
          $file_new->save();

          $file[0] = $file_new;
        }
      }
    }
    else {
      $file = file_save_upload($file_field, $validators, $temporary_file_path, NULL, FileSystemInterface::EXISTS_REPLACE);
    }

    if (!$file[0]) {
      return $form_state->setRebuild();
    };

    // Validate Scorm and Tincan packages.
    $this->validateZipPackage($form, $form_state, $file[0]);

    // Set file id in form state for loading on submit.
    $form_state->set('package', $file[0]->id());

  }

  private function validateZipPackage($form, FormStateInterface $form_state, File $file) {
    /* @var \Drupal\opigno_scorm\OpignoScorm $scorm_controller */
    $scorm_controller = \Drupal::service('opigno_scorm.scorm');
    $file_extension = substr(strrchr($file->getFilename(), '.'), 1);
    if ($file_extension == 'zip') {
      $scorm_controller->unzipPackage($file);
      $extract_dir = 'public://opigno_scorm_extracted/scorm_' . $file->id();
      // This is a standard: these files must always be here.
      $scorm_file = $extract_dir . '/imsmanifest.xml';
      $tincan_file = $extract_dir . '/tincan.xml';
      if (!file_exists($scorm_file) && !file_exists($tincan_file)) {
        $validation = FALSE;

        $files = scandir($extract_dir);
        $count_files = count($files);

        if ($count_files == 3 && is_dir($extract_dir. '/' . $files[2])) {
          $subfolder_files = scandir($extract_dir. '/' . $files[2]);

          if (in_array('imsmanifest.xml', $subfolder_files)) {
            $source = $extract_dir. '/' . $files[2];

            $i = new \RecursiveDirectoryIterator($source);
            foreach($i as $f) {
              if($f->isFile()) {
                rename($f->getPathname(), $extract_dir . '/' . $f->getFilename());
              } else if($f->isDir()) {
                rename($f->getPathname(), $extract_dir . '/' . $f->getFilename());
                unlink($f->getPathname());
              }
            }
            $validation = TRUE;
          }
        }


        if ($validation == FALSE) {
          $form_state->setError(
            $form['package'],
            $this->t('Your file is not recognized as a valid SCORM, TinCan, or H5P package.')
          );
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
