<?php

declare(strict_types=1);

namespace Billas\Tests\Unit;

use Billas\Support\Time;
use PHPUnit\Framework\TestCase;

final class TimeTest extends TestCase
{
    public function testBootForcesJakartaTimezone(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');
            Time::boot('Asia/Jakarta');

            self::assertSame('Asia/Jakarta', date_default_timezone_get());
            self::assertSame('Asia/Jakarta', Time::timezone()->getName());
        } finally {
            date_default_timezone_set($original);
        }
    }
}
