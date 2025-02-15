<?php

namespace Drupal\opigno_h5p\TypeProcessors;

/**
 * Class FillInProcessor.
 *
 * Processes and generates HTML report for 'fill-in' interaction type.
 */
class TrueFalseProcessor extends TypeProcessor {

  /**
   * {@inheritdoc}
   */
  public function generateHtml(
    string $description,
    ?array $crp,
    string $response,
    ?object $extras = NULL,
    ?object $scoreSettings = NULL
  ): string {
    // We need some style for our report.
    $this->setStyle('opigno_h5p/opigno_h5p.true-false');

    return $this->getContent($description, $crp, $response, $scoreSettings) .
           $this->generateFooter();
  }

  /**
   * Get report content.
   */
  private function getContent($description, $crp, $response, $scoreSettings) {
    $isCorrectClass = $response === $crp[0] ?
      'h5p-true-false-user-response-correct' :
      'h5p-true-false-user-response-wrong';

    return '<div class="h5p-reporting-container h5p-true-false-container">' .
      $this->generateHeader($description, $scoreSettings) .
      '<p class="h5p-true-false-task"><span class="h5p-true-false-correct-responses-pattern">' . $crp[0] . '</span><span class="' . $isCorrectClass . '">' . $response . '</span></p>' .
      '</div>';
  }

  /**
   * Generate header element.
   */
  private function generateHeader($description, $scoreSettings) {
    $descriptionHtml =
      "<p class='h5p-reporting-description h5p-true-false-task-description'>{$description}</p>";
    $scoreHtml = $this->generateScoreHtml($scoreSettings);

    return "<div class='h5p-choices-header'>{$descriptionHtml}{$scoreHtml}</div>";
  }

  /**
   * Generate footer.
   */
  public function generateFooter() {
    return '<div class="h5p-true-false-footer">' .
      '<span class="h5p-true-false-correct-responses-pattern">' . $this->t('Correct Answer') . '</span>' .
      '<span class="h5p-true-false-user-response-correct">' . $this->t('Your correct answer') . '</span>' .
      '<span class="h5p-true-false-user-response-wrong">' . $this->t('Your incorrect answer') . '</span>' .
      '</div>';
  }

}
