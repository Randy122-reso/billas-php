<?php

declare(strict_types=1);

namespace Billas\Session;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/** SQLite-backed session storage so sessions survive container restarts. */
final class SqliteSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $lifetime = 86400,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->db->prepare('SELECT data FROM sessions WHERE id = ? AND last_activity >= ?');
        $stmt->execute([$id, time() - $this->lifetime]);
        $data = $stmt->fetchColumn();

        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data, last_activity = excluded.last_activity'
        );

        return $stmt->execute([$id, $data, time()]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE id = ?');
        $stmt->execute([$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE last_activity < ?');
        $stmt->execute([time() - max($max_lifetime, $this->lifetime)]);
        $count = $stmt->rowCount();
        $this->db->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([time() - 3600]);

        return $count;
    }

    public function validateId(string $id): bool
    {
        if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/', $id)) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM sessions WHERE id = ? AND last_activity >= ?');
        $stmt->execute([$id, time() - $this->lifetime]);

        return (bool) $stmt->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $stmt = $this->db->prepare('UPDATE sessions SET last_activity = ? WHERE id = ?');
        $stmt->execute([time(), $id]);

        return true;
    }
}
