<?php
/** @var array<string,mixed> $account */
$payload = billas_order_payload($account['payload'] ?? null);
$isStatic = ($payload['mode'] ?? '') === 'static_fallback';
?>
<div class="payment-account">
    <div class="card-head">
        <div>
            <span class="eyebrow"><?= e(strtoupper((string) $account['account_type'])) ?> · <?= e(strtoupper((string) $account['gateway'])) ?></span>
            <h3><?= e($account['account_type'] === 'va' ? 'Virtual Account tetap' : 'QRIS tetap') ?></h3>
        </div>
        <span class="badge badge-<?= e((int) $account['active'] === 1 ? 'success' : 'muted') ?>"><?= e((int) $account['active'] === 1 ? 'Aktif' : 'Nonaktif') ?></span>
    </div>
    <?php if (!empty($account['qr_string'])): ?>
        <div class="qr-box small">
            <img src="<?= e(billas_qr_data_uri((string) $account['qr_string'], 220)) ?>" alt="QR <?= e($account['external_ref']) ?>" width="200" height="200">
        </div>
    <?php endif; ?>
    <dl class="summary-list compact">
        <div><dt>Referensi</dt><dd class="mono"><?= e($account['external_ref']) ?></dd></div>
        <div><dt>Dibuat</dt><dd><?= e(billas_date((int) $account['created_at'], true)) ?></dd></div>
    </dl>
    <?php if ($isStatic): ?>
        <p class="muted small">Akun ini memakai QRIS statis. Pembayaran dicocokkan manual oleh admin.</p>
    <?php endif; ?>
</div>
