<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

use Billas\Domain\UniqueCodeService;
use Billas\Tests\DatabaseTestCase;
use Billas\Support\Time;

final class UniqueCodeServiceTest extends DatabaseTestCase
{
    public function testUniqueCodeStaysInThreeDigitRange(): void
    {
        $service = $this->service(UniqueCodeService::class);

        $amount = $service->forPayment(50000);

        self::assertGreaterThanOrEqual(50101, $amount);
        self::assertLessThanOrEqual(50999, $amount);
    }

    public function testUniqueCodeAvoidsPendingOrderAmount(): void
    {
        $packageId = $this->createPackage(['price' => 50000]);
        $service = $this->service(UniqueCodeService::class);

        $first = $service->forPayment(50000);
        $this->createOrder($packageId, [
            'amount' => $first,
            'status' => 'pending',
            'expires_at' => Time::now() + 900,
        ]);
        $second = $service->forPayment(50000);

        self::assertNotSame($first, $second);
    }
}
