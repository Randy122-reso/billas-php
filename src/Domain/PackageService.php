<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Time;
use InvalidArgumentException;
use PDO;

final class PackageService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $type = null, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM packages WHERE 1 = 1';
        $params = [];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        if ($onlyActive) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY type, price, id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function captivePackages(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM packages
             WHERE type = 'hotspot' AND active = 1 AND available_on_captive = 1
             ORDER BY price, id"
        );

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM packages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, ?int $id = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? 'hotspot');
        $price = (int) ($data['price'] ?? 0);
        $durationMode = (string) ($data['duration_mode'] ?? 'uptime');
        $durationValue = isset($data['duration_value']) && $data['duration_value'] !== '' ? (int) $data['duration_value'] : null;
        $durationUnit = isset($data['duration_unit']) && $data['duration_unit'] !== '' ? (string) $data['duration_unit'] : null;
        $calendarDays = isset($data['calendar_days']) && $data['calendar_days'] !== '' ? (int) $data['calendar_days'] : null;
        $profile = trim((string) ($data['mikrotik_profile'] ?? ''));
        $availableOnCaptive = $type === 'hotspot' && !empty($data['available_on_captive']) ? 1 : 0;

        if ($name === '' || $profile === '') {
            throw new InvalidArgumentException('Nama dan MikroTik profile wajib diisi.');
        }
        if (!in_array($type, ['hotspot', 'pppoe'], true)) {
            throw new InvalidArgumentException('Tipe paket tidak valid.');
        }
        if ($price < 1000) {
            throw new InvalidArgumentException('Harga minimal Rp 1.000.');
        }
        if (!in_array($durationMode, ['uptime', 'calendar'], true)) {
            throw new InvalidArgumentException('Duration mode tidak valid.');
        }
        if ($durationMode === 'calendar' && ($calendarDays === null || $calendarDays < 1)) {
            throw new InvalidArgumentException('Paket kalender mewajibkan calendar_days >= 1.');
        }
        if ($durationMode === 'uptime') {
            if ($durationValue === null || $durationValue < 1 || !in_array($durationUnit, ['minutes', 'hours', 'days'], true)) {
                throw new InvalidArgumentException('Paket uptime mewajibkan duration_value dan duration_unit.');
            }
        } else {
            $durationValue = $durationValue ?? 0;
            $durationUnit = $durationUnit ?? 'days';
        }

        $now = Time::now();
        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO packages
                 (name, type, price, duration_value, duration_unit, duration_mode, calendar_days,
                  mikrotik_profile, bandwidth_down, bandwidth_up, active, available_on_captive, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name, $type, $price, $durationValue, $durationUnit, $durationMode, $calendarDays,
                $profile,
                $this->nullableInt($data['bandwidth_down'] ?? null),
                $this->nullableInt($data['bandwidth_up'] ?? null),
                !empty($data['active']) ? 1 : 0,
                $availableOnCaptive,
                $now,
            ]);

            return (int) $this->db->lastInsertId();
        }

        if ($this->find($id) === null) {
            throw new InvalidArgumentException('Paket tidak ditemukan.');
        }

        $stmt = $this->db->prepare(
            'UPDATE packages SET name = ?, type = ?, price = ?, duration_value = ?, duration_unit = ?,
             duration_mode = ?, calendar_days = ?, mikrotik_profile = ?, bandwidth_down = ?,
             bandwidth_up = ?, active = ?, available_on_captive = ? WHERE id = ?'
        );
        $stmt->execute([
            $name, $type, $price, $durationValue, $durationUnit, $durationMode, $calendarDays,
            $profile,
            $this->nullableInt($data['bandwidth_down'] ?? null),
            $this->nullableInt($data['bandwidth_up'] ?? null),
            !empty($data['active']) ? 1 : 0,
            $availableOnCaptive,
            $id,
        ]);

        return $id;
    }

    public function toggle(int $id): bool
    {
        $package = $this->find($id);
        if ($package === null) {
            return false;
        }
        $this->db->prepare('UPDATE packages SET active = ? WHERE id = ?')
            ->execute([(int) $package['active'] === 1 ? 0 : 1, $id]);

        return true;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
