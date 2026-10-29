<?php

declare(strict_types=1);

use Billas\Database\Migrator;

/**
 * Fitur F (v4.2): Webhook Simulator.
 * Additive-only table for observability; the original webhook pipeline never
 * reads it. Safe to run against a v4 database and safe to leave in place when
 * rolling back to a v4 image.
 */
return static function (PDO $db, Migrator $migrator): void {
    $migrator->exec("CREATE TABLE IF NOT EXISTS simulator_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target_type TEXT NOT NULL CHECK (target_type IN ('order','invoice','persistent_account')),
        target_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        event TEXT NOT NULL,
        mode TEXT NOT NULL CHECK (mode IN ('sign','bypass')),
        request_body TEXT,
        response_status INTEGER,
        response_body TEXT,
        created_by INTEGER REFERENCES users(id),
        created_at INTEGER NOT NULL
    )");

    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_simulator_runs_target ON simulator_runs(target_type, target_id)',
        'CREATE INDEX IF NOT EXISTS idx_simulator_runs_created ON simulator_runs(created_at)',
    ] as $sql) {
        $migrator->exec($sql);
    }
};
