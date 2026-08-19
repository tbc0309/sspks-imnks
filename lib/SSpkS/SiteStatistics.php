<?php

namespace SSpkS;

use SSpkS\Device\DeviceList;
use SSpkS\Package\BrowserPackageCatalog;
use think\facade\Cache;

final class SiteStatistics
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @return array{models: int, packages: int} */
    public function get(): array
    {
        $cacheKey = 'site_statistics_v2';
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['models'], $cached['packages'])) {
                return [
                    'models' => max(0, (int) $cached['models']),
                    'packages' => max(0, (int) $cached['packages']),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the site statistics cache: ' . $e->getMessage());
        }

        $statistics = [
            'models' => count((new DeviceList($this->config))->getDevices($this->config->models['priority_models'])),
            'packages' => count((new BrowserPackageCatalog($this->config))->getAll()),
        ];
        try {
            Cache::set($cacheKey, $statistics, 300);
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to write the site statistics cache: ' . $e->getMessage());
        }
        return $statistics;
    }
}
