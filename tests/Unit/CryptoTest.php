<?php

declare(strict_types=1);

namespace Billas\Tests\Unit;

use Billas\Support\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto('unit-encryption-key-0123456789', 'unit-session-secret-0123456789');
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $crypto = $this->crypto();
        $plain = 'router-password-rahasia-123';

        $cipher = $crypto->encrypt($plain);

        self::assertNotSame($plain, $cipher);
        self::assertSame($plain, $crypto->decrypt($cipher));
    }

    public function testCiphertextIsRandomised(): void
    {
        $crypto = $this->crypto();

        self::assertNotSame($crypto->encrypt('same'), $crypto->encrypt('same'));
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $cipher = $this->crypto()->encrypt('secret');
        $other = new Crypto('different-encryption-key-012345', 'unit-session-secret-0123456789');

        $this->expectException(RuntimeException::class);
        $other->decrypt($cipher);
    }

    public function testHmacSignAndVerify(): void
    {
        $crypto = $this->crypto();
        $payload = '1|RTRWNET|192.168.10.8|AA:BB|1700000000';
        $signature = $crypto->hmac($payload);

        self::assertTrue($crypto->verifyHmac($payload, $signature));
        self::assertFalse($crypto->verifyHmac($payload . 'x', $signature));
    }

    public function testSignedPayloadExpiry(): void
    {
        $crypto = $this->crypto();
        $token = $crypto->signPayload(['customer_id' => 7, 'ts' => time() - 120]);

        self::assertSame(7, $crypto->verifyPayload($token)['customer_id'] ?? null);
        self::assertNull($crypto->verifyPayload($token, 60));
    }
}
