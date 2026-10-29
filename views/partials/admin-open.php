<?php
/** @var string $pageTitle */
$brand = 'Billas';
$current = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$nav = [
    ['/admin/dashboard', 'Dashboard', '▦'],
    ['/admin/customers', 'Pelanggan', '◉'],
    ['/admin/packages', 'Paket', '◈'],
    ['/admin/routers', 'Router', '⌁'],
    ['/admin/invoices', 'Tagihan', '▤'],
    ['/admin/vouchers', 'Voucher', '▣'],
    ['/admin/wifi/sessions', 'Sesi WiFi', '≋'],
    ['/admin/analytics', 'Analitik', '◒'],
    ['/admin/reconciliation', 'Rekonsiliasi', '⇄'],
    ['/admin/settings', 'Pengaturan', '⚙'],
    ['/admin/audit', 'Audit Trail', '⌘'],
];
if (billas_feature('webhook_simulator')) {
    $nav[] = ['/admin/simulator', 'Simulator', '⚡'];
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1220">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle ?? 'Admin') ?> · <?= e($brand) ?> Admin</title>
    <link rel="icon" href="<?= e(billas_asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(billas_asset('css/style.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="sidebar">
        <a class="brand sidebar-brand" href="<?= e(billas_url('/admin/dashboard')) ?>">
            <span class="brand-mark" aria-hidden="true">B</span>
            <span><strong><?= e($brand) ?></strong><small>Admin Console</small></span>
        </a>
        <nav class="sidebar-nav" aria-label="Navigasi admin">
            <?php foreach ($nav as [$href, $label, $icon]): ?>
                <?php $active = $current === $href || ($href !== '/admin/dashboard' && str_starts_with($current, $href)); ?>
                <a class="<?= $active ? 'active' : '' ?>" href="<?= e(billas_url($href)) ?>">
                    <span class="nav-icon" aria-hidden="true"><?= e($icon) ?></span>
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <span class="muted small">Billas v4 · PHP <?= e(PHP_VERSION) ?></span>
            <a href="<?= e(billas_url('/')) ?>" class="small">Lihat portal publik →</a>
        </div>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <button class="btn btn-ghost btn-sm nav-toggle" type="button" data-nav-toggle aria-label="Buka navigasi">☰</button>
            <div>
                <strong><?= e($pageTitle ?? 'Admin') ?></strong>
                <span class="muted small topbar-sub">Satu billing, semua akses.</span>
            </div>
            <div class="header-user">
                <span class="avatar"><?= e(mb_substr(admin_name(), 0, 1)) ?></span>
                <span class="user-name"><?= e(admin_name()) ?></span>
                <form method="post" action="<?= e(billas_url('/admin/logout')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm">Keluar</button>
                </form>
            </div>
        </header>
        <main class="admin-content">
            <?php require __DIR__ . '/flashes.php'; ?>
