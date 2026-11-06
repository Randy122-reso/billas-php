<?php
/**
 * Fitur G (v4.3): Gateway API Tester.
 *
 * @var list<string> $providers
 * @var list<string> $configuredProviders
 * @var list<string> $allowedMethods
 * @var array<string,list<string>> $providerHints
 * @var list<array<string,mixed>> $runs
 * @var \Billas\Support\Env $env
 * @var \Billas\Support\Settings $settings
 */
$apiTesterEnabled = $settings->feature('api_tester');
$apiTesterMode = $settings->get('api_tester.mode', $env->string('API_TESTER_MODE', 'sandbox'));
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Alat operasional · admin-only</span>
        <h1>Gateway API Tester</h1>
        <p class="muted">Uji langsung endpoint outbound payment gateway secara aman: auth ditangani server-side, secret di-mask otomatis, dan dilindungi SSRF-safe whitelist.</p>
    </div>
    <div class="inline-actions">
        <span class="badge badge-<?= e($apiTesterEnabled ? 'success' : 'muted') ?>"><?= $apiTesterEnabled ? 'flag ON' : 'flag OFF' ?></span>
        <span class="badge badge-soft">mode: <?= e($apiTesterMode) ?></span>
    </div>
</div>

<div class="notice notice-warning">
    <strong>Perhatian Pengujian Outbound:</strong>
    Setiap request yang dikirim akan langsung menembak API gateway resmi sesuai base URL mode (<code><?= e($apiTesterMode) ?></code>). Jangan mengirim parameter sembarangan di mode production.
</div>

<div class="content-grid two">
    <section class="card">
        <div class="card-head">
            <h2>Kirim Request Outbound</h2>
            <span class="muted small">SSRF-safe whitelist</span>
        </div>

        <form id="api-tester-form" method="post" action="<?= e(billas_url('/admin/api-tester/send')) ?>">
            <?= csrf_field() ?>

            <div class="form-grid two">
                <label class="field">
                    <span>Payment Provider</span>
                    <select id="tester-provider" name="provider" required>
                        <?php foreach ($providers as $p): ?>
                            <?php $isConfigured = in_array($p, $configuredProviders, true); ?>
                            <option value="<?= e($p) ?>">
                                <?= e(strtoupper($p)) ?><?= $isConfigured ? ' (terkonfigurasi)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>HTTP Method</span>
                    <select id="tester-method" name="method" required>
                        <?php foreach ($allowedMethods as $m): ?>
                            <option value="<?= e($m) ?>"><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label class="field mt-2">
                <span>Endpoint (Relatif, path saja)</span>
                <input type="text" id="tester-endpoint" name="endpoint" placeholder="mis. v2/balance atau qr_codes" required>
                <div id="tester-hints" class="mt-1"></div>
            </label>

            <label class="field mt-2">
                <span>Request Body (JSON opsional)</span>
                <textarea id="tester-body-input" name="body" rows="6" placeholder='{"key": "value"}'></textarea>
                <small class="hint">Wajib JSON valid jika diisi. Maksimal 64 KB.</small>
            </label>

            <div class="mt-3">
                <button type="submit" id="tester-send-btn" class="btn btn-primary">Send Request</button>
            </div>
        </form>
    </section>

    <section class="card" id="tester-result-card" style="display: none;">
        <div class="card-head">
            <h2>Response Viewer</h2>
            <div>
                <span id="tester-status-badge" class="badge"></span>
                <span class="muted small ml-2" id="tester-duration"></span>
            </div>
        </div>

        <div>
            <label class="field">
                <span>Response Headers (Masked)</span>
                <pre id="tester-headers" class="code-box small" style="max-height: 140px; overflow: auto;"></pre>
            </label>

            <label class="field mt-2">
                <span>Response Body (Secret Masked)</span>
                <pre id="tester-body" class="code-box small" style="max-height: 250px; overflow: auto;"></pre>
            </label>
        </div>
    </section>
</div>

<section class="card mt-4">
    <div class="card-head">
        <h2>Riwayat Pengujian Terakhir (20 run)</h2>
        <span class="muted small">Tersimpan di tabel api_tester_runs (masked)</span>
    </div>

    <div class="table-wrap">
        <table class="table table-compact">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Provider</th>
                    <th>Method</th>
                    <th>Endpoint</th>
                    <th>Status</th>
                    <th>Durasi</th>
                    <th>Pelaksana &amp; Waktu</th>
                </tr>
            </thead>
            <tbody id="tester-runs-body">
                <?php if ($runs === []): ?>
                    <tr><td colspan="7" class="muted">Belum ada riwayat API tester.</td></tr>
                <?php else: ?>
                    <?php foreach ($runs as $r): ?>
                        <?php
                        $st = (int) ($r['response_status'] ?? 0);
                        $badgeClass = ($st >= 200 && $st < 300) ? 'success' : ($st > 0 ? 'warning' : 'danger');
                        $stText = $st > 0 ? (string) $st : ($r['error'] ? 'ERR' : '-');
                        $dur = (int) ($r['duration_ms'] ?? 0);
                        $creator = $r['creator_username'] ?? ($r['created_by'] ? '#' . $r['created_by'] : 'system');
                        ?>
                        <tr>
                            <td class="mono small"><?= e((string) $r['id']) ?></td>
                            <td><strong><?= e($r['provider']) ?></strong></td>
                            <td><span class="badge badge-soft"><?= e($r['method']) ?></span></td>
                            <td class="mono small break"><?= e($r['endpoint']) ?></td>
                            <td><span class="badge badge-<?= e($badgeClass) ?>"><?= e($stText) ?></span></td>
                            <td class="small"><?= $dur > 0 ? e((string) $dur) . ' ms' : '-' ?></td>
                            <td class="small">
                                <?= e($creator) ?><br>
                                <span class="muted small"><?= e(billas_date((int) $r['created_at'], true)) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
    window.API_TESTER_HINTS = <?= json_encode($providerHints, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= e(billas_asset('js/api-tester.js')) ?>"></script>
