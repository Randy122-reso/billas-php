<?php
/** @var array<int,array<string,mixed>> $customers */
/** @var array<int,array<string,mixed>> $packages */
/** @var array<int,array<string,mixed>> $routers */
/** @var array<string,mixed>|null $edit */
/** @var array<int,array<string,mixed>> $accounts */
/** @var string $filter_status */
/** @var string $search */
$activeQris = null;
foreach ($accounts as $account) {
    if ($account['account_type'] === 'qris' && (int) $account['active'] === 1) {
        $activeQris = $account;
        break;
    }
}
$guarantee = [
    'until' => $edit['service_guaranteed_until'] ?? null,
    'active' => !empty($edit['service_guaranteed_until']) && $edit['service_guaranteed_until'] >= date('Y-m-d'),
    'days_left' => 0,
    'label' => !empty($edit['service_guaranteed_until']) ? 'Garansi s/d ' . $edit['service_guaranteed_until'] : 'Belum ada garansi',
];
?>
<div class="page-head">
    <div>
        <span class="eyebrow">PPPoE &amp; langganan</span>
        <h1>Pelanggan</h1>
        <p class="muted">Kelola data pelanggan, masa aktif, garansi layanan, dan QRIS unik.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(billas_url('/admin/customers#customer-form')) ?>">Tambah pelanggan</a>
</div>

