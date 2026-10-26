<?php

declare(strict_types=1);

namespace Billas\App;

use Billas\Controller\ErrorController;
use Billas\Middleware\CsrfMiddleware;
use Billas\Middleware\ErrorHandlerMiddleware;
use Billas\Middleware\RateLimitMiddleware;
use Billas\Middleware\SecurityHeadersMiddleware;
use Billas\Middleware\StartSessionMiddleware;
use Billas\Support\Env;
use Billas\Support\View;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Middleware\BodyParsingMiddleware;

/**
 * Builds the Slim application. Middleware order is deliberate: security and
 * error handling wrap everything, sessions start before CSRF, and routing is
 * innermost so errors in route resolution still get JSON/HTML error responses.
 */
final class Application
{
    private App $app;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Env $env,
        private readonly LoggerInterface $logger,
    ) {
        $this->app = AppFactory::create();
        $this->configure();
    }

    public function app(): App
    {
        return $this->app;
    }

    public function run(): void
    {
        $this->app->run();
    }

    private function configure(): void
    {
        $app = $this->app;
        $app->setBasePath($this->basePath());

        $app->addRoutingMiddleware();

        // Added first = innermost. Execution order is the reverse:
        // security -> error -> session -> csrf -> body parsing -> rate limit -> routing.
        $app->add($this->container->get(RateLimitMiddleware::class));
        $app->add(new BodyParsingMiddleware());
        $app->add($this->container->get(CsrfMiddleware::class));
        $app->add($this->container->get(StartSessionMiddleware::class));

        // Error handling must wrap session/CSRF/rate-limit/body errors too.
        $app->add(new ErrorHandlerMiddleware(
            $this->logger,
            $this->container->get(View::class),
            $this->env,
        ));
        $app->add($this->container->get(SecurityHeadersMiddleware::class));

        (new Routes($app, $this->container))->register();
    }

    private function basePath(): string
    {
        $url = $this->env->string('BASE_URL', '') ?: $this->env->string('APP_URL', '');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return $path === '/' ? '' : rtrim($path, '/');
    }
}
