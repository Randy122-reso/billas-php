<?php
/** @var array<int,array<string,mixed>> $flashes */
foreach (($flashes ?? []) as $flash):
    $type = in_array(($flash['type'] ?? 'info'), ['success', 'error', 'warning', 'info'], true) ? $flash['type'] : 'info';
?>
    <div class="alert alert-<?= e($type) ?>" role="alert">
        <span><?= e($flash['message'] ?? '') ?></span>
        <button type="button" class="alert-close" data-dismiss="alert" aria-label="Tutup">×</button>
    </div>
<?php endforeach; ?>
