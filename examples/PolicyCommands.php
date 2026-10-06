<?php

declare(strict_types=1);

namespace Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drush\Attributes as CLI;

/**
 * Example Drush policy file for sites using southwark/drush-sanitize-test-db.
 *
 * Refuses `drush sql:sanitize` (Drush core's and every contrib sanitiser,
 * e.g. Webform's) unless the environment is local or an explicitly named
 * hosted environment.
 *
 * Why: Drush core's and contrib sanitisers are installed wherever Drush is,
 * production included, with only a y/N prompt in front of them. When this
 * package is a dev dependency (composer require --dev, deployed with
 * composer install --no-dev), its own guard is not on production. This file
 * is: commit it to the site and it is deployed everywhere.
 *
 * Install:
 * - Copy it to <project>/drush/Commands/PolicyCommands.php and commit it.
 *   Drush loads site-wide commandfiles from <project>/drush/Commands
 *   (namespace Drush\Commands) without a module being enabled.
 * - Check it is loaded: `drush sql:sanitize --uri=https://example.com` must
 *   fail with "Policy: sql:sanitize refused".
 * - Keep its rules in step with the package (src/EnvironmentGuard.php).
 * - Optionally add more ARGUMENT_VALIDATOR hooks to block other dangerous
 *   commands on production, e.g. sql:drop or site:install.
 *
 * It reads the same settings as the package (`sanitize_test_db` in
 * drush/drush.yml) and applies the same rules:
 * - local: a local opt-in (DDEV, Lando or
 *   $settings['allow_sql_sanitize'] = TRUE), a local database host and a
 *   local site URL; or
 * - hosted: $settings['sanitize_test_db_allowed_environment'] = '<name>';
 * and, on both routes, no prod_markers file loaded and the site URL not in
 * deny_uri_hosts. Fails closed.
 */
final class PolicyCommands extends DrushCommands {

  private const LOCAL_URL_SUFFIXES = ['.ddev.site', '.lndo.site', '.localhost', '.test'];

  /**
   * Refuses sql:sanitize outside local or named hosted environments.
   */
  #[CLI\Hook(type: HookManager::ARGUMENT_VALIDATOR, target: 'sql:sanitize')]
  public function sqlSanitizeValidate(CommandData $commandData): void {
    if (!\Drupal::hasContainer()) {
      throw new \Exception('Policy: sql:sanitize refused, Drupal is not bootstrapped so the environment cannot be checked.');
    }
    $config = (array) $this->getConfig()->get('sanitize_test_db', []);
    $failures = [];

    $host = \Drupal::hasRequest() ? strtolower(\Drupal::request()->getHost()) : '';
    $environment = Settings::get('sanitize_test_db_allowed_environment');
    $hosted = is_string($environment) && trim($environment) !== '';

    if (!$hosted) {
      if (getenv('IS_DDEV_PROJECT') !== 'true' && getenv('LANDO') !== 'ON' && Settings::get('allow_sql_sanitize') !== TRUE) {
        $failures[] = "no local opt-in (DDEV, Lando or \$settings['allow_sql_sanitize'] = TRUE in settings.local.php)";
      }
      $info = Database::getConnectionInfo()['default'] ?? [];
      if (!$this->isLocalDbHost((string) ($info['driver'] ?? ''), (string) ($info['host'] ?? ''))) {
        $failures[] = 'database host is not local: ' . ($info['host'] ?? '');
      }
      $suffixes = array_merge(self::LOCAL_URL_SUFFIXES, (array) ($config['local_url_suffixes'] ?? []));
      if (!$this->isLocalUriHost($host, $suffixes)) {
        $failures[] = "site URL is not local: $host (pass --uri)";
      }
    }

    foreach ((array) ($config['prod_markers'] ?? []) as $marker) {
      foreach (get_included_files() as $file) {
        if (basename($file) === $marker) {
          $failures[] = "production settings loaded: $marker";
          break;
        }
      }
    }
    foreach ((array) ($config['deny_uri_hosts'] ?? []) as $pattern) {
      if (fnmatch(strtolower((string) $pattern), $host)) {
        $failures[] = "site URL is a production host: $host";
        break;
      }
    }

    if ($failures) {
      throw new \Exception("Policy: sql:sanitize refused.\n  - " . implode("\n  - ", $failures));
    }
  }

  /**
   * Loopback, private-range IP, socket, SQLite or a dotless (Docker) name.
   */
  private function isLocalDbHost(string $driver, string $host): bool {
    $host = strtolower(trim($host, ' []'));
    if ($driver === 'sqlite' || $host === '' || $host === 'localhost') {
      return TRUE;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      return $host === '::1' || str_starts_with($host, '127.')
        || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === FALSE;
    }
    return !str_contains($host, '.');
  }

  /**
   * Whether a site hostname is localhost or ends in a local suffix.
   */
  private function isLocalUriHost(string $host, array $suffixes): bool {
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], TRUE)) {
      return TRUE;
    }
    foreach ($suffixes as $suffix) {
      if (str_ends_with($host, strtolower($suffix)) && strlen($host) > strlen($suffix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
