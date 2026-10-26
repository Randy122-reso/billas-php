<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Env;
use Billas\Support\Settings;
use Billas\Support\Time;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Idempotent cron driver. RouterOS Scheduler calls /internal/cron/run every
 * minute; each task has its own interval stored in env and a claim row in
 * job_state so overlapping scheduler invocations never run the same task twice.
 */
final class CronService
{
    public const TASKS = [
        'invoices',
        'isolir',
        'vouchers',
        'order_expire',
        'invoice_expire',
        'backup',
        'logs',
        'captive_sessions',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly Env $env,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly InvoiceService $invoices,
        private readonly CustomerService $customers,
        private readonly VoucherService $vouchers,
        private readonly OrderService $orders,
        private readonly CaptivePortalService $captive,
        private readonly BackupService $backup,
        private readonly RateLimitService $rateLimits,
    ) {
    }

    /**
     * @param list<string> $requested
     * @return array<string,mixed>
     */
    public function tick(array $requested = [], bool $force = false): array
    {
        $unknown = array_values(array_diff($requested, self::TASKS));
        $tasks = $requested === [] ? self::TASKS : array_values(array_intersect(self::TASKS, $requested));
        $summary = [];

        foreach ($tasks as $task) {
            $interval = $this->interval($task);
            if (!$force && !$this->claim($task, $interval)) {
                $summary[$task] = [
                    'status' => 'skipped',
                    'reason' => 'interval',
                    'interval' => $interval,
                ];
                continue;
            }

            $started = microtime(true);
            try {
                $result = $this->run($task);
                $summary[$task] = ['status' => 'ok', 'result' => $result];
                $this->finish($task, 'ok', $result);
            } catch (Throwable $e) {
                $this->logger->error('Cron task gagal', [
                    'task' => $task,
                    'error' => $e->getMessage(),
                ]);
                $summary[$task] = ['status' => 'error', 'error' => $e->getMessage()];
                $this->finish($task, 'error', ['error' => $e->getMessage()]);
            }
            $summary[$task]['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        }

        if ($unknown !== []) {
            $summary['_unknown'] = ['status' => 'ignored', 'tasks' => $unknown];
        }

        return [
            'ran_at' => Time::nowIso(),
            'tasks' => $summary,
        ];
    }

    private function interval(string $task): int
    {
        $key = match ($task) {
            'invoices' => 'CRON_INTERVAL_INVOICES',
            'isolir' => 'CRON_INTERVAL_ISOLIR',
            'vouchers' => 'CRON_INTERVAL_VOUCHERS',
            'order_expire' => 'CRON_INTERVAL_ORDER_EXPIRE',
            'invoice_expire' => 'CRON_INTERVAL_INVOICE_EXPIRE',
            'backup' => 'CRON_INTERVAL_BACKUP',
            'logs' => 'CRON_INTERVAL_LOGS',
            'captive_sessions' => 'CRON_INTERVAL_CAPTIVE_SESSIONS',
            default => 'CRON_INTERVAL_INVOICES',
        };

        return max(0, $this->env->int($key, 900));
    }

    /**
     * Atomically reserve a task slot. SQLite BEGIN IMMEDIATE serializes writers,
     * which makes this safe across the app and worker containers.
     */
    private function claim(string $task, int $interval): bool
    {
        $now = Time::now();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare('SELECT last_run_at FROM job_state WHERE task = ?');
            $stmt->execute([$task]);
            $last = $stmt->fetchColumn();
            if ($last !== false && $now - (int) $last < $interval) {
                $this->db->exec('COMMIT');

                return false;
            }
            $this->db->prepare(
                'INSERT INTO job_state (task, last_run_at, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT(task) DO UPDATE SET last_run_at = excluded.last_run_at, updated_at = excluded.updated_at'
            )->execute([$task, $now, $now]);
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $e;
        }

        return true;
    }

    /** @param array<string,mixed> $result */
    private function finish(string $task, string $status, array $result): void
    {
        $this->db->prepare('UPDATE job_state SET last_result = ?, updated_at = ? WHERE task = ?')
            ->execute([
                mb_substr((string) json_encode(['status' => $status] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 4000),
                Time::now(),
                $task,
            ]);
    }

    /** @return array<string,mixed> */
    private function run(string $task): array
    {
        return match ($task) {
            'invoices' => $this->invoices->generateMonthly(),
            'isolir' => $this->customers->isolirDue($this->settings->int('cron.grace_isolir_days', 3)),
            'vouchers' => $this->vouchers->expireDue(),
            'order_expire' => ['expired' => $this->orders->expirePending()],
            'invoice_expire' => $this->invoices->expirePendingTransactions(),
            'backup' => $this->backup->run(),
            'logs' => $this->pruneLogs(),
            'captive_sessions' => $this->captive->expireSessions(),
            default => throw new \RuntimeException('Task cron tidak dikenal: ' . $task),
        };
    }

    /** @return array{files:int,rate_limits:int} */
    private function pruneLogs(): array
    {
        $dir = $this->env->string('LOG_DIR');
        $files = 0;
        $cutoff = time() - (14 * 86400);
        if ($dir !== '' && is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . 'app-*.log') ?: [] as $file) {
                $mtime = filemtime($file);
                if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                    $files++;
                }
            }
        }

        return ['files' => $files, 'rate_limits' => $this->rateLimits->prune(86400)];
    }
}
