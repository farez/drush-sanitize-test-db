<?php

declare(strict_types=1);

namespace SanitizeTestDb\Tests;

use PHPUnit\Framework\TestCase;
use SanitizeTestDb\EnvironmentGuard;
use SanitizeTestDb\Settings;

/**
 * Unit tests for EnvironmentGuard.
 */
final class EnvironmentGuardTest extends TestCase {

  /**
   * Guard with this package's defaults and a production marker.
   */
  private function guard(array $deny = []): EnvironmentGuard {
    return new EnvironmentGuard(Settings::LOCAL_URL_SUFFIXES, ['settings.prod.aws.php'], $deny);
  }

  /**
   * Facts for a passing DDEV environment, with overrides.
   */
  private function facts(array $overrides = []): array {
    return $overrides + [
      'env' => ['IS_DDEV_PROJECT' => 'true', 'LANDO' => FALSE],
      'allow_local' => NULL,
      'allowed_environment' => NULL,
      'db_driver' => 'mysql',
      'db_host' => 'db',
      'uri_host' => 'site.ddev.site',
      'included_files' => ['/var/www/html/web/sites/default/settings.php'],
    ];
  }

  /**
   * Tests: ddev is allowed.
   */
  public function testDdevIsAllowed(): void {
    $result = $this->guard()->evaluate($this->facts());
    $this->assertTrue($result->allowed);
    $this->assertSame('local', $result->route);
    $this->assertSame([], $result->failures());
  }

  /**
   * Tests: lando and setting opt ins.
   */
  public function testLandoAndSettingOptIns(): void {
    $this->assertTrue($this->guard()->evaluate($this->facts([
      'env' => ['IS_DDEV_PROJECT' => FALSE, 'LANDO' => 'ON'],
      'db_host' => 'database',
      'uri_host' => 'site.lndo.site',
    ]))->allowed);
    $this->assertTrue($this->guard()->evaluate($this->facts([
      'env' => [],
      'allow_local' => TRUE,
      'db_host' => '127.0.0.1',
      'uri_host' => 'site.test',
    ]))->allowed);
  }

  /**
   * Tests: no opt in is refused.
   */
  public function testNoOptInIsRefused(): void {
    $result = $this->guard()->evaluate($this->facts(['env' => [], 'allow_local' => 'yes']));
    $this->assertFalse($result->allowed);
    $this->assertStringContainsString('Local opt-in', implode("\n", $result->failures()));
  }

  /**
   * @dataProvider dbHosts
   */
  public function testDbHost(string $driver, string $host, bool $local): void {
    $this->assertSame($local, EnvironmentGuard::isLocalDbHost($driver, $host));
  }

  /**
   * Data provider: driver, host, expected locality.
   */
  public static function dbHosts(): array {
    return [
      ['mysql', 'db', TRUE],
      ['mysql', 'mariadb', TRUE],
      ['mysql', '', TRUE],
      ['mysql', 'localhost', TRUE],
      ['mysql', '127.0.0.1', TRUE],
      ['mysql', '::1', TRUE],
      ['mysql', '10.1.2.3', TRUE],
      ['mysql', '172.20.0.5', TRUE],
      ['mysql', '192.168.1.10', TRUE],
      ['sqlite', '', TRUE],
      ['mysql', 'prod.abc123.eu-west-2.rds.amazonaws.com', FALSE],
      ['mysql', '8.8.8.8', FALSE],
      ['pgsql', 'db.example.com', FALSE],
    ];
  }

  /**
   * @dataProvider uriHosts
   */
  public function testUriHost(string $host, bool $local): void {
    $this->assertSame($local, $this->guard()->isLocalUriHost($host));
  }

  /**
   * Data provider: hostname, expected locality.
   */
  public static function uriHosts(): array {
    return [
      ['swk.ddev.site', TRUE],
      ['site.lndo.site', TRUE],
      ['app.localhost', TRUE],
      ['site.test', TRUE],
      ['localhost', TRUE],
      ['default', FALSE],
      ['ddev.site', FALSE],
      ['localoffer.southwark.gov.uk', FALSE],
      ['example.test.com', FALSE],
    ];
  }

  /**
   * Tests: prod marker always refuses.
   */
  public function testProdMarkerAlwaysRefuses(): void {
    $result = $this->guard()->evaluate($this->facts([
      'included_files' => ['/app/web/sites/default/settings.prod.aws.php'],
    ]));
    $this->assertFalse($result->allowed);
  }

  /**
   * Tests: hosted opt in replaces local checks only.
   */
  public function testHostedOptInReplacesLocalChecksOnly(): void {
    $hosted = $this->facts([
      'env' => [],
      'db_host' => 'stage-db.example.com',
      'uri_host' => 'stage.example.com',
      'allowed_environment' => 'stage',
    ]);
    $result = $this->guard()->evaluate($hosted);
    $this->assertTrue($result->allowed);
    $this->assertSame('hosted', $result->route);
    $this->assertSame('stage', $result->environment);

    // Empty or non-string names are not an opt-in.
    $this->assertFalse($this->guard()->evaluate(['allowed_environment' => TRUE] + $hosted)->allowed);
    $this->assertFalse($this->guard()->evaluate(['allowed_environment' => ' '] + $hosted)->allowed);

    // Production markers and denied hosts still refuse.
    $this->assertFalse($this->guard()->evaluate(['included_files' => ['/x/settings.prod.aws.php']] + $hosted)->allowed);
    $this->assertFalse($this->guard(['stage.example.com'])->evaluate($hosted)->allowed);
    $this->assertFalse($this->guard(['*.example.com'])->evaluate($hosted)->allowed);
  }

  /**
   * Tests: denied host refuses local route too.
   */
  public function testDeniedHostRefusesLocalRouteToo(): void {
    $this->assertFalse($this->guard(['site.ddev.site'])->evaluate($this->facts())->allowed);
  }

}
