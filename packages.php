<?php
/** @var array<int,array<string,mixed>> $packages */
/** @var array<string,mixed>|null $edit */
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Katalog layanan</span>
        <h1>Paket</h1>
        <p class="muted">Atur harga, durasi, profil MikroTik, dan ketersediaan di captive portal.</p>
    </div>
    <a class="btn btn-primary" href="#package-form">Tambah paket</a>
</div>

<section class="card">
    <div class="card-head"><h2>Daftar paket</h2><span class="muted small"><?= count($packages) ?> paket</span></div>
    <?php if ($packages === []): ?>
        <div class="empty-state"><p>Belum ada paket.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Nama</th><th>Tipe</th><th>Harga</th><th>Durasi</th><th>User Profile</th><th>Captive</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($packages as $package): ?>
                    <tr>
                        <td><strong><?= e($package['name']) ?></strong></td>
                        <td><span class="badge badge-soft"><?= e($package['type']) ?></span></td>
                        <td><?= e(billas_money((int) $package['price'])) ?></td>
                        <td class="small"><?= e(billas_duration($package['duration_mode'], (int) ($package['duration_value'] ?? 0), $package['duration_unit'] ?? null, (int) ($package['calendar_days'] ?? 0))) ?></td>
                        <td class="mono small"><?= e($package['mikrotik_profile']) ?></td>
                        <td><span class="badge badge-<?= e((int) ($package['available_on_captive'] ?? 0) === 1 ? 'success' : 'muted') ?>"><?= e((int) ($package['available_on_captive'] ?? 0) === 1 ? 'ya' : 'tidak') ?></span></td>
                        <td><span class="badge badge-<?= e((int) $package['active'] === 1 ? 'success' : 'muted') ?>"><?= e((int) $package['active'] === 1 ? 'aktif' : 'nonaktif') ?></span></td>
                        <td class="actions-cell">
                            <a class="link" href="<?= e(billas_url('/admin/packages?edit=' . (int) $package['id'])) ?>">Edit</a>
                            <form method="post" action="<?= e(billas_url('/admin/packages/' . (int) $package['id'] . '/toggle')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $package['active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="package-form">
    <div class="card-head"><h2><?= $edit ? 'Edit paket' : 'Tambah paket' ?></h2></div>
    <form method="post" action="<?= e(billas_url('/admin/packages')) ?>" class="form-grid" data-package-form>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <label class="field"><span>Nama paket <em>*</em></span><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></label>
        <label class="field">
            <span>Tipe</span>
            <select name="type" data-package-type>
                <option value="hotspot" <?= ($edit['type'] ?? 'hotspot') === 'hotspot' ? 'selected' : '' ?>>Hotspot</option>
                <option value="pppoe" <?= ($edit['type'] ?? '') === 'pppoe' ? 'selected' : '' ?>>PPPoE</option>
            </select>
        </label>
        <label class="field"><span>Harga (Rp) <em>*</em></span><input type="number" min="1000" step="500" name="price" value="<?= (int) ($edit['price'] ?? 5000) ?>" required></label>
        <label class="field">
            <span>Mode durasi</span>
            <select name="duration_mode" data-duration-mode>
                <option value="uptime" <?= ($edit['duration_mode'] ?? 'uptime') === 'uptime' ? 'selected' : '' ?>>Uptime</option>
                <option value="calendar" <?= ($edit['duration_mode'] ?? '') === 'calendar' ? 'selected' : '' ?>>Kalender</option>
            </select>
        </label>
        <label class="field"><span>Nilai durasi</span><input type="number" min="0" name="duration_value" value="<?= e($edit['duration_value'] ?? '') ?>" placeholder="mis. 7"></label>
        <label class="field">
            <span>Satuan durasi</span>
            <select name="duration_unit">
                <?php foreach (['minutes', 'hours', 'days'] as $unit): ?>
                    <option value="<?= e($unit) ?>" <?= ($edit['duration_unit'] ?? 'days') === $unit ? 'selected' : '' ?>><?= e($unit) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Hari kalender</span><input type="number" min="0" name="calendar_days" value="<?= e($edit['calendar_days'] ?? '') ?>" placeholder="mis. 30"></label>
        <label class="field">
            <span>User Profile (MikroTik) <em>*</em></span>
            <input type="text" name="mikrotik_profile" value="<?= e($edit['mikrotik_profile'] ?? '') ?>" required placeholder="mis. hsprof1">
            <small class="muted small">
                Hotspot: nama di <code>/ip hotspot user profile</code>.
                PPPoE: nama di <code>/ppp profile</code>.
                Bukan <code>/ip hotspot profile</code> (Server Profile). Profil harus sudah ada di router agar push voucher/pelanggan tidak gagal.
            </small>
        </label>
        <label class="field"><span>Bandwidth download (kbps)</span><input type="number" min="0" name="bandwidth_down" value="<?= e($edit['bandwidth_down'] ?? '') ?>"></label>
        <label class="field"><span>Bandwidth upload (kbps)</span><input type="number" min="0" name="bandwidth_up" value="<?= e($edit['bandwidth_up'] ?? '') ?>"></label>
        <label class="checkbox-field"><input type="checkbox" name="active" value="1" <?= (int) ($edit['active'] ?? 1) === 1 ? 'checked' : '' ?>> <span>Aktif</span></label>
        <label class="checkbox-field"><input type="checkbox" name="available_on_captive" value="1" <?= (int) ($edit['available_on_captive'] ?? 1) === 1 ? 'checked' : '' ?>> <span>Tampil di captive portal</span></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $edit ? 'Simpan perubahan' : 'Tambah paket' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(billas_url('/admin/packages')) ?>">Batal</a><?php endif; ?>
        </div>
    </form>
</section>