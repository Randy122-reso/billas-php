<?php
/** @var array<string,mixed> $order */
/** @var array<string,mixed> $payload */
/** @var string $qr_data_uri */
/** @var string $status_url */
/** @var string $voucher_url */
$checkout = (string) ($payload['checkout_url'] ?? $payload['invoice_url'] ?? '');
?>
<section class="wifi-pay-wrap">
    <div class="payment-card wifi-payment-card">
        <div class="payment-head">
            <div>
                <span class="eyebrow">Pembayaran WiFi</span>
                <h1>Scan &amp; bayar</h1>
                <p class="muted"><?= e($order['package_name']) ?> · <span class="mono"><?= e($order['merchant_ref']) ?></span></p>
            </div>
            <span class="badge badge-warning"><?= e($order['status']) ?></span>
        </div>

        <div class="payment-amount">
            <small>Total pembayaran</small>
            <strong><?= e(billas_money((int) $order['amount'])) ?></strong>
            <span class="countdown" data-countdown="<?= (int) $order['expires_at'] ?>">Menghitung…</span>
        </div>

        <?php if ($qr_data_uri !== ''): ?>
            <div class="qr-box"
                 data-wifi-qris
                 data-status-url="<?= e($status_url) ?>"
                 data-voucher-url="<?= e($voucher_url) ?>"
                 data-expires-at="<?= (int) $order['expires_at'] ?>">
                <img src="<?= e($qr_data_uri) ?>" alt="QRIS WiFi <?= e($order['merchant_ref']) ?>" width="280" height="280">
                <span class="qr-scan">Scan dengan m-banking / e-wallet</span>
            </div>
        <?php else: ?>
            <div class="empty-state compact"><h3>QR tidak tersedia</h3><p>Gunakan tautan pembayaran di bawah ini.</p></div>
        <?php endif; ?>

        <?php if ($checkout !== ''): ?>
            <a class="btn btn-primary btn-block" href="<?= e($checkout) ?>" target="_blank" rel="noopener noreferrer">Buka halaman pembayaran</a>
        <?php endif; ?>

        <div class="payment-status" data-wifi-message>
            <span class="spinner" aria-hidden="true"></span>
            Menunggu pembayaran…
        </div>

        <div class="notice notice-warning">
            <strong>Simpan password Anda.</strong> Password WiFi hanya ditampilkan sekali di halaman berikutnya dan tidak dikirim ulang.
        </div>

        <div class="inline-actions">
            <a class="btn btn-ghost" href="<?= e(billas_url('/wifi')) ?>">Ganti paket</a>
            <a class="btn btn-ghost" href="<?= e(billas_url($voucher_url)) ?>">Cek manual</a>
        </div>
    </div>

    <aside class="payment-aside">
        <h3>Ringkasan</h3>
        <dl class="summary-list">
            <div><dt>Paket</dt><dd><?= e($order['package_name']) ?></dd></div>
            <div><dt>SSID</dt><dd><?= e($order['ssid'] ?: '—') ?></dd></div>
            <div><dt>Gateway</dt><dd><?= e(strtoupper((string) $order['gateway'])) ?></dd></div>
            <div><dt>Referensi</dt><dd class="mono"><?= e($order['merchant_ref']) ?></dd></div>
            <div><dt>Berlaku sampai</dt><dd><?= e(billas_date((int) $order['expires_at'], true)) ?></dd></div>
        </dl>
        <div class="notice notice-info small">Pembayaran diverifikasi otomatis. Halaman ini akan berpindah ke password WiFi setelah pembayaran diterima.</div>
    </aside>
</section>
