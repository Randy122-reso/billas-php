<?php
/** @var array<int,array<string,mixed>> $packages */
/** @var string $brand */
?>
<section class="hero">
    <div class="hero-copy">
        <span class="eyebrow">Hotspot voucher · QRIS otomatis</span>
        <h1>Internet cepat untuk semua,<br><span class="accent">bayar dalam hitungan detik.</span></h1>
        <p>Pilih paket, bayar dengan QRIS, lalu gunakan kode voucher untuk masuk ke jaringan hotspot. Tanpa antre, tanpa langganan.</p>
        <div class="hero-actions">
            <a href="#paket" class="btn btn-primary btn-lg">Pilih paket</a>
            <a href="<?= e(billas_url('/wifi')) ?>" class="btn btn-ghost btn-lg">Portal WiFi RTRWNET</a>
        </div>
        <ul class="hero-points">
            <li>Bayar QRIS dari semua m-banking</li>
            <li>Voucher aktif otomatis</li>
            <li>Aman &amp; tanpa aplikasi tambahan</li>
        </ul>
    </div>
    <div class="hero-art" aria-hidden="true">
        <div class="signal-card">
            <span class="signal-icon">≋</span>
            <div>
                <strong><?= e($brand) ?></strong>
                <small>WiFi terkoneksi</small>
            </div>
            <span class="signal-dot"></span>
        </div>
        <div class="hero-orbit orbit-one"></div>
        <div class="hero-orbit orbit-two"></div>
        <div class="hero-orbit orbit-three"></div>
    </div>
</section>

<section id="paket" class="section">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Pilih sesuai kebutuhan</span>
            <h2>Paket hotspot</h2>
        </div>
        <p class="muted">Harga transparan. Pembayaran diverifikasi otomatis oleh gateway.</p>
    </div>

    <?php if ($packages === []): ?>
        <div class="empty-state">
            <h3>Belum ada paket hotspot</h3>
            <p>Operator belum mengaktifkan paket hotspot. Silakan hubungi admin.</p>
        </div>
    <?php else: ?>
        <div class="package-grid">
            <?php foreach ($packages as $index => $package): ?>
                <article class="package-card <?= $index === 1 ? 'featured' : '' ?>">
                    <?php if ($index === 1): ?><span class="ribbon">Paling populer</span><?php endif; ?>
                    <div class="package-top">
                        <span class="package-icon"><?= e($package['duration_mode'] === 'calendar' ? '▦' : '⏱') ?></span>
                        <span class="badge badge-soft"><?= e($package['duration_mode'] === 'calendar' ? 'Aktif kalender' : 'Aktif uptime') ?></span>
                    </div>
                    <h3><?= e($package['name']) ?></h3>
                    <p class="package-duration"><?= e(billas_duration($package['duration_mode'], (int) ($package['duration_value'] ?? 0), $package['duration_unit'] ?? null, (int) ($package['calendar_days'] ?? 0))) ?></p>
                    <div class="price"><span>Rp</span><?= e(number_format((float) $package['price'], 0, ',', '.')) ?></div>
                    <ul class="package-features">
                        <li><?= e($package['bandwidth_down'] ? (int) $package['bandwidth_down'] / 1000 . ' Mbps download' : 'Kecepatan sesuai profil') ?></li>
                        <li>Berlaku di semua perangkat</li>
                        <li>Voucher langsung terbit</li>
                    </ul>
                    <button
                        type="button"
                        class="btn btn-primary btn-block"
                        data-buy-package="<?= (int) $package['id'] ?>"
                        data-endpoint="<?= e(billas_url('/api/pay/create')) ?>">
                        Beli sekarang
                    </button>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="section">
    <div class="steps">
        <div class="step">
            <span>01</span>
            <h3>Pilih paket</h3>
            <p>Tentukan durasi dan harga yang paling sesuai.</p>
        </div>
        <div class="step">
            <span>02</span>
            <h3>Bayar QRIS</h3>
            <p>Scan QR dengan aplikasi bank atau e-wallet favorit.</p>
        </div>
        <div class="step">
            <span>03</span>
            <h3>Masuk dengan voucher</h3>
            <p>Gunakan kode voucher pada halaman login hotspot.</p>
        </div>
    </div>
</section>

<div id="buy-error" class="alert alert-error hidden" role="alert"></div>
