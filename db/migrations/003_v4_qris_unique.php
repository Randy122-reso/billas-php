<?php

declare(strict_types=1);

use Billas\Database\Migrator;

/**
 * Fitur D (v4): QRIS unik + garansi layanan.
 * Additive only; no existing column is modified or dropped.
 */
return static function (PDO $db, Migrator $migrator): void {
    $migrator->addColumnIfMissing('customers', 'service_guaranteed_until', 'DATE');
    $migrator->addColumnIfMissing('customers', 'service_guaranteed_updated_at', 'INTEGER');
    $migrator->addColumnIfMissing('customer_payment_accounts', 'qris_unique_ref', 'TEXT');

    $migrator->exec('CREATE INDEX IF NOT EXISTS idx_customers_guaranteed ON customers(service_guaranteed_until)');
    $migrator->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_cpa_qris_unique
                     ON customer_payment_accounts(gateway, qris_unique_ref)
                     WHERE qris_unique_ref IS NOT NULL AND active = 1');

    // Existing paid customers are considered guaranteed through their paid_until.
    $migrator->exec("UPDATE customers
                     SET service_guaranteed_until = paid_until,
                         service_guaranteed_updated_at = COALESCE(paid_until_updated_at, strftime('%s','now'))
                     WHERE service_guaranteed_until IS NULL AND paid_until IS NOT NULL");
};
