<?php

namespace SSpkS\Package;

/**
 * SPK archives must not contain PHAR metadata that PHP 7.4 can unserialize.
 */
final class TarArchiveGuard
{
    private const BLOCK_SIZE = 512;
    private const MAX_LONG_NAME_SIZE = 65536;

    public static function assertNoPharMetadata(string $filename): void
    {
        $handle = @fopen($filename, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open package archive for validation');
        }

        $pendingLongName = null;
        try {
            while (true) {
                $header = self::readBlock($handle);
                if ($header === null || $header === str_repeat("\0", self::BLOCK_SIZE)) {
                    return;
                }

                $size = self::parseOctal(substr($header, 124, 12));
                $type = $header[156] ?? "\0";

                if ($type === 'L') {
                    if ($size > self::MAX_LONG_NAME_SIZE) {
                        throw new \RuntimeException('Package archive contains an excessively long entry name');
                    }
                    $pendingLongName = rtrim(self::readBytes($handle, $size), "\0");
                    self::skipPadding($handle, $size);
                    continue;
                }

                $entryName = $pendingLongName ?? self::entryName($header);
                $pendingLongName = null;
                self::assertSafeEntryName($entryName);
                self::skipBytes($handle, self::paddedSize($size));
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return null|string */
    private static function readBlock($handle)
    {
        $block = fread($handle, self::BLOCK_SIZE);
        if ($block === false) {
            throw new \RuntimeException('Unable to read package archive header');
        }
        if ($block === '') {
            return null;
        }
        if (strlen($block) !== self::BLOCK_SIZE) {
            throw new \RuntimeException('Package archive is incomplete');
        }
        return $block;
    }

    private static function readBytes($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, min(8192, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Package archive is incomplete');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private static function entryName(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        if (substr($header, 257, 5) === 'ustar') {
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
        }
        return $name;
    }

    private static function assertSafeEntryName(string $entryName): void
    {
        $normalised = str_replace('\\', '/', trim($entryName));
        while (strpos($normalised, './') === 0) {
            $normalised = substr($normalised, 2);
        }
        $normalised = ltrim($normalised, '/');

        if (preg_match('~(?:^|/)\.phar(?:/|$)~i', $normalised) === 1) {
            throw new \RuntimeException('Package archive contains forbidden PHAR metadata');
        }
    }

    private static function parseOctal(string $value): int
    {
        $value = trim($value, " \0");
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-7]+$/D', $value) !== 1) {
            throw new \RuntimeException('Package archive contains an invalid entry size');
        }

        $size = octdec($value);
        if (!is_int($size) || $size < 0) {
            throw new \RuntimeException('Package archive entry is too large');
        }
        return $size;
    }

    private static function paddedSize(int $size): int
    {
        if ($size > PHP_INT_MAX - (self::BLOCK_SIZE - 1)) {
            throw new \RuntimeException('Package archive entry is too large');
        }
        return (int) (ceil($size / self::BLOCK_SIZE) * self::BLOCK_SIZE);
    }

    private static function skipPadding($handle, int $size): void
    {
        self::skipBytes($handle, self::paddedSize($size) - $size);
    }

    private static function skipBytes($handle, int $length): void
    {
        if ($length === 0) {
            return;
        }
        if (fseek($handle, $length, SEEK_CUR) !== 0) {
            throw new \RuntimeException('Unable to locate data in package archive');
        }
    }
}
