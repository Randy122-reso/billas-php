<?php

declare(strict_types=1);

use Billas\Support\Env;

/**
 * PDO SQLite factory with WAL, busy timeout and foreign keys.
 *
 * Expected variables in scope: $env, $root.
 */
/** @var Env $env */
/** @var string $root */
$path = $env->string('DB_PATH', $root . '/var/billas.db');
if ($path !== ':memory:' && !str_starts_with($path, 'file:')) {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Tidak dapat membuat direktori database: ' . $dir);
    }
}

$dsn = str_starts_with($path, 'file:') ? $path : 'sqlite:' . $path;
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA synchronous = NORMAL');

return $pdo;
