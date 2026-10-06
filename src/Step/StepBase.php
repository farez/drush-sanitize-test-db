<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;

/**
 * Defaults for optional step methods.
 */
abstract class StepBase implements StepInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(Context $context): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function errors(Context $context): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function coveredTables(Context $context): array {
    return [];
  }

}
