<?php
/** @var array<string,mixed> $settings */
/** @var array<string,bool> $features */
/** @var array<int,array<string,mixed>> $jobs */
/** @var array<int,string> $configured_gateways */
/** @var \Billas\Support\Env $env */
$value = static function (string $key, string $default = '') use ($settings, $env): string {
    if (array_key_exists($key, $settings)) {
        return (string) $settings[$key];
    }

    return (string) ($env->get($key, $default) ?? $default);
};
$checked = static function (string $key, bool $default = false) use ($value): bool {
    $raw = strtolower(trim($value($key, $default ? '1' : '0')));

    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
};
$cronSet = strlen($env->string('CRON_TOKEN')) >= 16;
$featureLabels = [
    'hotspot_calendar_expiry' => ['Tempo kalender hotspot', 'Voucher calendar otomatis dicabut saat expires_at lewat.'],
    'persistent_account' => ['Akun pembayaran persisten', 'VA/QRIS tetap per pelanggan PPPoE.'],
    'subscription_paid_until' => ['Validitas langganan eksplisit', 'Gunakan paid_until untuk masa aktif PPPoE.'],
    'pppoe_qris_unique' => ['QRIS unik PPPoE', 'Satu QRIS per pelanggan dengan garansi layanan.'],
    'wifi_captive_portal' => ['Captive portal WiFi', 'SSID RTRWNET → QRIS → password unik.'],
];
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Konfigurasi runtime</span>
        <h1>Pengaturan</h1>
        <p class="muted">Nilai di database menimpa default environment. Perubahan berlaku tanpa rebuild container.</p>
    </div>
    <div class="inline-actions">
        <a class="btn btn-ghost" href="<?= e(billas_url('/admin/audit')) ?>">Lihat audit</a>
        <a class="btn btn-ghost" href="<?= e(billas_url('/admin/reconciliation')) ?>">Rekonsiliasi</a>
    </div>
</div>

