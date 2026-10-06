<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use SanitizeTestDb\Context;

/**
 * Blanks revision log messages on every revisionable entity type.
 */
final class RevisionLogStep extends StepBase {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Revision log messages: blank';
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $lines = [];
    foreach ($this->targets($context) as [$table, $column]) {
      if ($count = $this->query($context, $table, $column)->countQuery()->execute()->fetchField()) {
        $lines[] = "$table.$column: $count messages blanked";
      }
    }
    return $lines ?: ['no revision log messages'];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    foreach ($this->targets($context) as [$table, $column]) {
      $context->database->update($table)
        ->fields([$column => ''])
        ->condition($column, '', '<>')
        ->execute();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    foreach ($this->targets($context) as [$table, $column]) {
      if ($count = $this->query($context, $table, $column)->countQuery()->execute()->fetchField()) {
        $failures[] = "$table.$column still has $count messages";
      }
    }
    return $failures;
  }

  /**
   * Selects rows with a non-empty revision log message.
   */
  private function query(Context $context, string $table, string $column) {
    return $context->database->select($table, 't')->condition("t.$column", '', '<>');
  }

  /**
   * @return array<int, array{0: string, 1: string}>
   *   [table, column] pairs.
   */
  private function targets(Context $context): array {
    $targets = [];
    foreach ($context->entityTypeManager()->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || !$definition->isRevisionable()) {
        continue;
      }
      $field = $definition->getRevisionMetadataKey('revision_log_message');
      $storage = $context->entityTypeManager()->getStorage($id);
      if (!$field || !$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      $storage_definition = $context->entityFieldManager()->getFieldStorageDefinitions($id)[$field] ?? NULL;
      if (!$storage_definition) {
        continue;
      }
      $mapping = $storage->getTableMapping();
      $column = $mapping->getFieldColumnName($storage_definition, 'value');
      foreach ($mapping->getAllFieldTableNames($field) as $table) {
        if ($context->tableExists($table)) {
          $targets[] = [$table, $column];
        }
      }
    }
    return $targets;
  }

}
