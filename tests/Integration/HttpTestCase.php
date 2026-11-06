<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\App\Application;
use Billas\Support\Session;
use Billas\Tests\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Shared helpers for integration tests that exercise the real Slim middleware
 * stack (session auth, CSRF, rate limiting, controllers and views).
 */
abstract class HttpTestCase extends DatabaseTestCase
{
    /**
     * Create an authenticated admin session and return its ID + CSRF token.
     *
     * @return array{0:string,1:string}
     */
    protected function adminSession(): array
    {
        /** @var Session $session */
        $session = $this->service(Session::class);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_id(bin2hex(random_bytes(16)));
        $session->start();
        $session->loginAdmin(1, 'admin', 'superadmin');
        $sessionId = session_id();
        $csrf = (string) ($_SESSION['_csrf'] ?? '');
        session_write_close();

        return [$sessionId, $csrf];
    }

    /**
     * Dispatch a request through the real Slim application/middleware stack.
     *
     * @param array{headers?:array<string,string>,body?:array<string,mixed>|null,session?:string} $options
     */
    protected function http(string $method, string $path, array $options = []): ResponseInterface
    {
        $app = (new Application(
            $this->services['container'],
            $this->services['env'],
            $this->services['logger'],
        ))->app();

        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            $path,
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (array_key_exists('body', $options)) {
            $request = $request->withParsedBody($options['body']);
        }
        if (isset($options['session'])) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            session_id((string) $options['session']);
        }

        return $app->handle($request);
    }

    /** @return array<string,mixed> */
    protected function jsonResponse(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
