<?php
/** @var array<string,mixed> $order */
/** @var array<string,mixed> $voucher */
/** @var string $ssid */
?>
<section class="wifi-voucher-wrap">
    <div class="success-mark" aria-hidden="true">✓</div>
    <span class="eyebrow">Pembayaran berhasil</span>
    <h1>Password WiFi Anda</h1>
    <p class="muted">Gunakan password di bawah pada halaman login hotspot <strong><?= e($ssid) ?></strong>.</p>

    <div class="voucher-card wifi-voucher-card">
        <div class="voucher-row password-row">
            <span>Password WiFi</span>
            <strong class="code password-code" data-copy="<?= e($voucher['password']) ?>"><?= e($voucher['password']) ?></strong>
            <button type="button" class="btn btn-ghost btn-sm" data-copy-button="<?= e($voucher['password']) ?>">Salin</button>
        </div>
        <div class="voucher-meta">
            <div><span>SSID</span><strong><?= e($ssid) ?></strong></div>
            <div><span>Paket</span><strong><?= e($order['package_name']) ?></strong></div>
            <div>
                <span>Berlaku sampai</span>
                <strong data-countdown="<?= (int) ($voucher['expires_at'] ?? 0) ?>">
                    <?= $voucher['expires_at'] ? e(billas_date((int) $voucher['expires_at'], true)) : 'Saat login pertama' ?>
                </strong>
            </div>
        </div>
        <div class="voucher-note">
            <span class="mono small">Username: <?= e($voucher['code']) ?></span>
            <span class="mono small">Password: <?= e($voucher['password']) ?></span>
        </div>
    </div>

    <div class="notice notice-warning">
        <strong>Simpan password ini sekarang.</strong> Password hanya ditampilkan pada halaman ini dan tidak akan dikirim melalui email atau SMS. Jika hilang, Anda harus membeli paket baru.
    </div>

    <ol class="wifi-login-steps">
        <li>Buka halaman login hotspot di perangkat Anda.</li>
        <li>Masukkan username dan password di atas.</li>
        <li>Klik Login dan mulai browsing.</li>
        <li>Akses berakhir otomatis saat masa berlaku habis.</li>
    </ol>

    <div class="inline-actions center">
        <a class="btn btn-primary" href="<?= e(billas_url('/wifi')) ?>">Beli paket lagi</a>
        <button type="button" class="btn btn-ghost" data-copy-button="<?= e($voucher['password']) ?>">Salin password</button>
    </div>

    <p class="muted small center">Referensi transaksi: <span class="mono"><?= e($order['merchant_ref']) ?></span></p>
</section>
