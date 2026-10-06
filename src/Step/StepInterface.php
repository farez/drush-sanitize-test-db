<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;

/**
 * One sanitisation step. plan() is read-only and drives the dry run;
 * apply() makes the same changes; verify() checks the result.
 */
interface StepInterface {

  /**
   * Short description, shown in the confirmation and the report.
   */
  public function label(): string;

  /**
   * Whether the step is relevant to this site (module/table present).
   */
  public function applies(Context $context): bool;

  /**
   * Configuration problems that must stop a real run.
   *
   * @return string[]
   */
  public function errors(Context $context): array;

  /**
   * Human-readable description of planned changes. Never includes secrets.
   *
   * @return string[]
   */
  public function plan(Context $context): array;

  /**
   * Makes the changes described by plan().
   */
  public function apply(Context $context): void;

  /**
   * @return string[]
   *   Failures; empty when the step's outcome is as expected.
   */
  public function verify(Context $context): array;

  /**
   * Tables this step sanitises, for the coverage report.
   *
   * @return string[]
   */
  public function coveredTables(Context $context): array;

}
