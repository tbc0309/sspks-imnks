<?php

namespace SSpkS\Package;

/**
 * 用于按需读取群晖官方加密 SPK 归档。
 */
final class SynologyArchiveReader
{
    private const MAX_HEADER_SIZE = 16 * 1024 * 1024;
    private const MAX_ENTRIES = 10000;
    private const TAR_BLOCK_SIZE = 512;
    private const CIPHER_HEADER_SIZE = 24;
    private const ENCRYPTED_TAR_HEADER_SIZE = 403;
    private const CONTENT_CHUNK_SIZE = 4 * 1024 * 1024;
    private const AUTH_BYTES = 17;
    private const MAX_EXTRACT_SIZE = 20 * 1024 * 1024;

    // Synology SPK key type 3, shared by both editions.
    private const SIGNING_PUBLIC_KEY_HEX = 'FECAA2DD065A86A68E5FE86BA34CD8481590A79FA2C29A7D69F25A3B3BFAA19E';
    private const MASTER_KEY_HEX = 'CF0D8D6ECB95EF97D0AC6A7021D99124C699808CF2CC5157DFEA5EBF15C805E7';

    private string $filename;
    /** @var resource */
    private $handle;
    private int $fileSize;
    private string $header;
    private string $headerInfo;
    private string $key;
    /** @var array<int,array{offset:int,length:int,hash:string}> */
    private array $encryptedEntries = [];
    /** @var null|array<string,array{offset:int,size:int,type:string}> */
    private ?array $entries = null;

    public static function supports(string $filename): bool
    {
        $handle = @fopen($filename, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $magic = fread($handle, 4);
            return is_string($magic)
                && strlen($magic) === 4
                && substr($magic, 1, 3) === "\xAD\xBE\xEF";
        } finally {
            fclose($handle);
        }
    }

