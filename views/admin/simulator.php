<?php
/**
 * Fitur F (v4.2): Webhook Simulator.
 *
 * @var array<string,mixed> $options
 * @var list<array<string,mixed>> $runs
 * @var array<string,mixed>|null $last_run
 * @var array<string,string> $prefill
 * @var \Billas\Support\Env $env
 */
$status = $options['status'];
$providers = $options['providers'];
$events = $options['events'];
$modes = $options['modes'];
$targetLabels = [
    'order' => 'Order hotspot (voucher)',
    'invoice' => 'Invoice PPPoE',
    'persistent_account' => 'Akun pembayaran persisten / QRIS unik',
];
$contextLinks = [];
if (is_array($last_run)) {
    $targetType = (string) ($last_run['target_type'] ?? '');
    $targetId = (string) ($last_run['target_id'] ?? '');
    if ($targetType === 'order' && $targetId !== '') {
        $contextLinks[] = ['/voucher/' . rawurlencode($targetId), 'Halaman voucher'];
        $contextLinks[] = ['/wifi/voucher/' . rawurlencode($targetId), 'Voucher captive'];
        $contextLinks[] = ['/portal/check', 'Portal pelanggan'];
    } elseif ($targetType === 'invoice') {
        $contextLinks[] = ['/admin/invoices', 'Daftar invoice'];
        $contextLinks[] = ['/portal/check', 'Portal pelanggan'];
    } elseif ($targetType === 'persistent_account') {
        $contextLinks[] = ['/admin/reconciliation', 'Rekonsiliasi QRIS'];
        $contextLinks[] = ['/admin/customers', 'Data pelanggan'];
    }
}
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Alat operasional · admin-only</span>
        <h1>Simulator Webhook</h1>
        <p class="muted">Kirim webhook sintetis yang menempuh pipeline pembayaran asli: order → voucher, invoice → garansi, atau akun persisten → pelunasan.</p>
    </div>
    <div class="inline-actions">
        <span class="badge badge-<?= e($status['enabled'] ? 'success' : 'muted') ?>"><?= $status['enabled'] ? 'flag ON' : 'flag OFF' ?></span>
        <span class="badge badge-soft">mode default: <?= e($status['mode']) ?></span>
    </div>
</div>

<div class="notice notice-warning">
    <strong>Hanya untuk pengujian.</strong>
    Mode <span class="mono">sign</span> memakai signature asli gateway. Mode <span class="mono">bypass</span> melewati verifikasi signature dan hanya boleh dipakai di lingkungan lokal/CI. Semua dispatch dicatat ke <span class="mono">simulator_runs</span> dan <span class="mono">audit_trail</span> dengan signature di-mask.
</div>

