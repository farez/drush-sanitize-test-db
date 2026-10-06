<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use Drupal\Component\Utility\Crypt;
use SanitizeTestDb\Context;
use SanitizeTestDb\EmailScrubber;
use SanitizeTestDb\Settings;

/**
 * Removes secrets from config and state. Never reports secret values.
 */
final class SecretsStep extends StepBase {

  use ConfigWalker;

  /**
   * Google's public reCAPTCHA test keys, so forms still render.
   */
  private const RECAPTCHA_TEST_KEYS = [
    'site_key' => '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI',
    'secret_key' => '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe',
  ];

  private const RECAPTCHA_CONFIG = ['recaptcha.settings', 'recaptcha_v3.settings'];

  private const REGENERATED_STATE = ['system.private_key', 'system.cron_key'];

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Secrets: blank config secrets, reCAPTCHA test keys, new private/cron keys, delete secret-like state';
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $lines = $this->describeConfigChanges($this->changes($context));
    $lines[] = 'state regenerated: ' . implode(', ', self::REGENERATED_STATE);
    $state = $this->secretStateKeys($context);
    $lines[] = $state ? 'state deleted: ' . implode(', ', $state) : 'no secret-like state keys';
    return $lines;
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    $this->writeConfigChanges($context, $this->changes($context));
    $state = \Drupal::state();
    foreach (self::REGENERATED_STATE as $key) {
      $state->set($key, Crypt::randomBytesBase64(55));
    }
    if ($keys = $this->secretStateKeys($context)) {
      $state->deleteMultiple($keys);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = array_map(fn(string $line): string => "config secret remains: $line", $this->describeConfigChanges($this->changes($context)));
    foreach ($this->secretStateKeys($context) as $key) {
      $failures[] = "state key remains: $key";
    }
    return $failures;
  }

  /**
   * Computes config changes that remove secrets.
   */
  private function changes(Context $context): array {
    $keys = array_map('strtolower', $context->settings->list('secret_keys', Settings::SECRET_KEYS));
    $exclude = $context->settings->list('secret_exclude');

    return $this->configChanges($context, function (string $name, array $data) use ($keys, $exclude): array {
      $changes = [];
      $is_recaptcha = in_array($name, self::RECAPTCHA_CONFIG, TRUE);

      $data = EmailScrubber::walk($data, function (string $value, string $path, string $key) use ($keys, $exclude, $name, $is_recaptcha, &$changes): string {
        if ($is_recaptcha && isset(self::RECAPTCHA_TEST_KEYS[$path])) {
          if ($value !== self::RECAPTCHA_TEST_KEYS[$path]) {
            $changes[] = "$path (reCAPTCHA test key)";
          }
          return self::RECAPTCHA_TEST_KEYS[$path];
        }
        // Identifier maps (e.g. Webform excluded_elements.password: password)
        // are not secrets.
        if ($value !== '' && $value !== $key && in_array(strtolower($key), $keys, TRUE) && !Settings::matchesConfigPath($exclude, $name, $path)) {
          $changes[] = "$path (blanked)";
          return '';
        }
        return $value;
      });

      // Key module: values stored in config by the "config" provider.
      if (str_starts_with($name, 'key.key.') && ($data['key_provider'] ?? NULL) === 'config'
        && ($data['key_provider_settings']['key_value'] ?? '') !== '') {
        $data['key_provider_settings']['key_value'] = '';
        $changes[] = 'key_provider_settings.key_value (blanked)';
      }
      return [$data, $changes];
    });
  }

  /**
   * @return string[]
   */
  private function secretStateKeys(Context $context): array {
    $pattern = (string) $context->settings->get('state_secret_pattern', Settings::STATE_SECRET_PATTERN);
    $keys = array_keys(\Drupal::keyValue('state')->getAll());
    return array_values(array_filter($keys, fn(string $key): bool => !in_array($key, self::REGENERATED_STATE, TRUE) && preg_match($pattern, $key)));
  }

}
