<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use Drupal\Core\Config\StorageInterface;
use SanitizeTestDb\Context;

/**
 * Walks every config object in every collection of the active storage.
 *
 * Writing straight to storage keeps serialization safe and avoids config
 * save events; the cache tables are emptied later in the run.
 */
trait ConfigWalker {

  /**
   * Computes changes for all config.
   *
   * @param callable $transform
   *   fn(string $name, array $data): array{0: array, 1: string[]} returning
   *   the new data and descriptions of what changed (empty when nothing).
   *
   * @return array<int, array{collection: string, name: string, data: array, changes: string[]}>
   */
  protected function configChanges(Context $context, callable $transform): array {
    $result = [];
    foreach ($context->configStorages() as $collection => $storage) {
      foreach ($storage->readMultiple($storage->listAll()) as $name => $data) {
        if (!is_array($data)) {
          continue;
        }
        [$new, $changes] = $transform($name, $data);
        if ($changes) {
          $result[] = ['collection' => $collection, 'name' => $name, 'data' => $new, 'changes' => $changes];
        }
      }
    }
    return $result;
  }

  /**
   * Writes changed config back to active storage.
   */
  protected function writeConfigChanges(Context $context, array $changes): void {
    $storages = $context->configStorages();
    foreach ($changes as $change) {
      $storages[$change['collection']]->write($change['name'], $change['data']);
    }
    \Drupal::configFactory()->reset();
  }

  /**
   * @return string[]
   */
  protected function describeConfigChanges(array $changes): array {
    $lines = [];
    foreach ($changes as $change) {
      $prefix = $change['collection'] === StorageInterface::DEFAULT_COLLECTION ? '' : "[{$change['collection']}] ";
      foreach ($change['changes'] as $description) {
        $lines[] = $prefix . $change['name'] . ':' . $description;
      }
    }
    return $lines;
  }

}
