<?php

declare(strict_types=1);

namespace Billas;

use Billas\App\Application;
use Billas\Database\Migrator;
use Billas\Database\Seeder;
use Billas\Support\Env;
use Billas\Support\Time;
use DI\ContainerBuilder;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class Kernel
{
    public static function boot(string $root): Application
    {
        $env = self::loadEnv($root);
        Time::boot($env->string('TZ', 'Asia/Jakarta'));

        /** @var PDO $db */
        $db = require $root . '/config/database.php';
        $logger = require $root . '/config/logger.php';

        if ($env->bool('AUTO_MIGRATE')) {
            $migrator = new Migrator($db, $root . '/db/schema.sql', $root . '/db/migrations');
            $applied = $migrator->migrate();
            if ($applied !== []) {
                $logger->info('Migrasi diterapkan', ['migrations' => $applied]);
            }
        }

        if ($env->bool('AUTO_SEED')) {
            $crypto = new \Billas\Support\Crypto(
                $env->string('ENCRYPTION_KEY', 'local-development-key-change-me'),
                $env->string('SESSION_SECRET', 'local-session-secret-change-me')
            );
            $summary = (new Seeder($db, $env, $crypto))->seed();
            if ($summary['admin'] || $summary['settings'] > 0 || $summary['demo']) {
                $logger->info('Seed awal dijalankan', $summary);
            }
        }

        /** @var ContainerInterface $container */
        $container = require $root . '/config/container.php';

        return new Application($container, $env, $logger);
    }

    public static function loadEnv(string $root): Env
    {
        /** @var Env $env */
        $env = require $root . '/config/env.php';

        return $env;
    }

    /**
     * Build a container without an HTTP app (used by CLI and tests).
     *
     * @return array{container:ContainerInterface,env:Env,db:PDO,logger:LoggerInterface}
     */
    public static function services(string $root): array
    {
        $env = self::loadEnv($root);
        Time::boot($env->string('TZ', 'Asia/Jakarta'));
        /** @var PDO $db */
        $db = require $root . '/config/database.php';
        $logger = require $root . '/config/logger.php';

        if ($env->bool('AUTO_MIGRATE')) {
            (new Migrator($db, $root . '/db/schema.sql', $root . '/db/migrations'))->migrate();
        }

        /** @var ContainerInterface $container */
        $container = require $root . '/config/container.php';

        return ['container' => $container, 'env' => $env, 'db' => $db, 'logger' => $logger];
    }
}