    public function __construct(string $filename)
    {
        $this->assertSodiumAvailable();
        $this->filename = $filename;
        $size = @filesize($filename);
        if (!is_int($size) || $size < 8 + 64) {
            throw new \RuntimeException('群晖归档长度过短：' . basename($filename));
        }
        $this->fileSize = $size;
        $this->handle = @fopen($filename, 'rb');
        if ($this->handle === false) {
            throw new \RuntimeException('无法打开群晖归档：' . basename($filename));
        }

        try {
            $this->initialise();
        } catch (\Throwable $error) {
            fclose($this->handle);
            throw $error;
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function getHeaderInfo(): string
    {
        return $this->headerInfo;
    }

    /** @return array<string,array{offset:int,size:int,type:string}> */
    public function listEntries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        foreach ($this->encryptedEntries as $encryptedEntry) {
            $header = $this->decryptTarHeader($encryptedEntry['offset']);
            $tarEntry = $this->parseTarHeader($header);
            $name = $tarEntry['name'];
            if (isset($entries[$name])) {
                throw new \RuntimeException('群晖归档包含重复条目：' . $name);
            }
            $entries[$name] = [
                'offset' => $encryptedEntry['offset'],
                'size' => $tarEntry['size'],
                'type' => $tarEntry['type'],
            ];
        }
        $this->entries = $entries;
        return $entries;
    }

    public function hasEntry(string $name): bool
    {
        return isset($this->listEntries()[$name]);
    }

    public function hasDirectory(string $name): bool
    {
        $prefix = rtrim($name, '/') . '/';
        foreach ($this->listEntries() as $entryName => $entry) {
            if ($entryName === rtrim($name, '/') || strpos($entryName, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    public function extractEntry(string $name, string $targetFile): bool
    {
        $entries = $this->listEntries();
        if (!isset($entries[$name])) {
            throw new \RuntimeException('群晖归档中找不到条目：' . $name);
        }
        $entry = $entries[$name];
        if ($entry['type'] !== '0' && $entry['type'] !== "\0") {
            throw new \RuntimeException('群晖归档条目不是普通文件：' . $name);
        }
        if ($entry['size'] < 0 || $entry['size'] > self::MAX_EXTRACT_SIZE) {
            throw new \RuntimeException('群晖归档条目过大，无法缓存：' . $name);
        }

        $directory = dirname($targetFile);
        if (!is_dir($directory) && !mkdir($directory, 0770, true)) {
            throw new \RuntimeException('无法创建套件缓存目录');
        }
        $temporary = $targetFile . '.tmp-' . bin2hex(random_bytes(8));
        $output = @fopen($temporary, 'xb');
        if ($output === false) {
            throw new \RuntimeException('无法创建临时缓存文件');
        }

        $completed = false;
        try {
            $this->seek($entry['offset'] + self::TAR_BLOCK_SIZE);
            $remaining = $entry['size'];
            $written = 0;
            $state = null;
            if ($remaining > 0) {
                $cipherHeader = $this->readExact(self::CIPHER_HEADER_SIZE, '条目内容头部');
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($cipherHeader, $this->key);
            }
            while ($remaining > 0) {
                $plainLength = min(self::CONTENT_CHUNK_SIZE, $remaining);
                $ciphertext = $this->readExact($plainLength + self::AUTH_BYTES, '条目内容');
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
                if ($result === false || !isset($result[0]) || strlen($result[0]) !== $plainLength) {
                    throw new \RuntimeException('群晖加密归档条目认证失败：' . $name);
                }
                if (fwrite($output, $result[0]) !== $plainLength) {
                    throw new \RuntimeException('无法写入已提取的群晖归档条目：' . $name);
                }
                $written += $plainLength;
                $remaining -= $plainLength;
            }
            if ($written !== $entry['size']) {
                throw new \RuntimeException('已提取的群晖归档条目大小不匹配：' . $name);
            }
            $completed = true;
        } finally {
            fclose($output);
            if (!$completed) {
                @unlink($temporary);
            }
        }

        if (!@rename($temporary, $targetFile)) {
            @unlink($temporary);
            throw new \RuntimeException('无法保存已提取的群晖归档条目：' . $name);
        }
        return true;
    }

    private function initialise(): void
    {
        $this->seek(0);
        $prefix = $this->readExact(8, '归档头部');
        if (substr($prefix, 1, 3) !== "\xAD\xBE\xEF") {
            throw new \RuntimeException('群晖归档标识无效');
        }
        $headerLength = unpack('Vlength', substr($prefix, 4, 4))['length'];
        if (!is_int($headerLength) || $headerLength <= 0 || $headerLength > self::MAX_HEADER_SIZE
            || 8 + $headerLength + 64 > $this->fileSize) {
            throw new \RuntimeException('群晖归档头部长度无效');
        }

        $this->header = $this->readExact($headerLength, 'MessagePack 头部');
        $signature = $this->readExact(64, '头部签名');
        $signingKey = $this->hexToBytes(self::SIGNING_PUBLIC_KEY_HEX);
        if (!sodium_crypto_sign_verify_detached($signature, $this->header, $signingKey)) {
            throw new \RuntimeException('群晖 SPK 头部签名验证失败');
        }

        $decoded = BoundedMessagePackReader::decode($this->header);
        if (!is_array($decoded) || count($decoded) < 3 || !is_string($decoded[0])
            || !is_array($decoded[1]) || !is_string($decoded[2])) {
            throw new \RuntimeException('群晖 SPK 的 MessagePack 结构无效');
        }
        if (strlen($decoded[0]) < 32 || count($decoded[1]) > self::MAX_ENTRIES) {
            throw new \RuntimeException('群晖 SPK 密钥数据或条目数量无效');
        }
        $this->headerInfo = $decoded[2];
        $this->key = $this->deriveKey($decoded[0]);

        $offset = 8 + $headerLength + 64;
        foreach ($decoded[1] as $index => $record) {
            if (!is_array($record) || count($record) !== 2 || !is_int($record[0])
                || $record[0] < self::TAR_BLOCK_SIZE || !is_string($record[1]) || strlen($record[1]) !== 32) {
                throw new \RuntimeException('群晖 SPK 第 ' . $index . ' 个条目记录无效');
            }
            if ($offset > $this->fileSize - $record[0]) {
                throw new \RuntimeException('群晖 SPK 条目超出归档大小');
            }
            $this->encryptedEntries[] = ['offset' => $offset, 'length' => $record[0], 'hash' => $record[1]];
            $offset += $record[0];
        }
        if ($offset !== $this->fileSize) {
            throw new \RuntimeException('群晖 SPK 条目总长度与归档大小不匹配');
        }
    }

    private function deriveKey(string $data): string
    {
        $subkeyBytes = substr($data, 16, 8);
        if (strlen($subkeyBytes) !== 8) {
            throw new \RuntimeException('群晖 SPK 密钥标识无效');
        }
        $context = substr($data, 24, 7) . "\0";
        return SynologyKdf::derive($subkeyBytes, $context, $this->hexToBytes(self::MASTER_KEY_HEX));
    }

    private function decryptTarHeader(int $offset): string
    {
        $this->seek($offset);
        $cipherHeader = $this->readExact(self::CIPHER_HEADER_SIZE, '加密 TAR 头部随机数');
        $ciphertext = $this->readExact(self::ENCRYPTED_TAR_HEADER_SIZE, '加密 TAR 头部');
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($cipherHeader, $this->key);
        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
        if ($result === false || !isset($result[0]) || strlen($result[0]) !== 386) {
            throw new \RuntimeException('无法解密群晖 SPK 的 TAR 头部');
        }
        return str_pad($result[0], self::TAR_BLOCK_SIZE, "\0");
    }

    /** @return array{name:string,size:int,type:string} */
    private function parseTarHeader(string $header): array
    {
        if (substr($header, 257, 5) !== 'ustar') {
            throw new \RuntimeException('已解密的群晖 SPK 条目不是 TAR 头部');
        }
        $this->assertTarChecksum($header);
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");
        if ($prefix !== '') {
            $name = $prefix . '/' . $name;
        }
        $name = str_replace('\\', '/', $name);
        if ($name === '' || $name[0] === '/' || preg_match('~(?:^|/)\.\.(?:/|$)~', $name)) {
            throw new \RuntimeException('群晖 SPK 的 TAR 头部包含不安全路径');
        }
        return [
            'name' => $name,
            'size' => $this->parseTarOctal(substr($header, 124, 12)),
            'type' => $header[156] ?? "\0",
        ];
    }

    private function assertTarChecksum(string $header): void
    {
        $expected = $this->parseTarOctal(substr($header, 148, 8));
        $checkHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $actual = array_sum(unpack('C*', $checkHeader));
        if ($expected !== $actual) {
            throw new \RuntimeException('已解密的群晖 SPK TAR 头部校验和无效');
        }
    }

    private function parseTarOctal(string $value): int
    {
        $value = trim($value, " \0");
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-7]+$/D', $value) !== 1) {
            throw new \RuntimeException('已解密的群晖 SPK TAR 头部包含无效八进制值');
        }
        $result = octdec($value);
        if (!is_int($result) || $result < 0) {
            throw new \RuntimeException('已解密的群晖 SPK TAR 头部数值过大');
        }
        return $result;
    }

    private function readExact(int $length, string $description): string
    {
        if ($length < 0) {
            throw new \RuntimeException($description . ' 的读取长度无效');
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->handle, min(1024 * 1024, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('读取' . $description . '时群晖归档意外结束');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function seek(int $offset): void
    {
        if ($offset < 0 || $offset > $this->fileSize || fseek($this->handle, $offset, SEEK_SET) !== 0) {
            throw new \RuntimeException('无法定位群晖归档的读取位置');
        }
    }

    private function hexToBytes(string $hex): string
    {
        $value = hex2bin($hex);
        if ($value === false || strlen($value) !== 32) {
            throw new \RuntimeException('群晖 SPK 密钥配置无效');
        }
        return $value;
    }

    private function assertSodiumAvailable(): void
    {
        $functions = [
            'sodium_crypto_sign_verify_detached',
            'sodium_crypto_secretstream_xchacha20poly1305_init_pull',
            'sodium_crypto_secretstream_xchacha20poly1305_pull',
        ];
        foreach ($functions as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('解析群晖官方 SPK 需要启用 PHP ext-sodium 扩展：' . $function);
            }
        }
    }
}
