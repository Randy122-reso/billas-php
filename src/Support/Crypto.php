<?php

declare(strict_types=1);

namespace Billas\Support;

use RuntimeException;

/**
 * AES-256-GCM encryption for router credentials, plus HMAC helpers used by
 * captive portal sessions and internal integrations.
 */
final class Crypto
{
    private string $key;

    public function __construct(
        string $encryptionKey,
        private readonly string $sessionSecret,
    ) {
        if ($encryptionKey === '') {
            throw new RuntimeException('ENCRYPTION_KEY kosong.');
        }
        // Derive a fixed 32-byte key from arbitrary secret material.
        $this->key = hash('sha256', $encryptionKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Enkripsi gagal.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Ciphertext tidak valid.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Dekripsi gagal: kunci berubah atau data rusak.');
        }

        return $plain;
    }

    public function hmac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->sessionSecret);
    }

    public function verifyHmac(string $payload, string $signature): bool
    {
        return hash_equals($this->hmac($payload), $signature);
    }

    public function signPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = self::base64UrlEncode((string) $json);

        return $encoded . '.' . $this->hmac($encoded);
    }

    /** @return array<string,mixed>|null */
    public function verifyPayload(string $token, ?int $maxAge = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !$this->verifyHmac($parts[0], $parts[1])) {
            return null;
        }

        $raw = self::base64UrlDecode($parts[0]);
        if ($raw === null) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return null;
        }
        if ($maxAge !== null && ($payload['ts'] ?? 0) + $maxAge < time()) {
            return null;
        }

        return $payload;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
