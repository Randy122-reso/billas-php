<?php

declare(strict_types=1);

namespace Billas\Support;

use Billas\Session\SqliteSessionHandler;

/**
 * Thin wrapper around PHP's native session, stored by SqliteSessionHandler.
 */
final class Session
{
    public function __construct(
        private readonly Env $env,
        private readonly ?SqliteSessionHandler $handler = null,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if ($this->handler !== null) {
            session_set_save_handler($this->handler, true);
        }
        $secure = str_starts_with($this->env->string('APP_URL'), 'https://');
        session_name('BILLASSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);

        return $value;
    }

    /** @param array<string,mixed> $data */
    public function flash(string $type, string $message, array $data = []): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message, 'data' => $data];
    }

    /** @return list<array{type:string,message:string,data:array<string,mixed>}> */
    public function consumeFlash(): array
    {
        $items = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($items) ? $items : [];
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function adminId(): ?int
    {
        $id = $_SESSION['admin_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function customerId(): ?int
    {
        $id = $_SESSION['customer_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function loginAdmin(int $id, string $username, string $role): void
    {
        $this->regenerate();
        $_SESSION['admin_id'] = $id;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role'] = $role;
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public function loginCustomer(int $id, string $code, string $name): void
    {
        $this->regenerate();
        $_SESSION['customer_id'] = $id;
        $_SESSION['customer_code'] = $code;
        $_SESSION['customer_name'] = $name;
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public function logoutAdmin(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function logoutCustomer(): void
    {
        unset($_SESSION['customer_id'], $_SESSION['customer_code'], $_SESSION['customer_name']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
