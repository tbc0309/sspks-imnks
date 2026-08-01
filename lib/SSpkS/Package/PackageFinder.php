<?php

namespace SSpkS\Package;

use SSpkS\Config;

final class PackageFinder
{
    private Config $config;
    private string $pattern;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $folder = rtrim((string) $config->paths['packages'], '/\\');
        if (!is_dir($folder)) {
            throw new \RuntimeException('套件路径不是目录：' . $folder);
        }
        $this->pattern = $folder . DIRECTORY_SEPARATOR . (string) $config->packages['file_mask'];
    }

    public function getAllPackageFiles(): array
    {
        $files = glob($this->pattern);
        if (!is_array($files)) {
            return [];
        }
        natcasesort($files);
        return array_values($files);
    }

    /** @return Package[] */
    public function getAllFilePackages(): array
    {
        $packages = [];
        foreach ($this->getAllPackageFiles() as $file) {
            try {
                $packages[] = new Package($this->config, $file);
            } catch (\Throwable $e) {
                error_log('[SSpkS] Ignored invalid package ' . basename($file) . ': ' . $e->getMessage());
            }
        }
        return $packages;
    }
}
