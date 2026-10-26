<?php
/** @var string $content */
/** @var string $pageTitle */
/** @var array<int,array<string,mixed>> $flashes */
/** @var string $brand */
$brand = $brand ?? 'RTRWNET';
$logo = $logo_url ?? '';
$terms = $terms_url ?? '';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1220">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle ?? 'WiFi Portal') ?> · <?= e($brand) ?></title>
    <link rel="icon" href="<?= e(billas_asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(billas_asset('css/style.css')) ?>">
</head>
<body class="wifi-body">
<header class="wifi-header">
    <a class="brand" href="<?= e(billas_url('/wifi')) ?>">
        <?php if ($logo !== ''): ?>
            <img class="brand-logo" src="<?= e($logo) ?>" alt="<?= e($brand) ?>">
        <?php else: ?>
            <span class="brand-mark">◉</span>
        <?php endif; ?>
        <span>
            <strong><?= e($brand) ?></strong>
            <small>WiFi Publik</small>
        </span>
    </a>
    <span class="wifi-status"><span class="dot"></span> Terhubung ke jaringan</span>
</header>

<main class="wifi-main">
    <?php require __DIR__ . '/../partials/flashes.php'; ?>
    <?= $content ?>
</main>

<footer class="wifi-footer">
    <div>
        <strong><?= e($brand) ?></strong>
        <?php if ($terms !== ''): ?>
            · <a href="<?= e($terms) ?>" target="_blank" rel="noopener noreferrer">Syarat &amp; ketentuan</a>
        <?php endif; ?>
    </div>
    <div class="muted small">Bayar QRIS · akses instan · password unik</div>
</footer>
<script src="<?= e(billas_asset('js/wifi.js')) ?>" defer></script>
</body>
</html>
