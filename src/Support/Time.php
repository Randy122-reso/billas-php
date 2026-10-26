<?php

declare(strict_types=1);

namespace Billas\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Central time helper: all persisted timestamps are unix seconds. */
final class Time
{
    private static string $timezone = 'Asia/Jakarta';

    public static function boot(string $timezone): void
    {
        self::$timezone = $timezone;
        date_default_timezone_set($timezone);
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::$timezone);
    }

    public static function now(): int
    {
        return time();
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function nowIso(): string
    {
        return date('c');
    }

    public static function date(int|string|null $value = null): string
    {
        if ($value === null || $value === '') {
            return self::today();
        }
        if (is_int($value)) {
            return date('Y-m-d', $value);
        }

        return date('Y-m-d', strtotime($value));
    }

    public static function addDays(int|string $from, int $days): string
    {
        $ts = is_int($from) ? $from : strtotime($from);

        return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days', (int) $ts));
    }

    public static function addMonths(int|string $from, int $months): string
    {
        $ts = is_int($from) ? $from : strtotime($from);

        return date('Y-m-d', strtotime(($months >= 0 ? '+' : '') . $months . ' months', (int) $ts));
    }

    /** First and last day of a YYYY-MM period. @return array{0:string,1:string} */
    public static function monthBounds(?string $period = null): array
    {
        $period = $period ?: date('Y-m');
        $start = $period . '-01';

        return [$start, date('Y-m-t', strtotime($start))];
    }

    public static function currentPeriod(): string
    {
        return date('Y-m');
    }

    /** First day after the period, used for prepaid renewals. */
    public static function nextPeriodStart(?string $period = null): string
    {
        [$start] = self::monthBounds($period);

        return date('Y-m-d', strtotime($start . ' +1 month'));
    }

    public static function format(?string $date, bool $withTime = false): string
    {
        if ($date === null || $date === '') {
            return '-';
        }
        $ts = is_numeric($date) ? (int) $date : strtotime($date);
        if ($ts === false) {
            return '-';
        }

        return date($withTime ? 'd M Y H:i' : 'd M Y', $ts);
    }

    public static function remainingLabel(?int $expiresAt): string
    {
        if ($expiresAt === null) {
            return '-';
        }
        $seconds = $expiresAt - time();
        if ($seconds <= 0) {
            return 'Berakhir';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days . 'h ' . $hours . 'j';
        }
        if ($hours > 0) {
            return $hours . 'j ' . $minutes . 'm';
        }

        return max(1, $minutes) . 'm';
    }

    public static function immutable(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, self::timezone());
    }
}
