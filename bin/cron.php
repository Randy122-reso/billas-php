<?php

declare(strict_types=1);

use Billas\Domain\CronService;
use Billas\Kernel;

/**
 * Manual CLI cron trigger (debug / host crontab fallback).
 *
 *   php bin/cron.php
 *   php bin/cron.php --force
 *   php bin/cron.php --tasks=vouchers,order_expire
 *   php bin/cron.php vouchers order_expire
 *
 * The HTTP endpoint POST /internal/cron/run remains the primary trigger for
 * RouterOS Scheduler. This script is useful when no router scheduler exists.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$force = false;
$tasks = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force' || $arg === '-f') {
        $force = true;
        continue;
    }
    if (str_starts_with($arg, '--tasks=')) {
        foreach (explode(',', substr($arg, 8)) as $task) {
            $task = trim($task);
            if ($task !== '') {
                $tasks[] = $task;
            }
        }
        continue;
    }
    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, 'Opsi tidak dikenal: ' . $arg . PHP_EOL);
        exit(2);
    }
    $tasks[] = $arg;
}

try {
    $services = Kernel::services($root);
    /** @var CronService $cron */
    $cron = $services['container']->get(CronService::class);
    $result = $cron->tick(array_values(array_unique($tasks)), $force);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

    $errors = 0;
    foreach (($result['tasks'] ?? []) as $task => $info) {
        if (is_array($info) && ($info['status'] ?? '') === 'error') {
            $errors++;
        }
    }
    exit($errors > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cron gagal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
