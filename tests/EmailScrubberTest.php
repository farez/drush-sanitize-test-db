<?php

declare(strict_types=1);

namespace SanitizeTestDb\Tests;

use PHPUnit\Framework\TestCase;
use SanitizeTestDb\EmailScrubber;
use SanitizeTestDb\Settings;

/**
 * Unit tests for EmailScrubber.
 */
final class EmailScrubberTest extends TestCase {

  /**
   * Scrubber with a test domain and one kept address.
   */
  private function scrubber(): EmailScrubber {
    return new EmailScrubber('admin@example.com', 'example.com', ['Team@Council.gov.uk']);
  }

  /**
   * Tests: replaces addresses and counts.
   */
  public function testReplacesAddressesAndCounts(): void {
    $count = 0;
    $result = $this->scrubber()->scrub('jane.doe@council.gov.uk, bob+x@mail.co.uk', $count);
    $this->assertSame('admin@example.com, admin@example.com', $result);
    $this->assertSame(2, $count);
  }

  /**
   * Tests: keeps safe and kept addresses and tokens.
   */
  public function testKeepsSafeAndKeptAddressesAndTokens(): void {
    $count = 0;
    $value = 'Email team@council.gov.uk or user+3@example.com. [webform_submission:values:email]';
    $this->assertSame($value, $this->scrubber()->scrub($value, $count));
    $this->assertSame(0, $count);
    $this->assertSame([], $this->scrubber()->findUnsafe($value));
  }

  /**
   * Tests: scrub is idempotent.
   */
  public function testScrubIsIdempotent(): void {
    $once = $this->scrubber()->scrub('a@b.com');
    $this->assertSame($once, $this->scrubber()->scrub($once));
  }

  /**
   * Tests: walk preserves structure and types.
   */
  public function testWalkPreservesStructureAndTypes(): void {
    $data = ['a' => ['b' => 'x@y.com', 'n' => 3, 't' => TRUE], 'list' => ['p@q.org']];
    $paths = [];
    $result = EmailScrubber::walk($data, function (string $value, string $path) use (&$paths): string {
      $paths[] = $path;
      return $this->scrubber()->scrub($value);
    });
    $this->assertSame(['a.b', 'list.0'], $paths);
    $this->assertSame(['a' => ['b' => 'admin@example.com', 'n' => 3, 't' => TRUE], 'list' => ['admin@example.com']], $result);
    // Still serializable and round-trips.
    $this->assertSame($result, unserialize(serialize($result)));
  }

  /**
   * Tests: config path matching.
   */
  public function testConfigPathMatching(): void {
    $patterns = ['update.settings', '*webform.settings:test.*'];
    $this->assertTrue(Settings::matchesConfigPath($patterns, 'update.settings'));
    $this->assertTrue(Settings::matchesConfigPath($patterns, 'localgov_forms.webform.settings', 'test.types'));
    $this->assertFalse(Settings::matchesConfigPath($patterns, 'webform.settings', 'settings.default_from_mail'));
    $this->assertFalse(Settings::matchesConfigPath($patterns, 'webform.settings'));
  }

}
