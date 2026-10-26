<?php

declare(strict_types=1);

use Billas\Database\Migrator;

/**
 * v3 backfill: derive invoice period bounds and paid_until from existing data.
 * Only fills empty values; never decreases validity.
 */
return static function (PDO $db, Migrator $migrator): void {
    if ($migrator->columnExists('invoices', 'period_start')) {
        $db->exec("UPDATE invoices
                   SET period_start = COALESCE(period_start, period || '-01')
                   WHERE period_start IS NULL AND period IS NOT NULL");
    }
    if ($migrator->columnExists('invoices', 'period_end')) {
        $db->exec("UPDATE invoices
                   SET period_end = COALESCE(period_end, date(period || '-01', '+1 month', '-1 day'))
                   WHERE period_end IS NULL AND period IS NOT NULL");
    }

    if ($migrator->columnExists('customers', 'paid_until')) {
        // Backfill from the latest paid invoice period_end per customer.
        $db->exec("UPDATE customers
                   SET paid_until = (
                     SELECT MAX(i.period_end) FROM invoices i
                     WHERE i.customer_id = customers.id AND i.status = 'paid'
                   )
                   WHERE paid_until IS NULL
                     AND EXISTS (
                       SELECT 1 FROM invoices i
                       WHERE i.customer_id = customers.id AND i.status = 'paid'
                     )");
    }
};
