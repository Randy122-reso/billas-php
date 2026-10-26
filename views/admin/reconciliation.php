<?php
/** @var int $days */
/** @var array<int,array<string,mixed>> $pending */
/** @var array<int,array<string,mixed>> $unmatched */
/** @var array<int,array<string,mixed>> $qris_accounts */
/** @var array<string,mixed> $stats */
/** @var array<int,array<string,mixed>>|null $preview */
/** @var array<int,string>|null $preview_refs */
$preview = $preview ?? null;
$previewRefs = $preview_refs ?? [];
?>
<div class="page-head">
    <div>
        <span class="eyebrow">QRIS statis</span>
        <h1>Rekonsiliasi</h1>
        <p class="muted">Cocokkan mutasi bank dengan nominal unik, lalu konfirmasi pembayaran secara manual.</p>
    </div>
    <form method="get" action="<?= e(billas_url('/admin/reconciliation')) ?>" class="inline-form">
        <label class="field field-inline">
            <span>Rentang</span>
            <select name="days" onchange="this.form.submit()">
                <?php foreach ([7, 14, 30, 60, 90] as $option): ?>
                    <option value="<?= $option ?>" <?= $days === $option ? 'selected' : '' ?>><?= $option ?> hari</option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button class="btn btn-ghost btn-sm" type="submit">Terapkan</button></noscript>
    </form>
</div>

<div class="stat-grid four">
    <div class="stat-card"><span class="stat-label">Pending QRIS statis</span><strong class="stat-value"><?= (int) $stats['pending'] ?></strong><span class="stat-note"><?= $days ?> hari terakhir</span></div>
    <div class="stat-card"><span class="stat-label">Webhook tidak cocok</span><strong class="stat-value"><?= (int) $stats['unmatched'] ?></strong><span class="stat-note">Perlu investigasi</span></div>
    <div class="stat-card"><span class="stat-label">Akun QRIS unik</span><strong class="stat-value"><?= (int) $stats['qris_accounts'] ?></strong><span class="stat-note">Aktif &amp; dapat dipakai ulang</span></div>
    <div class="stat-card stat-highlight"><span class="stat-label">Target terdaftar</span><strong class="stat-value"><?= count($pending) ?></strong><span class="stat-note">Order &amp; transaksi</span></div>
</div>

<div class="notice notice-info">
    Konfirmasi manual memakai pipeline webhook yang sama: invoice/voucher dibuat, audit dicatat, dan push MikroTik masuk retry queue. Pastikan nominal dan referensi cocok sebelum menekan konfirmasi.
</div>

