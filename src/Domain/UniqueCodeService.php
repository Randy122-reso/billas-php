<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Time;
use PDO;
use RuntimeException;

/**
 * Produces a collision-safe 3-digit unique nominal for static QRIS payments.
 * Manual reconciliation is matched by merchant ref + exact amount.
 */
final class UniqueCodeService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function forPayment(int $baseAmount): int
    {
        $baseAmount = max(1000, $baseAmount);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $code = random_int(101, 999);
            $total = $baseAmount + $code;
            if (!$this->isUsed($total)) {
                return $total;
            }
        }

        for ($code = 101; $code <= 999; $code++) {
            $total = $baseAmount + $code;
            if (!$this->isUsed($total)) {
                return $total;
            }
        }

        throw new RuntimeException('Tidak ada nominal unik yang tersedia.');
    }

    public function forCustomer(int $baseAmount, int $customerId): int
    {
        $baseAmount = max(1000, $baseAmount);
        $start = ($customerId % 899) + 101;
        for ($i = 0; $i < 899; $i++) {
            $code = (($start + $i - 101) % 899) + 101;
            $total = $baseAmount + $code;
            if (!$this->isUsed($total)) {
                return $total;
            }
        }

        return $this->forPayment($baseAmount);
    }

    private function isUsed(int $amount): bool
    {
        $now = Time::now();
        $stmt = $this->db->prepare(
            "SELECT 1 FROM orders WHERE amount = ? AND status = 'pending' AND expires_at > ?
             UNION ALL
             SELECT 1 FROM payment_transactions WHERE amount = ? AND status = 'pending' AND expires_at > ?
             LIMIT 1"
        );
        $stmt->execute([$amount, $now, $amount, $now]);

        return (bool) $stmt->fetchColumn();
    }
}
