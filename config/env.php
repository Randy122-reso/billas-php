<?php

declare(strict_types=1);

use Billas\Support\Env;

/**
 * Loads .env and validates critical configuration.
 *
 * Expected variables in scope: $root (absolute project path).
 */
/** @var string $root */
$env = new Env($root);
$env->load();
$env->validate();
Env::setInstance($env);

return $env;