<section class="card">
    <div class="card-head">
        <h2>Akun QRIS unik aktif</h2>
        <span class="muted small"><?= count($qris_accounts) ?> akun</span>
    </div>
    <p class="muted small">QRIS unik dipakai berulang, sehingga nominal tidak melekat pada akun. Masukkan nominal sesuai mutasi bank. Referensi mutasi bersifat opsional, tetapi sangat disarankan agar konfirmasi bulan berikutnya tidak dianggap duplikat.</p>
    <?php if ($qris_accounts === []): ?>
        <div class="empty-state compact"><p>Belum ada akun QRIS unik aktif. Buat dari halaman detail pelanggan.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th>Pelanggan</th><th>Gateway</th><th>Referensi</th><th>Masa bayar</th><th>Nominal (Rp)</th><th>Ref. mutasi</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($qris_accounts as $account): ?>
                    <?php
                    $accountId = (int) $account['id'];
                    $formId = 'qris-account-' . $accountId;
                    $ref = (string) $account['external_ref'];
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($account['customer_name']) ?></strong>
                            <div class="muted small mono"><?= e($account['customer_code']) ?></div>
                        </td>
                        <td><span class="badge badge-soft"><?= e($account['gateway']) ?></span></td>
                        <td class="mono small"><?= e($ref) ?></td>
                        <td class="small">
                            <div>Bayar s.d. <?= e($account['paid_until'] ?: '—') ?></div>
                            <div class="muted">Garansi s.d. <?= e($account['service_guaranteed_until'] ?: '—') ?></div>
                        </td>
                        <td>
                            <input form="<?= e($formId) ?>" type="number" name="amount" min="1000" step="1" required
                                   inputmode="numeric" placeholder="150000" aria-label="Nominal pembayaran <?= e($account['customer_name']) ?>">
                        </td>
                        <td>
                            <input form="<?= e($formId) ?>" type="text" name="mutation_ref" maxlength="120"
                                   placeholder="Opsional" aria-label="Referensi mutasi bank">
                        </td>
                        <td>
                            <form id="<?= e($formId) ?>" method="post" action="<?= e(billas_url('/admin/reconciliation/account-confirm')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="external_ref" value="<?= e($ref) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Konfirmasi pembayaran QRIS unik ini?')">Konfirmasi</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($preview !== null): ?>
    <section class="card">
        <div class="card-head">
            <h2>Hasil pratinjau</h2>
            <span class="badge badge-soft"><?= count($preview) ?> referensi</span>
        </div>
        <?php if ($preview === []): ?>
            <div class="empty-state compact"><p>Tidak ada referensi yang diperiksa.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Referensi</th><th>Ditemukan</th><th>Jenis</th><th>Status</th><th>Gateway</th><th>Nominal</th></tr></thead>
                    <tbody>
                    <?php foreach ($preview as $row): ?>
                        <tr>
                            <td class="mono small"><?= e($row['merchant_ref']) ?></td>
                            <td><span class="badge badge-<?= e($row['found'] ? 'success' : 'danger') ?>"><?= $row['found'] ? 'ya' : 'tidak' ?></span></td>
                            <td><?= e($row['kind'] ?? '—') ?></td>
                            <td><?= e($row['status'] ?? '—') ?></td>
                            <td><?= e($row['gateway'] ?? '—') ?></td>
                            <td><?= $row['amount'] !== null ? e(billas_money((int) $row['amount'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="divider"></div>
            <div class="inline-actions">
                <form method="post" action="<?= e(billas_url('/admin/reconciliation/batch')) ?>">
                    <?= csrf_field() ?>
                    <?php foreach ($previewRefs as $ref): ?>
                        <input type="hidden" name="refs[]" value="<?= e($ref) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="mode" value="apply">
                    <button class="btn btn-primary" type="submit" onclick="return confirm('Terapkan konfirmasi untuk <?= count($previewRefs) ?> referensi?')">Terapkan batch</button>
                </form>
                <a class="btn btn-ghost" href="<?= e(billas_url('/admin/reconciliation')) ?>">Tutup pratinjau</a>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2>Target pembayaran pending</h2>
        <span class="muted small"><?= count($pending) ?> baris</span>
    </div>
    <?php if ($pending === []): ?>
        <div class="empty-state"><h3>Tidak ada pembayaran pending</h3><p>Semua QRIS statis pada rentang ini sudah direkonsiliasi.</p></div>
    <?php else: ?>
        <form id="reconcile-batch" method="post" action="<?= e(billas_url('/admin/reconciliation/batch')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="apply">
        </form>
        <form id="reconcile-preview" method="post" action="<?= e(billas_url('/admin/reconciliation/batch')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="preview">
        </form>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th aria-label="Pilih"></th><th>Referensi</th><th>Jenis</th><th>Nominal</th><th>Dibuat</th><th>Kedaluwarsa</th><th>Invoice</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($pending as $row): ?>
                    <?php
                    $ref = (string) $row['merchant_ref'];
                    $kind = (string) $row['kind'];
                    ?>
                    <tr>
                        <td><input type="checkbox" name="refs[]" value="<?= e($ref) ?>" form="reconcile-batch" aria-label="Pilih <?= e($ref) ?>"></td>
                        <td class="mono small"><?= e($ref) ?></td>
                        <td><span class="badge badge-soft"><?= e($kind) ?></span></td>
                        <td><?= e(billas_money((int) $row['amount'])) ?></td>
                        <td class="small"><?= e(billas_date((int) $row['created_at'], true)) ?></td>
                        <td class="small"><?= e(billas_remaining((int) $row['expires_at'])) ?></td>
                        <td class="mono small"><?= e($row['invoice_no'] ?? '—') ?></td>
                        <td>
                            <form method="post" action="<?= e(billas_url('/admin/reconciliation/confirm')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="merchant_ref" value="<?= e($ref) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Konfirmasi pembayaran <?= e($ref) ?>?')">Konfirmasi</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="inline-actions batch-actions">
            <button class="btn btn-primary" type="submit" form="reconcile-batch" onclick="return confirm('Terapkan semua referensi terpilih?')">Terapkan terpilih</button>
            <button class="btn btn-ghost" type="submit" form="reconcile-preview">Pratinjau terpilih</button>
            <span class="muted small">Centang baris untuk batch, atau konfirmasi satu per satu.</span>
        </div>
    <?php endif; ?>
</section>

<div class="content-grid">
    <section class="card">
        <div class="card-head"><h2>Batch manual</h2><span class="muted small">Satu referensi per baris</span></div>
        <form method="post" action="<?= e(billas_url('/admin/reconciliation/batch')) ?>" class="stack-form">
            <?= csrf_field() ?>
            <label class="field">
                <span>Referensi merchant</span>
                <textarea name="refs_text" rows="5" placeholder="TRX-20260101-ABC123&#10;INV-QRIS-0001"></textarea>
            </label>
            <div class="inline-actions">
                <button class="btn btn-primary" type="submit" name="mode" value="apply" onclick="return confirm('Terapkan batch ini?')">Terapkan</button>
                <button class="btn btn-ghost" type="submit" name="mode" value="preview">Pratinjau</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head"><h2>Webhook tidak cocok</h2><span class="muted small">Terbaru</span></div>
        <?php if ($unmatched === []): ?>
            <div class="empty-state compact"><p>Tidak ada webhook unmatched.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead><tr><th>Provider</th><th>Referensi</th><th>Diterima</th><th>Catatan</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($unmatched, 0, 10) as $event): ?>
                        <tr>
                            <td><span class="badge badge-soft"><?= e($event['provider']) ?></span></td>
                            <td class="mono small"><?= e($event['merchant_ref'] ?: '—') ?></td>
                            <td class="small"><?= e(billas_date((int) $event['received_at'], true)) ?></td>
                            <td class="small"><?= e($event['note'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
