<?php

declare(strict_types=1);

namespace SanitizeTestDb;

/**
 * Per-site settings, read from the `sanitize_test_db` key in drush.yml.
 *
 * List settings are merged with the package defaults, so a site only lists
 * what it adds.
 */
final class Settings {

  public const LOCAL_URL_SUFFIXES = ['.ddev.site', '.lndo.site', '.localhost', '.test'];

  public const TABLES = [
    'watchdog',
    'flood',
    'queue',
    'batch',
    'semaphore',
    'key_value_expire',
    'history',
    'cachetags',
    'cache_*',
    'SimpleSAMLphp_*',
    // Transformed copies of config (export/import/snapshot): contain the
    // same emails and secrets as active config. Rebuilt on demand.
    'config_export',
    'config_import',
    'config_snapshot',
  ];

  public const TABLES_EXCLUDE = ['SimpleSAMLphp_tableVersion'];

  public const SECRET_KEYS = [
    'client_secret',
    'client_id',
    'secret_key',
    'secret',
    'password',
    'api_key',
    'apikey',
    'token',
    'access_token',
  ];

  /**
   * Webform's sample data for test submissions (example@example.com etc.).
   */
  public const CONFIG_EMAIL_EXCLUDE = ['*webform.settings:test.*'];

  public const REVIEWED_TABLES = [
    'config',
    'key_value',
    'router',
    'menu_tree',
    'sequences',
    'file_usage',
    'entity_usage',
    'locale_file',
    'locales_*',
    'search_api_*',
    'sessions',
    'SimpleSAMLphp_tableVersion',
    'date_recur__*',
    'migrate_map_*',
    'node_access',
    'simple_sitemap',
    'taxonomy_index',
    'webform',
  ];

  public const STATE_SECRET_PATTERN = '/(token|secret|api_?key|password)/i';

  public function __construct(private readonly array $values = []) {}

  /**
   * Returns a scalar setting.
   */
  public function get(string $key, mixed $default = NULL): mixed {
    return $this->values[$key] ?? $default;
  }

  /**
   * Returns a list setting merged with its defaults.
   *
   * @return string[]
   */
  public function list(string $key, array $defaults = []): array {
    $value = $this->values[$key] ?? [];
    return array_values(array_unique(array_merge($defaults, is_array($value) ? $value : [$value])));
  }

  /**
   * Field rules: "entity_type.field_name" => action.
   *
   * @return array<string, string>
   */
  public function fieldRules(): array {
    $rules = $this->values['field_rules'] ?? [];
    return is_array($rules) ? $rules : [];
  }

  /**
   * Matches a config name and key path against "name[:path]" glob patterns.
   *
   * A pattern without ":" matches the whole config object; with ":" it
   * matches a key path inside it (dot-separated).
   */
  public static function matchesConfigPath(array $patterns, string $name, ?string $path = NULL): bool {
    foreach ($patterns as $pattern) {
      [$name_pattern, $path_pattern] = array_pad(explode(':', $pattern, 2), 2, NULL);
      if (!fnmatch($name_pattern, $name)) {
        continue;
      }
      if ($path_pattern === NULL || ($path !== NULL && fnmatch($path_pattern, $path))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Matches a table name against glob patterns.
   */
  public static function matchesTable(array $patterns, string $table): bool {
    foreach ($patterns as $pattern) {
      if (fnmatch($pattern, $table)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
