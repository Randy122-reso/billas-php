<?php
/** @var string $brand */
$showDemo = billas_env('SEED_DEMO') === '1' || billas_env('APP_DEBUG') === '1';
?>
<section class="auth-wrap">
    <div class="auth-aside">
        <span class="eyebrow">Portal pelanggan</span>
        <h1>Kelola layanan internet Anda.</h1>
        <p>Cek tagihan, lihat masa aktif, bayar dengan QRIS, dan akses QRIS unik Anda dalam satu tempat.</p>
        <ul class="check-list">
            <li>Riwayat tagihan dan pembayaran</li>
            <li>Garansi layanan sampai akhir periode</li>
            <li>Pembayaran QRIS unik berulang</li>
        </ul>
    </div>
    <div class="auth-card">
        <h2>Masuk</h2>
        <p class="muted">Gunakan kode pelanggan atau nomor telepon terdaftar.</p>
        <form method="post" action="<?= e(billas_url('/portal/login')) ?>" class="stack-form">
            <?= csrf_field() ?>
            <label>
                <span>Kode pelanggan / nomor telepon</span>
                <input type="text" name="identifier" autocomplete="username" required autofocus placeholder="DEMO001 atau 0812…">
            </label>
            <label>
                <span>Password portal</span>
                <input type="password" name="password" autocomplete="current-password" required placeholder="••••••••">
            </label>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Masuk ke portal</button>
        </form>
        <?php if ($showDemo): ?>
            <div class="notice notice-info small">
                <strong>Demo lokal:</strong> <?= e(billas_env('DEMO_CUSTOMER_CODE', 'DEMO001')) ?> /
                <?= e(billas_env('DEMO_CUSTOMER_PASSWORD', 'pelanggan123')) ?>
            </div>
        <?php endif; ?>
        <p class="muted small">Lupa password? Hubungi operator untuk reset dari panel admin.</p>
    </div>
</section>
