<?php

declare(strict_types=1);

use Billas\Database\Migrator;

/**
 * Fitur E (v4): WiFi captive portal (SSID RTRWNET).
 * Additive-only columns + the new captive_portal_sessions table.
 */
return static function (PDO $db, Migrator $migrator): void {
    $migrator->addColumnIfMissing('orders', 'source', "TEXT NOT NULL DEFAULT 'web_portal'");
    $migrator->addColumnIfMissing('orders', 'ssid', 'TEXT');
    $migrator->addColumnIfMissing('orders', 'client_ip', 'TEXT');
    $migrator->addColumnIfMissing('orders', 'client_mac', 'TEXT');

    $migrator->addColumnIfMissing('vouchers', 'source', "TEXT NOT NULL DEFAULT 'web_portal'");
    $migrator->addColumnIfMissing('vouchers', 'ssid', 'TEXT');
    $migrator->addColumnIfMissing('vouchers', 'client_ip', 'TEXT');
    $migrator->addColumnIfMissing('vouchers', 'client_mac', 'TEXT');

    $migrator->addColumnIfMissing('packages', 'available_on_captive', 'INTEGER NOT NULL DEFAULT 1');

    $migrator->exec("CREATE TABLE IF NOT EXISTS captive_portal_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_token TEXT UNIQUE NOT NULL,
        router_id INTEGER REFERENCES routers(id),
        ssid TEXT NOT NULL,
        client_ip TEXT,
        client_mac TEXT,
        package_id INTEGER REFERENCES packages(id),
        order_id TEXT REFERENCES orders(id),
        voucher_id INTEGER REFERENCES vouchers(id),
        status TEXT NOT NULL DEFAULT 'pending'
          CHECK (status IN ('pending','paid','expired','cancelled','revoked')),
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL
    )");

    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_orders_source_ssid ON orders(source, ssid)',
        'CREATE INDEX IF NOT EXISTS idx_orders_client_mac ON orders(client_mac) WHERE client_mac IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_vouchers_source_ssid ON vouchers(source, ssid)',
        'CREATE INDEX IF NOT EXISTS idx_cps_status ON captive_portal_sessions(status)',
        'CREATE INDEX IF NOT EXISTS idx_cps_mac ON captive_portal_sessions(client_mac)',
        'CREATE INDEX IF NOT EXISTS idx_cps_created ON captive_portal_sessions(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_cps_order ON captive_portal_sessions(order_id)',
    ] as $sql) {
        $migrator->exec($sql);
    }
};
