<?php
/** @var string $content */
/** @var string $pageTitle */
/** @var array<int,array<string,mixed>> $flashes */
$brand = $brand ?? 'Billas';
$current = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle ?? 'Billas') ?> · <?= e($brand) ?></title>
    <link rel="icon" href="<?= e(billas_asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(billas_asset('css/style.css')) ?>">
</head>
<body class="public-body">
<header class="site-header">
    <a class="brand" href="<?= e(billas_url('/')) ?>">
        <span class="brand-mark" aria-hidden="true">B</span>
        <span>
            <strong><?= e($brand) ?></strong>
            <small>Internet &amp; Hotspot</small>
        </span>
    </a>
    <nav class="site-nav" aria-label="Navigasi utama">
        <a class="<?= $current === '/' ? 'active' : '' ?>" href="<?= e(billas_url('/')) ?>">Beli Voucher</a>
        <a class="<?= str_starts_with($current, '/portal') ? 'active' : '' ?>" href="<?= e(billas_url('/portal/login')) ?>">Portal Pelanggan</a>
        <a class="<?= str_starts_with($current, '/wifi') ? 'active' : '' ?>" href="<?= e(billas_url('/wifi')) ?>">WiFi</a>
        <a class="<?= str_starts_with($current, '/admin') ? 'active' : '' ?>" href="<?= e(billas_url('/admin/login')) ?>">Admin</a>
    </nav>
</header>

<main class="container">
    <?php require __DIR__ . '/partials/flashes.php'; ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div>
        <strong><?= e($brand) ?></strong> — satu billing, semua akses.
    </div>
    <div class="muted small">PHP <?= e(PHP_VERSION) ?> · <a href="<?= e(billas_url('/health')) ?>">Status layanan</a></div>
</footer>
<script src="<?= e(billas_asset('js/app.js')) ?>" defer></script>
</body>
</html>
