<?php
/** @var array<string,mixed> $customer */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<string,mixed>|null $qris_account */
/** @var array{until:?string,active:bool,days_left:int,label:string} $guarantee */
/** @var bool $persistent_enabled */
$vaAccounts = array_values(array_filter($accounts, static fn (array $a): bool => $a['account_type'] === 'va'));
$qrisAccounts = array_values(array_filter($accounts, static fn (array $a): bool => $a['account_type'] === 'qris'));
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(billas_url('/portal/check')) ?>">Cek tagihan</a>
    <span>/</span>
    <span>Akun</span>
</nav>

<section class="page-head">
    <div>
        <span class="eyebrow">Akun pelanggan</span>
        <h1><?= e($customer['name']) ?></h1>
        <p class="muted">Kode <span class="mono"><?= e($customer['code']) ?></span> · <?= e($customer['package_name'] ?? 'Tanpa paket') ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e(billas_url('/portal/check')) ?>">Kembali</a>
</section>

<div class="content-grid">
    <section class="card">
        <div class="card-head"><h2>Data layanan</h2></div>
        <dl class="summary-list">
            <div><dt>Nomor telepon</dt><dd><?= e($customer['phone'] ?: '—') ?></dd></div>
            <div><dt>Email</dt><dd><?= e($customer['email'] ?: '—') ?></dd></div>
            <div><dt>Username PPPoE</dt><dd class="mono"><?= e($customer['pppoe_username'] ?: '—') ?></dd></div>
            <div><dt>Paket</dt><dd><?= e($customer['package_name'] ?? '—') ?></dd></div>
            <div><dt>Masa aktif</dt><dd><?= e(billas_date($customer['paid_until'])) ?></dd></div>
            <div><dt>Layanan dijamin s/d</dt><dd><strong><?= e($guarantee['until'] ? billas_date($guarantee['until']) : '—') ?></strong><br><span class="muted small"><?= e($guarantee['label']) ?></span></dd></div>
        </dl>
        <div class="notice notice-info small">
            Perubahan data layanan dilakukan oleh operator. Hubungi admin jika ada data yang perlu diperbarui.
        </div>
    </section>

    <aside class="card">
        <div class="card-head"><h2>QRIS unik</h2></div>
        <?php if ($qris_account): ?>
            <p class="muted">QRIS unik Anda aktif. Gunakan untuk membayar tagihan kapan saja.</p>
            <a class="btn btn-primary btn-block" href="<?= e(billas_url('/portal/qris-unique')) ?>">Lihat QRIS unik</a>
        <?php else: ?>
            <p class="muted">Aktifkan QRIS unik agar pembayaran tagihan berulang lebih mudah.</p>
            <form method="post" action="<?= e(billas_url('/portal/qris-unique/create')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-block">Aktifkan sekarang</button>
            </form>
        <?php endif; ?>
        <div class="divider"></div>
        <h3>Garansi layanan</h3>
        <p class="muted small">Layanan tidak akan diisolir sebelum tanggal garansi berakhir, meskipun ada tagihan tertunda.</p>
        <strong><?= e($guarantee['until'] ? billas_date($guarantee['until']) : '—') ?></strong>
    </aside>
</div>

<section class="section">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Pembayaran berulang</span>
            <h2>Akun pembayaran persisten</h2>
        </div>
        <p class="muted"><?= $persistent_enabled ? 'Fitur akun persisten aktif untuk akun Anda.' : 'Fitur akun persisten sedang dinonaktifkan operator.' ?></p>
    </div>

    <?php if ($vaAccounts === [] && $qrisAccounts === []): ?>
        <div class="empty-state">
            <h3>Belum ada akun pembayaran persisten</h3>
            <p>Hubungi admin untuk membuat Virtual Account tetap atau gunakan QRIS unik di atas.</p>
        </div>
    <?php else: ?>
        <div class="account-grid">
            <?php foreach ($accounts as $account): ?>
                <?php require __DIR__ . '/../partials/persistent-qr.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
