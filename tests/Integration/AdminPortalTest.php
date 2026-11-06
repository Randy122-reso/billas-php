<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

/**
 * Admin console regressions for v4.2: settings persistence, router deletion
 * and the Simulator controls in the settings view.
 */
final class AdminPortalTest extends HttpTestCase
{
    public function testSettingsSaveAcceptsPhpMangledDottedKeys(): void
    {
        [$sessionId, $csrf] = $this->adminSession();

        // Native PHP form parsing turns "features.wifi_captive_portal" into
        // "features_wifi_captive_portal" before it reaches the controller.
        $response = $this->http('POST', '/admin/settings', [
            'session' => $sessionId,
            'headers' => ['X-CSRF-Token' => $csrf],
            'body' => [
                'features_wifi_captive_portal' => '1',
                'features_webhook_simulator' => '1',
                'pppoe_qris_auto_create' => '1',
                'wifi_ssid' => 'RTRWNET-TEST',
                'wifi_rate_limit_create_per_ip' => '7',
                'payment_default_gateway' => 'qris_static',
                'payment_fallback_chain' => 'qris_static',
                'payment_order_ttl' => '600',
                'webhook_simulator_mode' => 'bypass',
                'webhook_simulator_allow_in_production' => '1',
            ],
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/settings', $response->getHeaderLine('Location'));
        self::assertSame('1', $this->scalar("SELECT value FROM settings WHERE key = 'features.wifi_captive_portal'"));
        self::assertSame('1', $this->scalar("SELECT value FROM settings WHERE key = 'features.webhook_simulator'"));
        self::assertSame('1', $this->scalar("SELECT value FROM settings WHERE key = 'pppoe_qris.auto_create'"));
        self::assertSame('RTRWNET-TEST', $this->scalar("SELECT value FROM settings WHERE key = 'wifi.ssid'"));
        self::assertSame('7', $this->scalar("SELECT value FROM settings WHERE key = 'wifi.rate_limit_create_per_ip'"));
        self::assertSame('600', $this->scalar("SELECT value FROM settings WHERE key = 'payment.order_ttl'"));
        self::assertSame('bypass', $this->scalar("SELECT value FROM settings WHERE key = 'webhook_simulator.mode'"));
        self::assertSame('1', $this->scalar("SELECT value FROM settings WHERE key = 'webhook_simulator.allow_in_production'"));
    }

    public function testSettingsSaveClearsUncheckedFeatureFlags(): void
    {
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/settings', [
            'session' => $sessionId,
            'headers' => ['X-CSRF-Token' => $csrf],
            'body' => [
                'features_wifi_captive_portal' => '1',
                'wifi_ssid' => 'RTRWNET',
            ],
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('0', $this->scalar("SELECT value FROM settings WHERE key = 'features.webhook_simulator'"));
        self::assertSame('0', $this->scalar("SELECT value FROM settings WHERE key = 'features.pppoe_qris_unique'"));
        self::assertSame('0', $this->scalar("SELECT value FROM settings WHERE key = 'webhook_simulator.allow_in_production'"));
    }

    public function testSettingsViewExposesWebhookSimulatorControls(): void
    {
        [$sessionId] = $this->adminSession();

        $response = $this->http('GET', '/admin/settings', ['session' => $sessionId]);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="features.webhook_simulator"', $html);
        self::assertStringContainsString('name="webhook_simulator.mode"', $html);
        self::assertStringContainsString('name="webhook_simulator.allow_in_production"', $html);
    }

    public function testRouterDeleteRemovesUnreferencedRouter(): void
    {
        $routerId = $this->createRouter('Router Salah Tambah');
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/routers/delete/' . $routerId, [
            'session' => $sessionId,
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/routers', $response->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM routers WHERE id = ?', [$routerId]));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'router.deleted' AND entity_id = ?",
            [(string) $routerId]
        ));
    }

    public function testRouterDeleteIsBlockedWhileCustomersReferenceIt(): void
    {
        $routerId = $this->createRouter('Router Aktif');
        $this->createCustomer(['router_id' => $routerId]);
        [$sessionId, $csrf] = $this->adminSession();

        $response = $this->http('POST', '/admin/routers/delete/' . $routerId, [
            'session' => $sessionId,
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM routers WHERE id = ?', [$routerId]));
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_trail WHERE action = 'router.deleted' AND entity_id = ?",
            [(string) $routerId]
        ));
    }

    public function testRouterDeleteRequiresCsrfToken(): void
    {
        $routerId = $this->createRouter('Router CSRF');
        [$sessionId] = $this->adminSession();

        $response = $this->http('POST', '/admin/routers/delete/' . $routerId, [
            'session' => $sessionId,
        ]);

        self::assertSame(419, $response->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM routers WHERE id = ?', [$routerId]));
    }

    public function testRouterListRendersDeleteAction(): void
    {
        $routerId = $this->createRouter('Router Tampil');
        [$sessionId] = $this->adminSession();

        $response = $this->http('GET', '/admin/routers', ['session' => $sessionId]);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/admin/routers/delete/' . $routerId, $html);
        self::assertStringContainsString('Hapus', $html);
    }
}
