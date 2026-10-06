<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;
use SanitizeTestDb\EmailScrubber;
use SanitizeTestDb\Settings;

/**
 * Replaces email addresses found anywhere in config with the admin email.
 *
 * Skips config_email_exclude ("name" or "name:key.path" globs), keep_emails,
 * addresses already on the test domain and token-only values.
 */
final class ConfigEmailsStep extends StepBase {

  use ConfigWalker;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Config: replace email addresses';
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $changes = $this->describeConfigChanges($this->changes($context));
    return $changes ?: ['no email addresses to replace'];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    $this->writeConfigChanges($context, $this->changes($context));
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    return array_map(fn(string $line): string => "config email remains: $line", $this->describeConfigChanges($this->changes($context)));
  }

  /**
   * Computes config changes that replace email addresses.
   */
  private function changes(Context $context): array {
    $scrubber = $context->emailScrubber();
    $exclude = $context->settings->list('config_email_exclude', Settings::CONFIG_EMAIL_EXCLUDE);
    return $this->configChanges($context, function (string $name, array $data) use ($scrubber, $exclude): array {
      if (Settings::matchesConfigPath(array_filter($exclude, fn($p) => !str_contains($p, ':')), $name)) {
        return [$data, []];
      }
      $changes = [];
      $data = EmailScrubber::walk($data, function (string $value, string $path) use ($scrubber, $exclude, $name, &$changes): string {
        if (Settings::matchesConfigPath($exclude, $name, $path)) {
          return $value;
        }
        $count = 0;
        $new = $scrubber->scrub($value, $count);
        if ($count) {
          $changes[] = "$path ($count)";
        }
        return $new;
      });
      return [$data, $changes];
    });
  }

}
