<?php
/** @var string $content */
/** @var string $pageTitle */
/** @var array<int,array<string,mixed>> $flashes */
$brand = $brand ?? 'Billas';
$current = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$authed = auth_customer();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f766e">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle ?? 'Portal Pelanggan') ?> · <?= e($brand) ?></title>
    <link rel="icon" href="<?= e(billas_asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(billas_asset('css/style.css')) ?>">
</head>
<body class="portal-body">
<?php if ($authed): ?>
<header class="site-header portal-header">
    <a class="brand" href="<?= e(billas_url('/portal/check')) ?>">
        <span class="brand-mark" aria-hidden="true">B</span>
        <span>
            <strong><?= e($brand) ?></strong>
            <small>Portal Pelanggan</small>
        </span>
    </a>
    <nav class="site-nav" aria-label="Navigasi pelanggan">
        <a class="<?= $current === '/portal/check' || $current === '/portal' ? 'active' : '' ?>" href="<?= e(billas_url('/portal/check')) ?>">Cek Tagihan</a>
        <a class="<?= str_starts_with($current, '/portal/qris-unique') ? 'active' : '' ?>" href="<?= e(billas_url('/portal/qris-unique')) ?>">QRIS Unik</a>
        <a class="<?= str_starts_with($current, '/portal/account') ? 'active' : '' ?>" href="<?= e(billas_url('/portal/account')) ?>">Akun</a>
    </nav>
    <div class="header-user">
        <span class="avatar"><?= e(mb_substr(customer_name(), 0, 1)) ?></span>
        <span class="user-name"><?= e(customer_name()) ?></span>
        <form method="post" action="<?= e(billas_url('/portal/logout')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Keluar</button>
        </form>
    </div>
</header>
<?php else: ?>
<header class="site-header portal-header">
    <a class="brand" href="<?= e(billas_url('/')) ?>">
        <span class="brand-mark" aria-hidden="true">B</span>
        <span><strong><?= e($brand) ?></strong><small>Portal Pelanggan</small></span>
    </a>
    <nav class="site-nav">
        <a href="<?= e(billas_url('/')) ?>">Beli Voucher</a>
        <a href="<?= e(billas_url('/portal/login')) ?>" class="active">Masuk</a>
    </nav>
</header>
<?php endif; ?>

<main class="container">
    <?php require __DIR__ . '/../partials/flashes.php'; ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div><strong><?= e($brand) ?></strong> — portal pelanggan PPPoE.</div>
    <div class="muted small">Butuh bantuan? Hubungi operator jaringan Anda.</div>
</footer>
<script src="<?= e(billas_asset('js/app.js')) ?>" defer></script>
</body>
</html>
