<?php
/** @var array<string,mixed> $customer */
/** @var array<string,mixed>|null $account */
/** @var array{until:?string,active:bool,days_left:int,label:string} $guarantee */
/** @var array<string,mixed>|null $open_invoice */
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(billas_url('/portal/check')) ?>">Cek tagihan</a>
    <span>/</span>
    <span>QRIS unik</span>
</nav>

<section class="page-head">
    <div>
        <span class="eyebrow">Fitur QRIS unik</span>
        <h1>Bayar dengan satu QRIS</h1>
        <p class="muted">Scan QRIS unik Anda dari m-banking atau e-wallet. Pembayaran otomatis memperpanjang masa aktif dan garansi layanan.</p>
    </div>
    <span class="badge badge-<?= e($guarantee['active'] ? 'success' : 'muted') ?> badge-lg"><?= e($guarantee['active'] ? 'Layanan dijamin' : 'Garansi tidak aktif') ?></span>
</section>

<div class="content-grid qris-page-grid">
    <section class="card">
        <?php
        $create_action = '/portal/qris-unique/create';
        require __DIR__ . '/../partials/qris-unique.php';
        ?>
        <?php if ($account): ?>
            <div class="divider"></div>
            <div class="inline-actions">
                <a class="btn btn-ghost" href="<?= e(billas_url('/portal/account')) ?>">Kelola akun</a>
                <?php if ($open_invoice): ?>
                    <a class="btn btn-primary" href="<?= e(billas_url('/portal/invoice/' . (int) $open_invoice['id'])) ?>">Bayar invoice <?= e(billas_money((int) $open_invoice['amount'])) ?></a>
                <?php else: ?>
                    <a class="btn btn-primary" href="<?= e(billas_url('/portal/check')) ?>">Lihat ringkasan</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <aside class="card">
        <h2>Cara membayar</h2>
        <ol class="ordered-steps">
            <li>Buka aplikasi m-banking atau e-wallet.</li>
            <li>Pilih QRIS, lalu scan kode di samping.</li>
            <li>Masukkan nominal sesuai tagihan Anda.</li>
            <li>Konfirmasi dan simpan bukti pembayaran.</li>
            <li>Tagihan dan garansi diperbarui otomatis setelah webhook diterima.</li>
        </ol>
        <div class="notice notice-info">
            <strong>Garansi layanan:</strong> <?= e($guarantee['label']) ?>.
        </div>
        <?php if (!$account): ?>
            <div class="notice notice-warning">
                QRIS unik belum aktif. Klik tombol aktivasi di halaman ini. Jika gagal, minta admin membuatkannya dari panel pelanggan.
            </div>
        <?php endif; ?>
        <div class="muted small">Masalah pembayaran? Hubungi operator dengan menyertakan kode pelanggan <span class="mono"><?= e($customer['code']) ?></span>.</div>
    </aside>
</div>
