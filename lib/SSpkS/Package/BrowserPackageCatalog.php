<?php

namespace SSpkS\Package;

use SSpkS\Config;
use SSpkS\Language;
use SSpkS\Output\UrlFixer;
use think\facade\Cache;

final class BrowserPackageCatalog
{
    private Config $config;
    private string $language;
    private bool $downloadEnabled;
    private BrowserImageObfuscator $imageObfuscator;
    private BrowserDownloadResolver $downloadResolver;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->language = Language::getInstance($config)->code();
        $this->downloadEnabled = !empty($config->browser_download['enabled']);
        $this->imageObfuscator = new BrowserImageObfuscator($config);
        $this->downloadResolver = new BrowserDownloadResolver($config);
    }

    public function getAll(bool $refresh = false): array
    {
        return $this->load(null, $refresh);
    }

    public function getForArchitecture(string $architecture): array
    {
        return $this->load($architecture, false);
    }

    public function getImageFailureCount(): int
    {
        return $this->imageObfuscator->getFailureCount();
    }

    private function load(?string $architecture, bool $refresh): array
    {
        $scope = $architecture === null ? 'all' : strtolower($architecture);
        $cacheKey = 'browser_catalog_v20_' . md5(
            $scope . '|' . $this->language . '|' . (int) $this->downloadEnabled
            . '|' . (int) $this->config->browser_url_obfuscation['package_images']
            . '|' . (int) $this->config->browser_url_obfuscation['spk_downloads']
            . '|' . (int) $this->config->appearance['show_runtime_badges']
        );

        if (!$refresh) {
            try {
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
            } catch (\Throwable $e) {
                error_log('[SSpkS] Failed to read the browser package catalog cache: ' . $e->getMessage());
            }
        }

        $filter = new PackageFilter($this->config);
        if ($architecture !== null) {
            $filter->setArchitectureFilter($architecture);
        }
        $filter->setOsVersionFilter(null);
        $filter->setOldVersionFilter(true);
        $packageList = $filter->getFilteredPackageList();

        (new UrlFixer($this->config->baseUrl))->fixPackageList($packageList);

        $sorted = [];
        foreach ($packageList as $package) {
            $metadata = $this->present($package->getMetadata());
            $sortKey = ($metadata['displayname'] ?? '') . "\0" . ($metadata['package'] ?? '');
            $sorted[$sortKey] = $metadata;
        }
        ksort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        $packages = array_values($sorted);

        try {
            Cache::set($cacheKey, $packages, 300);
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to write the browser package catalog cache: ' . $e->getMessage());
        }
        return $packages;
    }

    private function present(array $metadata): array
    {
        $localizedKey = 'description_' . $this->language;
        if (!empty($metadata[$localizedKey])) {
            $metadata['description'] = $metadata[$localizedKey];
        }
        $metadata['runtimeBadges'] = !empty($this->config->appearance['show_runtime_badges'])
            ? $this->runtimeBadges($metadata['install_dep_packages'] ?? '')
            : [];
        $metadata['hasRuntimeBadges'] = $metadata['runtimeBadges'] !== [];
        if (!empty($this->config->browser_url_obfuscation['package_images'])) {
            $thumbnailPaths = array_slice((array) ($metadata['thumbnail'] ?? []), 0, 1);
            $metadata['thumbnail_url'] = $this->imageObfuscator->publishUrls($thumbnailPaths);
        }

        $maintainer = trim((string) ($metadata['maintainer'] ?? ''));
        if ($maintainer === '') {
            $maintainer = trim((string) ($this->config->packages['maintainer'] ?? ''));
        }
        $metadata['maintainer'] = $maintainer !== '' ? $maintainer : '—';
        $metadata['packageMaintainer'] = $metadata['maintainer'];
        $maintainerUrl = trim((string) ($metadata['maintainer_url'] ?? ''));
        if ($maintainerUrl === '') {
            $maintainerUrl = trim((string) ($this->config->packages['maintainer_url'] ?? ''));
        }
        $metadata['maintainerUrl'] = $this->safeHttpUrl($maintainerUrl);

        $size = max(0, (int) ($metadata['filesize'] ?? 0));
        $metadata['fileSizeLabel'] = $this->formatBytes($size);
        $downloadUrl = trim((string) ($metadata['spk_url'] ?? ''));
        $metadata['downloadEnabled'] = $this->downloadEnabled && $downloadUrl !== '';
        if ($metadata['downloadEnabled']) {
            if (!empty($this->config->browser_url_obfuscation['spk_downloads'])) {
                $obfuscatedUrl = $this->downloadResolver->urlForMd5((string) ($metadata['md5'] ?? ''));
                if ($obfuscatedUrl === '') {
                    // Never expose the real path when obfuscation fails.
                    $metadata['downloadEnabled'] = false;
                    unset($metadata['downloadUrl']);
                } else {
                    $metadata['downloadUrl'] = $obfuscatedUrl;
                }
            } else {
                $metadata['downloadUrl'] = $downloadUrl;
            }
        }
        // Templates must never retain real SPK URLs.
        unset($metadata['spk_url']);
        if (!$metadata['downloadEnabled']) {
            unset($metadata['downloadUrl']);
        }
        return $metadata;
    }

    private function runtimeBadges($dependencies): array
    {
        if (is_array($dependencies)) {
            $dependencies = implode(':', array_map('strval', $dependencies));
        }
        if (!is_scalar($dependencies)) {
            return [];
        }
        $dependencies = trim((string) $dependencies);
        if ($dependencies === '') {
            return [];
        }

        $types = [
            ['pattern' => '/(?:^|[^a-z0-9])(?:containermanager|docker)(?=$|[^a-z0-9])/i', 'type' => 'docker', 'label' => 'Docker'],
            ['pattern' => '/(?:^|[^a-z0-9])(?:openjdk|java)(?:[._-]?\d+)?(?=$|[^a-z0-9])/i', 'type' => 'java', 'label' => 'OpenJDK', 'icon' => 'duke.svg'],
            ['pattern' => '/(?:^|[^a-z0-9])python(?:[._-]?\d+)?(?=$|[^a-z0-9])/i', 'type' => 'python', 'label' => 'Python'],
            ['pattern' => '/(?:^|[^a-z0-9])node(?:\.?js)?(?:[._-]?v?\d+)?(?=$|[^a-z0-9])/i', 'type' => 'nodejs', 'label' => 'Node.js'],
            ['pattern' => '/(?:^|[^a-z0-9])php(?:[._-]?\d+(?:\.\d+)?)?(?=$|[^a-z0-9])/i', 'type' => 'php', 'label' => 'PHP'],
        ];
        $badges = [];
        foreach ($types as $type) {
            if (preg_match($type['pattern'], $dependencies) !== 1) {
                continue;
            }
            $badges[] = [
                'type' => $type['type'],
                'label' => $type['label'],
                'title' => 'Powered by ' . $type['label'],
                'icon' => $type['icon'] ?? $type['type'] . '.svg',
            ];
        }
        return $badges;
    }

    private function safeHttpUrl(string $url): string
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes;
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2)) . ' ' . $unit;
            }
        }
        return $bytes . ' B';
    }
}
