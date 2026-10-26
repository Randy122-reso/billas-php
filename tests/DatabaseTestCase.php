<?php

declare(strict_types=1);

namespace Billas\Tests;

use Billas\Database\Migrator;
use Billas\Database\Seeder;
use Billas\Kernel;
use Billas\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Boots the real container against a fresh throwaway SQLite database for each
 * test. This keeps schema/DI/migration regressions visible to the suite.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var array{container:ContainerInterface,env:\Billas\Support\Env,db:PDO,logger:\Psr\Log\LoggerInterface} */
    protected array $services = [];

    protected ?PDO $db = null;

    protected string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'billas-test-' . bin2hex(random_bytes(8)) . '.db';
        putenv('DB_PATH=' . $this->dbPath);
        $_ENV['DB_PATH'] = $this->dbPath;

        $root = dirname(__DIR__);
        $this->services = Kernel::services($root);
        $this->db = $this->services['db'];

        /** @var Migrator $migrator */
        $migrator = $this->services['container']->get(Migrator::class);
        $migrator->migrate();

        /** @var Seeder $seeder */
        $seeder = $this->services['container']->get(Seeder::class);
        $seeder->seed();
    }

    protected function tearDown(): void
    {
        $path = $this->dbPath;
        $this->db = null;
        $this->services = [];

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    protected function db(): PDO
    {
        if ($this->db === null) {
            throw new \RuntimeException('Test database is not initialised.');
        }

        return $this->db;
    }

    protected function service(string $class): object
    {
        return $this->services['container']->get($class);
    }

    protected function createRouter(string $name = 'Test Router'): int
    {
        $this->db()->prepare(
            'INSERT INTO routers (name, host, port, username, password_enc, use_tls, active, created_at)
             VALUES (?, ?, ?, ?, ?, 0, 1, ?)'
        )->execute([$name, '127.0.0.1', 8728, 'billing-api', 'encrypted', Time::now()]);

        return (int) $this->db()->lastInsertId();
    }

    /** @param array<string,mixed> $overrides */
    protected function createPackage(array $overrides = []): int
    {
        $defaults = [
            'name' => 'Hotspot Test ' . bin2hex(random_bytes(3)),
            'type' => 'hotspot',
            'price' => 10000,
            'duration_value' => 1,
            'duration_unit' => 'days',
            'duration_mode' => 'calendar',
            'calendar_days' => 1,
            'mikrotik_profile' => 'hs-test',
            'bandwidth_down' => 10000,
            'bandwidth_up' => 2000,
            'active' => 1,
            'available_on_captive' => 1,
        ];
        $row = array_merge($defaults, $overrides);

        $this->db()->prepare(
            'INSERT INTO packages
             (name, type, price, duration_value, duration_unit, duration_mode, calendar_days,
              mikrotik_profile, bandwidth_down, bandwidth_up, active, available_on_captive, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['name'], $row['type'], $row['price'], $row['duration_value'], $row['duration_unit'],
            $row['duration_mode'], $row['calendar_days'], $row['mikrotik_profile'],
            $row['bandwidth_down'], $row['bandwidth_up'], $row['active'], $row['available_on_captive'],
            Time::now(),
        ]);

        return (int) $this->db()->lastInsertId();
    }

    /** @param array<string,mixed> $overrides */
    protected function createCustomer(array $overrides = []): int
    {
        $packageId = (int) ($overrides['package_id'] ?? $this->createPackage(['type' => 'pppoe', 'mikrotik_profile' => 'pppoe-test']));
        $defaults = [
            'code' => 'T-' . strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Test Customer',
            'phone' => '081200000000',
            'email' => 'test@example.test',
            'pppoe_username' => 'user-' . bin2hex(random_bytes(5)),
            'pppoe_password' => 'secret',
            'package_id' => $packageId,
            'router_id' => null,
            'portal_password_hash' => password_hash('pelanggan123', PASSWORD_DEFAULT),
            'status' => 'active',
            'paid_until' => null,
            'paid_until_updated_at' => null,
            'service_guaranteed_until' => null,
            'service_guaranteed_updated_at' => null,
        ];
        $row = array_merge($defaults, $overrides);

        $this->db()->prepare(
            'INSERT INTO customers
             (code, name, phone, email, pppoe_username, pppoe_password, package_id, router_id,
              portal_password_hash, status, paid_until, paid_until_updated_at,
              service_guaranteed_until, service_guaranteed_updated_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['code'], $row['name'], $row['phone'], $row['email'], $row['pppoe_username'],
            $row['pppoe_password'], $row['package_id'], $row['router_id'], $row['portal_password_hash'],
            $row['status'], $row['paid_until'], $row['paid_until_updated_at'],
            $row['service_guaranteed_until'], $row['service_guaranteed_updated_at'], Time::now(),
        ]);

        return (int) $this->db()->lastInsertId();
    }

    /** @param array<string,mixed> $overrides */
    protected function createInvoice(int $customerId, array $overrides = []): int
    {
        $periodEnd = (string) ($overrides['period_end'] ?? Time::addDays(Time::today(), 20));
        $periodStart = (string) ($overrides['period_start'] ?? date('Y-m-d', strtotime($periodEnd . ' -1 month +1 day')));
        $period = substr($periodStart, 0, 7);
        $defaults = [
            'invoice_no' => 'INV-TEST-' . strtoupper(bin2hex(random_bytes(5))),
            'customer_id' => $customerId,
            'period' => $period,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'amount' => 150000,
            'due_date' => (string) ($overrides['due_date'] ?? $period . '-10'),
            'status' => 'unpaid',
            'source' => 'monthly',
        ];
        $row = array_merge($defaults, $overrides);

        $this->db()->prepare(
            'INSERT INTO invoices
             (invoice_no, customer_id, period, period_start, period_end, amount, due_date, status, source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['invoice_no'], $row['customer_id'], $row['period'], $row['period_start'],
            $row['period_end'], $row['amount'], $row['due_date'], $row['status'], $row['source'],
            Time::now(),
        ]);

        return (int) $this->db()->lastInsertId();
    }

    /** @param array<string,mixed> $overrides */
    protected function createOrder(int $packageId, array $overrides = []): array
    {
        $id = (string) ($overrides['id'] ?? 'ord-' . bin2hex(random_bytes(6)));
        $row = array_merge([
            'id' => $id,
            'package_id' => $packageId,
            'amount' => 10000,
            'gateway' => 'qris_static',
            'status' => 'pending',
            'merchant_ref' => 'BLS-TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'expires_at' => Time::now() + 900,
            'source' => 'captive_portal',
            'ssid' => 'RTRWNET',
            'client_ip' => '192.168.10.20',
            'client_mac' => 'AA:BB:CC:DD:EE:FF',
            'router_id' => null,
        ], $overrides);

        $this->db()->prepare(
            'INSERT INTO orders
             (id, package_id, amount, gateway, status, merchant_ref, expires_at, source, ssid,
              client_ip, client_mac, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $row['id'], $row['package_id'], $row['amount'], $row['gateway'], $row['status'],
            $row['merchant_ref'], $row['expires_at'], $row['source'], $row['ssid'],
            $row['client_ip'], $row['client_mac'], Time::now(),
        ]);

        return $row;
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
