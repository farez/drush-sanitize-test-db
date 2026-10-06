<?php

declare(strict_types=1);

namespace SanitizeTestDb\Step;

use SanitizeTestDb\Context;

/**
 * Anonymises usernames and sets uid 1's identity.
 *
 * Drush core's user plugin has already replaced emails and passwords; this
 * step runs after it, so uid 1's email ends up as --sanitize-admin-email.
 */
final class UsersStep extends StepBase {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Users: rename to user<uid>, set uid 1 identity, empty users_data';
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Context $context): bool {
    return $context->tableExists('users_field_data');
  }

  /**
   * {@inheritdoc}
   */
  public function plan(Context $context): array {
    $renames = 0;
    foreach ($this->names($context) as $uid => $name) {
      if ($uid > 1 && $name !== "user$uid") {
        $renames++;
      }
    }
    $admin = $context->database->select('users_field_data', 'u')
      ->fields('u', ['name', 'mail'])
      ->condition('uid', 1)
      ->execute()->fetchAssoc();
    $lines = ["$renames usernames → user<uid>"];
    if ($admin) {
      $lines[] = sprintf('uid 1: name "%s" → "%s", mail "%s" → "%s", status → active',
        $admin['name'], $context->adminName(), $admin['mail'], $context->adminEmail());
    }
    if ($context->tableExists('users_data')) {
      $lines[] = 'users_data: ' . $context->rowCount('users_data') . ' rows emptied';
    }
    return $lines;
  }

  /**
   * {@inheritdoc}
   */
  public function apply(Context $context): void {
    // Rename others first so uid 1's new name cannot collide.
    foreach ($this->names($context) as $uid => $name) {
      if ($uid > 1 && $name !== "user$uid") {
        $context->database->update('users_field_data')
          ->fields(['name' => "user$uid"])
          ->condition('uid', $uid)
          ->execute();
      }
    }
    $context->database->update('users_field_data')
      ->fields([
        'name' => $context->adminName(),
        'mail' => $context->adminEmail(),
        'init' => $context->adminEmail(),
        'status' => 1,
      ])
      ->condition('uid', 1)
      ->execute();
    if ($context->tableExists('users_data')) {
      $context->database->truncate('users_data')->execute();
    }
    $context->entityTypeManager()->getStorage('user')->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  public function verify(Context $context): array {
    $failures = [];
    foreach ($this->names($context) as $uid => $name) {
      if ($uid > 1 && $name !== "user$uid") {
        $failures[] = "uid $uid is still named \"$name\"";
      }
    }
    $admin = $context->database->select('users_field_data', 'u')
      ->fields('u', ['name', 'mail'])
      ->condition('uid', 1)
      ->execute()->fetchAssoc();
    if ($admin && ($admin['name'] !== $context->adminName() || $admin['mail'] !== $context->adminEmail())) {
      $failures[] = 'uid 1 does not have the admin name and email';
    }

    // When Drush core sanitised emails with a %uid-only pattern, check them.
    $pattern = $context->options['sanitize-email'] ?? NULL;
    if ($context->enabled('sanitize-email') && is_string($pattern) && !preg_match('/%(name|mail)/', $pattern)) {
      $mails = $context->database->select('users_field_data', 'u')
        ->fields('u', ['uid', 'mail'])
        ->condition('uid', 1, '>')
        ->execute()->fetchAllKeyed();
      $wrong = 0;
      foreach ($mails as $uid => $mail) {
        if ($mail !== str_replace('%uid', (string) $uid, $pattern)) {
          $wrong++;
        }
      }
      if ($wrong) {
        $failures[] = "$wrong user emails do not match the pattern $pattern";
      }
    }
    if ($context->tableExists('users_data') && $context->rowCount('users_data')) {
      $failures[] = 'users_data is not empty';
    }
    return $failures;
  }

  /**
   * {@inheritdoc}
   */
  public function coveredTables(Context $context): array {
    return ['users_data'];
  }

  /**
   * @return array<int, string>
   */
  private function names(Context $context): array {
    return $context->database->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->condition('uid', 0, '>')
      ->execute()->fetchAllKeyed();
  }

}
