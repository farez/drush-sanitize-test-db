<?php

declare(strict_types=1);

namespace SanitizeTestDb;

/**
 * Finds and replaces email addresses inside string values.
 *
 * Addresses on the safe (test) domain and addresses in the keep list are left
 * alone, so scrubbing is idempotent and public addresses survive.
 */
final class EmailScrubber {

  public const PATTERN = '/[A-Z0-9._%+\'-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i';

  /**
   * @var string[]
   */
  private array $keep;

  /**
   * @param string $replacement
   *   Address to substitute.
   * @param string $safeDomain
   *   Addresses on this domain are treated as already sanitised.
   * @param string[] $keepEmails
   *   Addresses to keep (case-insensitive).
   */
  public function __construct(
    private readonly string $replacement,
    private readonly string $safeDomain,
    array $keepEmails = [],
  ) {
    $this->keep = array_map('strtolower', $keepEmails);
  }

  /**
   * Whether an address is kept, on the safe domain or the replacement.
   */
  public function isSafe(string $email): bool {
    $email = strtolower($email);
    return in_array($email, $this->keep, TRUE)
      || str_ends_with($email, '@' . strtolower($this->safeDomain))
      || $email === strtolower($this->replacement);
  }

  /**
   * Returns the addresses in $value that would be replaced.
   *
   * @return string[]
   */
  public function findUnsafe(string $value): array {
    if (!str_contains($value, '@') || !preg_match_all(self::PATTERN, $value, $matches)) {
      return [];
    }
    return array_values(array_filter($matches[0], fn(string $email): bool => !$this->isSafe($email)));
  }

  /**
   * Replaces every unsafe address in $value; $count receives the number.
   */
  public function scrub(string $value, int &$count = 0): string {
    if (!str_contains($value, '@')) {
      return $value;
    }
    return preg_replace_callback(self::PATTERN, function (array $match) use (&$count): string {
      if ($this->isSafe($match[0])) {
        return $match[0];
      }
      $count++;
      return $this->replacement;
    }, $value);
  }

  /**
   * Applies $callback to every string leaf of $data, with its dot path.
   *
   * The callback returns the new value. Non-string leaves are untouched.
   */
  public static function walk(array $data, callable $callback, string $prefix = ''): array {
    foreach ($data as $key => $value) {
      $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
      if (is_array($value)) {
        $data[$key] = self::walk($value, $callback, $path);
      }
      elseif (is_string($value)) {
        $data[$key] = $callback($value, $path, (string) $key);
      }
    }
    return $data;
  }

}
