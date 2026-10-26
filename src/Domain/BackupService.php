<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Env;
use RuntimeException;

final class BackupService
{
    public function __construct(
        private readonly \PDO $db,
        private readonly Env $env,
    ) {
    }

    /** @return array{file:string,size:int} */
    public function run(): array
    {
        $dbPath = $this->env->string('DB_PATH');
        if ($dbPath === '' || $dbPath === ':memory:') {
            throw new RuntimeException('Backup tidak tersedia untuk database in-memory.');
        }
        if (!is_file($dbPath)) {
            throw new RuntimeException('File database tidak ditemukan: ' . $dbPath);
        }
        $backupDir = dirname($dbPath) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Tidak dapat membuat direktori backup.');
        }

        try {
            $this->db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
        } catch (\Throwable) {
        }

        $target = $backupDir . DIRECTORY_SEPARATOR . 'billas-' . date('Ymd-His') . '.db';
        if (!copy($dbPath, $target)) {
            throw new RuntimeException('Copy database gagal.');
        }
        $this->prune($backupDir, 14);

        return ['file' => $target, 'size' => (int) filesize($target)];
    }

    private function prune(string $dir, int $keepDays): void
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . 'billas-*.db') ?: [];
        $cutoff = time() - ($keepDays * 86400);
        foreach ($files as $file) {
            if (filemtime($file) !== false && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
