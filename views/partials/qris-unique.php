<?php
/**
 * @var array<string,mixed>|null $account
 * @var array<string,mixed> $customer
 * @var array{until:?string,active:bool,days_left:int,label:string} $guarantee
 * @var string|null $create_action
 * @var bool $compact
 */
$payload = billas_order_payload($account['payload'] ?? null);
$isStatic = ($payload['mode'] ?? '') === 'static_fallback';
$compact = $compact ?? false;
?>
<div class="qris-unique <?= $compact ? 'compact' : '' ?>">
    <?php if ($account): ?>
        <div class="qris-unique-head">
            <div>
                <span class="eyebrow">QRIS unik</span>
                <h2><?= e($customer['name']) ?></h2>
                <p class="muted small">Kode pelanggan <span class="mono"><?= e($customer['code']) ?></span> · <?= e(strtoupper((string) $account['gateway'])) ?></p>
            </div>
            <span class="badge badge-success">Aktif</span>
        </div>
        <?php if (!empty($account['qr_string'])): ?>
            <div class="qr-box qris-unique-box">
                <img src="<?= e(billas_qr_data_uri((string) $account['qr_string'], 360)) ?>" alt="QRIS unik <?= e($customer['code']) ?>" width="320" height="320">
                <span class="qr-scan">Scan untuk membayar tagihan</span>
            </div>
        <?php else: ?>
            <div class="empty-state compact"><p>QR string belum tersedia. Hubungi admin untuk regenerasi QRIS unik.</p></div>
        <?php endif; ?>
        <dl class="summary-list">
            <div><dt>Referensi</dt><dd class="mono"><?= e($account['external_ref']) ?></dd></div>
            <div><dt>Tipe</dt><dd><?= e($isStatic ? 'QRIS statis (rekonsiliasi manual)' : 'QRIS dinamis gateway') ?></dd></div>
            <div><dt>Dibuat</dt><dd><?= e(billas_date((int) $account['created_at'], true)) ?></dd></div>
            <div><dt>Garansi layanan</dt><dd><?= e($guarantee['until'] ? billas_date($guarantee['until']) : '—') ?></dd></div>
        </dl>
        <?php if ($isStatic): ?>
            <div class="notice notice-warning small">
                QRIS ini bersifat statis. Setelah membayar, unggah bukti atau hubungi operator agar pembayaran dikonfirmasi manual melalui menu rekonsiliasi.
            </div>
        <?php else: ?>
            <div class="notice notice-info small">
                Pembayaran dengan QRIS ini otomatis dialokasikan ke tagihan tertua. Nominal bebas sesuai jumlah tagihan.
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state">
            <h3>QRIS unik belum aktif</h3>
            <p>QRIS unik memungkinkan Anda membayar tagihan berulang dengan satu kode QR. Aktifkan sekarang, atau minta admin membuatkannya.</p>
            <?php if (!empty($create_action)): ?>
                <form method="post" action="<?= e($create_action) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary">Aktifkan QRIS unik</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
