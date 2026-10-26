<?php
/** @var array<string,mixed> $summary */
/** @var array<int,array<string,mixed>> $orders */
/** @var array<int,array<string,mixed>> $vouchers */
/** @var array<int,array<string,mixed>> $captive */
/** @var array<int,array<string,mixed>> $jobs */
/** @var array<int,array<string,mixed>> $retry */
$retryMap = [];
foreach ($retry as $row) {
    $retryMap[(string) $row['status']] = (int) $row['total'];
}
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Ringkasan operasional</span>
        <h1>Dashboard</h1>
        <p class="muted">Data langsung dari database SQLite tunggal.</p>
    </div>
    <div class="inline-actions">
        <a class="btn btn-ghost" href="<?= e(billas_url('/admin/analytics')) ?>">Analitik</a>
        <a class="btn btn-primary" href="<?= e(billas_url('/admin/invoices')) ?>">Kelola tagihan</a>
    </div>
</div>

<div class="stat-grid four">
    <div class="stat-card">
        <span class="stat-label">Pendapatan total</span>
        <strong class="stat-value"><?= e(billas_money((int) $summary['revenue_total'])) ?></strong>
        <span class="stat-note">Hari ini <?= e(billas_money((int) $summary['revenue_today'])) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Pelanggan aktif</span>
        <strong class="stat-value"><?= (int) $summary['customers_active'] ?></strong>
        <span class="stat-note"><?= (int) $summary['customers_isolir'] ?> diisolir</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Order sukses</span>
        <strong class="stat-value"><?= (int) $summary['orders_paid'] ?> / <?= (int) $summary['orders_total'] ?></strong>
        <span class="stat-note">Success rate <?= e((string) $summary['success_rate']) ?>%</span>
    </div>
    <div class="stat-card stat-highlight">
        <span class="stat-label">Voucher aktif</span>
        <strong class="stat-value"><?= (int) $summary['vouchers_active'] ?></strong>
        <span class="stat-note"><?= (int) $summary['vouchers_captive'] ?> dari captive portal</span>
    </div>
</div>

<div class="stat-grid four compact-stats">
    <div class="stat-card"><span class="stat-label">Order pending</span><strong class="stat-value"><?= (int) $summary['orders_pending'] ?></strong></div>
    <div class="stat-card"><span class="stat-label">Invoice belum lunas</span><strong class="stat-value"><?= (int) $summary['invoices_unpaid'] ?></strong></div>
    <div class="stat-card"><span class="stat-label">Sesi captive pending</span><strong class="stat-value"><?= (int) $summary['captive_pending'] ?></strong></div>
    <div class="stat-card"><span class="stat-label">Captive terbayar</span><strong class="stat-value"><?= (int) $summary['captive_paid'] ?></strong></div>
</div>

<div class="content-grid">
    <section class="card">
        <div class="card-head">
            <h2>Order terbaru</h2>
            <a class="link small" href="<?= e(billas_url('/admin/vouchers')) ?>">Semua voucher</a>
        </div>
        <?php if ($orders === []): ?>
            <div class="empty-state compact"><p>Belum ada order.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Referensi</th><th>Paket</th><th>Nominal</th><th>Status</th><th>Waktu</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($orders, 0, 8) as $order): ?>
                        <tr>
                            <td class="mono small"><?= e($order['merchant_ref']) ?></td>
                            <td><?= e($order['package_name']) ?><br><span class="muted small"><?= e($order['source'] ?? 'web_portal') ?></span></td>
                            <td><?= e(billas_money((int) $order['amount'])) ?></td>
                            <td><span class="badge badge-<?= e($order['status'] === 'paid' ? 'success' : ($order['status'] === 'pending' ? 'warning' : 'muted')) ?>"><?= e($order['status']) ?></span></td>
                            <td class="small"><?= e(billas_date((int) $order['created_at'], true)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <aside class="card">
        <div class="card-head"><h2>Kesehatan cron</h2></div>
        <ul class="job-list">
            <?php foreach ($jobs as $job): ?>
                <?php
                $result = json_decode((string) ($job['last_result'] ?? ''), true);
                $ok = is_array($result) && (($result['status'] ?? '') === 'ok');
                $lastRun = (int) $job['last_run_at'];
                ?>
                <li>
                    <span class="job-dot <?= $lastRun > 0 ? ($ok ? 'ok' : 'warn') : 'idle' ?>"></span>
                    <div>
                        <strong><?= e($job['task']) ?></strong>
                        <span class="muted small"><?= $lastRun > 0 ? e(billas_date($lastRun, true)) : 'Belum pernah' ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="divider"></div>
        <h3>Retry queue</h3>
        <div class="pill-row">
            <span class="badge badge-warning">pending <?= (int) ($retryMap['pending'] ?? 0) ?></span>
            <span class="badge badge-info">processing <?= (int) ($retryMap['processing'] ?? 0) ?></span>
            <span class="badge badge-success">done <?= (int) ($retryMap['done'] ?? 0) ?></span>
            <span class="badge badge-muted">failed <?= (int) ($retryMap['failed'] ?? 0) ?></span>
        </div>
    </aside>
</div>

<div class="content-grid">
    <section class="card">
        <div class="card-head">
            <h2>Voucher terbaru</h2>
            <a class="link small" href="<?= e(billas_url('/admin/vouchers')) ?>">Lihat semua</a>
        </div>
        <?php if ($vouchers === []): ?>
            <div class="empty-state compact"><p>Belum ada voucher.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Kode</th><th>Paket</th><th>Sumber</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($vouchers, 0, 6) as $voucher): ?>
                        <tr>
                            <td class="mono"><?= e($voucher['code']) ?></td>
                            <td><?= e($voucher['package_name']) ?></td>
                            <td><span class="badge badge-soft"><?= e($voucher['source'] ?? 'web_portal') ?></span></td>
                            <td><span class="badge badge-<?= e((int) $voucher['revoked'] === 1 ? 'muted' : 'success') ?>"><?= e((int) $voucher['revoked'] === 1 ? 'revoked' : 'active') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Journey captive terbaru</h2>
            <a class="link small" href="<?= e(billas_url('/admin/wifi/sessions')) ?>">Detail</a>
        </div>
        <?php if ($captive === []): ?>
            <div class="empty-state compact"><p>Belum ada sesi captive.</p></div>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($captive as $session): ?>
                    <li>
                        <span class="timeline-dot <?= e($session['status']) ?>"></span>
                        <div>
                            <strong><?= e($session['ssid']) ?> · <?= e($session['package_name'] ?? 'Tanpa paket') ?></strong>
                            <span class="badge badge-<?= e($session['status'] === 'paid' ? 'success' : ($session['status'] === 'pending' ? 'warning' : 'muted')) ?>"><?= e($session['status']) ?></span>
                            <p class="muted small mono"><?= e($session['client_ip'] ?: 'IP tidak diketahui') ?> · <?= e(billas_date((int) $session['created_at'], true)) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
