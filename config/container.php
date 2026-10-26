<?php

declare(strict_types=1);

use Billas\Database\Migrator;
use Billas\Database\Seeder;
use Billas\Domain\RetryService;
use Billas\Session\SqliteSessionHandler;
use Billas\Support\Crypto;
use Billas\Support\Env;
use Billas\Support\Session;
use Billas\Support\Settings;
use Billas\Support\View;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PHP-DI container definitions. Services are autowired; only runtime primitives
 * and classes with scalar constructors need explicit factories.
 *
 * Expected variables in scope: $env, $db, $logger, $root.
 */
/** @var Env $env */
/** @var PDO $db */
/** @var LoggerInterface $logger */
/** @var string $root */
$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->useAttributes(false);

$crypto = new Crypto(
    $env->string('ENCRYPTION_KEY', 'local-development-key-change-me'),
    $env->string('SESSION_SECRET', 'local-session-secret-change-me')
);
$sessionHandler = new SqliteSessionHandler($db, 86400);

$builder->addDefinitions([
    'app.root' => $root,
    'app.version' => $env->string('APP_VERSION', '4.0.0'),
    Env::class => $env,
    PDO::class => $db,
    LoggerInterface::class => $logger,
    View::class => new View($root . DIRECTORY_SEPARATOR . 'views'),
    Crypto::class => $crypto,
    Settings::class => new Settings($db, $env),
    SqliteSessionHandler::class => $sessionHandler,
    Session::class => new Session($env, $sessionHandler),
    Migrator::class => new Migrator($db, $root . '/db/schema.sql', $root . '/db/migrations'),
    Seeder::class => new Seeder($db, $env, $crypto),
    RetryService::class => new RetryService(
        $db,
        new \Billas\Domain\MikrotikService($db, new \Billas\Domain\RouterService($db, $crypto, $env), new \Billas\Domain\MikrotikClient($env, $logger), new \Billas\Domain\AuditService($db, $logger)),
        $logger,
        max(1, $env->int('RETRY_MAX_ATTEMPTS', 10)),
        max(1, $env->int('RETRY_BACKOFF_BASE', 60)),
    ),
]);

/** @var ContainerInterface $container */
$container = $builder->build();

return $container;
