<?php

namespace SSpkS\Package;

/**
 * 用于解析群晖归档头部的精简受限 MessagePack 解码器。
 */
final class BoundedMessagePackReader
{
    private const MAX_DEPTH = 16;
    private const MAX_ITEMS = 10000;
    private const MAX_BINARY_SIZE = 16 * 1024 * 1024;

    private string $data;
    private int $offset = 0;
    private int $itemsRead = 0;

    public static function decode(string $data)
    {
        $reader = new self($data);
        $value = $reader->readValue(0);
        if ($reader->offset !== strlen($data)) {
            throw new \RuntimeException('群晖归档的 MessagePack 头部包含多余数据');
        }
        return $value;
    }

    private function __construct(string $data)
    {
        if ($data === '' || strlen($data) > self::MAX_BINARY_SIZE) {
            throw new \RuntimeException('群晖归档的 MessagePack 头部大小无效');
        }
        $this->data = $data;
    }

    private function readValue(int $depth)
    {
        if ($depth > self::MAX_DEPTH || ++$this->itemsRead > self::MAX_ITEMS) {
            throw new \RuntimeException('群晖归档的 MessagePack 头部结构过于复杂');
        }

        $type = ord($this->readBytes(1));
        if ($type <= 0x7f) {
            return $type;
        }
        if ($type >= 0xe0) {
            return $type - 256;
        }
        if ($type >= 0x90 && $type <= 0x9f) {
            return $this->readArray($type & 0x0f, $depth);
        }
        if ($type >= 0x80 && $type <= 0x8f) {
            return $this->readMap($type & 0x0f, $depth);
        }
        if ($type >= 0xa0 && $type <= 0xbf) {
            return $this->readBytes($type & 0x1f);
        }

        switch ($type) {
            case 0xc0:
                return null;
            case 0xc2:
                return false;
            case 0xc3:
                return true;
            case 0xc4:
                return $this->readBytes($this->readUnsigned(1));
            case 0xc5:
                return $this->readBytes($this->readUnsigned(2));
            case 0xc6:
                return $this->readBytes($this->readUnsigned(4));
            case 0xcc:
                return $this->readUnsigned(1);
            case 0xcd:
                return $this->readUnsigned(2);
            case 0xce:
                return $this->readUnsigned(4);
            case 0xcf:
                return $this->readUnsigned64();
            case 0xd9:
                return $this->readBytes($this->readUnsigned(1));
            case 0xda:
                return $this->readBytes($this->readUnsigned(2));
            case 0xdb:
                return $this->readBytes($this->readUnsigned(4));
            case 0xdc:
                return $this->readArray($this->readUnsigned(2), $depth);
            case 0xdd:
                return $this->readArray($this->readUnsigned(4), $depth);
            case 0xde:
                return $this->readMap($this->readUnsigned(2), $depth);
            case 0xdf:
                return $this->readMap($this->readUnsigned(4), $depth);
        }

        throw new \RuntimeException(sprintf('群晖归档包含不支持的 MessagePack 类型 0x%02x', $type));
    }

    private function readArray(int $length, int $depth): array
    {
        $this->assertCollectionLength($length);
        $result = [];
        for ($index = 0; $index < $length; $index++) {
            $result[] = $this->readValue($depth + 1);
        }
        return $result;
    }

    private function readMap(int $length, int $depth): array
    {
        $this->assertCollectionLength($length);
        $result = [];
        for ($index = 0; $index < $length; $index++) {
            $key = $this->readValue($depth + 1);
            if (!is_int($key) && !is_string($key)) {
                throw new \RuntimeException('群晖归档包含不支持的 MessagePack 映射键');
            }
            $result[$key] = $this->readValue($depth + 1);
        }
        return $result;
    }

    private function readUnsigned(int $bytes): int
    {
        $raw = $this->readBytes($bytes);
        $value = 0;
        for ($index = 0; $index < $bytes; $index++) {
            if ($value > intdiv(PHP_INT_MAX - ord($raw[$index]), 256)) {
                throw new \RuntimeException('MessagePack 整数超出当前 PHP 版本的处理范围');
            }
            $value = ($value * 256) + ord($raw[$index]);
        }
        return $value;
    }

    private function readUnsigned64(): int
    {
        return $this->readUnsigned(8);
    }

    private function readBytes(int $length): string
    {
        if ($length < 0 || $length > self::MAX_BINARY_SIZE || $this->offset > strlen($this->data) - $length) {
            throw new \RuntimeException('群晖归档的 MessagePack 头部不完整或超过大小限制');
        }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    private function assertCollectionLength(int $length): void
    {
        if ($length < 0 || $length > self::MAX_ITEMS) {
            throw new \RuntimeException('群晖归档的 MessagePack 集合超过数量限制');
        }
    }
}
