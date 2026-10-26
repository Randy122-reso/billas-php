<?php
$showDemo = billas_env('SEED_DEMO') === '1' || billas_env('APP_DEBUG') === '1';
?>
<section class="auth-wrap admin-auth">
    <div class="auth-aside admin-aside">
        <span class="eyebrow">Billas Admin</span>
        <h1>Kendali penuh atas jaringan Anda.</h1>
        <p>Kelola pelanggan PPPoE, voucher hotspot, tagihan, gateway pembayaran, captive portal, dan rekonsiliasi dari satu konsol.</p>
        <ul class="check-list">
            <li>Monitoring order &amp; voucher real-time</li>
            <li>QRIS unik dan garansi layanan</li>
            <li>Retry queue dan cron yang transparan</li>
        </ul>
    </div>
    <div class="auth-card">
        <h2>Masuk admin</h2>
        <p class="muted">Gunakan kredensial operator yang diberikan.</p>
        <form method="post" action="<?= e(billas_url('/admin/login')) ?>" class="stack-form">
            <?= csrf_field() ?>
            <label>
                <span>Username</span>
                <input type="text" name="username" autocomplete="username" required autofocus>
            </label>
            <label>
                <span>Password</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Masuk ke konsol</button>
        </form>
        <?php if ($showDemo): ?>
            <div class="notice notice-info small">
                <strong>Demo lokal:</strong> <?= e(billas_env('ADMIN_USERNAME', 'admin')) ?> /
                <?= e(billas_env('ADMIN_PASSWORD', 'admin123')) ?>
            </div>
        <?php endif; ?>
        <a class="link small" href="<?= e(billas_url('/')) ?>">← Kembali ke portal publik</a>
    </div>
</section>
