<?php

declare(strict_types=1);

namespace SanitizeTestDb\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings as DrupalSettings;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use SanitizeTestDb\Context;
use SanitizeTestDb\Coverage;
use SanitizeTestDb\EnvironmentGuard;
use SanitizeTestDb\GuardResult;
use SanitizeTestDb\Runner;
use SanitizeTestDb\SanitizePluginInterface;
use SanitizeTestDb\Settings;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Extends sql:sanitize for local test databases.
 *
 * - A fail-closed environment guard (argument validator) that stops the whole
 *   sql:sanitize command, Drush core and contrib plugins included, outside a
 *   local or explicitly named hosted environment.
 * - --dry-run: reports every planned change and stops before the prompt.
 * - Extra sanitisation steps, run after Drush core's and contrib plugins
 *   (a "*" post-command hook runs after hooks targeting sql:sanitize).
 *
 * Discovered by Drush via PSR-4: <PSR-4 prefix>\Drush\Commands\*DrushCommands.
 */
final class SanitizeTestDbDrushCommands extends DrushCommands implements SanitizePluginInterface {

  private const COMMAND = 'sql:sanitize';

  private const CONFIRMS = 'sql-sanitize-confirms';

  private static ?GuardResult $guard = NULL;

  private static array $baseline = [];

  /**
 *
 */
  #[CLI\Hook(type: HookManager::OPTION_HOOK, target: self::COMMAND)]
  #[CLI\Option(name: 'sanitize-test-db', description: 'Run the test-database sanitisation steps (users, config emails, secrets, field rules, tables). Specify <info>no</info> to skip them; the environment guard always applies.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would be sanitised, then stop without changing anything.')]
  #[CLI\Option(name: 'sanitize-admin-name', description: 'Username for uid 1.')]
  #[CLI\Option(name: 'sanitize-admin-email', description: 'Email for uid 1; also replaces emails found in config.')]
  public function options(
    $options = [
      'sanitize-test-db' => NULL,
      'dry-run' => FALSE,
      'sanitize-admin-name' => 'admin',
      'sanitize-admin-email' => 'admin@example.com',
    ],
  ): void {
  }

  /**
   * Environment guard. Runs before the prompt and before every plugin.
   */
  #[CLI\Hook(type: HookManager::ARGUMENT_VALIDATOR, target: self::COMMAND)]
  public function validateEnvironment(CommandData $commandData): void {
    self::$guard = NULL;
    if (!\Drupal::hasContainer()) {
      throw new \Exception('sql:sanitize refused: Drupal is not bootstrapped, so the environment cannot be checked.');
    }
    $settings = $this->settings();
    $guard = (new EnvironmentGuard(
      $settings->list('local_url_suffixes', Settings::LOCAL_URL_SUFFIXES),
      $settings->list('prod_markers'),
      $settings->list('deny_uri_hosts'),
    ))->evaluate($this->facts());

    if (!$guard->allowed) {
      throw new \Exception("sql:sanitize refused: this does not look like a local environment.\n  - "
        . implode("\n  - ", $guard->failures())
        . "\nFor a local setup that is not DDEV or Lando, add \$settings['allow_sql_sanitize'] = TRUE; to settings.local.php.");
    }

    $context = Context::create($settings, $commandData->options());
    $runner = new Runner($context);
    $errors = $context->enabled('sanitize-test-db') ? $runner->errors() : [];

    if ($context->enabled('dry-run', FALSE)) {
      $this->report($guard, $context, $runner, $errors);
      throw new UserAbortException('Dry run: no changes made.');
    }
    if ($errors) {
      throw new \Exception("sql:sanitize refused: sanitize_test_db configuration errors:\n  - " . implode("\n  - ", $errors));
    }

    if ($guard->route === 'hosted') {
      $this->logger()->warning(dt('sql:sanitize is running on the hosted environment "@env".', ['@env' => $guard->environment]));
      \Drupal::logger('sanitize_test_db')->warning('sql:sanitize run on hosted environment @env', ['@env' => $guard->environment]);
    }
    self::$guard = $guard;
    self::$baseline = $runner->baseline();
  }

  /**
   * Lists our operations in sql:sanitize's confirmation.
   */
  #[CLI\Hook(type: HookManager::ON_EVENT, target: self::CONFIRMS)]
  public function messages(array &$messages, InputInterface $input): void {
    if (self::$guard?->route === 'hosted') {
      $messages[] = dt('HOSTED ENVIRONMENT: @env', ['@env' => self::$guard->environment]);
    }
    $context = Context::create($this->settings(), $input->getOptions());
    if (!$context->enabled('sanitize-test-db')) {
      return;
    }
    foreach ((new Runner($context))->steps() as $step) {
      $messages[] = $step->label();
    }
  }

