<?php

declare(strict_types=1);

namespace Billas\App;

use Billas\Controller\AdminPortalController;
use Billas\Controller\CaptivePortalController;
use Billas\Controller\CustomerPortalController;
use Billas\Controller\HealthController;
use Billas\Controller\InternalController;
use Billas\Controller\PagesController;
use Billas\Controller\PaymentApiController;
use Billas\Controller\WebhookController;
use Billas\Domain\CaptivePortalService;
use Billas\Middleware\AuthMiddleware;
use Billas\Middleware\CaptiveSessionMiddleware;
use Billas\Middleware\CronAuthMiddleware;
use Billas\Support\Session;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Central route table. Route middleware only handles authentication and the
 * captive HMAC check; CSRF/rate limiting/session are global middleware.
 */
final class Routes
{
    public function __construct(
        private readonly App $app,
        private readonly ContainerInterface $container,
    ) {
    }

    public function register(): void
    {
        $this->publicRoutes();
        $this->internalRoutes();
        $this->wifiRoutes();
        $this->adminRoutes();
        $this->customerRoutes();
    }

    private function publicRoutes(): void
    {
        $c = $this->container;

        $this->app->get('/', $this->call(PagesController::class, 'index'));
        $this->app->get('/voucher/{orderId}', $this->call(PagesController::class, 'voucher'));
        $this->app->get('/health', $this->call(HealthController::class, 'health'));
        $this->app->get('/ready', $this->call(HealthController::class, 'ready'));

        $this->app->post('/api/pay/create', $this->call(PaymentApiController::class, 'create'));
        $this->app->get('/api/pay/status/{orderId}', $this->call(PaymentApiController::class, 'status'));
        $this->app->post('/api/webhook/{provider}', $this->call(WebhookController::class, 'handle'));
    }

    private function internalRoutes(): void
    {
        $this->app->post('/internal/cron/run', $this->call(InternalController::class, 'cron'))
            ->add(new CronAuthMiddleware($this->container->get(\Billas\Support\Env::class)));
    }

    private function wifiRoutes(): void
    {
        $c = $this->container;

        $this->app->get('/wifi', $this->call(CaptivePortalController::class, 'index'));
        $this->app->get('/wifi/voucher/{orderId}', $this->call(CaptivePortalController::class, 'voucher'));
        $this->app->get('/api/wifi/status/{orderId}', $this->call(CaptivePortalController::class, 'status'));

        $this->app->post('/api/wifi/pay/create', $this->call(CaptivePortalController::class, 'create'))
            ->add(new CaptiveSessionMiddleware(
                $c->get(CaptivePortalService::class),
                $c->get(\Billas\Support\Settings::class)
            ));
    }

