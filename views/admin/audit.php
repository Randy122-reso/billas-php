<?php
/** @var array<int,array<string,mixed>> $entries */
$actionTone = static function (string $action): string {
    if (str_contains($action, 'failed') || str_contains($action, 'revoke') || str_contains($action, 'error')) {
        return 'warning';
    }
    if (str_contains($action, 'paid') || str_contains($action, 'success') || str_contains($action, 'voucher_issued')) {
        return 'success';
    }
    if (str_contains($action, 'login') || str_contains($action, 'created') || str_contains($action, 'updated')) {
        return 'info';
    }

    return 'soft';
};
?>
<div class="page-head">
    <div>
        <span class="eyebrow">Jejak aktivitas</span>
        <h1>Audit Trail</h1>
        <p class="muted">300 entri terbaru dari admin, webhook, cron, dan worker.</p>
    </div>
    <span class="badge badge-soft"><?= count($entries) ?> entri</span>
</div>

<section class="card">
    <div class="card-head">
        <h2>Aktivitas terbaru</h2>
        <span class="muted small">Meta dan IP tersimpan untuk investigasi</span>
    </div>
    <?php if ($entries === []): ?>
        <div class="empty-state"><h3>Belum ada aktivitas</h3><p>Audit akan terisi saat admin login atau sistem memproses pembayaran.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Waktu</th><th>Aktor</th><th>Aksi</th><th>Entitas</th><th>Meta</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $meta = [];
                    if (!empty($entry['meta'])) {
                        $decoded = json_decode((string) $entry['meta'], true);
                        $meta = is_array($decoded) ? $decoded : ['raw' => $entry['meta']];
                    }
                    ?>
                    <tr>
                        <td class="small"><?= e(billas_date((int) $entry['created_at'], true)) ?></td>
                        <td>
                            <span class="badge badge-soft"><?= e($entry['actor_type']) ?></span>
                            <br><span class="mono small"><?= e($entry['actor_id']) ?></span>
                        </td>
                        <td><span class="badge badge-<?= e($actionTone((string) $entry['action'])) ?>"><?= e($entry['action']) ?></span></td>
                        <td class="small">
                            <?= e($entry['entity_type'] ?: '—') ?>
                            <?php if (!empty($entry['entity_id'])): ?><br><span class="mono small"><?= e($entry['entity_id']) ?></span><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($meta === []): ?>
                                <span class="muted">—</span>
                            <?php else: ?>
                                <code class="code-inline"><?= e(json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></code>
                            <?php endif; ?>
                        </td>
                        <td class="mono small"><?= e($entry['ip'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
