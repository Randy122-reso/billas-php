<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Http;
use Billas\Support\Time;
use PDO;
use Psr\Log\LoggerInterface;

final class AuditService
{
    public function __construct(
        private readonly PDO $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(
        string $actorType,
        string $actorId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        mixed $meta = null,
        ?string $ip = null,
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_trail (actor_type, actor_id, action, entity_type, entity_id, meta, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $actorType,
                $actorId,
                $action,
                $entityType,
                $entityId,
                $meta === null ? null : (is_string($meta) ? $meta : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                $ip ?? (PHP_SAPI === 'cli' ? 'cli' : Http::ip()),
                Time::now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break a payment/revoke operation.
            $this->logger->error('Gagal menulis audit trail', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->query("SELECT * FROM audit_trail ORDER BY id DESC LIMIT {$limit}");

        return $stmt->fetchAll();
    }
}
