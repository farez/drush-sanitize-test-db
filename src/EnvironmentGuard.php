<?php

declare(strict_types=1);

namespace SanitizeTestDb;

/**
 * Decides whether sql:sanitize may run in the current environment.
 *
 * Fails closed. It allows a run only when either route is satisfied:
 * - local: a local opt-in (DDEV, Lando or the allow_sql_sanitize setting),
 *   a local database host and a local site URL; or
 * - hosted: $settings['sanitize_test_db_allowed_environment'] names a
 *   non-local environment (e.g. 'stage').
 * In both cases no production marker file may be loaded and the site URI
 * must not be in the deny list.
 *
 * Pure: takes collected facts, so it can be unit tested without Drupal.
 */
final class EnvironmentGuard {

  /**
   * @param string[] $localUrlSuffixes
   * @param string[] $prodMarkers
   *   File names (or path suffixes) that only production loads.
   * @param string[] $denyUriHosts
   *   Production hostnames (glob patterns) that are always refused.
   */
  public function __construct(
    private readonly array $localUrlSuffixes,
    private readonly array $prodMarkers,
    private readonly array $denyUriHosts,
  ) {}

  /**
   * @param array{
   *   env: array<string, string|false>,
   *   allow_local: mixed,
   *   allowed_environment: mixed,
   *   db_driver: string,
   *   db_host: string,
   *   uri_host: string,
   *   included_files: string[],
   *   } $facts
   */
  public function evaluate(array $facts): GuardResult {
    $checks = [];

    // Local route.
    $opt_in = $this->localOptIn($facts);
    $checks[] = [
      'name' => 'Local opt-in',
      'passed' => $opt_in !== NULL,
      'detail' => $opt_in ?? 'none of IS_DDEV_PROJECT=true, LANDO=ON or $settings[\'allow_sql_sanitize\'] = TRUE',
    ];
    $db_local = self::isLocalDbHost($facts['db_driver'], $facts['db_host']);
    $checks[] = [
      'name' => 'Local database host',
      'passed' => $db_local,
      'detail' => $facts['db_driver'] === 'sqlite' ? 'sqlite' : ($facts['db_host'] === '' ? '(socket)' : $facts['db_host']),
    ];
    $uri_local = $this->isLocalUriHost($facts['uri_host']);
    $checks[] = [
      'name' => 'Local site URL',
      'passed' => $uri_local,
      'detail' => ($facts['uri_host'] ?: '(none)') . ($uri_local ? '' : ' (allowed: localhost, ' . implode(', ', $this->localUrlSuffixes) . '; pass --uri)'),
    ];
    $local_route = $opt_in !== NULL && $db_local && $uri_local;

    // Hosted route: replaces the three local checks only.
    $environment = is_string($facts['allowed_environment']) && trim($facts['allowed_environment']) !== ''
      ? trim($facts['allowed_environment'])
      : NULL;
    $hosted_route = !$local_route && $environment !== NULL;
    if ($environment !== NULL) {
      $checks[] = [
        'name' => 'Hosted environment opt-in',
        'passed' => TRUE,
        'detail' => $local_route ? "$environment (not needed: local checks pass)" : $environment,
      ];
    }
    if ($hosted_route) {
      // Local checks are superseded; report them as informational.
      foreach ($checks as &$check) {
        if (!$check['passed']) {
          $check['passed'] = TRUE;
          $check['detail'] .= ' (superseded by hosted opt-in)';
        }
      }
      unset($check);
    }

    // Checks that apply on every route.
    $markers = $this->loadedProdMarkers($facts['included_files']);
    $checks[] = [
      'name' => 'No production settings loaded',
      'passed' => $markers === [],
      'detail' => $markers === [] ? ($this->prodMarkers ? 'none of ' . implode(', ', $this->prodMarkers) : 'no prod_markers configured') : 'loaded: ' . implode(', ', $markers),
    ];
    $denied = $this->isDeniedUriHost($facts['uri_host']);
    $checks[] = [
      'name' => 'Site URL not a production host',
      'passed' => !$denied,
      'detail' => $denied ? "{$facts['uri_host']} is in deny_uri_hosts" : ($this->denyUriHosts ? 'not in deny_uri_hosts' : 'no deny_uri_hosts configured'),
    ];

    $allowed = ($local_route || $hosted_route) && $markers === [] && !$denied;
    $route = $local_route ? 'local' : ($hosted_route ? 'hosted' : '');
    return new GuardResult($allowed, $route, $checks, $hosted_route ? $environment : NULL);
  }

  /**
   * Describes the local opt-in that applies, or NULL when none does.
   */
  private function localOptIn(array $facts): ?string {
    if (($facts['env']['IS_DDEV_PROJECT'] ?? FALSE) === 'true') {
      return 'DDEV (IS_DDEV_PROJECT=true)';
    }
    if (($facts['env']['LANDO'] ?? FALSE) === 'ON') {
      return 'Lando (LANDO=ON)';
    }
    if ($facts['allow_local'] === TRUE) {
      return "\$settings['allow_sql_sanitize'] = TRUE";
    }
    return NULL;
  }

  /**
   * Loopback, private-range IP, socket, SQLite or a dotless (Docker) name.
   */
  public static function isLocalDbHost(string $driver, string $host): bool {
    if ($driver === 'sqlite') {
      return TRUE;
    }
    $host = strtolower(trim($host, " []"));
    if ($host === '' || $host === 'localhost') {
      return TRUE;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      if ($host === '::1' || str_starts_with($host, '127.')) {
        return TRUE;
      }
      // Valid IP that fails the "no private range" filter is private.
      return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === FALSE;
    }
    return !str_contains($host, '.');
  }

  /**
   * Whether a site hostname is local (localhost or a local suffix).
   */
  public function isLocalUriHost(string $host): bool {
    $host = strtolower(trim($host));
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
      return TRUE;
    }
    foreach ($this->localUrlSuffixes as $suffix) {
      $suffix = strtolower($suffix);
      if ($suffix !== '' && str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a site hostname matches deny_uri_hosts.
   */
  private function isDeniedUriHost(string $host): bool {
    $host = strtolower($host);
    foreach ($this->denyUriHosts as $pattern) {
      if (fnmatch(strtolower($pattern), $host)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return string[]
   */
  private function loadedProdMarkers(array $included_files): array {
    $loaded = [];
    foreach ($this->prodMarkers as $marker) {
      foreach ($included_files as $file) {
        if (basename($file) === $marker || str_ends_with($file, '/' . ltrim($marker, '/'))) {
          $loaded[] = $marker;
          break;
        }
      }
    }
    return $loaded;
  }

}
