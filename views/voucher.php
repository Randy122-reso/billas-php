<?php
/** @var array<string,mixed> $order */
/** @var array<string,mixed> $voucher */
?>
<section class="voucher-wrap">
    <div class="success-mark" aria-hidden="true">✓</div>
    <span class="eyebrow">Pembayaran berhasil</span>
    <h1>Voucher hotspot Anda</h1>
    <p class="muted">Masukkan kode dan password di halaman login hotspot. Jangan bagikan voucher ini.</p>

    <div class="voucher-card">
        <div class="voucher-row">
            <span>Kode voucher</span>
            <strong class="code" data-copy="<?= e($voucher['code']) ?>"><?= e($voucher['code']) ?></strong>
        </div>
        <div class="voucher-row">
            <span>Password</span>
            <strong class="code" data-copy="<?= e($voucher['password']) ?>"><?= e($voucher['password']) ?></strong>
        </div>
        <div class="voucher-meta">
            <div><span>Paket</span><strong><?= e($order['package_name']) ?></strong></div>
            <div><span>Durasi</span><strong><?= e(billas_duration($voucher['duration_mode'], (int) $voucher['duration_value'], $voucher['duration_unit'], (int) ($order['calendar_days'] ?? 0))) ?></strong></div>
            <div>
                <span>Berlaku</span>
                <strong>
                    <?php if ($voucher['expires_at']): ?>
                        s/d <?= e(billas_date((int) $voucher['expires_at'], true)) ?>
                    <?php else: ?>
                        Mulai saat login pertama
                    <?php endif; ?>
                </strong>
            </div>
        </div>
    </div>

    <div class="notice notice-warning">
        <strong>Simpan kode ini.</strong> Halaman voucher dapat dibuka kembali dari tautan order Anda, tetapi jangan menyimpan password di tempat umum.
    </div>

    <div class="inline-actions center">
        <a class="btn btn-primary" href="<?= e(billas_url('/')) ?>">Beli voucher lagi</a>
        <a class="btn btn-ghost" href="<?= e(billas_url('/portal/login')) ?>">Portal pelanggan</a>
    </div>

    <p class="muted small center">Referensi order: <span class="mono"><?= e($order['merchant_ref']) ?></span></p>
</section>