<section class="card">
    <form method="get" action="<?= e(billas_url('/admin/customers')) ?>" class="filter-bar">
        <label class="field grow">
            <span>Cari</span>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Kode, nama, telepon, PPPoE…">
        </label>
        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="">Semua</option>
                <?php foreach (['active' => 'Aktif', 'isolir' => 'Isolir', 'inactive' => 'Nonaktif'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filter_status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Terapkan</button>
            <a class="btn btn-ghost" href="<?= e(billas_url('/admin/customers')) ?>">Reset</a>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-head">
        <h2>Daftar pelanggan</h2>
        <span class="muted small"><?= count($customers) ?> baris</span>
    </div>
    <?php if ($customers === []): ?>
        <div class="empty-state"><h3>Belum ada pelanggan</h3><p>Tambahkan pelanggan PPPoE pertama melalui formulir di bawah.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Pelanggan</th><th>Paket</th><th>Status</th><th>Masa aktif</th><th>Garansi s/d</th><th>QRIS unik</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td>
                            <strong><?= e($customer['name']) ?></strong><br>
                            <span class="mono small"><?= e($customer['code']) ?></span>
                            <?php if ($customer['phone']): ?><br><span class="muted small"><?= e($customer['phone']) ?></span><?php endif; ?>
                        </td>
                        <td><?= e($customer['package_name'] ?? '—') ?><br><span class="muted small"><?= e($customer['router_name'] ?? 'Tanpa router') ?></span></td>
                        <td>
                            <?php $sc = $customer['status'] === 'active' ? 'success' : ($customer['status'] === 'isolir' ? 'warning' : 'muted'); ?>
                            <span class="badge badge-<?= e($sc) ?>"><?= e($customer['status']) ?></span>
                        </td>
                        <td><?= e(billas_date($customer['paid_until'])) ?></td>
                        <td><?= e(billas_date($customer['service_guaranteed_until'])) ?></td>
                        <td>
                            <?php if (!empty($customer['qris_unique_ref'])): ?>
                                <span class="badge badge-success">Aktif</span>
                                <br><span class="muted small mono"><?= e($customer['qris_unique_ref']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-muted">Belum ada</span>
                            <?php endif; ?>
                        </td>
                        <td class="actions-cell">
                            <a class="link" href="<?= e(billas_url('/admin/customers?edit=' . (int) $customer['id'])) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="customer-form">
    <div class="card-head">
        <h2><?= $edit ? 'Edit pelanggan' : 'Tambah pelanggan' ?></h2>
        <?php if ($edit): ?><span class="badge badge-soft mono"><?= e($edit['code']) ?></span><?php endif; ?>
    </div>
    <form method="post" action="<?= e(billas_url('/admin/customers')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <label class="field"><span>Kode pelanggan</span><input type="text" name="code" value="<?= e($edit['code'] ?? '') ?>" placeholder="Otomatis jika kosong"></label>
        <label class="field"><span>Nama <em>*</em></span><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></label>
        <label class="field"><span>Nomor telepon</span><input type="text" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></label>
        <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></label>
        <label class="field"><span>Username PPPoE</span><input type="text" name="pppoe_username" value="<?= e($edit['pppoe_username'] ?? '') ?>"></label>
        <label class="field"><span>Password PPPoE</span><input type="text" name="pppoe_password" value="<?= e($edit['pppoe_password'] ?? '') ?>"></label>
        <label class="field">
            <span>Paket</span>
            <select name="package_id">
                <option value="">— Pilih paket —</option>
                <?php foreach ($packages as $package): ?>
                    <option value="<?= (int) $package['id'] ?>" <?= (int) ($edit['package_id'] ?? 0) === (int) $package['id'] ? 'selected' : '' ?>>
                        <?= e($package['name']) ?> · <?= e(billas_money((int) $package['price'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Router</span>
            <select name="router_id">
                <option value="">— Pilih router —</option>
                <?php foreach ($routers as $router): ?>
                    <option value="<?= (int) $router['id'] ?>" <?= (int) ($edit['router_id'] ?? 0) === (int) $router['id'] ? 'selected' : '' ?>><?= e($router['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Status</span>
            <select name="status">
                <?php foreach (['active' => 'Aktif', 'isolir' => 'Isolir', 'inactive' => 'Nonaktif'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($edit['status'] ?? 'active') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Masa aktif (paid_until)</span><input type="date" name="paid_until" value="<?= e($edit['paid_until'] ?? '') ?>"></label>
        <label class="field"><span>Garansi layanan s/d</span><input type="date" name="service_guaranteed_until" value="<?= e($edit['service_guaranteed_until'] ?? '') ?>"></label>
        <label class="field"><span>Password portal</span><input type="text" name="portal_password" placeholder="<?= $edit ? 'Kosongkan jika tidak diubah' : 'Password untuk portal pelanggan' ?>" autocomplete="new-password"></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $edit ? 'Simpan perubahan' : 'Tambah pelanggan' ?></button>
            <?php if ($edit): ?><a class="btn btn-ghost" href="<?= e(billas_url('/admin/customers')) ?>">Batal</a><?php endif; ?>
        </div>
    </form>
</section>

<?php if ($edit): ?>
    <div class="content-grid">
        <section class="card">
            <div class="card-head">
                <h2>Akun pembayaran</h2>
                <span class="badge badge-soft"><?= count($accounts) ?> akun</span>
            </div>
            <?php if ($accounts === []): ?>
                <p class="muted">Belum ada akun pembayaran persisten atau QRIS unik.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Tipe</th><th>Gateway</th><th>Referensi</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><span class="badge badge-soft"><?= e(strtoupper((string) $account['account_type'])) ?></span></td>
                                <td><?= e(strtoupper((string) $account['gateway'])) ?></td>
                                <td class="mono small"><?= e($account['external_ref']) ?></td>
                                <td><span class="badge badge-<?= e((int) $account['active'] === 1 ? 'success' : 'muted') ?>"><?= e((int) $account['active'] === 1 ? 'aktif' : 'nonaktif') ?></span></td>
                                <td class="small"><?= e(billas_date((int) $account['created_at'], true)) ?></td>
                                <td>
                                    <?php if ($account['account_type'] === 'qris' && (int) $account['active'] === 1): ?>
                                        <form method="post" action="<?= e(billas_url('/admin/customers/' . (int) $edit['id'] . '/qris-unique/deactivate')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-danger btn-sm" type="submit">Nonaktifkan</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="divider"></div>
            <div class="inline-actions">
                <form method="post" action="<?= e(billas_url('/admin/customers/' . (int) $edit['id'] . '/qris-unique')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary btn-sm" type="submit"><?= $activeQris ? 'Regenerasi / pastikan QRIS unik' : 'Buat QRIS unik' ?></button>
                </form>
                <form method="post" action="<?= e(billas_url('/admin/customers/' . (int) $edit['id'] . '/payment-account')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <select name="gateway" aria-label="Gateway akun persisten">
                        <option value="xendit">Xendit</option>
                        <option value="midtrans">Midtrans</option>
                        <option value="duitku">Duitku</option>
                    </select>
                    <button class="btn btn-ghost btn-sm" type="submit">Buat akun VA</button>
                </form>
            </div>
        </section>

        <aside class="card">
            <div class="card-head"><h2>Status layanan</h2></div>
            <dl class="summary-list compact">
                <div><dt>Status</dt><dd><span class="badge badge-<?= e($edit['status'] === 'active' ? 'success' : ($edit['status'] === 'isolir' ? 'warning' : 'muted')) ?>"><?= e($edit['status']) ?></span></dd></div>
                <div><dt>Masa aktif</dt><dd><?= e(billas_date($edit['paid_until'])) ?></dd></div>
                <div><dt>Garansi</dt><dd><?= e(billas_date($edit['service_guaranteed_until'])) ?></dd></div>
                <div><dt>Router</dt><dd><?= e($edit['router_name'] ?? '—') ?></dd></div>
            </dl>
            <div class="divider"></div>
            <h3>Ubah status cepat</h3>
            <div class="pill-row">
                <?php foreach (['active', 'isolir', 'inactive'] as $value): ?>
                    <form method="post" action="<?= e(billas_url('/admin/customers/' . (int) $edit['id'] . '/status')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="<?= e($value) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" <?= $edit['status'] === $value ? 'disabled' : '' ?>><?= e(ucfirst($value)) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <div class="notice notice-info small">
                Mengaktifkan pelanggan akan mengirim perintah enable PPPoE dan kick session ke router melalui retry queue.
            </div>
        </aside>
    </div>

    <section class="card">
        <?php
        $customer = $edit;
        $account = $activeQris;
        $create_action = '/admin/customers/' . (int) $edit['id'] . '/qris-unique';
        $compact = false;
        require __DIR__ . '/../partials/qris-unique.php';
        ?>
    </section>
<?php endif; ?>
