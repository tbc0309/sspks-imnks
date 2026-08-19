<?php

namespace SSpkS\Output;

use SSpkS\Package\Package;

final class UrlFixer
{
    private string $urlPrefix;

    public function __construct(string $urlPrefix)
    {
        $this->urlPrefix = rtrim($urlPrefix, '/') . '/';
    }

    public function fixPackage(Package $package): void
    {
        $package->spk_url = $this->makeAbsolute((string) $package->spk);
        $package->thumbnail_url = $this->fixPaths((array) $package->thumbnail);
        $package->snapshot_url = $this->fixPaths((array) $package->snapshot);
    }

    /** @param Package[] $packages */
    public function fixPackageList(array $packages): void
    {
        foreach ($packages as $package) {
            $this->fixPackage($package);
        }
    }

    public function makeAbsolute(string $path): string
    {
        return $this->urlPrefix . $this->encodePath(ltrim($path, '/\\'));
    }

    private function fixPaths(array $paths): array
    {
        return array_map(function ($path): string {
            return $this->makeAbsolute((string) $path);
        }, $paths);
    }

    private function encodePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
