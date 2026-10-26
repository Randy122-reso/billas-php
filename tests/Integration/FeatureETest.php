<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\CaptivePortalService;
use Billas\Domain\CronService;
use Billas\Domain\VoucherService;
use Billas\Support\Time;
use Billas\Tests\DatabaseTestCase;

/**
 * Feature E — RTRWNET captive portal, one-time WiFi password, expiry.
 */
final class FeatureETest extends DatabaseTestCase
{
    public function testCreateSessionReturnsValidHmacToken(): void
    {
        $routerId = $this->createRouter();

        /** @var CaptivePortalService $captive */
        $captive = $this->service(CaptivePortalService::class);
        $session = $captive->createSession($routerId, 'RTRWNET', '192.168.10.8', 'AA:BB:CC:DD:EE:01');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session['session_token']);
        self::assertGreaterThan(Time::now(), $session['expires_at']);
        self::assertFalse($session['reused']);

        $validated = $captive->validateSessionToken($session['session_token']);
        self::assertNotNull($validated);
        self::assertSame('RTRWNET', $validated['ssid']);
        self::assertSame('AA:BB:CC:DD:EE:01', $validated['client_mac']);
        self::assertSame('pending', $validated['status']);
    }

    public function testSessionReuseWithinWindowUsesSameToken(): void
    {
        $routerId = $this->createRouter();

        /** @var CaptivePortalService $captive */
        $captive = $this->service(CaptivePortalService::class);
        $first = $captive->createSession($routerId, 'RTRWNET', '192.168.10.9', 'AA:BB:CC:DD:EE:02');
        $second = $captive->createSession($routerId, 'RTRWNET', '192.168.10.9', 'AA:BB:CC:DD:EE:02');

        self::assertTrue($second['reused']);
        self::assertSame($first['session_token'], $second['session_token']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM captive_portal_sessions'));
    }

    public function testExpiredOrPaidSessionIsRejected(): void
    {
        $routerId = $this->createRouter();

        /** @var CaptivePortalService $captive */
        $captive = $this->service(CaptivePortalService::class);
        $expired = $captive->createSession($routerId, 'RTRWNET', '192.168.10.10', 'AA:BB:CC:DD:EE:03');
        $this->db()->prepare('UPDATE captive_portal_sessions SET expires_at = ? WHERE id = ?')
            ->execute([Time::now() - 1, $expired['session_id']]);
        self::assertNull($captive->validateSessionToken($expired['session_token']));

        $paid = $captive->createSession($routerId, 'RTRWNET', '192.168.10.11', 'AA:BB:CC:DD:EE:04');
        $this->db()->prepare("UPDATE captive_portal_sessions SET status = 'paid' WHERE id = ?")
            ->execute([$paid['session_id']]);
        self::assertNull($captive->validateSessionToken($paid['session_token']));
    }

    public function testForgedOrTamperedTokenIsRejected(): void
    {
        $routerId = $this->createRouter();

        /** @var CaptivePortalService $captive */
        $captive = $this->service(CaptivePortalService::class);
        $session = $captive->createSession($routerId, 'RTRWNET', '192.168.10.12', 'AA:BB:CC:DD:EE:05');

        self::assertNull($captive->validateSessionToken(str_repeat('a', 64)));
        $tampered = $session['session_token'];
        $tampered[0] = $tampered[0] === 'a' ? 'b' : 'a';
        self::assertNull($captive->validateSessionToken($tampered));

        // A valid-length token stored in the row still fails the HMAC check.
        $this->db()->prepare('UPDATE captive_portal_sessions SET session_token = ? WHERE id = ?')
            ->execute([str_repeat('b', 64), $session['session_id']]);
        self::assertNull($captive->validateSessionToken(str_repeat('b', 64)));
    }

    public function testPersistCaptiveIsIdempotentAndUsesSafePassword(): void
    {
        $routerId = $this->createRouter();
        $packageId = $this->createPackage([
            'type' => 'hotspot',
            'duration_mode' => 'calendar',
            'duration_value' => 1,
            'duration_unit' => 'days',
            'calendar_days' => 1,
            'mikrotik_profile' => 'hs-captive',
        ]);
        $order = $this->createOrder($packageId, ['router_id' => $routerId]);
        $package = $this->db()->query('SELECT * FROM packages WHERE id = ' . $packageId)->fetch();

        /** @var VoucherService $vouchers */
        $vouchers = $this->service(VoucherService::class);
        $first = $vouchers->persistCaptive($order, $package, ['router_id' => $routerId]);
        $second = $vouchers->persistCaptive($order, $package, ['router_id' => $routerId]);

        self::assertSame((int) $first['id'], (int) $second['id']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM vouchers WHERE order_id = ?', [$order['id']]));
        self::assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', (string) $first['password']);
        self::assertStringNotContainsString('0', (string) $first['password']);
        self::assertStringNotContainsString('O', (string) $first['password']);
        self::assertStringNotContainsString('I', (string) $first['password']);
        self::assertSame('captive_portal', $first['source']);
        self::assertSame('RTRWNET', $first['ssid']);
        self::assertSame('192.168.10.20', $first['client_ip']);
        self::assertSame('AA:BB:CC:DD:EE:FF', $first['client_mac']);
        self::assertSame('calendar', $first['duration_mode']);
        self::assertGreaterThan(Time::now(), (int) $first['expires_at']);
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM retry_queue WHERE task_type = 'hotspot_push' AND status = 'pending'"
        ));
    }

    public function testCronExpiresOnlyOverduePendingSessions(): void
    {
        $routerId = $this->createRouter();

        /** @var CaptivePortalService $captive */
        $captive = $this->service(CaptivePortalService::class);
        $overdue = $captive->createSession($routerId, 'RTRWNET', '192.168.10.30', 'AA:BB:CC:DD:EE:30');
        $current = $captive->createSession($routerId, 'RTRWNET', '192.168.10.31', 'AA:BB:CC:DD:EE:31');

        $this->db()->prepare('UPDATE captive_portal_sessions SET expires_at = ? WHERE id = ?')
            ->execute([Time::now() - 10, $overdue['session_id']]);

        /** @var CronService $cron */
        $cron = $this->service(CronService::class);
        $result = $cron->tick(['captive_sessions'], true);

        self::assertSame('ok', $result['tasks']['captive_sessions']['status'] ?? null);
        self::assertSame('expired', $this->scalar('SELECT status FROM captive_portal_sessions WHERE id = ?', [$overdue['session_id']]));
        self::assertSame('pending', $this->scalar('SELECT status FROM captive_portal_sessions WHERE id = ?', [$current['session_id']]));
    }

    public function testExpiredVoucherIsRevokedAndDisabledOnRouter(): void
    {
        $routerId = $this->createRouter();
        $packageId = $this->createPackage(['mikrotik_profile' => 'hs-captive']);
        $order = $this->createOrder($packageId, ['router_id' => $routerId]);
        $package = $this->db()->query('SELECT * FROM packages WHERE id = ' . $packageId)->fetch();

        /** @var VoucherService $vouchers */
        $vouchers = $this->service(VoucherService::class);
        $voucher = $vouchers->persistCaptive($order, $package, ['router_id' => $routerId]);
        $this->db()->prepare('UPDATE vouchers SET expires_at = ? WHERE id = ?')
            ->execute([Time::now() - 5, $voucher['id']]);

        $result = $vouchers->expireDue();

        self::assertGreaterThanOrEqual(1, $result['expired']);
        self::assertSame(1, (int) $this->scalar('SELECT revoked FROM vouchers WHERE id = ?', [$voucher['id']]));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM retry_queue WHERE task_type = 'hotspot_disable' AND status = 'pending'"
        ));
    }
}
