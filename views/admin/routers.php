<?php
/** @var array<int,array<string,mixed>> $routers */
/** @var array<string,mixed>|null $edit */
/** @var bool $fake */
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Infrastruktur</span>
        <h1>Router</h1>
        <p class="muted">Koneksi RouterOS API untuk push voucher, enable/disable PPPoE, dan kick session.</p>
    </div>
    <a class="btn btn-primary" href="#router-form">Tambah router</a>
</div>

<?php if ($fake): ?>
    <div class="alert alert-info">Mode <strong>MIKROTIK_FAKE</strong> aktif. Semua operasi router disimulasikan tanpa koneksi jaringan.</div>
<?php endif; ?>

<section class="card">
    <div class="card-head"><h2>Daftar router</h2><span class="muted small"><?= count($routers) ?> router</span></div>
    <?php if ($routers === []): ?>
        <div class="empty-state"><p>Belum ada router. Tambahkan router pertama Anda.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Nama</th><th>Host</th><th>Port</th><th>User</th><th>TLS</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($routers as $router): ?>
                    <tr>
                        <td><strong><?= e($router['name']) ?></strong></td>
                        <td class="mono"><?= e($router['host']) ?></td>
                        <td><?= (int) $router['port'] ?></td>
                        <td class="mono"><?= e($router['username']) ?></td>
                        <td><span class="badge badge-<?= e((int) $router['use_tls'] === 1 ? 'success' : 'muted') ?>"><?= e((int) $router['use_tls'] === 1 ? 'ya' : 'tidak') ?></span></td>
                        <td><span class="badge badge-<?= e((int) $router['active'] === 1 ? 'success' : 'muted') ?>"><?= e((int) $router['active'] === 1 ? 'aktif' : 'nonaktif') ?></span></td>
                        <td class="actions-cell">
                            <a class="link" href="<?= e(billas_url('/admin/routers?edit=' . (int) $router['id'])) ?>">Edit</a>
                            <form method="post" action="<?= e(billas_url('/admin/routers/' . (int) $router['id'] . '/test')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-sm" type="submit">Tes</button>
                            </form>
                            <form method="post" action="<?= e(billas_url('/admin/routers/' . (int) $router['id'] . '/toggle')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $router['active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="router-form">
    <div class="card-head"><h2><?= $edit ? 'Edit router' : 'Tambah router' ?></h2></div>
    <form method="post" action="<?= e(billas_url('/admin/routers')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <label class="field"><span>Nama <em>*</em></span><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></label>
        <label class="field"><span>Host / IP <em>*</em></span><input type="text" name="host" value="<?= e($edit['host'] ?? '') ?>" required placeholder="192.168.88.1"></label>
        <label class="field"><span>Port API</span><input type="number" min="1" max="65535" name="port" value="<?= (int) ($edit['port'] ?? 8728) ?>"></label>
        <label class="field"><span>Username <em>*</em></span><input type="text" name="username" value="<?= e($edit['username'] ?? '') ?>" required></label>
        <label class="field"><span>Password</span><input type="password" name="password" placeholder="<?= $edit ? 'Kosongkan jika tidak diubah' : 'Password API router' ?>" autocomplete="new-password"></label>
        <label class="checkbox-field"><input type="checkbox" name="use_tls" value="1" <?= (int) ($edit['use_tls'] ?? 0) === 1 ? 'checked' : '' ?>> <span>Gunakan TLS (api-ssl)</span></label>
        <label class="checkbox-field"><input type="checkbox" name="active" value="1" <?= (int) ($edit['active'] ?? 1) === 1 ? 'checked' : '' ?>> <span>Aktif</span></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $edit ? 'Simpan perubahan' : 'Tambah router' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(billas_url('/admin/routers')) ?>">Batal</a><?php endif; ?>
        </div>
    </form>
    <div class="notice notice-info small">Password router disimpan terenkripsi AES-256-GCM menggunakan <span class="mono">ENCRYPTION_KEY</span>.</div>
</section>
