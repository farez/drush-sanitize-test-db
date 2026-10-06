<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;

/**
 * Empties a fixed list of tables when they exist (e.g. authmap).
 */
final class TruncateStep extends StepBase {

  /**
   * @param string[] $tables
   */
  public function __construct(
    private readonly string $label,
    private readonly array $tables,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return $this->label;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Context $context): bool {
    return $this->existing($context) !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    return array_map(fn(string $table): string => "$table: " . $context->rowCount($table) . ' rows emptied', $this->existing($context));
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    foreach ($this->existing($context) as $table) {
      $context->database->truncate($table)->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    foreach ($this->existing($context) as $table) {
      if ($context->rowCount($table)) {
        $failures[] = "$table is not empty";
      }
    }
    return $failures;
  }

  /**
   * {@inheritdoc}
   */
  public function coveredTables(Context $context): array {
    return $this->tables;
  }

  /**
   * @return string[]
   */
  private function existing(Context $context): array {
    return array_values(array_filter($this->tables, [$context, 'tableExists']));
  }

}
