<?php

declare(strict_types=1);

use Billas\Support\Env;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

/**
 * Monolog factory with daily rotation and secret redaction.
 *
 * Expected variables in scope: $env, $root.
 */
/** @var Env $env */
/** @var string $root */
$logDir = $env->string('LOG_DIR', $root . '/logs');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$level = Level::fromName(strtoupper($env->string('LOG_LEVEL', 'info')));
$logger = new Logger('billas');
try {
    $logger->pushHandler(new RotatingFileHandler($logDir . '/app.log', 14, $level, true, 0664));
} catch (Throwable) {
    $logger->pushHandler(new StreamHandler('php://stderr', $level));
}

$redact = static function (LogRecord $record): LogRecord {
    $walk = static function (mixed $value) use (&$walk): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $lower = is_string($key) ? strtolower($key) : '';
            if (preg_match('/(password|passwd|secret|token|authorization|api[_-]?key|private[_-]?key|signature)/', $lower)) {
                $clean[$key] = '[REDACTED]';
            } else {
                $clean[$key] = $walk($item);
            }
        }

        return $clean;
    };

    return $record->with(
        context: $walk($record->context),
        extra: $walk($record->extra),
    );
};
$logger->pushProcessor($redact);

return $logger;
