<?php

declare(strict_types=1);

use Billas\Database\Migrator;

/**
 * v3 additive migration: subscription validity + explicit invoice period.
 * Safe to run against both v2 and v4 databases.
 */
return static function (PDO $db, Migrator $migrator): void {
    $migrator->addColumnIfMissing('customers', 'paid_until', 'DATE');
    $migrator->addColumnIfMissing('customers', 'paid_until_updated_at', 'INTEGER');
    $migrator->addColumnIfMissing('invoices', 'period_start', 'DATE');
    $migrator->addColumnIfMissing('invoices', 'period_end', 'DATE');
    $migrator->addColumnIfMissing('invoices', 'source', "TEXT NOT NULL DEFAULT 'monthly'");
    $migrator->addColumnIfMissing('invoices', 'paid_via_account_id', 'INTEGER');

    $migrator->exec('CREATE INDEX IF NOT EXISTS idx_customers_paid_until ON customers(paid_until)');
    $migrator->exec('CREATE INDEX IF NOT EXISTS idx_invoices_status_due ON invoices(status, due_date)');
};
