<?php
/** @var array<string,mixed> $transaction */
/** @var array<string,mixed> $payload */
/** @var string $qr_data_uri */
/** @var ?string $checkout_url */
/** @var bool $paid */
/** @var bool $expired */
/** @var string $status_url */
/** @var string $invoice_url */
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(billas_url('/portal/check')) ?>">Cek tagihan</a>
    <span>/</span>
    <a href="<?= e(billas_url($invoice_url)) ?>"><?= e($transaction['invoice_no']) ?></a>
    <span>/</span>
    <span>Status pembayaran</span>
</nav>

<section class="payment-wrap">
    <div class="payment-card">
        <?php if ($paid): ?>
            <div class="success-mark" aria-hidden="true">✓</div>
            <span class="eyebrow">Pembayaran diterima</span>
            <h1>Terima kasih!</h1>
            <p class="muted">Invoice <strong><?= e($transaction['invoice_no']) ?></strong> sudah lunas dan layanan Anda diperpanjang.</p>
            <div class="inline-actions center">
                <a class="btn btn-primary" href="<?= e(billas_url('/portal/check')) ?>">Lihat ringkasan layanan</a>
                <a class="btn btn-ghost" href="<?= e(billas_url($invoice_url)) ?>">Detail invoice</a>
            </div>
        <?php else: ?>
            <div class="payment-head">
                <div>
                    <span class="eyebrow">Pembayaran invoice</span>
                    <h1><?= e($transaction['invoice_no']) ?></h1>
                    <p class="muted">via <?= e(strtoupper((string) $transaction['gateway'])) ?> · <span class="mono"><?= e($transaction['merchant_ref']) ?></span></p>
                </div>
                <span class="badge badge-<?= e($expired ? 'muted' : 'warning') ?>"><?= e($expired ? 'Kedaluwarsa' : 'Menunggu') ?></span>
            </div>

            <div class="payment-amount">
                <small>Total pembayaran</small>
                <strong><?= e(billas_money((int) $transaction['amount'])) ?></strong>
                <?php if ($expired): ?>
                    <span class="muted">Transaksi sudah kedaluwarsa. Buat pembayaran baru dari halaman invoice.</span>
                <?php else: ?>
                    <span class="countdown" data-countdown="<?= (int) $transaction['expires_at'] ?>">Menghitung…</span>
                <?php endif; ?>
            </div>

            <?php if (!$expired && $qr_data_uri !== ''): ?>
                <div class="qr-box" data-qris-poll data-status-url="<?= e($status_url) ?>?format=json" data-redirect-url="<?= e($invoice_url) ?>" data-expires-at="<?= (int) $transaction['expires_at'] ?>">
                    <img src="<?= e($qr_data_uri) ?>" alt="QRIS <?= e($transaction['merchant_ref']) ?>" width="280" height="280">
                    <span class="qr-scan">Scan dengan aplikasi pembayaran</span>
                </div>
            <?php endif; ?>

            <?php if ($checkout_url): ?>
                <a class="btn btn-primary btn-block" href="<?= e($checkout_url) ?>" target="_blank" rel="noopener noreferrer">Buka halaman pembayaran</a>
            <?php endif; ?>

            <?php if (!$expired): ?>
                <div class="payment-status" data-poll-message>
                    <span class="spinner" aria-hidden="true"></span>
                    Menunggu pembayaran dikonfirmasi…
                </div>
            <?php endif; ?>

            <div class="inline-actions">
                <a class="btn btn-ghost" href="<?= e(billas_url($invoice_url)) ?>">Kembali ke invoice</a>
                <a class="btn btn-ghost" href="<?= e(billas_url('/portal/check')) ?>">Ringkasan</a>
            </div>
        <?php endif; ?>
    </div>

    <aside class="payment-aside">
        <h3>Detail transaksi</h3>
        <dl class="summary-list">
            <div><dt>Invoice</dt><dd class="mono"><?= e($transaction['invoice_no']) ?></dd></div>
            <div><dt>Gateway</dt><dd><?= e(strtoupper((string) $transaction['gateway'])) ?></dd></div>
            <div><dt>Referensi</dt><dd class="mono"><?= e($transaction['merchant_ref']) ?></dd></div>
            <div><dt>Dibuat</dt><dd><?= e(billas_date((int) $transaction['created_at'], true)) ?></dd></div>
            <div><dt>Berakhir</dt><dd><?= e(billas_date((int) $transaction['expires_at'], true)) ?></dd></div>
        </dl>
        <div class="notice notice-info small">
            Jangan menutup halaman ini sebelum pembayaran terkonfirmasi. Anda juga dapat membuka kembali status dari halaman invoice.
        </div>
    </aside>
</section>
