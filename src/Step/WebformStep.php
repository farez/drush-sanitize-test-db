<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;

/**
 * Webform extras. Submissions themselves are emptied by Webform's own
 * sql:sanitize plugin (--sanitize-webform-submissions).
 */
final class WebformStep extends StepBase {

  private const STATE_KEYS = ['webform.element.message'];

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Webform: delete per-user message state';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Context $context): bool {
    return $context->moduleExists('webform');
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $present = $this->presentStateKeys();
    return [$present ? 'state deleted: ' . implode(', ', $present) : 'no Webform message state'];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    \Drupal::state()->deleteMultiple(self::STATE_KEYS);
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    if ($this->presentStateKeys()) {
      $failures[] = 'Webform message state remains';
    }
    if ($context->enabled('sanitize-webform-submissions') && $context->tableExists('webform_submission') && $context->rowCount('webform_submission')) {
      $failures[] = 'webform_submission is not empty';
    }
    return $failures;
  }

  /**
   * State::getMultiple() returns NULL for missing keys; keep the set ones.
   *
   * @return string[]
   */
  private function presentStateKeys(): array {
    return array_keys(array_filter(\Drupal::state()->getMultiple(self::STATE_KEYS), fn($value) => $value !== NULL));
  }

  /**
   * {@inheritdoc}
   */
  public function coveredTables(Context $context): array {
    return ['webform_submission', 'webform_submission_data', 'webform_submission_log'];
  }

}