<form method="post" action="<?= e(billas_url('/admin/settings')) ?>" class="settings-form">
    <?= csrf_field() ?>

    <section class="card">
        <div class="card-head">
            <h2>Fitur</h2>
            <span class="muted small">Aktif/nonaktif tanpa mengubah kode</span>
        </div>
        <div class="toggle-grid">
            <?php foreach ($featureLabels as $name => [$label, $description]): ?>
                <?php $key = 'features.' . $name; ?>
                <label class="toggle-card">
                    <span>
                        <strong><?= e($label) ?></strong>
                        <small><?= e($description) ?></small>
                    </span>
                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= !empty($features[$name]) ? 'checked' : '' ?>>
                    <span class="switch" aria-hidden="true"></span>
                </label>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="content-grid">
        <section class="card">
            <div class="card-head"><h2>QRIS unik PPPoE</h2><span class="badge badge-soft">Fitur D</span></div>
            <div class="form-grid two">
                <label class="field">
                    <span>Provider yang diizinkan</span>
                    <input type="text" name="pppoe_qris.providers" value="<?= e($value('pppoe_qris.providers', 'midtrans,xendit,duitku')) ?>" placeholder="midtrans,xendit,duitku">
                    <small class="hint">Dipisah koma. Hanya provider terkonfigurasi yang dipakai.</small>
                </label>
                <label class="field">
                    <span>Hari garansi layanan</span>
                    <input type="number" min="0" max="90" name="pppoe_qris.guarantee_days" value="<?= e($value('pppoe_qris.guarantee_days', '0')) ?>">
                    <small class="hint">Tambahan hari garansi setelah pembayaran QRIS unik.</small>
                </label>
                <label class="checkbox-field span-2">
                    <input type="checkbox" name="pppoe_qris.auto_create" value="1" <?= $checked('pppoe_qris.auto_create', true) ? 'checked' : '' ?>>
                    <span>Buat QRIS unik otomatis saat pelanggan baru ditambahkan</span>
                </label>
            </div>
            <div class="notice notice-info small">
                Provider QRIS unik harus mendukung persistent QRIS/VA. QRIS statis tetap tersedia lewat rekonsiliasi manual.
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2>Pembayaran</h2><span class="muted small">Gateway &amp; TTL</span></div>
            <div class="form-grid two">
                <label class="field">
                    <span>Gateway default</span>
                    <select name="payment.default_gateway">
                        <?php foreach (['qris_static', 'tripay', 'midtrans', 'xendit', 'duitku'] as $gateway): ?>
                            <option value="<?= e($gateway) ?>" <?= $value('payment.default_gateway', 'qris_static') === $gateway ? 'selected' : '' ?>><?= e(strtoupper($gateway)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>TTL order (detik)</span>
                    <input type="number" min="60" max="86400" name="payment.order_ttl" value="<?= e($value('payment.order_ttl', '900')) ?>">
                </label>
                <label class="field span-2">
                    <span>Urutan fallback</span>
                    <input type="text" name="payment.fallback_chain" value="<?= e($value('payment.fallback_chain', 'qris_static')) ?>" placeholder="tripay,midtrans,xendit,duitku,qris_static">
                </label>
            </div>
            <div class="divider"></div>
            <h3>Gateway terkonfigurasi</h3>
            <?php if ($configured_gateways === []): ?>
                <div class="notice notice-warning small">Tidak ada gateway terkonfigurasi. Mode lokal memakai QRIS statis demo.</div>
            <?php else: ?>
                <div class="pill-row">
                    <?php foreach ($configured_gateways as $gateway): ?>
                        <span class="badge badge-success"><?= e(strtoupper($gateway)) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="inline-actions test-gateway-form" data-test-gateway="<?= e(billas_url('/admin/test-gateway')) ?>">
                <label class="field field-inline">
                    <span>Tes</span>
                    <select data-test-gateway-name>
                        <?php foreach (['tripay', 'midtrans', 'xendit', 'duitku', 'qris_static'] as $gateway): ?>
                            <option value="<?= e($gateway) ?>"><?= e(strtoupper($gateway)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-ghost btn-sm" type="button" data-test-gateway-button>Periksa konfigurasi</button>
                <span class="muted small" data-test-gateway-result></span>
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card-head"><h2>WiFi publik RTRWNET</h2><span class="badge badge-soft">Fitur E</span></div>
        <div class="form-grid three">
            <label class="field"><span>SSID utama</span><input type="text" name="wifi.ssid" value="<?= e($value('wifi.ssid', 'RTRWNET')) ?>" required></label>
            <label class="field"><span>SSID diizinkan</span><input type="text" name="wifi.allowed_ssids" value="<?= e($value('wifi.allowed_ssids', 'RTRWNET')) ?>"><small class="hint">Pisahkan dengan koma.</small></label>
            <label class="field"><span>Nama brand</span><input type="text" name="wifi.brand_name" value="<?= e($value('wifi.brand_name', 'RTRWNET')) ?>"></label>
            <label class="field"><span>URL logo</span><input type="url" name="wifi.logo_url" value="<?= e($value('wifi.logo_url')) ?>" placeholder="https://…"></label>
            <label class="field"><span>URL syarat &amp; ketentuan</span><input type="url" name="wifi.terms_url" value="<?= e($value('wifi.terms_url')) ?>" placeholder="https://…"></label>
            <label class="field"><span>TTL sesi captive (detik)</span><input type="number" min="300" max="86400" name="wifi.session_token_ttl" value="<?= e($value('wifi.session_token_ttl', '1800')) ?>"></label>
            <label class="field"><span>Panjang password unik</span><input type="number" min="6" max="32" name="wifi.password_length" value="<?= e($value('wifi.password_length', '8')) ?>"></label>
            <label class="field"><span>Hari kalender default</span><input type="number" min="1" max="90" name="wifi.calendar_days_default" value="<?= e($value('wifi.calendar_days_default', '1')) ?>"></label>
            <label class="field"><span>Maks order pending per MAC</span><input type="number" min="1" max="20" name="wifi.max_pending_orders_per_mac" value="<?= e($value('wifi.max_pending_orders_per_mac', '2')) ?>"></label>
            <label class="field"><span>Rate limit buat order / IP</span><input type="number" min="1" max="100" name="wifi.rate_limit_create_per_ip" value="<?= e($value('wifi.rate_limit_create_per_ip', '5')) ?>"></label>
            <label class="field"><span>Rate limit buat order / MAC</span><input type="number" min="1" max="100" name="wifi.rate_limit_create_per_mac" value="<?= e($value('wifi.rate_limit_create_per_mac', '3')) ?>"></label>
        </div>
    </section>

    <?php
    $simulatorEnabled = billas_feature('webhook_simulator');
    $simulatorMode = $value('webhook_simulator.mode', 'sign');
    $simulatorAllow = $checked('webhook_simulator.allow_in_production', false);
    ?>
    <section class="card">
        <div class="card-head">
            <h2>Webhook Simulator</h2>
            <span class="badge badge-<?= e($simulatorEnabled ? 'success' : 'muted') ?>"><?= $simulatorEnabled ? 'aktif' : 'nonaktif' ?></span>
        </div>
        <div class="content-grid">
            <dl class="summary-list compact">
                <div><dt>Feature flag</dt><dd class="mono">FEATURE_WEBHOOK_SIMULATOR=<?= $simulatorEnabled ? 'true' : 'false' ?></dd></div>
                <div><dt>Mode default</dt><dd><span class="mono"><?= e($simulatorMode) ?></span></dd></div>
                <div><dt>Production allowed</dt><dd><span class="badge badge-<?= e($simulatorAllow ? 'warning' : 'success') ?>"><?= $simulatorAllow ? 'ya' : 'tidak' ?></span></dd></div>
            </dl>
            <div>
                <div class="notice notice-warning small">
                    Simulator adalah alat admin-only dan default OFF. Di production, flag ini wajib disertai <span class="mono">WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION=true</span>; tanpa itu aplikasi menolak boot. Ubah nilai melalui <span class="mono">.env</span>.
                </div>
                <?php if ($simulatorEnabled): ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(billas_url('/admin/simulator')) ?>">Buka simulator →</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="content-grid">
        <section class="card">
            <div class="card-head"><h2>Cron &amp; isolir</h2><span class="badge badge-<?= e($cronSet ? 'success' : 'warning') ?>"><?= $cronSet ? 'CRON_TOKEN terisi' : 'CRON_TOKEN lemah' ?></span></div>
            <div class="form-grid two">
                <label class="field">
                    <span>Grace isolir (hari)</span>
                    <input type="number" min="0" max="30" name="cron.grace_isolir_days" value="<?= e($value('cron.grace_isolir_days', '3')) ?>">
                </label>
                <div class="field">
                    <span>Endpoint cron</span>
                    <input type="text" value="<?= e(billas_url('/internal/cron/run')) ?>" readonly>
                    <small class="hint">Kirim header <span class="mono">X-Cron-Token</span> dari RouterOS Scheduler.</small>
                </div>
            </div>
            <div class="divider"></div>
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead><tr><th>Task</th><th>Terakhir</th><th>Hasil</th></tr></thead>
                    <tbody>
                    <?php if ($jobs === []): ?>
                        <tr><td colspan="3" class="muted">Belum ada job_state.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($jobs as $job): ?>
                        <?php
                        $result = json_decode((string) ($job['last_result'] ?? ''), true);
                        $ok = is_array($result) && (($result['status'] ?? '') === 'ok');
                        $lastRun = (int) $job['last_run_at'];
                        ?>
                        <tr>
                            <td class="mono small"><?= e($job['task']) ?></td>
                            <td class="small"><?= $lastRun > 0 ? e(billas_date($lastRun, true)) : 'Belum pernah' ?></td>
                            <td><span class="badge badge-<?= e($lastRun === 0 ? 'muted' : ($ok ? 'success' : 'warning')) ?>"><?= $lastRun === 0 ? 'idle' : ($ok ? 'ok' : 'periksa') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="card">
            <div class="card-head"><h2>Keamanan konfigurasi</h2></div>
            <dl class="summary-list compact">
                <div><dt>APP_ENV</dt><dd><span class="badge badge-soft"><?= e($env->string('APP_ENV', 'production')) ?></span></dd></div>
                <div><dt>APP_URL</dt><dd class="mono small break"><?= e($env->string('APP_URL')) ?></dd></div>
                <div><dt>CRON_TOKEN</dt><dd class="mono"><?= e($cronSet ? str_repeat('•', 8) . substr($env->string('CRON_TOKEN'), -4) : 'belum diatur') ?></dd></div>
                <div><dt>MIKROTIK_FAKE</dt><dd><span class="badge badge-<?= e($env->bool('MIKROTIK_FAKE') ? 'warning' : 'success') ?>"><?= $env->bool('MIKROTIK_FAKE') ? 'ya' : 'tidak' ?></span></dd></div>
                <div><dt>Encryption key</dt><dd><span class="badge badge-<?= e(strlen($env->string('ENCRYPTION_KEY')) >= 16 ? 'success' : 'warning') ?>"><?= strlen($env->string('ENCRYPTION_KEY')) >= 16 ? 'kuat' : 'lemah' ?></span></dd></div>
            </dl>
            <?php if ($env->warnings() !== []): ?>
                <div class="notice notice-warning small">
                    <strong>Peringatan konfigurasi:</strong>
                    <ul class="bare-list">
                        <?php foreach ($env->warnings() as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <div class="notice notice-info small">
                Ubah nilai sensitif melalui <span class="mono">.env</span> lalu restart container. Nilai di halaman ini disimpan ke tabel <span class="mono">settings</span>.
            </div>
        </aside>
    </div>

    <div class="sticky-actions">
        <button type="submit" class="btn btn-primary">Simpan pengaturan</button>
        <a class="btn btn-ghost" href="<?= e(billas_url('/admin/settings')) ?>">Muat ulang</a>
    </div>
</form>
