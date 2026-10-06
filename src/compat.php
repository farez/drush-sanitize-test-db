<?php

/**
 * @file
 * Drush 12/13 compatibility.
 *
 * Drush 13 moved SanitizePluginInterface from Drush\Drupal\Commands\sql to
 * Drush\Commands\sql\sanitize. Alias whichever exists to a package-local name
 * so the commandfile can implement it on both major versions.
 */

declare(strict_types=1);

if (!interface_exists('SanitizeTestDb\SanitizePluginInterface', FALSE)) {
  foreach ([
    'Drush\Commands\sql\sanitize\SanitizePluginInterface',
    'Drush\Drupal\Commands\sql\SanitizePluginInterface',
  ] as $sanitize_test_db_interface) {
    if (interface_exists($sanitize_test_db_interface)) {
      class_alias($sanitize_test_db_interface, 'SanitizeTestDb\SanitizePluginInterface');
      break;
    }
  }
  unset($sanitize_test_db_interface);
}
