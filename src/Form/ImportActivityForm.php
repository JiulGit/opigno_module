<?php

namespace Drupal\opigno_module\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\Entity\File;
use Drupal\h5p\Entity\H5PContent;
use Drupal\h5p\H5PDrupal\H5PDrupal;
use Drupal\h5peditor\H5PEditor\H5PEditorUtilities;
use Drupal\media\Entity\Media;
use Drupal\opigno_module\Entity\OpignoActivity;
use Drupal\opigno_module\H5PImportClasses\H5PEditorAjaxImport;
use Drupal\opigno_module\H5PImportClasses\H5PStorageImport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Import Activity form.
 */
class ImportActivityForm extends FormBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The DB connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The serializer service.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * The H5P config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * ImportActivityForm constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Database\Connection $database
   *   The DB connection service.
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    FileSystemInterface $file_system,
    TimeInterface $time,
    Connection $database,
    SerializerInterface $serializer,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger
  ) {
    $this->fileSystem = $file_system;
    $this->time = $time;
    $this->database = $database;
    $this->serializer = $serializer;
    $this->config = $config_factory->get('h5p.settings');
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system'),
      $container->get('datetime.time'),
      $container->get('database'),
      $container->get('serializer'),
      $container->get('config.factory'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'import_activity_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $mode = NULL) {
    $is_ppt = ($mode && $mode == 'ppt') ? TRUE : FALSE;
    if ($is_ppt) {
      $form_state->set('mode', $mode);
    }

    $form['activity_opi'] = [
      '#title' => $this->t('Activity'),
      '#type' => 'file',
      '#description' => $this->t('Here you can import activity. Allowed extension: opi'),
    ];

    $ajax_id = "ajax-form-entity-external-package";
    $form['#attributes']['class'][] = $ajax_id;
    $form['#attached']['library'][] = 'opigno_module/ajax_form';

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validation is optional.
    if (empty($_FILES['files']['name']['activity_opi'])) {
      // Only need to validate if the field actually has a file.
      $form_state->setError(
        $form['activity_opi'],
        $this->t("The file was not uploaded.")
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Prepare folder.
    $temporary_file_path = 'public://opigno-import';

    $this->fileSystem->deleteRecursive($temporary_file_path);
    $this->fileSystem->prepareDirectory($temporary_file_path, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY);

    // Prepare file validators.
    $extensions = ['opi'];
    $validators = [
      'file_validate_extensions' => $extensions,
    ];
    $files = [];
    $file = file_save_upload('activity_opi', $validators, $temporary_file_path, NULL, FileSystemInterface::EXISTS_REPLACE);

    if (!empty($file[0])) {
      $path = $this->fileSystem->realpath($file[0]->getFileUri());

      $folder = DRUPAL_ROOT . '/sites/default/files/opigno-import';

      $zip = new \ZipArchive();
      $result = $zip->open($path);

      if ($zip->locateName('.htaccess') !== FALSE) {
        $this->messenger->addMessage(t('Unsafe files detected.'), 'error');
        $zip->close();
        $this->fileSystem->delete($path);
        $this->fileSystem->deleteRecursive($temporary_file_path);
        return;
      }

      if ($result === TRUE) {
        $zip->extractTo($folder);
        $zip->close();
      }

      $this->fileSystem->delete($path);
      $files = scandir($folder);
    }

    if (in_array('export-opigno_activity.json', $files)) {
      $file_path = $temporary_file_path . '/export-opigno_activity.json';
      $real_path = $this->fileSystem->realpath($file_path);
      $file = file_get_contents($real_path);

      $format = 'json';
      $activity_content_array = $this->serializer->decode($file, $format);
      $content = reset($activity_content_array);

      if (empty($content['bundle'])) {
        $this->messenger->addMessage(t('Incorrect archive structure.'), 'error');
        return;
      }

      $new_activity = OpignoActivity::create([
        'type' => $content['bundle'],
      ]);

      $new_activity->setName($content['name'][0]['value']);
      $new_activity->set('langcode', $content['langcode'][0]['value']);
      $new_activity->set('status', $content['status'][0]['value']);

      prepare_directory_structure_for_import();

      switch ($content['bundle']) {
        case 'opigno_long_answer':
          $new_activity->opigno_body->value = $content['opigno_body'][0]['value'];
          $new_activity->opigno_body->format = $content['opigno_body'][0]['format'];
          $new_activity->opigno_evaluation_method->value = $content['opigno_evaluation_method'][0]['value'];
          break;

        case 'opigno_file_upload':
          $new_activity->opigno_body->value = $content['opigno_body'][0]['value'];
          $new_activity->opigno_body->format = $content['opigno_body'][0]['format'];
          $new_activity->opigno_evaluation_method->value = $content['opigno_evaluation_method'][0]['value'];
          $new_activity->opigno_allowed_extension->value = $content['opigno_allowed_extension'][0]['value'];
          break;

        case 'opigno_scorm':
          foreach ($content['files'] as $file_key => $file_content) {
            $scorm_file_path = $temporary_file_path . '/' . $file_key;
            $uri = $this->fileSystem->copy($scorm_file_path, 'public://opigno_scorm/' . $file_content['file_name'], FileSystemInterface::EXISTS_RENAME);

            $file = File::Create([
              'uri' => $uri,
              'uid' => $this->currentUser()->id(),
              'status' => $file_content['status'],
            ]);
            $file->save();
            $fid = $file->id();
            $new_activity->opigno_scorm_package->target_id = $fid;
            $new_activity->opigno_scorm_package->display = 1;
          }
          break;

        case 'opigno_tincan':
          foreach ($content['files'] as $file_key => $file_content) {
            $tincan_file_path = $temporary_file_path . '/' . $file_key;
            $uri = $this->fileSystem->copy($tincan_file_path, 'public://opigno_tincan/' . $file_content['file_name'], FileSystemInterface::EXISTS_RENAME);

            $file = File::Create([
              'uri' => $uri,
              'uid' => $this->currentUser()->id(),
              'status' => $file_content['status'],
            ]);
            $file->save();
            $fid = $file->id();
            $new_activity->opigno_tincan_package->target_id = $fid;
            $new_activity->opigno_tincan_package->display = 1;
          }
          break;

        case 'opigno_slide':
          $new_activity->opigno_body->value = $content['opigno_body'][0]['value'];
          $new_activity->opigno_body->format = $content['opigno_body'][0]['format'];
          $content_files = $content['files'] ?? [];

          if (!$content_files || !is_array($content_files)) {
            break;
          }

          foreach ($content_files as $file_key => $file_content) {
            $slide_file_path = $temporary_file_path . '/' . $file_key;
            $current_timestamp = $this->time->getCurrentTime();
            $date = date('Y-m', $current_timestamp);
            $uri = $this->fileSystem->copy($slide_file_path, 'public://' . $date . '/' . $file_content['file_name'], FileSystemInterface::EXISTS_RENAME);

            $file = File::Create([
              'uri' => $uri,
              'uid' => $this->currentUser()->id(),
              'status' => $file_content['status'],
            ]);
            $file->save();

            $media = Media::create([
              'bundle' => $file_content['bundle'],
              'name' => $file_content['file_name'],
              'field_media_file' => [
                'target_id' => $file->id(),
              ],
            ]);

            $media->save();

            $new_activity->opigno_slide_pdf->target_id = $media->id();
            $new_activity->opigno_slide_pdf->display = 1;
          }

          break;

        case 'opigno_video':
          foreach ($content['files'] as $file_key => $file_content) {
            $video_file_path = $temporary_file_path . '/' . $file_key;
            $current_timestamp = $this->time->getCurrentTime();
            $date = date('Y-m', $current_timestamp);
            $uri = $this->fileSystem->copy($video_file_path, 'public://video-thumbnails/' . $date . '/' . $file_content['file_name'], FileSystemInterface::EXISTS_RENAME);

            $file = File::Create([
              'uri' => $uri,
              'uid' => $this->currentUser()->id(),
              'status' => $file_content['status'],
            ]);
            $file->save();

            $new_activity->field_video->target_id = $file->id();
          }
          break;

        case 'opigno_h5p':
          $h5p_content_id = $content['opigno_h5p'][0]['h5p_content_id'];
          $file = $folder . "/interactive-content-{$h5p_content_id}.h5p";
          $interface = H5PDrupal::getInstance();

          if ($file) {
            $file_service = $this->fileSystem;
            $dir = $file_service->realpath($temporary_file_path . '/h5p');
            $interface->getUploadedH5pFolderPath($dir);
            $interface->getUploadedH5pPath($folder . "/interactive-content-{$h5p_content_id}.h5p");

            $editor = H5PEditorUtilities::getInstance();
            $h5pEditorAjax = new H5PEditorAjaxImport($editor->ajax->core, $editor, $editor->ajax->storage);

            if ($h5pEditorAjax->isValidPackage(TRUE)) {
              // Add new libraries from file package.
              $storage = new H5PStorageImport($h5pEditorAjax->core->h5pF, $h5pEditorAjax->core);

              // Serialize metadata array in libraries.
              if (!empty($storage->h5pC->librariesJsonData)) {
                foreach ($storage->h5pC->librariesJsonData as &$library) {
                  if (array_key_exists('metadataSettings', $library) && is_array($library['metadataSettings'])) {
                    $metadataSettings = serialize($library['metadataSettings']);
                    $library['metadataSettings'] = $metadataSettings;
                  }
                }
              }

              $storage->saveLibraries();

              $h5p_json = $dir . '/h5p.json';
              $real_path = $this->fileSystem->realpath($h5p_json);
              $h5p_json = file_get_contents($real_path);

              $format = 'json';
              $h5p_json = $this->serializer->decode($h5p_json, $format);
              $dependencies = $h5p_json['preloadedDependencies'];

              // Get ID of main library.
              foreach ($h5p_json['preloadedDependencies'] as $dependency) {
                if ($dependency['machineName'] == $h5p_json['mainLibrary']) {
                  $h5p_json['majorVersion'] = $dependency['majorVersion'];
                  $h5p_json['minorVersion'] = $dependency['minorVersion'];
                }
              }

              $query = $this->database->select('h5p_libraries', 'h_l');
              $query->condition('machine_name', $h5p_json['mainLibrary'], '=');
              $query->condition('major_version', $h5p_json['majorVersion'], '=');
              $query->condition('minor_version', $h5p_json['minorVersion'], '=');
              $query->fields('h_l', ['library_id']);
              $query->orderBy('patch_version', 'DESC');
              $main_library_id = $query->execute()->fetchField();

              if (!$main_library_id) {
                $query = $this->database->select('h5p_libraries', 'h_l');
                $query->condition('machine_name', $h5p_json['mainLibrary'], '=');
                $query->fields('h_l', ['library_id']);
                $query->orderBy('major_version', 'DESC');
                $query->orderBy('minor_version', 'DESC');
                $query->orderBy('patch_version', 'DESC');
                $main_library_id = $query->execute()->fetchField();
              }

              $content_json = $dir . '/content/content.json';
              $real_path = $this->fileSystem->realpath($content_json);
              $content_json = file_get_contents($real_path);

              $fields = [
                'library_id' => $main_library_id,
                'title' => $h5p_json['title'],
                'parameters' => $content_json,
                'filtered_parameters' => $content_json,
                'disabled_features' => 0,
                'authors' => '[]',
                'changes' => '[]',
                'license' => 'U',
              ];

              $h5p_content = H5PContent::create($fields);
              $h5p_content->save();
              $new_activity->set('opigno_h5p', $h5p_content->id());

              $h5p_dest_path = $this->config->get('h5p_default_path');
              $h5p_dest_path = $h5p_dest_path ?: 'h5p';

              $dest_folder = DRUPAL_ROOT . '/sites/default/files/' . $h5p_dest_path . '/content/' . $h5p_content->id();
              $source_folder = DRUPAL_ROOT . '/sites/default/files/opigno-import/h5p/content/*';
              $this->fileSystem->prepareDirectory($dest_folder, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY);
              shell_exec('rm ' . $dest_folder . '/content.json');
              shell_exec('cp -r ' . $source_folder . ' ' . $dest_folder);

              // Clean up.
              $h5pEditorAjax->storage->removeTemporarilySavedFiles($h5pEditorAjax->core->h5pF->getUploadedH5pFolderPath());

              foreach ($dependencies as $dependency_key => $dependency) {
                $query = $this->database->select('h5p_libraries', 'h_l');
                $query->condition('machine_name', $dependency['machineName'], '=');
                $query->condition('major_version', $dependency['majorVersion'], '=');
                $query->condition('minor_version', $dependency['minorVersion'], '=');
                $query->fields('h_l', ['library_id']);
                $query->orderBy('patch_version', 'DESC');
                $library_id = $query->execute()->fetchField();

                if (!$library_id) {
                  $query = $this->database->select('h5p_libraries', 'h_l');
                  $query->condition('machine_name', $dependency['machineName'], '=');
                  $query->fields('h_l', ['library_id']);
                  $query->orderBy('major_version', 'DESC');
                  $query->orderBy('minor_version', 'DESC');
                  $query->orderBy('patch_version', 'DESC');
                  $library_id = $query->execute()->fetchField();
                }

                if ($h5p_json['mainLibrary'] == $dependency['machineName']) {
                  $main_library_values = [
                    'content_id' => $h5p_content->id(),
                    'library_id' => $library_id,
                    'dependency_type' => 'preloaded',
                    'drop_css' => 0,
                    'weight' => count($dependencies) + 1,
                  ];

                  continue;
                }

                if ($library_id) {
                  $this->database->insert('h5p_content_libraries')
                    ->fields([
                      'content_id',
                      'library_id',
                      'dependency_type',
                      'drop_css',
                      'weight',
                    ])
                    ->values([
                      'content_id' => $h5p_content->id(),
                      'library_id' => $library_id,
                      'dependency_type' => 'preloaded',
                      'drop_css' => 0,
                      'weight' => $dependency_key + 1,
                    ])
                    ->execute();
                }
              }

              if (!empty($main_library_values)) {
                $this->database->insert('h5p_content_libraries')
                  ->fields([
                    'content_id',
                    'library_id',
                    'dependency_type',
                    'drop_css',
                    'weight',
                  ])
                  ->values($main_library_values)
                  ->execute();
              }
            }
          }
          break;
      }

      $new_activity->save();

      $route_parameters = [
        'opigno_activity' => $new_activity->id(),
      ];

      $this->messenger->addMessage(t('Imported activity %activity', [
        '%activity' => Link::createFromRoute($new_activity->label(), 'entity.opigno_activity.canonical', $route_parameters)->toString(),
      ]));

      $form_state->setRedirect('entity.opigno_activity.collection');
    }
  }

}
