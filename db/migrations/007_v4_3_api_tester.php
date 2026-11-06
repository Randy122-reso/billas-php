<?php

declare(strict_types=1);

use Billas\Database\Migrator;

/**
 * Fitur G (v4.3): Gateway API Tester.
 *
 * Additive-only table and settings for outbound API observability. Safe to run
 * against an existing database and safe to leave in place on rollback.
 */
return static function (PDO $db, Migrator $migrator): void {
    $migrator->exec("CREATE TABLE IF NOT EXISTS api_tester_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider TEXT NOT NULL CHECK (provider IN ('tripay','midtrans','xendit','duitku','qris_static')),
        method TEXT NOT NULL CHECK (method IN ('GET','POST','DELETE')),
        endpoint TEXT NOT NULL,
        request_body TEXT,
        response_status INTEGER,
        response_body TEXT,
        duration_ms INTEGER,
        error TEXT,
        created_by INTEGER REFERENCES users(id),
        created_at INTEGER NOT NULL
    )");

    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_api_tester_runs_provider ON api_tester_runs(provider)',
        'CREATE INDEX IF NOT EXISTS idx_api_tester_runs_created ON api_tester_runs(created_at)',
    ] as $sql) {
        $migrator->exec($sql);
    }

    $bool = static function (string $key, bool $default): string {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default ? '1' : '0';
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    };
    $string = static function (string $key, string $default): string {
        $value = getenv($key);

        return $value === false || trim($value) === '' ? $default : trim($value);
    };

    $defaults = [
        'features.api_tester' => $bool('FEATURE_API_TESTER', false),
        'api_tester.mode' => $string('API_TESTER_MODE', 'sandbox'),
        'api_tester.timeout_seconds' => $string('API_TESTER_TIMEOUT_SECONDS', '10'),
        'api_tester.max_body_bytes' => $string('API_TESTER_MAX_BODY_BYTES', '65536'),
    ];

    $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
};
