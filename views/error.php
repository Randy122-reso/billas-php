<?php
/** @var int $status */
/** @var string $message */
$status = (int) ($status ?? 500);
$title = match ($status) {
    403 => 'Akses ditolak',
    404 => 'Halaman tidak ditemukan',
    410 => 'Sudah tidak berlaku',
    419 => 'Sesi kedaluwarsa',
    429 => 'Terlalu banyak permintaan',
    default => $status >= 500 ? 'Terjadi kesalahan' : 'Permintaan tidak dapat diproses',
};
?>
<section class="error-page">
    <span class="error-code"><?= e($status) ?></span>
    <h1><?= e($title) ?></h1>
    <p><?= e($message ?? 'Silakan coba lagi.') ?></p>
    <?php if (!empty($exception)): ?>
        <details class="error-debug">
            <summary>Detail developer</summary>
            <pre><?= e($exception) ?><?= !empty($trace) ? "\n\n" . e($trace) : '' ?></pre>
        </details>
    <?php endif; ?>
    <div class="inline-actions center">
        <a class="btn btn-primary" href="<?= e(billas_url('/')) ?>">Kembali ke beranda</a>
        <a class="btn btn-ghost" href="javascript:history.back()">Halaman sebelumnya</a>
    </div>
</section>
