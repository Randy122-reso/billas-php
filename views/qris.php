<?php
/** @var array<string,mixed> $order */
/** @var array<string,mixed> $payload */
/** @var string $qr_string */
/** @var string $qr_data_uri */
/** @var string $status_url */
/** @var string $redirect_url */
$checkout = (string) ($payload['checkout_url'] ?? $payload['invoice_url'] ?? '');
?>
<section class="payment-wrap">
    <div class="payment-card">
        <div class="payment-head">
            <div>
                <span class="eyebrow">Pembayaran QRIS</span>
                <h1>Selesaikan pembayaran</h1>
                <p class="muted">Order <strong><?= e($order['merchant_ref']) ?></strong></p>
            </div>
            <span class="badge badge-warning" data-order-status><?= e($order['status']) ?></span>
        </div>

        <div class="payment-amount">
            <small>Total pembayaran</small>
            <strong><?= e(billas_money((int) $order['amount'])) ?></strong>
            <span class="countdown" data-countdown="<?= (int) $order['expires_at'] ?>">Menghitung…</span>
        </div>

        <?php if ($qr_data_uri !== ''): ?>
            <div class="qr-box" data-qris-poll data-status-url="<?= e($status_url) ?>" data-redirect-url="<?= e($redirect_url) ?>" data-expires-at="<?= (int) $order['expires_at'] ?>">
                <img src="<?= e($qr_data_uri) ?>" alt="QRIS pembayaran <?= e($order['merchant_ref']) ?>" width="280" height="280">
                <span class="qr-scan">Scan dengan m-banking / e-wallet</span>
            </div>
        <?php else: ?>
            <div class="empty-state compact">
                <h3>QR tidak tersedia</h3>
                <p>Silakan lanjutkan pembayaran melalui tautan gateway berikut.</p>
            </div>
        <?php endif; ?>

        <?php if ($checkout !== ''): ?>
            <a class="btn btn-primary btn-block" href="<?= e($checkout) ?>" rel="noopener noreferrer" target="_blank">Buka halaman pembayaran</a>
        <?php endif; ?>

        <div class="payment-status" data-poll-message>
            <span class="spinner" aria-hidden="true"></span>
            Menunggu pembayaran dikonfirmasi…
        </div>

        <ol class="payment-steps">
            <li>Buka aplikasi m-banking atau e-wallet.</li>
            <li>Pilih menu QRIS lalu scan kode di atas.</li>
            <li>Pastikan nominal <?= e(billas_money((int) $order['amount'])) ?> dan selesaikan pembayaran.</li>
            <li>Halaman ini otomatis beralih setelah pembayaran dikonfirmasi.</li>
        </ol>

        <div class="notice notice-info">
            <strong>Penting:</strong> bayar tepat sesuai nominal. Nominal unik dipakai untuk mencocokkan pembayaran Anda secara otomatis.
        </div>

        <div class="inline-actions">
            <a class="btn btn-ghost" href="<?= e(billas_url('/')) ?>">Kembali ke beranda</a>
            <a class="btn btn-ghost" href="<?= e(billas_url($redirect_url)) ?>">Cek status manual</a>
        </div>
    </div>

    <aside class="payment-aside">
        <h3>Ringkasan order</h3>
        <dl class="summary-list">
            <div><dt>Paket</dt><dd><?= e($order['package_name'] ?? 'Hotspot') ?></dd></div>
            <div><dt>Gateway</dt><dd><?= e(strtoupper((string) $order['gateway'])) ?></dd></div>
            <div><dt>Referensi</dt><dd class="mono"><?= e($order['merchant_ref']) ?></dd></div>
            <div><dt>Berlaku sampai</dt><dd><?= e(billas_date((int) $order['expires_at'], true)) ?></dd></div>
        </dl>
        <div class="notice">
            Setelah pembayaran berhasil, voucher akan muncul otomatis di halaman ini.
        </div>
    </aside>
</section>