  /**
   * Runs our steps after Drush core's and contrib sanitisers, then verifies.
   */
  #[CLI\Hook(type: HookManager::POST_COMMAND_HOOK, target: '*')]
  public function sanitize($result, CommandData $commandData): void {
    if ($commandData->annotationData()->get('command') !== self::COMMAND || self::$guard === NULL) {
      return;
    }
    $context = Context::create($this->settings(), $commandData->options());
    if (!$context->enabled('sanitize-test-db')) {
      return;
    }
    $runner = new Runner($context);
    $runner->apply(fn(string $label) => $this->logger()->success($label));

    $failures = $runner->verify(self::$baseline);
    if ($failures) {
      throw new \Exception("Sanitisation verification failed:\n  - " . implode("\n  - ", $failures));
    }
    $this->logger()->success(dt('Verified: test database sanitised.'));
  }

  /**
   * Site settings from the sanitize_test_db key in drush.yml.
   */
  private function settings(): Settings {
    return new Settings((array) $this->getConfig()->get('sanitize_test_db', []));
  }

  /**
   * Collects the facts the environment guard evaluates.
   */
  private function facts(): array {
    $info = Database::getConnectionInfo()['default'] ?? [];
    return [
      'env' => [
        'IS_DDEV_PROJECT' => getenv('IS_DDEV_PROJECT'),
        'LANDO' => getenv('LANDO'),
      ],
      'allow_local' => DrupalSettings::get('allow_sql_sanitize'),
      'allowed_environment' => DrupalSettings::get('sanitize_test_db_allowed_environment'),
      'db_driver' => (string) ($info['driver'] ?? ''),
      'db_host' => (string) ($info['host'] ?? ''),
      'uri_host' => $this->uriHost(),
      'included_files' => get_included_files(),
    ];
  }

  /**
   * Hostname of the site URI Drush is running against.
   */
  private function uriHost(): string {
    if (\Drupal::hasRequest()) {
      return \Drupal::request()->getHost();
    }
    return (string) (parse_url((string) $this->getConfig()->get('options.uri'), PHP_URL_HOST) ?? '');
  }

  /**
   * Prints the dry-run report.
   */
  private function report(GuardResult $guard, Context $context, Runner $runner, array $errors): void {
    $io = $this->io();
    $io->title('sql:sanitize --dry-run (no changes will be made)');

    $io->section('Environment checks (route: ' . $guard->route . ($guard->environment ? ", environment: {$guard->environment}" : '') . ')');
    $io->listing(array_map(fn(array $c): string => ($c['passed'] ? '[pass] ' : '[FAIL] ') . "{$c['name']}: {$c['detail']}", $guard->checks));

    $io->section('Drush core');
    $io->listing($this->coreLines($context));

    if ($context->moduleExists('webform') && $context->enabled('sanitize-webform-submissions')) {
      $io->section('Webform (its own sql:sanitize plugin)');
      $io->listing(array_map(
        fn(string $t): string => "$t: " . ($context->tableExists($t) ? $context->rowCount($t) : 0) . ' rows emptied',
        ['webform_submission', 'webform_submission_data', 'webform_submission_log'],
      ));
    }

    if (!$context->enabled('sanitize-test-db')) {
      $io->note('--sanitize-test-db=no: the sanitize_test_db steps are skipped.');
    }
    else {
      foreach ($runner->plan() as $label => $lines) {
        $io->section($label);
        $io->listing($lines ?: ['nothing to do']);
      }
    }

    if ($errors) {
      $io->section('Configuration errors (a real run would stop)');
      $io->listing($errors);
    }

    $io->section('Coverage: tables with data that nothing sanitises (add to reviewed_tables or extra_tables)');
    $tables = Coverage::uncoveredTables($context, $runner);
    $io->listing($tables ? array_map(fn($t, $n) => "$t ($n rows)", array_keys($tables), $tables) : ['none']);

    $io->section('Coverage: likely personal-data fields without a field rule (add to field_rules)');
    $fields = Coverage::uncoveredFields($context);
    $io->listing($fields ? array_map(fn($f, $n) => "$f ($n values)", array_keys($fields), $fields) : ['none']);

    $io->section('Baseline (must be unchanged after a real run)');
    $baseline = $runner->baseline();
    $io->listing(array_map(fn($k, $v) => "$k: $v", array_keys($baseline), $baseline));
  }

  /**
   * @return string[]
   */
  private function coreLines(Context $context): array {
    $lines = [];
    $users = $context->tableExists('users_field_data')
      ? (int) $context->database->select('users_field_data')->condition('uid', 0, '>')->countQuery()->execute()->fetchField()
      : 0;
    if ($context->enabled('sanitize-email')) {
      $lines[] = "$users user emails → " . ($context->options['sanitize-email'] ?? 'user+%uid@localhost.localdomain');
    }
    if ($context->enabled('sanitize-password')) {
      $lines[] = "$users user passwords → " . (($context->options['sanitize-password'] ?? NULL) === NULL ? 'random' : 'the --sanitize-password value');
    }
    if ($context->tableExists('sessions')) {
      $lines[] = 'sessions: ' . $context->rowCount('sessions') . ' rows emptied';
    }
    $lines[] = 'user field values overwritten (except --allowlist-fields)';
    return $lines;
  }

}
