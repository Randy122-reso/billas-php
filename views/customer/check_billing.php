<?php
/** @var array<string,mixed> $customer */
/** @var array<int,array<string,mixed>> $invoices */
/** @var array<string,mixed>|null $open_invoice */
/** @var array{until:?string,active:bool,days_left:int,label:string} $guarantee */
/** @var array<string,mixed>|null $qris_account */
$status = (string) $customer['status'];
$statusLabel = match ($status) {
    'active' => 'Aktif',
    'isolir' => 'Diisolir',
    'inactive' => 'Nonaktif',
    default => ucfirst($status),
};
$statusClass = $status === 'active' ? 'success' : ($status === 'isolir' ? 'warning' : 'muted');
$daysToPaid = $customer['paid_until'] ? max(0, (int) floor((strtotime((string) $customer['paid_until']) - strtotime(date('Y-m-d'))) / 86400)) : null;
?>
<section class="page-head">
    <div>
        <span class="eyebrow">Ringkasan layanan</span>
        <h1>Halo, <?= e($customer['name']) ?></h1>
        <p class="muted">Kode <span class="mono"><?= e($customer['code']) ?></span> · <?= e($customer['package_name'] ?? 'Belum ada paket') ?></p>
    </div>
    <span class="badge badge-<?= e($statusClass) ?> badge-lg"><?= e($statusLabel) ?></span>
</section>

<?php if ($status === 'isolir'): ?>
    <div class="alert alert-warning" role="alert">
        Layanan Anda sementara diisolir karena tagihan. Selesaikan pembayaran untuk mengaktifkan kembali layanan secara otomatis.
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">Masa aktif</span>
        <strong class="stat-value"><?= e(billas_date($customer['paid_until'])) ?></strong>
        <span class="stat-note">
            <?= $daysToPaid === null ? 'Belum diatur' : ($daysToPaid > 0 ? $daysToPaid . ' hari lagi' : 'Perlu perpanjangan') ?>
        </span>
    </div>
    <div class="stat-card stat-highlight">
        <span class="stat-label">Layanan dijamin s/d</span>
        <strong class="stat-value"><?= e($guarantee['until'] ? billas_date($guarantee['until']) : '—') ?></strong>
        <span class="stat-note"><?= e($guarantee['label']) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Tagihan terbuka</span>
        <strong class="stat-value"><?= e($open_invoice ? billas_money((int) $open_invoice['amount']) : 'Lunas') ?></strong>
        <span class="stat-note"><?= e($open_invoice ? 'Jatuh tempo ' . billas_date($open_invoice['due_date']) : 'Tidak ada tagihan tertunggak') ?></span>
    </div>
</div>

<?php if ($open_invoice): ?>
    <section class="card callout-card">
        <div>
            <span class="eyebrow">Perlu dibayar</span>
            <h2><?= e($open_invoice['invoice_no']) ?></h2>
            <p class="muted">Periode <?= e(billas_date($open_invoice['period_start'])) ?> – <?= e(billas_date($open_invoice['period_end'])) ?> · jatuh tempo <?= e(billas_date($open_invoice['due_date'])) ?></p>
        </div>
        <div class="callout-amount">
            <strong><?= e(billas_money((int) $open_invoice['amount'])) ?></strong>
            <a class="btn btn-primary" href="<?= e(billas_url('/portal/invoice/' . (int) $open_invoice['id'])) ?>">Lihat &amp; bayar</a>
        </div>
    </section>
<?php endif; ?>

<div class="content-grid">
    <section class="card">
        <div class="card-head">
            <h2>Riwayat tagihan</h2>
            <span class="muted small"><?= count($invoices) ?> invoice</span>
        </div>
        <?php if ($invoices === []): ?>
            <div class="empty-state compact"><p>Belum ada tagihan. Tagihan bulanan akan muncul di sini.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>Invoice</th><th>Periode</th><th>Nominal</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td class="mono small"><?= e($invoice['invoice_no']) ?></td>
                            <td><?= e($invoice['period']) ?></td>
                            <td><?= e(billas_money((int) $invoice['amount'])) ?></td>
                            <td>
                                <?php
                                $invStatus = (string) $invoice['status'];
                                $invClass = match ($invStatus) {
                                    'paid' => 'success',
                                    'unpaid' => 'warning',
                                    'void' => 'muted',
                                    default => 'muted',
                                };
                                ?>
                                <span class="badge badge-<?= e($invClass) ?>"><?= e($invStatus) ?></span>
                            </td>
                            <td class="actions-cell">
                                <a class="link" href="<?= e(billas_url('/portal/invoice/' . (int) $invoice['id'])) ?>">Detail</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <aside class="card qris-teaser">
        <span class="eyebrow">Bayar lebih cepat</span>
        <h2>QRIS unik Anda</h2>
        <?php if ($qris_account): ?>
            <p class="muted">Satu QRIS untuk semua pembayaran. Pembayaran otomatis dialokasikan ke tagihan tertua.</p>
            <?php if (!empty($qris_account['qr_string'])): ?>
                <img class="qr-mini" src="<?= e(billas_qr_data_uri((string) $qris_account['qr_string'], 240)) ?>" alt="QRIS unik <?= e($customer['code']) ?>">
            <?php endif; ?>
            <a class="btn btn-primary btn-block" href="<?= e(billas_url('/portal/qris-unique')) ?>">Buka QRIS unik</a>
        <?php else: ?>
            <p class="muted">Aktifkan QRIS unik untuk membayar tagihan berulang tanpa membuat transaksi baru.</p>
            <form method="post" action="<?= e(billas_url('/portal/qris-unique/create')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-block">Aktifkan QRIS unik</button>
            </form>
        <?php endif; ?>
        <div class="notice notice-info small">
            Layanan dijamin sampai <strong><?= e($guarantee['until'] ? billas_date($guarantee['until']) : '—') ?></strong>.
            Pembayaran memperpanjang masa aktif dan garansi secara otomatis.
        </div>
    </aside>
</div>
