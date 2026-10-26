<?php

declare(strict_types=1);

namespace Billas\Tests\Unit;

use Billas\Domain\QrisUtil;
use PHPUnit\Framework\TestCase;

final class QrisUtilTest extends TestCase
{
    public function testDemoPayloadHasValidCrc(): void
    {
        $payload = QrisUtil::demoPayload('DEMO000000000000', 'BILLAS TEST', 'JAKARTA', '10000');

        self::assertTrue(QrisUtil::isValid($payload));
        self::assertSame('01', QrisUtil::parse($payload)['00']);
        self::assertSame('BILLAS TEST', QrisUtil::parse($payload)['59']);
    }

    public function testWithAmountSetsDynamicTagAndCrc(): void
    {
        $base = QrisUtil::demoPayload('DEMO000000000000', 'BILLAS TEST', 'JAKARTA', '10000');
        $amounted = QrisUtil::withAmount($base, 15555);
        $tags = QrisUtil::parse($amounted);

        self::assertSame('12', $tags['01']);
        self::assertSame('15555', $tags['54']);
        self::assertTrue(QrisUtil::isValid($amounted));
    }

    public function testWithoutAmountRemovesAmountTag(): void
    {
        $amounted = QrisUtil::withAmount(
            QrisUtil::demoPayload('DEMO000000000000', 'BILLAS TEST', 'JAKARTA', '10000'),
            50000
        );
        $static = QrisUtil::withoutAmount($amounted);
        $tags = QrisUtil::parse($static);

        self::assertSame('11', $tags['01']);
        self::assertArrayNotHasKey('54', $tags);
        self::assertTrue(QrisUtil::isValid($static));
    }

    public function testTamperedPayloadIsInvalid(): void
    {
        $payload = QrisUtil::demoPayload('DEMO000000000000', 'BILLAS TEST', 'JAKARTA', '10000');
        $tampered = substr($payload, 0, -1) . (str_ends_with($payload, '0') ? '1' : '0');

        self::assertFalse(QrisUtil::isValid($tampered));
    }
}
