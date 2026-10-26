<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Crypto;
use Billas\Support\Env;
use Billas\Support\Time;
use InvalidArgumentException;
use PDO;

final class RouterService
{
    public function __construct(
        private readonly PDO $db,
        private readonly Crypto $crypto,
        private readonly Env $env,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM routers' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY name';

        return $this->db->query($sql)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM routers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function defaultId(): ?int
    {
        $id = $this->db->query('SELECT id FROM routers WHERE active = 1 ORDER BY id LIMIT 1')->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $data */
    public function save(array $data, ?int $id = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $host = trim((string) ($data['host'] ?? ''));
        $port = (int) ($data['port'] ?? 8728);
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $useTls = !empty($data['use_tls']) ? 1 : 0;
        $active = !empty($data['active']) ? 1 : 0;

        if ($name === '' || $host === '' || $username === '') {
            throw new InvalidArgumentException('Nama, host, dan username router wajib diisi.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port router tidak valid.');
        }

        if ($id === null) {
            if ($password === '') {
                $password = $this->env->string('MIKROTIK_PASS');
            }
            $stmt = $this->db->prepare(
                'INSERT INTO routers (name, host, port, username, password_enc, use_tls, active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $host, $port, $username, $this->crypto->encrypt($password), $useTls, $active, Time::now()]);

            return (int) $this->db->lastInsertId();
        }

        $existing = $this->find($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Router tidak ditemukan.');
        }

        $encrypted = $password !== '' ? $this->crypto->encrypt($password) : (string) $existing['password_enc'];
        $stmt = $this->db->prepare(
            'UPDATE routers SET name = ?, host = ?, port = ?, username = ?, password_enc = ?,
             use_tls = ?, active = ? WHERE id = ?'
        );
        $stmt->execute([$name, $host, $port, $username, $encrypted, $useTls, $active, $id]);

        return $id;
    }

    public function toggle(int $id): bool
    {
        $router = $this->find($id);
        if ($router === null) {
            return false;
        }
        $this->db->prepare('UPDATE routers SET active = ? WHERE id = ?')
            ->execute([(int) $router['active'] === 1 ? 0 : 1, $id]);

        return true;
    }

    /** @return array{host:string,port:int,username:string,password:string,use_tls:bool} */
    public function connection(int $routerId): array
    {
        $router = $this->find($routerId);
        if ($router === null) {
            throw new InvalidArgumentException('Router tidak ditemukan.');
        }

        return [
            'host' => (string) $router['host'],
            'port' => (int) $router['port'],
            'username' => (string) $router['username'],
            'password' => $this->crypto->decrypt((string) $router['password_enc']),
            'use_tls' => (bool) $router['use_tls'],
        ];
    }
}
