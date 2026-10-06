<?php

declare(strict_types=1);

namespace SanitizeTestDb;

use SanitizeTestDb\Step\ConfigEmailsStep;
use SanitizeTestDb\Step\FieldRulesStep;
use SanitizeTestDb\Step\GroupStep;
use SanitizeTestDb\Step\RevisionLogStep;
use SanitizeTestDb\Step\SecretsStep;
use SanitizeTestDb\Step\StepInterface;
use SanitizeTestDb\Step\TablesStep;
use SanitizeTestDb\Step\TruncateStep;
use SanitizeTestDb\Step\UsersStep;
use SanitizeTestDb\Step\WebformStep;

/**
 * Runs the steps that apply to this site, in order.
 */
final class Runner {

  /**
   * @var \SanitizeTestDb\Step\StepInterface[]
   */
  private array $steps;

  public function __construct(private readonly Context $context) {
    $this->steps = array_values(array_filter(self::allSteps(), fn(StepInterface $step): bool => $step->applies($context)));
  }

  /**
   * Order matters: config/state before tables, tables (caches) last.
   *
   * @return \SanitizeTestDb\Step\StepInterface[]
   */
  public static function allSteps(): array {
    return [
      new UsersStep(),
      new TruncateStep('External auth: empty authmap', ['authmap']),
      new GroupStep(),
      new ConfigEmailsStep(),
      new WebformStep(),
      new SecretsStep(),
      new RevisionLogStep(),
      new FieldRulesStep(),
      new TablesStep(),
    ];
  }

  /**
   * @return \SanitizeTestDb\Step\StepInterface[]
   */
  public function steps(): array {
    return $this->steps;
  }

  /**
   * @return string[]
   */
  public function errors(): array {
    $errors = [];
    foreach ($this->steps as $step) {
      array_push($errors, ...$step->errors($this->context));
    }
    return $errors;
  }

  /**
   * @return array<string, string[]>
   *   Planned changes keyed by step label.
   */
  public function plan(): array {
    $plan = [];
    foreach ($this->steps as $step) {
      $plan[$step->label()] = $step->plan($this->context);
    }
    return $plan;
  }

  /**
   * @param callable $log
   *   Receives each step label as it completes.
   */
  public function apply(callable $log): void {
    foreach ($this->steps as $step) {
      $step->apply($this->context);
      $log($step->label());
    }
  }

  /**
   * Counts that sanitising must not change.
   *
   * @return array<string, int>
   */
  public function baseline(): array {
    $counts = [];
    foreach (['node', 'user', 'taxonomy_term', 'media', 'group'] as $entity_type_id) {
      if ($this->context->entityTypeManager()->hasDefinition($entity_type_id)) {
        $counts[$entity_type_id] = (int) $this->context->entityTypeManager()->getStorage($entity_type_id)
          ->getQuery()->accessCheck(FALSE)->count()->execute();
      }
    }
    return $counts;
  }

  /**
   * @return string[]
   */
  public function verify(array $baseline): array {
    $failures = [];
    foreach ($this->baseline() as $entity_type_id => $count) {
      if (isset($baseline[$entity_type_id]) && $baseline[$entity_type_id] !== $count) {
        $failures[] = "$entity_type_id count changed from {$baseline[$entity_type_id]} to $count";
      }
    }
    foreach ($this->steps as $step) {
      array_push($failures, ...$step->verify($this->context));
    }
    return $failures;
  }

}
