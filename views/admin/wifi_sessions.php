<?php
/** @var array<int,array<string,mixed>> $sessions */
/** @var array<int,array<string,mixed>> $stats */
/** @var string $status */
$statMap = [];
foreach ($stats as $row) {
    $statMap[(string) $row['status']] = (int) $row['total'];
}
$total = array_sum($statMap);
$statuses = ['pending' => 'Pending', 'paid' => 'Terbayar', 'expired' => 'Kedaluwarsa', 'cancelled' => 'Dibatalkan', 'revoked' => 'Dicabut'];
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Fitur E · captive portal</span>
        <h1>Sesi WiFi</h1>
        <p class="muted">Jejak sesi pengguna SSID RTRWNET: IP, MAC, paket, order QRIS, dan voucher unik.</p>
    </div>
    <a class="btn btn-ghost" href="<?= e(billas_url('/admin/analytics')) ?>">Analitik captive</a>
</div>

<div class="stat-grid five">
    <?php foreach ($statuses as $value => $label): ?>
        <div class="stat-card <?= $status === $value ? 'stat-highlight' : '' ?>">
            <span class="stat-label"><?= e($label) ?></span>
            <strong class="stat-value"><?= (int) ($statMap[$value] ?? 0) ?></strong>
        </div>
    <?php endforeach; ?>
</div>

<section class="card">
    <form method="get" action="<?= e(billas_url('/admin/wifi/sessions')) ?>" class="filter-bar">
        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="">Semua (<?= $total ?>)</option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="filter-actions">
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="<?= e(billas_url('/admin/wifi/sessions')) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>Daftar sesi</h2>
        <span class="muted small"><?= count($sessions) ?> baris · maksimal 500 terbaru</span>
    </div>
    <?php if ($sessions === []): ?>
        <div class="empty-state"><h3>Belum ada sesi WiFi</h3><p>Sesi akan muncul ketika pengguna membuka captive portal RTRWNET.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Waktu</th><th>Klien</th><th>SSID</th><th>Paket</th><th>Order</th><th>Voucher</th><th>Status</th><th>Kedaluwarsa</th></tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $session): ?>
                    <?php
                    $sessionStatus = (string) $session['status'];
                    $badge = match ($sessionStatus) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        'expired' => 'muted',
                        'revoked', 'cancelled' => 'danger',
                        default => 'soft',
                    };
                    ?>
                    <tr>
                        <td class="small"><?= e(billas_date((int) $session['created_at'], true)) ?></td>
                        <td>
                            <span class="mono small"><?= e($session['client_ip'] ?: '—') ?></span><br>
                            <span class="muted small mono"><?= e($session['client_mac'] ?: 'MAC tidak diketahui') ?></span>
                        </td>
                        <td><span class="badge badge-soft"><?= e($session['ssid']) ?></span></td>
                        <td><?= e($session['package_name'] ?? '—') ?>
                            <?php if (!empty($session['amount'])): ?><br><span class="muted small"><?= e(billas_money((int) $session['amount'])) ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($session['order_id'])): ?>
                                <span class="badge badge-<?= e(($session['order_status'] ?? '') === 'paid' ? 'success' : 'warning') ?>"><?= e($session['order_status'] ?? '-') ?></span>
                            <?php else: ?>
                                <span class="muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($session['voucher_code'])): ?>
                                <span class="mono small"><?= e($session['voucher_code']) ?></span>
                                <br>
                                <span class="badge badge-<?= e((int) ($session['voucher_revoked'] ?? 0) === 1 ? 'muted' : 'success') ?>"><?= (int) ($session['voucher_revoked'] ?? 0) === 1 ? 'dicabut' : 'aktif' ?></span>
                            <?php else: ?>
                                <span class="muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= e($badge) ?>"><?= e($sessionStatus) ?></span></td>
                        <td class="small"><?= e(billas_date((int) $session['expires_at'], true)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
