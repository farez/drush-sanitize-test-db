<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;
use SanitizeTestDb\Settings;

/**
 * Empties log, cache and temporary tables matched by glob patterns.
 */
final class TablesStep extends StepBase {

  /**
   * Tables that Drupal may repopulate during the run; not verified.
   */
  private const VOLATILE = ['cache_*', 'cachetags', 'semaphore', 'key_value_expire'];

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Tables: empty logs, caches and temporary data';
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $lines = [];
    $empty = 0;
    foreach ($this->tables($context) as $table) {
      if ($count = $context->rowCount($table)) {
        $lines[] = "$table: $count rows emptied";
      }
      else {
        $empty++;
      }
    }
    if ($empty) {
      $lines[] = "$empty matching tables already empty";
    }
    return $lines;
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    foreach ($this->tables($context) as $table) {
      $context->database->truncate($table)->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    foreach ($this->tables($context) as $table) {
      if (!Settings::matchesTable(self::VOLATILE, $table) && $context->rowCount($table)) {
        $failures[] = "$table is not empty";
      }
    }
    return $failures;
  }

  /**
   * {@inheritdoc}
   */
  public function coveredTables(Context $context): array {
    return $this->tables($context);
  }

  /**
   * @return string[]
   */
  private function tables(Context $context): array {
    $include = array_merge(Settings::TABLES, $context->settings->list('extra_tables'));
    $exclude = $context->settings->list('tables_exclude', Settings::TABLES_EXCLUDE);
    $tables = array_filter(
      $context->database->schema()->findTables('%'),
      fn(string $table): bool => Settings::matchesTable($include, $table) && !Settings::matchesTable($exclude, $table),
    );
    sort($tables);
    return array_values($tables);
  }

}
