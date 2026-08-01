<?php

namespace SSpkS\Handler;

use SSpkS\Device\DeviceList;
use SSpkS\Output\JsonOutput;
use SSpkS\Output\UrlFixer;
use SSpkS\Package\PackageFilter;
use think\facade\Cache;

final class SynologyHandler extends AbstractHandler
{
    private const RESPONSE_CACHE_TTL = 3600;
    private const RESPONSE_CACHE_SLOTS = 128;

    public function canHandle(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && isset($_GET['unique'])
            && is_string($_GET['unique'])
            && strpos($_GET['unique'], 'synology_') === 0;
    }

    public function handle(): void
    {
        $unique = $this->queryString('unique');
        $arch = $this->queryString('arch');
        $major = $this->queryString('major');
        $minor = $this->queryString('minor');
        $build = $this->queryString('build');
        $channel = $this->queryString('package_update_channel', 'stable');
        $language = $this->queryString('language');

        if ($unique === null
            || $arch === null
            || $major === null
            || $minor === null
            || $build === null
            || $channel === null
            || $language === null) {
            $this->invalidRequest();
            return;
        }

        $unique = trim(str_replace(' ', '+', $unique));
        $arch = trim($arch);
        $major = trim($major);
        $minor = trim($minor);
        $build = trim($build);
        $channel = trim($channel);
        $language = trim($language);

        if (strlen($unique) > 200
            || preg_match('/^synology_[a-z0-9_]+_[a-z0-9+._-]+$/iD', $unique) !== 1
            || strlen($arch) > 64
            || preg_match('/^[a-z0-9_]+$/iD', $arch) !== 1
            || stripos($unique, 'synology_' . $arch . '_') !== 0
            || preg_match('/^\d{1,2}$/D', $major) !== 1
            || (int) $major < 7
            || preg_match('/^\d{1,3}$/D', $minor) !== 1
            || preg_match('/^\d{1,10}$/D', $build) !== 1
            || (strlen(ltrim($build, '0')) === 10 && strcmp(ltrim($build, '0'), '2147483647') > 0)
            || !in_array($channel, ['stable', 'beta'], true)
            || ($language !== '' && preg_match('/^[a-z]{3}$/iD', $language) !== 1)) {
            $this->invalidRequest();
            return;
        }

        $unique = strtolower($unique);
        $arch = strtolower($arch);
        $language = strtolower($language);
        try {
            if (!(new DeviceList($this->config))->isKnownArchitecture($arch)) {
                throw new \InvalidArgumentException('未知的套件架构');
            }
        } catch (\Throwable $e) {
            $this->invalidRequest();
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        $firmwareVersion = $major . '.' . $minor . '-' . $build;
        $cacheSignature = $this->responseCacheSignature($unique, $arch, $firmwareVersion, $channel, $language);
        $cacheKey = 'synology_response_v1_' . (hexdec(substr($cacheSignature, 0, 2)) % self::RESPONSE_CACHE_SLOTS);
        $cachedResponse = $this->cachedResponse($cacheKey, $cacheSignature);
        if ($cachedResponse !== null) {
            echo $cachedResponse;
            return;
        }

        $filter = new PackageFilter($this->config);
        $filter->setArchitectureFilter($arch);
        $filter->setModelFilter($unique);
        $filter->setChannelFilter($channel);
        $filter->setOsVersionFilter($firmwareVersion);
        $filter->setOldVersionFilter(true);
        $packages = $filter->getFilteredPackageList();

        (new UrlFixer($this->config->baseUrl))->fixPackageList($packages);

        // DSM 7 Package Center does not use the beta field.
        $response = (new JsonOutput($this->config))->encodePackages($packages, $language, ['beta']);
        $this->cacheResponse($cacheKey, $cacheSignature, $response);
        echo $response;
    }

    private function responseCacheSignature(string $unique, string $arch, string $firmwareVersion, string $channel, string $language): string
    {
        $modelsFile = $this->config->basePath . DIRECTORY_SEPARATOR
            . ltrim((string) $this->config->paths['models'], '/\\');
        $jsonOutputFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Output' . DIRECTORY_SEPARATOR . 'JsonOutput.php';
        $signature = serialize([
            $unique,
            $arch,
            $firmwareVersion,
            $channel,
            $language,
            $this->config->baseUrl,
            $this->config->packages,
            is_file($modelsFile) ? (int) filemtime($modelsFile) : 0,
            (int) filemtime(__FILE__),
            is_file($jsonOutputFile) ? (int) filemtime($jsonOutputFile) : 0,
        ]);
        return hash('sha256', $signature);
    }

    private function cachedResponse(string $cacheKey, string $cacheSignature): ?string
    {
        try {
            $cached = Cache::get($cacheKey);
            if (!is_array($cached)
                || !isset($cached['signature'], $cached['response'])
                || !is_string($cached['signature'])
                || !is_string($cached['response'])
                || !hash_equals($cacheSignature, $cached['signature'])) {
                return null;
            }
            return $cached['response'] !== '' ? $cached['response'] : null;
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the Package Center response cache: ' . $e->getMessage());
            return null;
        }
    }

    private function cacheResponse(string $cacheKey, string $cacheSignature, string $response): void
    {
        try {
            Cache::set($cacheKey, ['signature' => $cacheSignature, 'response' => $response], self::RESPONSE_CACHE_TTL);
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to write the Package Center response cache: ' . $e->getMessage());
        }
    }

    private function invalidRequest(): void
    {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['error' => '套件中心请求无效'], JSON_UNESCAPED_UNICODE);
    }

    private function queryString(string $name, string $default = ''): ?string
    {
        if (!array_key_exists($name, $_GET)) {
            return $default;
        }
        return is_string($_GET[$name]) ? $_GET[$name] : null;
    }
}
