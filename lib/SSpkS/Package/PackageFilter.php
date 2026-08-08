<?php

namespace SSpkS\Package;

use SSpkS\Config;
use SSpkS\Device\DeviceList;
use think\facade\Db;

final class PackageFilter
{
    private Config $config;
    /** @var string[]|null */
    private ?array $filterArch = null;
    private ?string $filterModel = null;
    private ?string $filterOsVersion = null;
    private ?string $filterChannel = null;
    private bool $filterOldVersions = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function setArchitectureFilter(string $architecture): void
    {
        try {
            $family = (new DeviceList($this->config))->getFamily($architecture);
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to query architecture families: ' . $e->getMessage());
            $family = $architecture;
        }
        $this->filterArch = array_values(array_unique([$architecture, $family]));
    }

    public function setModelFilter(string $unique): void
    {
        $this->filterModel = strtolower($unique);
    }

    public function setOsVersionFilter(?string $version): void
    {
        $this->filterOsVersion = $version;
    }

    public function setChannelFilter(string $channel): void
    {
        if (!in_array($channel, ['stable', 'beta'], true)) {
            throw new \InvalidArgumentException('Unsupported package channel');
        }
        $this->filterChannel = $channel;
    }

    public function setOldVersionFilter(bool $enabled): void
    {
        $this->filterOldVersions = $enabled;
    }

    private function isMatchingArchitecture(Package $package): bool
    {
        if ($this->filterArch === null) {
            return true;
        }

        $excludedArchitectures = $this->normaliseList($package->exclude_arch);
        if (array_intersect($this->filterArch, $excludedArchitectures) !== []) {
            return false;
        }

        $packageArchitectures = $this->normaliseList($package->arch);
        return in_array('noarch', $packageArchitectures, true)
            || array_intersect($this->filterArch, $packageArchitectures) !== [];
    }

    private function isMatchingModel(Package $package): bool
    {
        if ($this->filterModel === null) {
            return true;
        }

        $excludedModels = array_map('strtolower', $this->normaliseList($package->exclude_model));
        if (in_array($this->filterModel, $excludedModels, true)) {
            return false;
        }

        $models = array_map('strtolower', $this->normaliseList($package->model));
        return $models === [] || in_array($this->filterModel, $models, true);
    }

    private function isMatchingOsVersion(Package $package): bool
    {
        if ($this->filterOsVersion === null) {
            return true;
        }

        $minimumVersion = trim((string) $package->os_min_ver);
        if ($minimumVersion === ''
            || Package::compareDsmVersions($minimumVersion, '7.0-40000') < 0
            || Package::compareDsmVersions($minimumVersion, $this->filterOsVersion) > 0) {
            return false;
        }

        $maximumVersion = trim((string) $package->os_max_ver);
        return $maximumVersion === ''
            || Package::compareDsmVersions($maximumVersion, $this->filterOsVersion) >= 0;
    }

    private function normaliseList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), 'strlen'));
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function isDsm7Package(Package $package): bool
    {
        $minimumVersion = trim((string) $package->os_min_ver);
        return $minimumVersion !== ''
            && Package::compareDsmVersions($minimumVersion, '7.0-40000') >= 0
            && strpos($minimumVersion, '7.') === 0;
    }

    /** @return Package[] */
    public function getFilteredPackageList(): array
    {
        $query = Db::name('Spk')->field('id,params')->order('id desc');
        if ($this->filterChannel === 'stable') {
            $query->where('beta', 0);
        }

        $result = [];
        foreach ($query->cursor() as $row) {
            try {
                $package = $this->unserializePackage((string) ($row['params'] ?? ''));
                if (!$this->isDsm7Package($package)
                    || !$this->isMatchingArchitecture($package)
                    || !$this->isMatchingModel($package)
                    || !$this->isMatchingOsVersion($package)) {
                    continue;
                }

                if (!$this->filterOldVersions) {
                    $result[] = $package;
                    continue;
                }

                $packageName = (string) $package->package;
                if (!isset($result[$packageName]) || Package::comparePackageVersions((string) $package->version, (string) $result[$packageName]->version) > 0) {
                    $result[$packageName] = $package;
                }
            } catch (\Throwable $e) {
                error_log('[SSpkS] Skipped corrupt package record ' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        return $this->filterOldVersions ? array_values($result) : $result;
    }

    private function unserializePackage(string $serialized): Package
    {
        $package = @unserialize($serialized, ['allowed_classes' => [Package::class]]);
        if (!$package instanceof Package) {
            throw new \UnexpectedValueException('Invalid serialized package metadata');
        }
        return $package;
    }
}
