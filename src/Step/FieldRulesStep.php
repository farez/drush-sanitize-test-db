<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use SanitizeTestDb\Context;

/**
 * Applies per-site field rules: "entity_type.field_name: action".
 *
 * Actions: email, phone, text, blank, keep. Works on any fieldable entity
 * type by resolving tables and the main property column from the table
 * mapping, and updates the data and revision tables directly.
 */
final class FieldRulesStep extends StepBase {

  public const ACTIONS = ['email', 'phone', 'text', 'blank', 'keep'];

  public const PHONE = '020 7946 0000';

  public const TEXT = 'Sanitised text';

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Field rules: sanitise listed content fields';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Context $context): bool {
    return $context->settings->fieldRules() !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function errors(Context $context): array {
    $errors = [];
    foreach ($context->settings->fieldRules() as $key => $action) {
      try {
        $this->resolve($context, (string) $key, (string) $action);
      }
      catch (\InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
      }
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $lines = [];
    foreach ($this->targets($context) as $target) {
      $count = $this->pending($context, $target)->countQuery()->execute()->fetchField();
      $lines[] = "{$target['key']} → {$target['action']}: $count values in {$target['table']}";
    }
    return $lines ?: ['only "keep" rules'];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    foreach ($this->targets($context) as $target) {
      $table = $target['table'];
      $column = $target['column'];
      switch ($target['action']) {
        case 'email':
          $context->database->update($table)
            ->expression($column, $context->concatExpression($target['id_column']), [
              ':sanitize_prefix' => 'user+',
              ':sanitize_suffix' => '@' . $context->emailDomain(),
            ])
            ->isNotNull($column)
            ->execute();
          break;

        case 'phone':
        case 'text':
          $context->database->update($table)
            ->fields([$column => $target['action'] === 'phone' ? self::PHONE : self::TEXT])
            ->isNotNull($column)
            ->execute();
          break;

        case 'blank':
          if ($target['dedicated']) {
            $context->database->delete($table)->execute();
          }
          else {
            $context->database->update($table)->fields([$column => NULL])->execute();
          }
          break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    foreach ($this->targets($context) as $target) {
      if ($count = $this->pending($context, $target)->countQuery()->execute()->fetchField()) {
        $failures[] = "{$target['key']}: $count values in {$target['table']} not sanitised";
      }
    }
    return $failures;
  }

  /**
   * Rows that still need the rule applied.
   */
  private function pending(Context $context, array $target) {
    $column = $target['column'];
    $query = $context->database->select($target['table'], 't')->isNotNull("t.$column");
    switch ($target['action']) {
      case 'email':
        $query->condition("t.$column", 'user+%@' . $context->database->escapeLike($context->emailDomain()), 'NOT LIKE');
        break;

      case 'phone':
        $query->condition("t.$column", self::PHONE, '<>');
        break;

      case 'text':
        $query->condition("t.$column", self::TEXT, '<>');
        break;
    }
    return $query;
  }

  /**
   * One target per table for every non-"keep" rule.
   */
  private function targets(Context $context): array {
    $targets = [];
    foreach ($context->settings->fieldRules() as $key => $action) {
      if ($action === 'keep') {
        continue;
      }
      try {
        array_push($targets, ...$this->resolve($context, (string) $key, (string) $action));
      }
      catch (\InvalidArgumentException) {
        // Reported by errors(); a real run never gets here with one.
      }
    }
    return $targets;
  }

  /**
   * Validates a rule and resolves its tables and columns.
   *
   * @throws \InvalidArgumentException
   */
  private function resolve(Context $context, string $key, string $action): array {
    if (!in_array($action, self::ACTIONS, TRUE)) {
      throw new \InvalidArgumentException("field_rules.$key: unknown action '$action' (use " . implode(', ', self::ACTIONS) . ')');
    }
    [$entity_type_id, $field_name] = array_pad(explode('.', $key, 2), 2, '');
    $manager = $context->entityTypeManager();
    if (!$manager->hasDefinition($entity_type_id)) {
      throw new \InvalidArgumentException("field_rules.$key: unknown entity type '$entity_type_id'");
    }
    $storage = $manager->getStorage($entity_type_id);
    $definition = $context->entityFieldManager()->getFieldStorageDefinitions($entity_type_id)[$field_name] ?? NULL;
    if (!$definition || !$storage instanceof SqlEntityStorageInterface) {
      throw new \InvalidArgumentException("field_rules.$key: unknown field '$field_name' on '$entity_type_id'");
    }
    if ($action === 'keep') {
      return [];
    }
    $mapping = $storage->getTableMapping();
    $dedicated = $mapping->requiresDedicatedTableStorage($definition);
    $column = $mapping->getFieldColumnName($definition, $definition->getMainPropertyName());
    $id_column = $dedicated ? 'entity_id' : $manager->getDefinition($entity_type_id)->getKey('id');
    $targets = [];
    foreach ($mapping->getAllFieldTableNames($field_name) as $table) {
      if ($context->tableExists($table)) {
        $targets[] = compact('key', 'action', 'table', 'column', 'id_column', 'dedicated');
      }
    }
    return $targets;
  }

}
