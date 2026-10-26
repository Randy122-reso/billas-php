<?php

declare(strict_types=1);

namespace Billas\Support;

/** CSRF token helpers shared by middleware, views, and controllers. */
final class Csrf
{
    public const SESSION_KEY = '_csrf';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }
}