<?php if (!empty($last_run)): ?>
    <section class="card">
        <div class="card-head">
            <h2>Hasil run #<?= (int) $last_run['id'] ?></h2>
            <span class="badge badge-<?= e(((int) ($last_run['response_status'] ?? 0)) === 200 ? 'success' : 'warning') ?>">HTTP <?= (int) ($last_run['response_status'] ?? 0) ?></span>
        </div>
        <div class="content-grid">
            <div>
                <h3>Respons</h3>
                <pre class="code-block"><?= e(json_encode($last_run['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
            <div>
                <h3>Request (signature di-mask)</h3>
                <pre class="code-block"><?= e(json_encode($last_run['request'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        </div>
        <?php if ($contextLinks !== []): ?>
            <div class="inline-actions">
                <?php foreach ($contextLinks as [$href, $label]): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(billas_url($href)) ?>"><?= e($label) ?> →</a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="content-grid">
    <section class="card">
        <div class="card-head"><h2>Kirim webhook sintetis</h2><span class="badge badge-soft">CSRF + rate limit 20/menit</span></div>
        <form method="post" action="<?= e(billas_url('/admin/simulator/webhook')) ?>" class="form-grid two">
            <?= csrf_field() ?>
            <label class="field">
                <span>Tipe target</span>
                <select name="target_type" required>
                    <?php foreach ($targetLabels as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $prefill['target_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Target ID</span>
                <input type="text" name="target_id" value="<?= e($prefill['target_id']) ?>" list="simulator-targets" placeholder="UUID order / ID invoice / ID akun" required>
                <small class="hint">Pilih dari daftar 10 terakhir di bawah atau tempel ID.</small>
            </label>
            <datalist id="simulator-targets">
                <?php foreach ($options['orders'] as $order): ?>
                    <option value="<?= e($order['id']) ?>">Order · <?= e($order['merchant_ref']) ?> · <?= e($order['package_name'] ?? '') ?> · <?= e(billas_money((int) $order['amount'])) ?></option>
                <?php endforeach; ?>
                <?php foreach ($options['invoices'] as $invoice): ?>
                    <option value="<?= (int) $invoice['id'] ?>">Invoice · <?= e($invoice['invoice_no']) ?> · <?= e($invoice['customer_name']) ?> · <?= e(billas_money((int) $invoice['amount'])) ?></option>
                <?php endforeach; ?>
                <?php foreach ($options['accounts'] as $account): ?>
                    <option value="<?= (int) $account['id'] ?>">Akun · <?= e($account['external_ref']) ?> · <?= e($account['customer_name'] ?? '') ?> · <?= e(strtoupper((string) $account['gateway'])) ?></option>
                <?php endforeach; ?>
            </datalist>
            <label class="field">
                <span>Provider</span>
                <select name="provider" required>
                    <?php foreach ($providers as $provider): ?>
                        <option value="<?= e($provider['name']) ?>" <?= $prefill['provider'] === $provider['name'] ? 'selected' : '' ?>>
                            <?= e(strtoupper($provider['name'])) ?><?= $provider['configured'] ? '' : ' (belum terkonfigurasi)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="hint">Mode sign membutuhkan gateway terkonfigurasi dan signature yang valid.</small>
            </label>
            <label class="field">
                <span>Event</span>
                <select name="event" required>
                    <?php foreach ($events as $event): ?>
                        <option value="<?= e($event) ?>" <?= $prefill['event'] === $event ? 'selected' : '' ?>><?= e($event) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Amount override (opsional)</span>
                <input type="number" min="1" name="amount" value="<?= e($prefill['amount']) ?>" placeholder="Kosongkan untuk nominal target">
                <small class="hint">Isi nominal berbeda untuk menguji amount mismatch.</small>
            </label>
            <label class="field">
                <span>Mode</span>
                <select name="mode" required>
                    <?php foreach ($modes as $mode): ?>
                        <option value="<?= e($mode) ?>" <?= $prefill['mode'] === $mode ? 'selected' : '' ?>>
                            <?= e($mode) ?><?= $mode === 'bypass' ? ' (lewati signature)' : ' (signature asli)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="form-actions span-2">
                <button class="btn btn-primary" type="submit">Kirim webhook simulasi</button>
                <a class="btn btn-ghost" href="<?= e(billas_url('/admin/simulator')) ?>">Reset form</a>
            </div>
        </form>
    </section>

    <aside class="card">
        <div class="card-head"><h2>Status simulator</h2></div>
        <dl class="summary-list compact">
            <div><dt>Feature flag</dt><dd><span class="badge badge-<?= e($status['enabled'] ? 'success' : 'muted') ?>"><?= $status['enabled'] ? 'aktif' : 'nonaktif' ?></span></dd></div>
            <div><dt>Mode default</dt><dd><span class="mono"><?= e($status['mode']) ?></span></dd></div>
            <div><dt>Production allowed</dt><dd><span class="badge badge-<?= e($status['allow_in_production'] ? 'warning' : 'success') ?>"><?= $status['allow_in_production'] ? 'ya' : 'tidak' ?></span></dd></div>
            <div><dt>APP_ENV</dt><dd><span class="badge badge-soft"><?= e($env->string('APP_ENV', 'production')) ?></span></dd></div>
            <div><dt>Signing provider</dt><dd class="small"><?= e($status['signing_providers'] === [] ? 'belum ada secret' : implode(', ', $status['signing_providers'])) ?></dd></div>
        </dl>
        <div class="notice notice-info small">
            Mode <span class="mono">sign</span> tetap memanggil <span class="mono">verifyWebhook()</span> provider. Mode <span class="mono">bypass</span> hanya aktif jika flag ON, header <span class="mono">X-Billas-Simulated: 1</span> dikirim, dan route dilindungi session admin.
        </div>
        <div class="divider"></div>
        <h3>Target terbaru</h3>
        <?php if ($options['orders'] === [] && $options['invoices'] === [] && $options['accounts'] === []): ?>
            <p class="muted small">Belum ada order, invoice, atau akun pembayaran.</p>
        <?php else: ?>
            <ul class="bare-list small">
                <?php foreach (array_slice($options['orders'], 0, 3) as $order): ?>
                    <li><a href="<?= e(billas_url('/admin/simulator?target_type=order&target_id=' . rawurlencode((string) $order['id']) . '&provider=' . rawurlencode((string) $order['gateway']) . '&event=PAID')) ?>">Order <?= e($order['merchant_ref']) ?></a></li>
                <?php endforeach; ?>
                <?php foreach (array_slice($options['invoices'], 0, 3) as $invoice): ?>
                    <li><a href="<?= e(billas_url('/admin/simulator?target_type=invoice&target_id=' . (int) $invoice['id'] . '&event=PAID')) ?>">Invoice <?= e($invoice['invoice_no']) ?></a></li>
                <?php endforeach; ?>
                <?php foreach (array_slice($options['accounts'], 0, 3) as $account): ?>
                    <li><a href="<?= e(billas_url('/admin/simulator?target_type=persistent_account&target_id=' . (int) $account['id'] . '&provider=' . rawurlencode((string) $account['gateway']) . '&event=PAID')) ?>">Akun <?= e($account['external_ref']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
</div>

<section class="card">
    <div class="card-head">
        <h2>Riwayat 20 run terakhir</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(billas_url('/admin/simulator/runs')) ?>">JSON</a>
    </div>
    <?php if ($runs === []): ?>
        <div class="empty-state"><p>Belum ada simulasi yang dijalankan.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead><tr><th>#</th><th>Waktu</th><th>Target</th><th>Provider</th><th>Event</th><th>Mode</th><th>Status</th><th>Respons</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($runs as $run): ?>
                    <?php
                    $ok = ((int) ($run['response_status'] ?? 0)) === 200;
                    $response = is_array($run['response'] ?? null) ? $run['response'] : [];
                    ?>
                    <tr>
                        <td class="mono small"><?= (int) $run['id'] ?></td>
                        <td class="small"><?= e(billas_date((int) $run['created_at'], true)) ?></td>
                        <td class="small"><?= e($run['target_type']) ?><br><span class="mono muted"><?= e($run['target_id']) ?></span></td>
                        <td><span class="badge badge-soft"><?= e(strtoupper((string) $run['provider'])) ?></span></td>
                        <td><?= e($run['event']) ?></td>
                        <td><span class="badge badge-<?= e($run['mode'] === 'bypass' ? 'warning' : 'soft') ?>"><?= e($run['mode']) ?></span></td>
                        <td><span class="badge badge-<?= e($ok ? 'success' : 'warning') ?>"><?= e($response['status'] ?? 'error') ?></span></td>
                        <td class="mono small"><?= (int) ($run['response_status'] ?? 0) ?></td>
                        <td><a class="link" href="<?= e(billas_url('/admin/simulator?run=' . (int) $run['id'])) ?>">Lihat</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
