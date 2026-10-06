<?php

declare(strict_types=1);

namespace SanitizeTestDb;

use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;

/**
 * Dry-run coverage: data that no step sanitises and nobody has reviewed.
 */
final class Coverage {

  private const FIELD_TYPES = ['email', 'telephone', 'address'];

  private const FIELD_NAME_PATTERN = '/(e?mail|phone|mobile|minicom)/i';

  /**
   * Non-entity tables with rows, not sanitised and not in reviewed_tables.
   *
   * Entity tables are content and kept on purpose; personal data in them is
   * handled by field rules (see uncoveredFields()).
   *
   * @return array<string, int>
   */
  public static function uncoveredTables(Context $context, Runner $runner): array {
    $known = [];
    foreach ($runner->steps() as $step) {
      array_push($known, ...$step->coveredTables($context));
    }
    foreach ($context->entityTypeManager()->getDefinitions() as $id => $definition) {
      try {
        $storage = $context->entityTypeManager()->getStorage($id);
        if ($storage instanceof SqlEntityStorageInterface) {
          array_push($known, ...$storage->getTableMapping()->getTableNames());
        }
      }
      catch (\Throwable) {
        // Entity types without usable storage are skipped.
      }
    }
    $reviewed = $context->settings->list('reviewed_tables', Settings::REVIEWED_TABLES);

    $uncovered = [];
    foreach ($context->database->schema()->findTables('%') as $table) {
      if (in_array($table, $known, TRUE) || Settings::matchesTable($reviewed, $table)) {
        continue;
      }
      if ($count = $context->rowCount($table)) {
        $uncovered[$table] = $count;
      }
    }
    ksort($uncovered);
    return $uncovered;
  }

  /**
   * Likely personal-data fields with values and no field rule.
   *
   * User fields are left to Drush core's user-fields sanitiser.
   *
   * @return array<string, int>
   */
  public static function uncoveredFields(Context $context): array {
    $rules = $context->settings->fieldRules();
    $manager = $context->entityTypeManager();
    if (!$manager->hasDefinition('field_storage_config')) {
      return [];
    }
    $uncovered = [];
    /** @var \Drupal\field\FieldStorageConfigInterface $field */
    foreach ($manager->getStorage('field_storage_config')->loadMultiple() as $field) {
      $entity_type_id = $field->getTargetEntityTypeId();
      $key = $entity_type_id . '.' . $field->getName();
      if ($entity_type_id === 'user' || isset($rules[$key]) || $key === 'group_relationship.invitee_mail' || $key === 'group_content.invitee_mail') {
        continue;
      }
      if (!in_array($field->getType(), self::FIELD_TYPES, TRUE) && !preg_match(self::FIELD_NAME_PATTERN, $field->getName())) {
        continue;
      }
      $storage = $manager->getStorage($entity_type_id);
      if (!$storage instanceof SqlEntityStorageInterface) {
        continue;
      }
      $table = $storage->getTableMapping()->getDedicatedDataTableName($field);
      if ($context->tableExists($table) && ($count = $context->rowCount($table))) {
        $uncovered[$key] = $count;
      }
    }
    ksort($uncovered);
    return $uncovered;
  }

}
