<?php
/** @var array<string,mixed> $summary */
/** @var array<int,array{day:string,orders:int,revenue:int,captive:int}> $daily */
/** @var array<int,array<string,mixed>> $top_packages */
/** @var array<int,array<string,mixed>> $captive_daily */
$maxRevenue = 1;
foreach ($daily as $row) {
    $maxRevenue = max($maxRevenue, (int) $row['revenue']);
}
$maxOrders = 1;
foreach ($daily as $row) {
    $maxOrders = max($maxOrders, (int) $row['orders']);
}
$captiveByDay = [];
foreach ($captive_daily as $row) {
    $captiveByDay[(string) $row['day']] = (int) $row['sessions'];
}
$totalRevenue = 0;
$totalOrders = 0;
foreach ($daily as $row) {
    $totalRevenue += (int) $row['revenue'];
    $totalOrders += (int) $row['orders'];
}
?>
<div class="page-head">
    <div>
        <span class="eyebrow">14 hari terakhir</span>
        <h1>Analitik</h1>
        <p class="muted">Pendapatan, order, dan trafik captive portal dari satu database.</p>
    </div>
    <div class="inline-actions">
        <a class="btn btn-ghost" href="<?= e(billas_url('/admin/analytics/export.csv?days=30')) ?>">Unduh CSV 30 hari</a>
        <a class="btn btn-primary" href="<?= e(billas_url('/admin/dashboard')) ?>">Dashboard</a>
    </div>
</div>

<div class="stat-grid four">
    <div class="stat-card">
        <span class="stat-label">Pendapatan total</span>
        <strong class="stat-value"><?= e(billas_money((int) $summary['revenue_total'])) ?></strong>
        <span class="stat-note">Hari ini <?= e(billas_money((int) $summary['revenue_today'])) ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Order sukses</span>
        <strong class="stat-value"><?= (int) $summary['orders_paid'] ?></strong>
        <span class="stat-note">dari <?= (int) $summary['orders_total'] ?> order · <?= e((string) $summary['success_rate']) ?>%</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Pendapatan 14 hari</span>
        <strong class="stat-value"><?= e(billas_money($totalRevenue)) ?></strong>
        <span class="stat-note"><?= (int) $totalOrders ?> order terbayar</span>
    </div>
    <div class="stat-card stat-highlight">
        <span class="stat-label">Sesi captive</span>
        <strong class="stat-value"><?= (int) $summary['captive_paid'] ?> / <?= (int) $summary['captive_pending'] + (int) $summary['captive_paid'] ?></strong>
        <span class="stat-note"><?= (int) $summary['vouchers_captive'] ?> voucher captive</span>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <h2>Tren pendapatan &amp; order</h2>
        <span class="muted small">Skala batang relatif terhadap hari tertinggi</span>
    </div>
    <?php if ($daily === []): ?>
        <div class="empty-state compact"><p>Belum ada data harian.</p></div>
    <?php else: ?>
        <div class="bar-chart" role="img" aria-label="Grafik pendapatan 14 hari">
            <?php foreach ($daily as $row): ?>
                <?php
                $height = max(2, (int) round(((int) $row['revenue'] / $maxRevenue) * 100));
                $orderHeight = max(1, (int) round(((int) $row['orders'] / $maxOrders) * 100));
                $dayLabel = date('d/m', strtotime((string) $row['day']));
                ?>
                <div class="bar-chart-col" title="<?= e($row['day']) ?>: <?= e(billas_money((int) $row['revenue'])) ?> · <?= (int) $row['orders'] ?> order">
                    <div class="bar-stack">
                        <span class="bar bar-revenue" style="height: <?= $height ?>%"></span>
                        <span class="bar bar-orders" style="height: <?= $orderHeight ?>%"></span>
                    </div>
                    <span class="bar-label"><?= e($dayLabel) ?></span>
                    <span class="bar-value"><?= (int) $row['orders'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="chart-legend">
            <span><i class="legend-dot revenue"></i> Pendapatan</span>
            <span><i class="legend-dot orders"></i> Order</span>
            <span class="muted small">Captive: <?= array_sum(array_map(static fn (array $r): int => (int) $r['captive'], $daily)) ?> order</span>
        </div>
    <?php endif; ?>
</section>

<div class="content-grid">
    <section class="card">
        <div class="card-head"><h2>Paket terlaris</h2><span class="muted small">Berdasarkan order terbayar</span></div>
        <?php if ($top_packages === []): ?>
            <div class="empty-state compact"><p>Belum ada penjualan.</p></div>
        <?php else: ?>
            <?php $topMax = max(1, ...array_map(static fn (array $row): int => (int) $row['total'], $top_packages)); ?>
            <ul class="rank-list">
                <?php foreach ($top_packages as $index => $package): ?>
                    <li>
                        <span class="rank-no"><?= $index + 1 ?></span>
                        <div class="rank-body">
                            <div class="rank-head">
                                <strong><?= e($package['name']) ?></strong>
                                <span><?= (int) $package['total'] ?> order</span>
                            </div>
                            <div class="progress"><span style="width: <?= max(2, (int) round(((int) $package['total'] / $topMax) * 100)) ?>%"></span></div>
                            <span class="muted small"><?= e(billas_money((int) $package['revenue'])) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h2>Trafik captive harian</h2><span class="muted small">Sesi dibuat</span></div>
        <?php if ($captive_daily === []): ?>
            <div class="empty-state compact"><p>Belum ada sesi captive.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead><tr><th>Tanggal</th><th>Sesi</th><th>Terbayar</th><th>Konversi</th></tr></thead>
                    <tbody>
                    <?php foreach ($captive_daily as $row): ?>
                        <?php
                        $sessions = (int) $row['sessions'];
                        $paid = (int) $row['paid'];
                        $rate = $sessions > 0 ? round(($paid / $sessions) * 100, 1) : 0.0;
                        ?>
                        <tr>
                            <td><?= e(billas_date((string) $row['day'])) ?></td>
                            <td><?= $sessions ?></td>
                            <td><?= $paid ?></td>
                            <td><span class="badge badge-<?= e($rate >= 50 ? 'success' : ($rate > 0 ? 'warning' : 'muted')) ?>"><?= e((string) $rate) ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
