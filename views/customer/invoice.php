<?php
/** @var array<string,mixed> $customer */
/** @var array<string,mixed> $invoice */
/** @var array<int,array<string,mixed>> $transactions */
/** @var list<string> $gateways */
/** @var array{until:?string,active:bool,days_left:int,label:string} $guarantee */
$isUnpaid = $invoice['status'] === 'unpaid';
$pending = null;
foreach ($transactions as $transaction) {
    if ($transaction['status'] === 'pending' && (int) $transaction['expires_at'] > time()) {
        $pending = $transaction;
        break;
    }
}
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(billas_url('/portal/check')) ?>">Cek tagihan</a>
    <span>/</span>
    <span><?= e($invoice['invoice_no']) ?></span>
</nav>

<section class="page-head">
    <div>
        <span class="eyebrow">Invoice</span>
        <h1><?= e($invoice['invoice_no']) ?></h1>
        <p class="muted">Periode <?= e(billas_date($invoice['period_start'])) ?> – <?= e(billas_date($invoice['period_end'])) ?></p>
    </div>
    <span class="badge badge-<?= e($invoice['status'] === 'paid' ? 'success' : ($invoice['status'] === 'unpaid' ? 'warning' : 'muted')) ?> badge-lg">
        <?= e($invoice['status']) ?>
    </span>
</section>

<div class="content-grid">
    <section class="card">
        <div class="invoice-summary">
            <div>
                <span class="muted small">Total tagihan</span>
                <strong class="invoice-total"><?= e(billas_money((int) $invoice['amount'])) ?></strong>
            </div>
            <dl class="summary-list compact">
                <div><dt>Jatuh tempo</dt><dd><?= e(billas_date($invoice['due_date'])) ?></dd></div>
                <div><dt>Paket</dt><dd><?= e($invoice['package_name'] ?? $customer['package_name'] ?? '—') ?></dd></div>
                <div><dt>Dibuat</dt><dd><?= e(billas_date((int) $invoice['created_at'], true)) ?></dd></div>
                <div><dt>Status</dt><dd><?= e($invoice['status']) ?></dd></div>
            </dl>
        </div>

        <div class="notice notice-info">
            Layanan Anda dijamin sampai <strong><?= e($guarantee['until'] ? billas_date($guarantee['until']) : '—') ?></strong>.
            Setelah pembayaran diterima, masa aktif dan garansi diperpanjang otomatis.
        </div>

        <?php if ($pending): ?>
            <div class="pending-payment">
                <div>
                    <span class="eyebrow">Menunggu pembayaran</span>
                    <strong><?= e(billas_money((int) $pending['amount'])) ?></strong>
                    <p class="muted small">via <?= e(strtoupper((string) $pending['gateway'])) ?> · berakhir <?= e(billas_date((int) $pending['expires_at'], true)) ?></p>
                </div>
                <a class="btn btn-primary" href="<?= e(billas_url('/portal/payment-status/' . (int) $pending['id'])) ?>">Lanjutkan pembayaran</a>
            </div>
        <?php endif; ?>

        <?php if ($isUnpaid): ?>
            <div class="pay-panel">
                <h2>Bayar tagihan</h2>
                <?php if ($gateways === []): ?>
                    <div class="alert alert-warning">Belum ada gateway pembayaran aktif. Hubungi operator untuk melakukan pembayaran manual.</div>
                <?php else: ?>
                    <form method="post" action="<?= e(billas_url('/portal/pay/' . (int) $invoice['id'])) ?>" class="stack-form">
                        <?= csrf_field() ?>
                        <label>
                            <span>Metode pembayaran</span>
                            <select name="gateway">
                                <option value="">Otomatis (fallback gateway)</option>
                                <?php foreach ($gateways as $gateway): ?>
                                    <option value="<?= e($gateway) ?>"><?= e(strtoupper($gateway)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="btn btn-primary btn-lg">Buat pembayaran QRIS</button>
                    </form>
                    <p class="muted small">Anda akan diarahkan ke halaman QRIS. Pembayaran diverifikasi otomatis melalui webhook gateway.</p>
                <?php endif; ?>
            </div>
        <?php elseif ($invoice['status'] === 'paid'): ?>
            <div class="alert alert-success">
                Invoice ini sudah lunas pada <?= e(billas_date($invoice['paid_at'], true)) ?>.
                <a href="<?= e(billas_url('/portal/check')) ?>">Kembali ke ringkasan</a>.
            </div>
        <?php endif; ?>
    </section>

    <aside class="card">
        <h2>Riwayat pembayaran</h2>
        <?php if ($transactions === []): ?>
            <p class="muted">Belum ada transaksi untuk invoice ini.</p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($transactions as $transaction): ?>
                    <li>
                        <span class="timeline-dot <?= e($transaction['status']) ?>"></span>
                        <div>
                            <strong><?= e(billas_money((int) $transaction['amount'])) ?></strong>
                            <span class="badge badge-<?= e($transaction['status'] === 'paid' ? 'success' : ($transaction['status'] === 'pending' ? 'warning' : 'muted')) ?>"><?= e($transaction['status']) ?></span>
                            <p class="muted small">
                                <?= e(strtoupper((string) $transaction['gateway'])) ?> ·
                                <?= e(billas_date((int) $transaction['created_at'], true)) ?>
                            </p>
                            <a class="link small" href="<?= e(billas_url('/portal/payment-status/' . (int) $transaction['id'])) ?>">Lihat status</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="divider"></div>
        <a class="btn btn-ghost btn-block" href="<?= e(billas_url('/portal/account')) ?>">Kelola akun pembayaran</a>
    </aside>
</div>