    private function adminRoutes(): void
    {
        $c = $this->container;

        $this->app->group('/admin', function (RouteCollectorProxy $group) use ($c): void {
            $group->get('/login', $this->call(AdminPortalController::class, 'loginForm'));
            $group->post('/login', $this->call(AdminPortalController::class, 'login'));
        });

        $admin = $this->app->group('/admin', function (RouteCollectorProxy $group) use ($c): void {
            $group->post('/logout', $this->call(AdminPortalController::class, 'logout'));
            $group->get('/dashboard', $this->call(AdminPortalController::class, 'dashboard'));

            $group->get('/customers', $this->call(AdminPortalController::class, 'customers'));
            $group->post('/customers', $this->call(AdminPortalController::class, 'customersSave'));
            $group->post('/customers/{id}/qris-unique', $this->call(AdminPortalController::class, 'createQrisUnique'));
            $group->post('/customers/{id}/qris-unique/deactivate', $this->call(AdminPortalController::class, 'deactivateQrisUnique'));
            $group->post('/customers/{id}/payment-account', $this->call(AdminPortalController::class, 'createPaymentAccount'));
            $group->post('/customers/{id}/status', $this->call(AdminPortalController::class, 'customerStatus'));

            $group->get('/packages', $this->call(AdminPortalController::class, 'packages'));
            $group->post('/packages', $this->call(AdminPortalController::class, 'packagesSave'));
            $group->post('/packages/{id}/toggle', $this->call(AdminPortalController::class, 'packageToggle'));

            $group->get('/routers', $this->call(AdminPortalController::class, 'routers'));
            $group->post('/routers', $this->call(AdminPortalController::class, 'routersSave'));
            $group->post('/routers/{id}/toggle', $this->call(AdminPortalController::class, 'routerToggle'));
            $group->post('/routers/{id}/test', $this->call(AdminPortalController::class, 'routerTest'));

            $group->get('/invoices', $this->call(AdminPortalController::class, 'invoices'));
            $group->post('/invoices/generate', $this->call(AdminPortalController::class, 'invoicesGenerate'));
            $group->post('/invoices/{id}/mark-paid', $this->call(AdminPortalController::class, 'invoiceMarkPaid'));

            $group->get('/vouchers', $this->call(AdminPortalController::class, 'vouchers'));
            $group->post('/vouchers/{id}/revoke', $this->call(AdminPortalController::class, 'voucherRevoke'));

            $group->get('/wifi/sessions', $this->call(AdminPortalController::class, 'wifiSessions'));
            $group->get('/analytics', $this->call(AdminPortalController::class, 'analytics'));
            $group->get('/analytics/export.csv', $this->call(AdminPortalController::class, 'analyticsCsv'));

            $group->get('/reconciliation', $this->call(AdminPortalController::class, 'reconciliation'));
            $group->post('/reconciliation/confirm', $this->call(AdminPortalController::class, 'reconciliationConfirm'));
            $group->post('/reconciliation/account-confirm', $this->call(AdminPortalController::class, 'reconciliationAccountConfirm'));
            $group->post('/reconciliation/batch', $this->call(AdminPortalController::class, 'reconciliationBatch'));

            $group->get('/settings', $this->call(AdminPortalController::class, 'settings'));
            $group->post('/settings', $this->call(AdminPortalController::class, 'settingsSave'));
            $group->post('/test-gateway', $this->call(AdminPortalController::class, 'testGateway'));
            $group->get('/audit', $this->call(AdminPortalController::class, 'audit'));
        });

        $admin->add(new AuthMiddleware($c->get(Session::class), 'admin'));
    }

    private function customerRoutes(): void
    {
        $c = $this->container;

        $this->app->group('/portal', function (RouteCollectorProxy $group): void {
            $group->get('/login', $this->call(CustomerPortalController::class, 'loginForm'));
            $group->post('/login', $this->call(CustomerPortalController::class, 'login'));
        });

        $portal = $this->app->group('/portal', function (RouteCollectorProxy $group): void {
            $group->post('/logout', $this->call(CustomerPortalController::class, 'logout'));
            $group->get('', $this->call(CustomerPortalController::class, 'check'));
            $group->get('/check', $this->call(CustomerPortalController::class, 'check'));
            $group->get('/invoice/{id}', $this->call(CustomerPortalController::class, 'invoice'));
            $group->post('/pay/{id}', $this->call(CustomerPortalController::class, 'pay'));
            $group->get('/payment-status/{id}', $this->call(CustomerPortalController::class, 'paymentStatus'));
            $group->get('/account', $this->call(CustomerPortalController::class, 'account'));
            $group->get('/qris-unique', $this->call(CustomerPortalController::class, 'qrisUnique'));
            $group->post('/qris-unique/create', $this->call(CustomerPortalController::class, 'qrisUniqueCreate'));
        });

        $portal->add(new AuthMiddleware($c->get(Session::class), 'customer'));
    }

    /**
     * @param class-string $controller
     * @return callable(ServerRequestInterface,ResponseInterface,array<string,string>):ResponseInterface
     */
    private function call(string $controller, string $method): callable
    {
        return function (ServerRequestInterface $request, ResponseInterface $response, array $args = []) use ($controller, $method): ResponseInterface {
            /** @var object $instance */
            $instance = $this->container->get($controller);

            return $instance->{$method}($request, $response, $args);
        };
    }
}
