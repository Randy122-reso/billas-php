<?php

declare(strict_types=1);

namespace Billas\Database;

use PDO;
use RuntimeException;

/**
 * Idempotent schema/migration runner. Each migration file returns a callable:
 *   return static function (PDO $db, Migrator $migrator): void { ... };
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $schemaFile,
        private readonly string $migrationsDir,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->applySchema();
        $applied = [];
        $files = glob($this->migrationsDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if ($this->isApplied($name)) {
                continue;
            }

            $callback = require $file;
            if (!is_callable($callback)) {
                throw new RuntimeException('Migrasi tidak mengembalikan callable: ' . $name);
            }

            $this->db->beginTransaction();
            try {
                $callback($this->db, $this);
                $this->db->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, ?)')
                    ->execute([$name, time()]);
                $this->db->commit();
                $applied[] = $name;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new RuntimeException('Migrasi gagal (' . $name . '): ' . $e->getMessage(), 0, $e);
            }
        }

        return $applied;
    }

    public function applySchema(): void
    {
        $sql = file_get_contents($this->schemaFile);
        if ($sql === false) {
            throw new RuntimeException('schema.sql tidak ditemukan: ' . $this->schemaFile);
        }
        $this->db->exec($sql);
    }

    public function isApplied(string $migration): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM schema_migrations WHERE migration = ?');
            $stmt->execute([$migration]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string) $row['name'], $column) === 0) {
                return true;
            }
        }

        return false;
    }

    public function addColumnIfMissing(string $table, string $column, string $definition): bool
    {
        if ($this->columnExists($table, $column)) {
            return false;
        }
        $this->db->exec(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $definition
        ));

        return true;
    }

    public function indexExists(string $index): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?");
        $stmt->execute([$index]);

        return (bool) $stmt->fetchColumn();
    }

    public function exec(string $sql): void
    {
        $this->db->exec($sql);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException('Nama identifier tidak valid: ' . $identifier);
        }

        return '"' . $identifier . '"';
    }
}
