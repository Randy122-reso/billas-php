<?php

declare(strict_types=1);

use Billas\Kernel;
use Billas\Support\Env;

/**
 * Front controller for PHP-FPM/Nginx and the PHP built-in server.
 *
 * When used as a router script (`php -S ... public/index.php`), return false
 * for existing static files so the built-in server can stream them directly.
 */
$root = dirname(__DIR__);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require $root . '/vendor/autoload.php';

try {
    Kernel::boot($root)->run();
} catch (Throwable $e) {
    error_log('[billas] boot failure: ' . $e->getMessage());
    $debug = false;
    try {
        $debug = Env::instance()->bool('APP_DEBUG');
    } catch (Throwable) {
        // Env itself may have failed; fall back to a generic response.
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $message = $debug
        ? htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : 'Aplikasi gagal dimulai. Periksa log server dan konfigurasi .env.';
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>500 · Billas</title>'
        . '<body style="font-family:system-ui;background:#f4f7f6;color:#0e1c1a;padding:3rem">'
        . '<h1 style="color:#0f766e">Billas tidak dapat dimulai</h1>'
        . '<p>' . $message . '</p>'
        . '<p><a href="/">Kembali ke beranda</a></p></body></html>';
}
