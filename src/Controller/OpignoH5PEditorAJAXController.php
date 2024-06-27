<?php

namespace Drupal\opigno_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\h5peditor\H5PEditor\H5PEditorUtilities;
use Drupal\opigno_module\H5PImportClasses\H5PEditorAjaxImport;


class OpignoH5PEditorAJAXController extends ControllerBase {

  /**
   * Callback that returns the content type cache
   */
  public function contentTypeCacheCallback() {
    $editor = H5PEditorUtilities::getInstance();

    $h5pEditorAjax = new H5PEditorAjaxImport($editor->ajax->core, $editor, $editor->ajax->storage);
    $libraries = $h5pEditorAjax->h5pLibariesList();

    $this->filterH5PLibraries($libraries);

    \H5PCore::ajaxSuccess($libraries, TRUE);
    exit();
  }

  /**
   * Excludes disabled libraries.
   *
   * @param $libraries
   */
  public function filterH5PLibraries(&$libraries) {
    // Get disabled list.
    $config = \Drupal::config('opigno_module.settings');
    $disabled = $config->get('disabled_h5p');

    foreach ($libraries['libraries'] as $key => $library) {
      if (in_array($library['machineName'], $disabled)) {
        unset($libraries['libraries'][$key]);
      }
    }

    $libraries['libraries'] = array_values($libraries['libraries']);
  }
}
