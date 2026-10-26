<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Time;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Durable retry queue for MikroTik operations. The app never talks to a router
 * during a web request longer than necessary; the worker drains this queue.
 */
final class RetryService
{
    public function __construct(
        private readonly PDO $db,
        private readonly MikrotikService $mikrotik,
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts = 10,
        private readonly int $backoffBase = 60,
    ) {
    }

    public function enqueue(string $taskType, array $payload, int $delaySeconds = 0): int
    {
        $now = Time::now();
        $stmt = $this->db->prepare(
            'INSERT INTO retry_queue
             (task_type, payload, attempts, max_attempts, next_attempt_at, status, created_at, updated_at)
             VALUES (?, ?, 0, ?, ?, "pending", ?, ?)'
        );
        $stmt->execute([
            $taskType,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->maxAttempts,
            $now + max(0, $delaySeconds),
            $now,
            $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function claim(int $limit = 20): array
    {
        $now = Time::now();
        $limit = max(1, min(100, $limit));
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $rows = $this->db->query(
                "SELECT * FROM retry_queue
                 WHERE status = 'pending' AND next_attempt_at <= {$now}
                 ORDER BY id ASC LIMIT {$limit}"
            )->fetchAll();
            $update = $this->db->prepare("UPDATE retry_queue SET status = 'processing', locked_at = ?, updated_at = ? WHERE id = ?");
            foreach ($rows as $row) {
                $update->execute([$now, $now, $row['id']]);
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }

        return $rows;
    }

    /** @return array{processed:int,done:int,failed:int} */
    public function flush(int $limit = 20): array
    {
        $rows = $this->claim($limit);
        $done = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $this->dispatch($row['task_type'], json_decode((string) $row['payload'], true) ?: []);
                $this->db->prepare("UPDATE retry_queue SET status = 'done', updated_at = ?, last_error = NULL WHERE id = ?")
                    ->execute([Time::now(), $row['id']]);
                $done++;
            } catch (Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $max = (int) $row['max_attempts'];
                $status = $attempts >= $max ? 'failed' : 'pending';
                $delay = min(86400, $this->backoffBase * (2 ** min(10, $attempts)));
                $this->db->prepare(
                    'UPDATE retry_queue
                     SET attempts = ?, status = ?, next_attempt_at = ?, updated_at = ?, last_error = ?
                     WHERE id = ?'
                )->execute([$attempts, $status, Time::now() + $delay, Time::now(), mb_substr($e->getMessage(), 0, 2000), $row['id']]);
                $this->logger->warning('Retry task gagal', [
                    'task' => $row['task_type'],
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['processed' => count($rows), 'done' => $done, 'failed' => $failed];
    }

    /** @param array<string,mixed> $payload */
    private function dispatch(string $taskType, array $payload): void
    {
        switch ($taskType) {
            case 'hotspot_push':
                $this->mikrotik->pushVoucherById((int) ($payload['voucher_id'] ?? 0));
                break;
            case 'hotspot_disable':
                $this->mikrotik->disableVoucherById((int) ($payload['voucher_id'] ?? 0), (string) ($payload['reason'] ?? 'manual'));
                break;
            case 'pppoe_enable':
                $this->mikrotik->enablePppoe((int) ($payload['customer_id'] ?? 0));
                break;
            case 'pppoe_disable':
                $this->mikrotik->disablePppoe((int) ($payload['customer_id'] ?? 0));
                break;
            case 'pppoe_kick':
                $this->mikrotik->kickPppoe((int) ($payload['customer_id'] ?? 0));
                break;
            case 'router_test':
                $this->mikrotik->testRouter((int) ($payload['router_id'] ?? 0));
                break;
            default:
                throw new \RuntimeException('Task retry tidak dikenal: ' . $taskType);
        }
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $rows = $this->db->query('SELECT status, COUNT(*) AS total FROM retry_queue GROUP BY status')->fetchAll();
        $result = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['total'];
        }

        return $result;
    }
}
