<?php

namespace SSpkS\Package;

/**
 * 兼容 libsodium crypto_kdf_blake2b 的密钥派生实现，支持无符号 64 位 ID。
 * PHP 的 Sodium 绑定只接受正有符号整数，而群晖 ID 可能使用完整的 uint64 范围。
 */
final class SynologyKdf
{
    private const MASK32 = 0xffffffff;

    /** @var array<int,array{0:int,1:int}> */
    private const IV = [
        [0xf3bcc908, 0x6a09e667], [0x84caa73b, 0xbb67ae85],
        [0xfe94f82b, 0x3c6ef372], [0x5f1d36f1, 0xa54ff53a],
        [0xade682d1, 0x510e527f], [0x2b3e6c1f, 0x9b05688c],
        [0xfb41bd6b, 0x1f83d9ab], [0x137e2179, 0x5be0cd19],
    ];

    /** @var array<int,array<int,int>> */
    private const SIGMA = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        [14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3],
        [11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4],
        [7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8],
        [9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13],
        [2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9],
        [12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11],
        [13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10],
        [6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5],
        [10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0],
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
        [14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3],
    ];

    public static function derive(string $subkeyIdLittleEndian, string $context, string $masterKey): string
    {
        if (strlen($subkeyIdLittleEndian) !== 8 || strlen($context) !== 8 || strlen($masterKey) !== 32) {
            throw new \RuntimeException('群晖 KDF 参数无效');
        }

        $parameter = chr(32) . chr(32) . chr(1) . chr(1)
            . str_repeat("\0", 28)
            . $subkeyIdLittleEndian . str_repeat("\0", 8)
            . $context . str_repeat("\0", 8);
        $state = self::IV;
        for ($index = 0; $index < 8; $index++) {
            $state[$index] = self::xor64($state[$index], self::read64le(substr($parameter, $index * 8, 8)));
        }

        $block = str_pad($masterKey, 128, "\0");
        self::compress($state, $block, 128, true);

        $result = '';
        for ($index = 0; $index < 4; $index++) {
            $result .= pack('V2', $state[$index][0], $state[$index][1]);
        }
        return $result;
    }

    /** @param array<int,array{0:int,1:int}> $state */
    private static function compress(array &$state, string $block, int $counter, bool $last): void
    {
        $message = [];
        for ($index = 0; $index < 16; $index++) {
            $message[$index] = self::read64le(substr($block, $index * 8, 8));
        }
        $work = array_merge($state, self::IV);
        $work[12] = self::xor64($work[12], [$counter & self::MASK32, 0]);
        if ($last) {
            $work[14] = self::xor64($work[14], [self::MASK32, self::MASK32]);
        }

        for ($round = 0; $round < 12; $round++) {
            $s = self::SIGMA[$round];
            self::mix($work, 0, 4, 8, 12, $message[$s[0]], $message[$s[1]]);
            self::mix($work, 1, 5, 9, 13, $message[$s[2]], $message[$s[3]]);
            self::mix($work, 2, 6, 10, 14, $message[$s[4]], $message[$s[5]]);
            self::mix($work, 3, 7, 11, 15, $message[$s[6]], $message[$s[7]]);
            self::mix($work, 0, 5, 10, 15, $message[$s[8]], $message[$s[9]]);
            self::mix($work, 1, 6, 11, 12, $message[$s[10]], $message[$s[11]]);
            self::mix($work, 2, 7, 8, 13, $message[$s[12]], $message[$s[13]]);
            self::mix($work, 3, 4, 9, 14, $message[$s[14]], $message[$s[15]]);
        }
        for ($index = 0; $index < 8; $index++) {
            $state[$index] = self::xor64($state[$index], self::xor64($work[$index], $work[$index + 8]));
        }
    }

    /** @param array<int,array{0:int,1:int}> $v */
    private static function mix(array &$v, int $a, int $b, int $c, int $d, array $x, array $y): void
    {
        $v[$a] = self::add64(self::add64($v[$a], $v[$b]), $x);
        $v[$d] = self::rotateRight(self::xor64($v[$d], $v[$a]), 32);
        $v[$c] = self::add64($v[$c], $v[$d]);
        $v[$b] = self::rotateRight(self::xor64($v[$b], $v[$c]), 24);
        $v[$a] = self::add64(self::add64($v[$a], $v[$b]), $y);
        $v[$d] = self::rotateRight(self::xor64($v[$d], $v[$a]), 16);
        $v[$c] = self::add64($v[$c], $v[$d]);
        $v[$b] = self::rotateRight(self::xor64($v[$b], $v[$c]), 63);
    }

    /** @return array{0:int,1:int} */
    private static function add64(array $left, array $right): array
    {
        $lowSum = $left[0] + $right[0];
        $low = $lowSum & self::MASK32;
        $carry = intdiv($lowSum, 0x100000000);
        $high = ($left[1] + $right[1] + $carry) & self::MASK32;
        return [$low, $high];
    }

    /** @return array{0:int,1:int} */
    private static function xor64(array $left, array $right): array
    {
        return [($left[0] ^ $right[0]) & self::MASK32, ($left[1] ^ $right[1]) & self::MASK32];
    }

    /** @return array{0:int,1:int} */
    private static function rotateRight(array $value, int $bits): array
    {
        if ($bits === 32) {
            return [$value[1], $value[0]];
        }
        if ($bits === 63) {
            return [
                (($value[0] << 1) & self::MASK32) | ($value[1] >> 31),
                (($value[1] << 1) & self::MASK32) | ($value[0] >> 31),
            ];
        }
        if ($bits < 32) {
            return [
                ($value[0] >> $bits) | (($value[1] << (32 - $bits)) & self::MASK32),
                ($value[1] >> $bits) | (($value[0] << (32 - $bits)) & self::MASK32),
            ];
        }
        $bits -= 32;
        return [
            ($value[1] >> $bits) | (($value[0] << (32 - $bits)) & self::MASK32),
            ($value[0] >> $bits) | (($value[1] << (32 - $bits)) & self::MASK32),
        ];
    }

    /** @return array{0:int,1:int} */
    private static function read64le(string $value): array
    {
        $parts = unpack('Vlow/Vhigh', $value);
        return [$parts['low'], $parts['high']];
    }
}
