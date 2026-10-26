<?php

declare(strict_types=1);

namespace Billas\Domain;

/**
 * Minimal EMVCo QRIS TLV encoder/decoder + CRC16 (CCITT/XMODEM).
 *
 * This is intentionally dependency-free and used both for static QRIS fallback
 * and for tests of the QR string contract.
 */
final class QrisUtil
{
    /** @return array<string,string> */
    public static function parse(string $payload): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($payload);
        while ($offset + 4 <= $length) {
            $tag = substr($payload, $offset, 2);
            $len = (int) substr($payload, $offset + 2, 2);
            if ($len < 0 || $offset + 4 + $len > $length) {
                break;
            }
            $value = substr($payload, $offset + 4, $len);
            $result[$tag] = $value;
            $offset += 4 + $len;
        }

        return $result;
    }

    /** @param array<string,string> $tags */
    public static function build(array $tags): string
    {
        ksort($tags, SORT_STRING);
        $out = '';
        foreach ($tags as $tag => $value) {
            $tag = str_pad((string) $tag, 2, '0', STR_PAD_LEFT);
            $length = strlen((string) $value);
            if ($length > 99) {
                throw new \InvalidArgumentException('Nilai TLV terlalu panjang untuk tag ' . $tag);
            }
            $out .= $tag . str_pad((string) $length, 2, '0', STR_PAD_LEFT) . $value;
        }

        return $out;
    }

    public static function withAmount(string $payload, int $amount): string
    {
        $tags = self::parse($payload);
        unset($tags['63']);
        $tags['01'] = '12';
        $tags['54'] = (string) max(0, $amount);
        $body = self::build($tags);

        return $body . '6304' . self::crc16($body);
    }

    public static function withoutAmount(string $payload): string
    {
        $tags = self::parse($payload);
        unset($tags['54'], $tags['63']);
        $tags['01'] = '11';
        $body = self::build($tags);

        return $body . '6304' . self::crc16($body);
    }

    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    public static function isValid(string $payload): bool
    {
        if (strlen($payload) < 8) {
            return false;
        }
        $body = substr($payload, 0, -8);
        $provided = substr($payload, -4);

        return hash_equals(self::crc16($body), strtoupper($provided));
    }

    /**
     * Build a demo static QRIS when operators do not supply a real payload.
     * The UI labels this value as demo; a real merchant payload is required for
     * production payments.
     */
    public static function demoPayload(string $merchantId, string $merchantName, string $city, string $postal, ?int $amount = null): string
    {
        $merchant = self::build([
            '00' => 'ID.CO.QRIS.WWW',
            '01' => $merchantId !== '' ? $merchantId : 'DEMO000000000000',
            '02' => 'UMI',
        ]);
        $tags = [
            '00' => '01',
            '01' => $amount === null ? '11' : '12',
            '26' => $merchant,
            '52' => '5812',
            '53' => '360',
            '58' => 'ID',
            '59' => $merchantName !== '' ? mb_substr($merchantName, 0, 25) : 'BILLAS DEMO',
            '60' => $city !== '' ? mb_substr($city, 0, 15) : 'JAKARTA',
            '61' => $postal !== '' ? mb_substr($postal, 0, 10) : '10000',
        ];
        if ($amount !== null) {
            $tags['54'] = (string) $amount;
        }
        $body = self::build($tags);

        return $body . '6304' . self::crc16($body);
    }
}
