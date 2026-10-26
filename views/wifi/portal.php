<?php
/** @var string $brand */
/** @var string $logo_url */
/** @var string $terms_url */
/** @var string $ssid */
/** @var array<string,mixed> $router */
/** @var string $client_ip */
/** @var string|null $client_mac */
/** @var string $session_token */
/** @var int $session_expires_at */
/** @var array<int,array<string,mixed>> $packages */
?>
<section class="wifi-hero">
    <div class="wifi-hero-copy">
        <span class="eyebrow">WiFi publik · <?= e($ssid) ?></span>
        <h1>Akses internet<br><span class="accent">tanpa akun.</span></h1>
        <p>Pilih paket, bayar dengan QRIS, dan terima password WiFi unik yang langsung aktif. Tanpa registrasi, tanpa antre.</p>
        <div class="wifi-facts">
            <div><span class="dot"></span> <?= e($ssid) ?></div>
            <div>IP <?= e($client_ip) ?></div>
            <?php if ($client_mac): ?><div class="mono"><?= e($client_mac) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="wifi-badge-card">
        <span class="signal-icon big">≋</span>
        <strong><?= e($brand) ?></strong>
        <span>Siap melayani</span>
        <small>Router: <?= e($router['name']) ?></small>
    </div>
</section>

<section class="section" id="paket-wifi">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Langkah 1 dari 2</span>
            <h2>Pilih paket WiFi</h2>
        </div>
        <p class="muted">Harga sudah termasuk pajak. Pembayaran aman melalui QRIS.</p>
    </div>

    <?php if ($packages === []): ?>
        <div class="empty-state">
            <h3>Belum ada paket WiFi</h3>
            <p>Operator belum menyiapkan paket untuk captive portal. Hubungi petugas di lokasi.</p>
        </div>
    <?php else: ?>
        <div class="package-grid wifi-package-grid">
            <?php foreach ($packages as $index => $package): ?>
                <article class="package-card <?= $index === 0 ? 'featured' : '' ?>">
                    <?php if ($index === 0): ?><span class="ribbon">Paling cepat</span><?php endif; ?>
                    <div class="package-top">
                        <span class="package-icon"><?= e($package['duration_mode'] === 'calendar' ? '▦' : '⏱') ?></span>
                        <span class="badge badge-soft"><?= e($package['duration_mode'] === 'calendar' ? 'Kalender' : 'Uptime') ?></span>
                    </div>
                    <h3><?= e($package['name']) ?></h3>
                    <p class="package-duration"><?= e(billas_duration($package['duration_mode'], (int) ($package['duration_value'] ?? 0), $package['duration_unit'] ?? null, (int) ($package['calendar_days'] ?? 0))) ?></p>
                    <div class="price"><span>Rp</span><?= e(number_format((float) $package['price'], 0, ',', '.')) ?></div>
                    <ul class="package-features">
                        <li>Password WiFi unik</li>
                        <li>Berlaku untuk satu perangkat</li>
                        <li>Aktif otomatis setelah bayar</li>
                    </ul>
                    <button
                        type="button"
                        class="btn btn-primary btn-block"
                        data-wifi-buy="<?= (int) $package['id'] ?>"
                        data-endpoint="<?= e(billas_url('/api/wifi/pay/create')) ?>"
                        data-session-token="<?= e($session_token) ?>"
                        data-client-mac="<?= e($client_mac ?? '') ?>"
                        data-voucher-base="<?= e(billas_url('/wifi/voucher/')) ?>">
                        Pilih &amp; bayar
                    </button>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div id="wifi-error" class="alert alert-error hidden" role="alert"></div>

<section class="wifi-how">
    <div class="wifi-how-item">
        <span>01</span>
        <h3>Pilih paket</h3>
        <p>Durasi dan harga transparan.</p>
    </div>
    <div class="wifi-how-item">
        <span>02</span>
        <h3>Scan QRIS</h3>
        <p>Bayar dari m-banking atau e-wallet.</p>
    </div>
    <div class="wifi-how-item">
        <span>03</span>
        <h3>Masukkan password</h3>
        <p>Gunakan password unik di halaman login WiFi.</p>
    </div>
</section>

<p class="wifi-session-note muted small">
    Sesi ini berlaku hingga <?= e(billas_date($session_expires_at, true)) ?>.
    Jangan tutup halaman sebelum pembayaran selesai.
</p>
