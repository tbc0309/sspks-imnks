<?php

namespace SSpkS\Package;

use SSpkS\Config;
use think\facade\Db;

final class BrowserDownloadResolver
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{12}$/';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function urlForMd5(string $md5): string
    {
        $token = $this->tokenFromMd5($md5);
        return $token === '' ? '' : $this->config->baseUrlRelative . '?download=' . $token;
    }

    public function isValidToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** @return array{filename:string,internalUri:string}|null */
    public function resolve(string $token): ?array
    {
        if (!$this->isValidToken($token)) {
            return null;
        }

        foreach (Db::name('Spk')->field('spk,md5')->order('id desc')->cursor() as $row) {
            $md5 = strtolower(trim((string) ($row['md5'] ?? '')));
            if (!hash_equals($token, $this->tokenFromMd5($md5))) {
                continue;
            }

            $resolved = $this->resolvePackageFile((string) ($row['spk'] ?? ''));
            if ($resolved !== null) {
                return $resolved;
            }
        }
        return null;
    }

    private function tokenFromMd5(string $md5): string
    {
        $md5 = strtolower(trim($md5));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            return '';
        }
        $digest = hash('sha256', "sspks-browser-download\0" . $md5, true);
        return rtrim(strtr(base64_encode(substr($digest, 0, 9)), '+/', '-_'), '=');
    }

    /** @return array{filename:string,internalUri:string}|null */
    private function resolvePackageFile(string $relativePath): ?array
    {
        if ($relativePath === '' || strpos($relativePath, "\0") !== false) {
            return null;
        }
        $packageDirectory = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $this->config->paths['packages']), DIRECTORY_SEPARATOR);
        $packageRoot = realpath($this->config->basePath . DIRECTORY_SEPARATOR . $packageDirectory);
        $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $isAbsolute = preg_match('/^(?:[a-z]:[\\\\\/]|[\\\\\/]{1,2})/i', $normalised) === 1;
        $candidate = $isAbsolute ? $normalised : $this->config->basePath . DIRECTORY_SEPARATOR . ltrim($normalised, DIRECTORY_SEPARATOR);
        $absolute = realpath($candidate);
        if ($packageRoot === false
            || $absolute === false
            || !is_file($absolute)
            || strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION)) !== 'spk'
            || !$this->isInside($absolute, $packageRoot)) {
            return null;
        }

        $filename = basename($absolute);
        return [
            'filename' => $filename,
            'internalUri' => '/_sspks_download/' . rawurlencode($filename),
        ];
    }

    private function isInside(string $path, string $root): bool
    {
        $pathForComparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $rootForComparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($rootPrefix) : $rootPrefix;
        return strpos($pathForComparison, $rootForComparison) === 0;
    }
}
