<?php

declare(strict_types=1);

namespace Billas\Domain;

use Billas\Support\Time;
use PDO;

final class AnalyticsService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $orderRevenue = (int) $this->db->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status = 'paid'")->fetchColumn();
        $invoiceRevenue = (int) $this->db->query("SELECT COALESCE(SUM(amount),0) FROM payment_transactions WHERE status = 'paid'")->fetchColumn();
        $todayStart = strtotime('today');
        $todayRevenue = (int) $this->db->query(
            "SELECT COALESCE(SUM(amount),0) FROM orders WHERE status = 'paid' AND paid_at >= {$todayStart}"
        )->fetchColumn();
        $todayRevenue += (int) $this->db->query(
            "SELECT COALESCE(SUM(amount),0) FROM payment_transactions WHERE status = 'paid' AND paid_at >= {$todayStart}"
        )->fetchColumn();

        $ordersTotal = (int) $this->db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $ordersPaid = (int) $this->db->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();

        return [
            'revenue_total' => $orderRevenue + $invoiceRevenue,
            'revenue_today' => $todayRevenue,
            'orders_total' => $ordersTotal,
            'orders_paid' => $ordersPaid,
            'orders_pending' => (int) $this->db->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
            'invoices_unpaid' => (int) $this->db->query("SELECT COUNT(*) FROM invoices WHERE status = 'unpaid'")->fetchColumn(),
            'invoices_paid' => (int) $this->db->query("SELECT COUNT(*) FROM invoices WHERE status = 'paid'")->fetchColumn(),
            'customers_active' => (int) $this->db->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn(),
            'customers_isolir' => (int) $this->db->query("SELECT COUNT(*) FROM customers WHERE status = 'isolir'")->fetchColumn(),
            'vouchers_active' => (int) $this->db->query('SELECT COUNT(*) FROM vouchers WHERE revoked = 0')->fetchColumn(),
            'vouchers_captive' => (int) $this->db->query("SELECT COUNT(*) FROM vouchers WHERE source = 'captive_portal'")->fetchColumn(),
            'captive_pending' => (int) $this->db->query("SELECT COUNT(*) FROM captive_portal_sessions WHERE status = 'pending'")->fetchColumn(),
            'captive_paid' => (int) $this->db->query("SELECT COUNT(*) FROM captive_portal_sessions WHERE status = 'paid'")->fetchColumn(),
            'success_rate' => $ordersTotal > 0 ? round(($ordersPaid / $ordersTotal) * 100, 1) : 0.0,
        ];
    }

    /** @return list<array{day:string,orders:int,revenue:int,captive:int}> */
    public function dailySeries(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $start = Time::now() - (($days - 1) * 86400);
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = date('Y-m-d', strtotime('+' . $i . ' days', strtotime(date('Y-m-d', $start))));
            $series[$day] = ['day' => $day, 'orders' => 0, 'revenue' => 0, 'captive' => 0];
        }

        $stmt = $this->db->prepare(
            "SELECT date(paid_at, 'unixepoch', 'localtime') AS day, COUNT(*) AS total,
                    COALESCE(SUM(amount),0) AS revenue,
                    SUM(CASE WHEN source = 'captive_portal' THEN 1 ELSE 0 END) AS captive
             FROM orders WHERE status = 'paid' AND paid_at >= ?
             GROUP BY day"
        );
        $stmt->execute([$start]);
        foreach ($stmt->fetchAll() as $row) {
            $day = (string) $row['day'];
            if (isset($series[$day])) {
                $series[$day]['orders'] += (int) $row['total'];
                $series[$day]['revenue'] += (int) $row['revenue'];
                $series[$day]['captive'] += (int) $row['captive'];
            }
        }

        $stmt = $this->db->prepare(
            "SELECT date(paid_at, 'unixepoch', 'localtime') AS day, COALESCE(SUM(amount),0) AS revenue
             FROM payment_transactions WHERE status = 'paid' AND paid_at >= ? GROUP BY day"
        );
        $stmt->execute([$start]);
        foreach ($stmt->fetchAll() as $row) {
            $day = (string) $row['day'];
            if (isset($series[$day])) {
                $series[$day]['revenue'] += (int) $row['revenue'];
            }
        }

        return array_values($series);
    }

    /** @return list<array<string,mixed>> */
    public function topPackages(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->query(
            "SELECT p.name, COUNT(o.id) AS total, COALESCE(SUM(o.amount),0) AS revenue
             FROM orders o JOIN packages p ON p.id = o.package_id
             WHERE o.status = 'paid'
             GROUP BY p.id ORDER BY total DESC LIMIT {$limit}"
        );

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function captiveDaily(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $from = Time::now() - ($days * 86400);
        $stmt = $this->db->prepare(
            "SELECT date(created_at, 'unixepoch', 'localtime') AS day,
                    COUNT(*) AS sessions,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid
             FROM captive_portal_sessions WHERE created_at >= ?
             GROUP BY day ORDER BY day"
        );
        $stmt->execute([$from]);

        return $stmt->fetchAll();
    }

    public function toCsv(int $days = 30): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, ['tanggal', 'order', 'pendapatan', 'captive']);
        foreach ($this->dailySeries($days) as $row) {
            fputcsv($handle, [$row['day'], $row['orders'], $row['revenue'], $row['captive']]);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
