<?php

/**
 * @file
 * Uses this package's autoloader, or the host project's when installed there.
 */

declare(strict_types=1);

$candidates = [
  __DIR__ . '/../vendor/autoload.php',
  getcwd() . '/vendor/autoload.php',
];
foreach ($candidates as $autoload) {
  if (is_file($autoload)) {
    $loader = require $autoload;
    break;
  }
}
if (!isset($loader)) {
  fwrite(STDERR, "No Composer autoloader found. Run composer install.\n");
  exit(1);
}
$loader->addPsr4('SanitizeTestDb\\Tests\\', __DIR__);
$loader->addPsr4('SanitizeTestDb\\', dirname(__DIR__) . '/src');
