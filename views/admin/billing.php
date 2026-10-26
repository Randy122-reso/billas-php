<?php
/** @var array<int,array<string,mixed>> $invoices */
/** @var string $status */
/** @var string $period */
?>
<div class="page-head">
    <div>
        <span class="eyebrow">PPPoE billing</span>
        <h1>Tagihan</h1>
        <p class="muted">Buat invoice bulanan, pantau status, dan tandai pembayaran manual.</p>
    </div>
    <form method="post" action="<?= e(billas_url('/admin/invoices/generate')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="month" name="period" value="<?= e($period ?: date('Y-m')) ?>" aria-label="Periode invoice">
        <button class="btn btn-primary" type="submit">Generate invoice bulanan</button>
    </form>
</div>

<section class="card">
    <form method="get" action="<?= e(billas_url('/admin/invoices')) ?>" class="filter-bar">
        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="">Semua</option>
                <?php foreach (['unpaid', 'paid', 'void', 'expired'] as $value): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Periode</span>
            <input type="month" name="period" value="<?= e($period) ?>">
        </label>
        <div class="filter-actions">
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="<?= e(billas_url('/admin/invoices')) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head"><h2>Daftar invoice</h2><span class="muted small"><?= count($invoices) ?> baris</span></div>
    <?php if ($invoices === []): ?>
        <div class="empty-state"><h3>Belum ada invoice</h3><p>Gunakan tombol generate invoice bulanan untuk membuat tagihan pelanggan PPPoE aktif.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Invoice</th><th>Pelanggan</th><th>Periode</th><th>Nominal</th><th>Jatuh tempo</th><th>Sumber</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td class="mono small"><?= e($invoice['invoice_no']) ?></td>
                        <td><strong><?= e($invoice['customer_name']) ?></strong><br><span class="muted small mono"><?= e($invoice['customer_code']) ?></span></td>
                        <td><?= e(billas_date($invoice['period_start'])) ?> – <?= e(billas_date($invoice['period_end'])) ?></td>
                        <td><?= e(billas_money((int) $invoice['amount'])) ?></td>
                        <td><?= e(billas_date($invoice['due_date'])) ?></td>
                        <td><span class="badge badge-soft"><?= e($invoice['source']) ?></span></td>
                        <td><span class="badge badge-<?= e($invoice['status'] === 'paid' ? 'success' : ($invoice['status'] === 'unpaid' ? 'warning' : 'muted')) ?>"><?= e($invoice['status']) ?></span></td>
                        <td>
                            <?php if ($invoice['status'] === 'unpaid'): ?>
                                <form method="post" action="<?= e(billas_url('/admin/invoices/' . (int) $invoice['id'] . '/mark-paid')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Tandai invoice ini lunas? Masa aktif dan garansi pelanggan akan diperpanjang.')">Tandai lunas</button>
                                </form>
                            <?php else: ?>
                                <span class="muted small"><?= e($invoice['paid_at'] ? billas_date((int) $invoice['paid_at'], true) : '—') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
