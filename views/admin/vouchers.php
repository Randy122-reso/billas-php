<?php
/** @var array<int,array<string,mixed>> $vouchers */
/** @var string $source */
/** @var string $status */
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Hotspot &amp; captive</span>
        <h1>Voucher</h1>
        <p class="muted">Pantau voucher web portal dan captive portal, termasuk status push ke MikroTik.</p>
    </div>
</div>

<section class="card">
    <form method="get" action="<?= e(billas_url('/admin/vouchers')) ?>" class="filter-bar">
        <label class="field">
            <span>Sumber</span>
            <select name="source">
                <option value="">Semua</option>
                <?php foreach (['web_portal' => 'Web portal', 'captive_portal' => 'Captive portal'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $source === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="">Semua</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktif</option>
                <option value="revoked" <?= $status === 'revoked' ? 'selected' : '' ?>>Dicabut</option>
            </select>
        </label>
        <div class="filter-actions">
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="<?= e(billas_url('/admin/vouchers')) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head"><h2>Daftar voucher</h2><span class="muted small"><?= count($vouchers) ?> baris</span></div>
    <?php if ($vouchers === []): ?>
        <div class="empty-state"><p>Belum ada voucher yang cocok dengan filter.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Kode</th><th>Password</th><th>Paket</th><th>Sumber</th><th>SSID</th><th>Berlaku</th><th>Push</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($vouchers as $voucher): ?>
                    <tr>
                        <td class="mono"><?= e($voucher['code']) ?></td>
                        <td class="mono"><?= e($voucher['password']) ?></td>
                        <td><?= e($voucher['package_name']) ?><br><span class="muted small"><?= e(billas_money((int) $voucher['amount'])) ?></span></td>
                        <td><span class="badge badge-soft"><?= e($voucher['source'] ?? 'web_portal') ?></span></td>
                        <td class="small"><?= e($voucher['ssid'] ?: '—') ?></td>
                        <td class="small"><?= e($voucher['expires_at'] ? billas_date((int) $voucher['expires_at'], true) : 'Uptime') ?></td>
                        <td><span class="badge badge-<?= e((int) $voucher['pushed_to_router'] === 1 ? 'success' : 'warning') ?>"><?= e((int) $voucher['pushed_to_router'] === 1 ? 'terkirim' : 'pending') ?></span></td>
                        <td><span class="badge badge-<?= e((int) $voucher['revoked'] === 1 ? 'muted' : 'success') ?>"><?= e((int) $voucher['revoked'] === 1 ? 'dicabut' : 'aktif') ?></span></td>
                        <td>
                            <?php if ((int) $voucher['revoked'] === 0): ?>
                                <form method="post" action="<?= e(billas_url('/admin/vouchers/' . (int) $voucher['id'] . '/revoke')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Cabut voucher ini? Pengguna akan kehilangan akses.')">Cabut</button>
                                </form>
                            <?php endif; ?>
                            <?php if (billas_feature('webhook_simulator') && !empty($voucher['order_id'])): ?>
                                <a class="link small" href="<?= e(billas_url('/admin/simulator?target_type=order&target_id=' . rawurlencode((string) $voucher['order_id']) . '&provider=' . rawurlencode((string) ($voucher['gateway'] ?? '')) . '&event=PAID')) ?>">Simulasi</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
