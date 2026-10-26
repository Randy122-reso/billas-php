<?php

declare(strict_types=1);

/**
 * Global template/helper functions for Billas.
 *
 * Kept intentionally tiny: all framework-level helpers live here so views can
 * use them without importing classes.
 */

if (!function_exists('e')) {
    /** HTML-escape any scalar for safe template output. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('billas_env')) {
    function billas_env(string $key, ?string $default = null): ?string
    {
        try {
            return \Billas\Support\Env::instance()->get($key, $default);
        } catch (\Throwable) {
            $value = $_ENV[$key] ?? getenv($key);

            return $value === false || $value === '' ? $default : (string) $value;
        }
    }
}

if (!function_exists('billas_url')) {
    /** Build an absolute application URL; falls back to relative paths. */
    function billas_url(string $path = ''): string
    {
        $base = rtrim((string) (billas_env('BASE_URL') ?: billas_env('APP_URL') ?: ''), '/');
        if ($path === '') {
            return $base !== '' ? $base : '/';
        }

        return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('billas_asset')) {
    /** Build an asset URL that works behind Cloudflare Tunnel. */
    function billas_asset(string $path): string
    {
        return billas_url(ltrim($path, '/'));
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return \Billas\Support\Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return \Billas\Support\Csrf::field();
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_flash'][] = ['type' => $type, 'message' => $message, 'data' => []];
        }
    }
}

if (!function_exists('billas_flashes')) {
    /** @return list<array{type:string,message:string,data:array<string,mixed>}> */
    function billas_flashes(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $items = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($items) ? $items : [];
    }
}

if (!function_exists('auth_admin')) {
    function auth_admin(): bool
    {
        return isset($_SESSION['admin_id']);
    }
}

if (!function_exists('auth_customer')) {
    function auth_customer(): bool
    {
        return isset($_SESSION['customer_id']);
    }
}

if (!function_exists('admin_name')) {
    function admin_name(): string
    {
        return (string) ($_SESSION['admin_username'] ?? '');
    }
}

if (!function_exists('customer_name')) {
    function customer_name(): string
    {
        return (string) ($_SESSION['customer_name'] ?? '');
    }
}

if (!function_exists('billas_qr_data_uri')) {
    /** Render an EMVCo payload (or any string) as an inline SVG data URI. */
    function billas_qr_data_uri(string $data, int $size = 300): string
    {
        if ($data === '') {
            return '';
        }
        try {
            $qr = \Endroid\QrCode\QrCode::create($data)
                ->setSize($size)
                ->setMargin(12);
            $result = (new \Endroid\QrCode\Writer\SvgWriter())->write($qr);

            return $result->getDataUri();
        } catch (\Throwable) {
            return '';
        }
    }
}

if (!function_exists('billas_order_payload')) {
    /** Decode the JSON gateway payload attached to an order. */
    function billas_order_payload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (!is_string($payload) || $payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('billas_remaining')) {
    /** Human-friendly remaining time until a unix timestamp. */
    function billas_remaining(?int $expiresAt): string
    {
        if ($expiresAt === null) {
            return '-';
        }
        $seconds = max(0, $expiresAt - time());
        if ($seconds <= 0) {
            return 'kedaluwarsa';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . ' menit';
        }
        $hours = intdiv($minutes, 60);

        return $hours . ' jam ' . ($minutes % 60) . ' menit';
    }
}

if (!function_exists('billas_money')) {
    /** Format an integer rupiah amount. */
    function billas_money(int|float|string|null $amount): string
    {
        return 'Rp ' . number_format((float) ($amount ?? 0), 0, ',', '.');
    }
}

if (!function_exists('billas_date')) {
    /** Format an ISO date (or unix timestamp) in Indonesian style. */
    function billas_date(string|int|null $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $ts = is_int($value) || ctype_digit((string) $value) ? (int) $value : strtotime((string) $value);
        if ($ts === false) {
            return '-';
        }

        return date($withTime ? 'd M Y H:i' : 'd M Y', $ts);
    }
}

if (!function_exists('billas_duration')) {
    /** Human label for voucher/duration definitions. */
    function billas_duration(?string $mode, ?int $value, ?string $unit, ?int $calendarDays = null): string
    {
        if ($mode === 'calendar') {
            return max(1, (int) ($calendarDays ?? 1)) . ' hari (kalender)';
        }

        if (!$value || !$unit) {
            return '-';
        }

        return $value . ' ' . $unit;
    }
}
