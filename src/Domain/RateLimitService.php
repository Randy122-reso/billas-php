<?php

declare(strict_types=1);

namespace Billas\Domain;

use PDO;

/**
 * Fixed-window rate limiter backed by SQLite. Suitable for a single-node SQLite
 * deployment and avoids extra infrastructure.
 */
final class RateLimitService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{allowed:bool,limit:int,remaining:int,retry_after:int} */
    public function hit(string $bucket, string $key, int $limit, int $windowSeconds = 60): array
    {
        $window = (int) floor(time() / max(1, $windowSeconds)) * $windowSeconds;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare('SELECT count FROM rate_limits WHERE bucket = ? AND key = ? AND window_start = ?');
            $stmt->execute([$bucket, $key, $window]);
            $count = $stmt->fetchColumn();
            $count = $count === false ? 0 : (int) $count;

            if ($count >= $limit) {
                $this->db->exec('COMMIT');

                return [
                    'allowed' => false,
                    'limit' => $limit,
                    'remaining' => 0,
                    'retry_after' => max(1, $window + $windowSeconds - time()),
                ];
            }

            $stmt = $this->db->prepare(
                'INSERT INTO rate_limits (bucket, key, window_start, count) VALUES (?, ?, ?, 1)
                 ON CONFLICT(bucket, key, window_start) DO UPDATE SET count = count + 1'
            );
            $stmt->execute([$bucket, $key, $window]);
            $this->db->exec('COMMIT');

            return [
                'allowed' => true,
                'limit' => $limit,
                'remaining' => max(0, $limit - $count - 1),
                'retry_after' => 0,
            ];
        } catch (\Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function prune(int $olderThanSeconds = 86400): int
    {
        $stmt = $this->db->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $stmt->execute([time() - $olderThanSeconds]);

        return $stmt->rowCount();
    }
}
