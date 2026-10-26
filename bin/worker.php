<?php

declare(strict_types=1);

use Billas\Domain\RetryService;
use Billas\Kernel;
use Billas\Support\Time;

/**
 * Billas v4 retry worker.
 *
 * Drains retry_queue in a long-running loop. Designed for the billas-worker
 * container (PHP CLI, UID 1000) and exits cleanly on SIGTERM/SIGINT.
 *
 *   php bin/worker.php                 # loop forever
 *   php bin/worker.php --once          # drain one batch and exit
 *   php bin/worker.php --max-loops=5   # bounded loop
 *   php bin/worker.php --health        # container health probe
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $body = substr($arg, 2);
    $eq = strpos($body, '=');
    if ($eq === false) {
        $options[$body] = true;
    } else {
        $options[substr($body, 0, $eq)] = substr($body, $eq + 1);
    }
}

try {
    $services = Kernel::services($root);
} catch (Throwable $e) {
    fwrite(STDERR, '[billas-worker] boot failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** @var Psr\Log\LoggerInterface $logger */
$logger = $services['logger'];
/** @var RetryService $retry */
$retry = $services['container']->get(RetryService::class);
/** @var PDO $db */
$db = $services['db'];

if (isset($options['health'])) {
    try {
        $db->query('SELECT 1')->fetchColumn();
        $pending = (int) $db->query("SELECT COUNT(*) FROM retry_queue WHERE status = 'pending'")->fetchColumn();
        echo json_encode(['status' => 'ok', 'pending' => $pending], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'health check failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

$interval = max(1, (int) ($options['interval'] ?? $services['env']->int('RETRY_INTERVAL', 60)));
$once = isset($options['once']);
$maxLoops = isset($options['max-loops']) ? max(1, (int) $options['max-loops']) : null;
$batch = isset($options['batch']) ? max(1, min(100, (int) $options['batch'])) : 50;

// Prevent two workers from draining the same queue on the same host/volume.
$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'billas-worker-' . md5($root) . '.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, '[billas-worker] another worker already holds ' . $lockPath . PHP_EOL);
    exit(1);
}

$stop = false;
$reload = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach (['SIGTERM', 'SIGINT', 'SIGQUIT'] as $signalName) {
        if (defined($signalName)) {
            pcntl_signal(constant($signalName), static function (int $sig) use (&$stop): void {
                $stop = true;
            });
        }
    }
    if (defined('SIGHUP')) {
        pcntl_signal(SIGHUP, static function () use (&$reload): void {
            $reload = true;
        });
    }
}

/**
 * Interruptible sleep: checks the signal flag every 200 ms.
 */
$sleep = static function (int $seconds) use (&$stop): void {
    $until = microtime(true) + $seconds;
    while (!$stop && microtime(true) < $until) {
        usleep(200_000);
    }
};

$logger->info('Retry worker dimulai', [
    'interval' => $interval,
    'batch' => $batch,
    'pid' => getmypid(),
    'once' => $once,
]);

$loops = 0;
$totalDone = 0;
$totalFailed = 0;

while (!$stop) {
    $started = microtime(true);
    try {
        $stats = $retry->flush($batch);
        $totalDone += $stats['done'];
        $totalFailed += $stats['failed'];
        if ($stats['processed'] > 0) {
            $logger->info('Retry batch selesai', $stats + ['duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }
    } catch (Throwable $e) {
        // A transient SQLite/busy error must not kill the worker.
        $logger->error('Retry worker error', [
            'error' => $e->getMessage(),
            'at' => Time::nowIso(),
        ]);
    }

    $loops++;
    if ($reload) {
        $logger->info('Retry worker menerima SIGHUP; lanjut dengan konfigurasi lama', ['at' => Time::nowIso()]);
        $reload = false;
    }
    if ($once || ($maxLoops !== null && $loops >= $maxLoops)) {
        break;
    }
    $sleep($interval);
}

$logger->info('Retry worker berhenti', [
    'loops' => $loops,
    'done' => $totalDone,
    'failed' => $totalFailed,
    'pid' => getmypid(),
]);

flock($lock, LOCK_UN);
fclose($lock);
@unlink($lockPath);

exit(0);
