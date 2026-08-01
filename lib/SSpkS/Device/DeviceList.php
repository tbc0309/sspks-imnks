<?php

namespace SSpkS\Device;

use SSpkS\Config;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use think\facade\Cache;

final class DeviceList
{
    private string $yamlFilepath;
    private array $devices = [];
    private array $families = [];

    public function __construct(Config $config)
    {
        $relativePath = ltrim((string) $config->paths['models'], '/\\');
        $this->yamlFilepath = $config->basePath . DIRECTORY_SEPARATOR . $relativePath;
        if (!is_file($this->yamlFilepath)) {
            throw new \RuntimeException('找不到机型列表文件：' . $relativePath);
        }

        $mtime = filemtime($this->yamlFilepath);
        if ($mtime === false) {
            throw new \RuntimeException('无法读取机型列表的修改时间');
        }
        $cacheKey = 'devices_v2';

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)
                && (int) ($cached['mtime'] ?? -1) === $mtime
                && isset($cached['devices'], $cached['families'])
                && is_array($cached['devices'])
                && is_array($cached['families'])) {
                $this->devices = $cached['devices'];
                $this->families = $cached['families'];
                return;
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read the model cache: ' . $e->getMessage());
        }

        $this->parseYaml();
        try {
            Cache::set($cacheKey, [
                'mtime' => $mtime,
                'devices' => $this->devices,
                'families' => $this->families,
            ]);
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to write the model cache: ' . $e->getMessage());
        }
    }

    private function parseYaml(): void
    {
        try {
            $familyList = Yaml::parseFile($this->yamlFilepath);
        } catch (ParseException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if (!is_array($familyList)) {
            throw new \RuntimeException('机型列表结构无效');
        }

        foreach ($familyList as $family => $architectureList) {
            if (!is_array($architectureList)) {
                throw new \RuntimeException($family . ' 的架构列表必须为数组');
            }
            foreach ($architectureList as $architecture => $models) {
                if (!is_array($models)) {
                    throw new \RuntimeException($architecture . ' 的机型列表必须为数组');
                }
                $this->families[$architecture] = $family;
                foreach ($models as $model) {
                    $this->devices[] = [
                        'arch' => (string) $architecture,
                        'name' => (string) $model,
                        'family' => (string) $family,
                    ];
                }
            }
        }

        usort($this->devices, static function (array $left, array $right): int {
            return strnatcasecmp($left['name'], $right['name']);
        });
    }

    public function getFamily(string $architecture): string
    {
        return (string) ($this->families[$architecture] ?? $architecture);
    }

    public function isKnownArchitecture(string $architecture): bool
    {
        return isset($this->families[$architecture])
            || in_array($architecture, $this->families, true);
    }

    public function getDevices(array $priorityModels = []): array
    {
        if ($priorityModels === []) {
            return $this->devices;
        }

        $priorityOrder = [];
        foreach ($priorityModels as $model) {
            $key = strtolower(trim((string) $model));
            if ($key !== '' && !isset($priorityOrder[$key])) {
                $priorityOrder[$key] = count($priorityOrder);
            }
        }
        if ($priorityOrder === []) {
            return $this->devices;
        }

        $devices = [];
        foreach ($this->devices as $device) {
            $key = strtolower($device['name']);
            if (isset($priorityOrder[$key])) {
                $device['priority_rank'] = $priorityOrder[$key] + 1;
            }
            $devices[] = $device;
        }
        return $devices;
    }

    /** @return string[] */
    public function getKnownArchitectures(): array
    {
        $architectures = array_merge(array_keys($this->families), array_values($this->families));
        $architectures = array_values(array_unique(array_filter($architectures, 'is_string')));
        natcasesort($architectures);
        return array_values($architectures);
    }

    /** @return string[] */
    public function getModelsForArchitecture(string $architecture): array
    {
        $models = [];
        foreach ($this->devices as $device) {
            if ($device['arch'] === $architecture || $device['family'] === $architecture) {
                $models[] = $device['name'];
            }
        }
        $models = array_values(array_unique($models));
        natcasesort($models);
        return array_values($models);
    }
}
