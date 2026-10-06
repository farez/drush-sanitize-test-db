<?php

declare(strict_types=1);

namespace SanitizeTestDb;

/**
 * Outcome of the environment checks.
 */
final class GuardResult {

  /**
   * @param bool $allowed
   *   Whether sanitising may run.
   * @param string $route
   *   'local', 'hosted' or '' when neither route was satisfied.
   * @param array<int, array{name: string, passed: bool, detail: string}> $checks
   *   Every check, for reporting.
   * @param string|null $environment
   *   The named hosted environment, when the hosted route was used.
   */
  public function __construct(
    public readonly bool $allowed,
    public readonly string $route,
    public readonly array $checks,
    public readonly ?string $environment = NULL,
  ) {}

  /**
   * @return string[]
   */
  public function failures(): array {
    $failures = [];
    foreach ($this->checks as $check) {
      if (!$check['passed']) {
        $failures[] = "{$check['name']}: {$check['detail']}";
      }
    }
    return $failures;
  }

}
