<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;

/**
 * Group: deletes invitations (invitee emails) and relabels memberships.
 *
 * Membership relationships store a copy of the member's username in their
 * label, which can be a real name or email; it becomes the sanitised name.
 */
final class GroupStep extends StepBase {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Group: delete invitations, relabel memberships';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Context $context): bool {
    return $this->entityTypeId($context) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    return [
      count($this->ids($context)) . ' invitations deleted',
      $this->staleLabels($context) . ' membership labels → sanitised username',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    $storage = $context->entityTypeManager()->getStorage($this->entityTypeId($context));
    foreach (array_chunk($this->ids($context), 50) as $chunk) {
      $storage->delete($storage->loadMultiple($chunk));
    }
    [$table, $label] = $this->labelColumn($context);
    if ($table) {
      $names = $this->userNames($context);
      foreach ($this->memberships($context, $table, $label) as $id => $row) {
        if (isset($names[$row->entity_id]) && $row->label !== $names[$row->entity_id]) {
          $context->database->update($table)
            ->fields([$label => $names[$row->entity_id]])
            ->condition('id', $id)
            ->execute();
        }
      }
      $storage->resetCache();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    if ($count = count($this->ids($context))) {
      $failures[] = "$count group invitations remain";
    }
    if ($stale = $this->staleLabels($context)) {
      $failures[] = "$stale group membership labels not sanitised";
    }
    return $failures;
  }

  /**
   * Counts membership labels that differ from the sanitised username.
   */
  private function staleLabels(Context $context): int {
    [$table, $label] = $this->labelColumn($context);
    if (!$table) {
      return 0;
    }
    $names = $this->userNames($context);
    $stale = 0;
    foreach ($this->memberships($context, $table, $label) as $row) {
      if (isset($names[$row->entity_id]) && $row->label !== $names[$row->entity_id]) {
        $stale++;
      }
    }
    return $stale;
  }

  /**
   * [data table, label column] of the relationship entity type.
   */
  private function labelColumn(Context $context): array {
    $definition = $context->entityTypeManager()->getDefinition($this->entityTypeId($context));
    $table = $definition->getDataTable() ?: $definition->getBaseTable();
    $label = $definition->getKey('label');
    return $table && $label && $context->tableExists($table) ? [$table, $label] : [NULL, NULL];
  }

  /**
   * Membership rows keyed by id: entity_id (uid) and label.
   */
  private function memberships(Context $context, string $table, string $label): array {
    $query = $context->database->select($table, 'r')
      ->fields('r', ['id', 'entity_id'])
      ->condition('r.plugin_id', 'group_membership');
    $query->addField('r', $label, 'label');
    return $query->execute()->fetchAllAssoc('id');
  }

  /**
   * @return array<int, string>
   */
  private function userNames(Context $context): array {
    return $context->database->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->execute()->fetchAllKeyed();
  }

  /**
   * Group_relationship (Group 2/3) or group_content (Group 1).
   */
  private function entityTypeId(Context $context): ?string {
    foreach (['group_relationship', 'group_content'] as $id) {
      if ($context->entityTypeManager()->hasDefinition($id)) {
        return $id;
      }
    }
    return NULL;
  }

  /**
   * @return int[]
   */
  private function ids(Context $context): array {
    $entity_type_id = $this->entityTypeId($context);
    $bundles = [];
    foreach ($context->entityTypeManager()->getStorage($entity_type_id . '_type')->loadMultiple() as $type) {
      if (method_exists($type, 'getPluginId') && $type->getPluginId() === 'group_invitation') {
        $bundles[] = $type->id();
      }
    }
    if (!$bundles) {
      return [];
    }
    return array_values($context->entityTypeManager()->getStorage($entity_type_id)->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->execute());
  }

}
