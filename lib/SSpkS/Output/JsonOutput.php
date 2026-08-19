<?php

namespace SSpkS\Output;

use SSpkS\Config;
use SSpkS\Package\Package;

final class JsonOutput
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @return mixed */
    private function value(Package $package, string $property, $fallback = null)
    {
        $value = $package->$property;
        return $value === null || $value === '' ? $fallback : $value;
    }

    private function changelog(Package $package): string
    {
        $original = trim((string) $this->value($package, 'changelog', ''));
        if (empty($this->config->packages['changelog_ad_enabled'])) {
            return $original;
        }
        $addition = trim((string) ($this->config->packages['changelog_ad'] ?? ''));
        if ($addition === '') {
            return $original;
        }
        return $original === '' ? $addition : $original . '<br><br>' . $addition;
    }

    private function packageToJson(Package $package, string $language, array $hideKeys): array
    {
        $localizedDisplayName = $language === '' ? '' : 'displayname_' . $language;
        $localizedDescription = $language === '' ? '' : 'description_' . $language;

        $data = [
            'package' => $package->package,
            'version' => $package->version,
            'dname' => $localizedDisplayName === '' ? $package->displayname : $this->value($package, $localizedDisplayName, $package->displayname),
            'desc' => $localizedDescription === '' ? $package->description : $this->value($package, $localizedDescription, $package->description),
            'price' => 0,
            'download_count' => 2026,
            'recent_download_count' => 0,
            'link' => $package->spk_url,
            'size' => (int) ($package->filesize ?? 0),
            'md5' => (string) ($package->md5 ?? ''),
            'thumbnail' => (array) $package->thumbnail_url,
            'snapshot' => (array) $package->snapshot_url,
            'qinst' => (bool) $package->qinst,
            'qstart' => (bool) $package->qstart,
            'qupgrade' => (bool) $package->qupgrade,
            'depsers' => $this->value($package, 'start_dep_services'),
            'deppkgs' => $this->value($package, 'install_dep_packages'),
            'conflictpkgs' => $this->value($package, 'install_conflict_packages'),
            'start' => true,
            'maintainer' => $this->value($package, 'maintainer', $this->config->packages['maintainer']),
            'maintainer_url' => $this->value($package, 'maintainer_url', $this->config->packages['maintainer_url']),
            'distributor' => $this->value($package, 'distributor', $this->config->packages['distributor']),
            'distributor_url' => $this->value($package, 'distributor_url', $this->config->packages['distributor_url']),
            'support_url' => $this->value($package, 'support_url', $this->config->packages['support_url']),
            'changelog' => $this->changelog($package),
            'thirdparty' => true,
            'category' => 0,
            'subcategory' => 0,
            'type' => 0,
            'silent_install' => (bool) $this->value($package, 'silent_install', false),
            'silent_uninstall' => (bool) $this->value($package, 'silent_uninstall', false),
            'silent_upgrade' => (bool) $this->value($package, 'silent_upgrade', false),
            'auto_upgrade_from' => $this->value($package, 'auto_upgrade_from'),
            'beta' => (bool) $package->beta,
        ];

        foreach ($hideKeys as $key) {
            unset($data[$key]);
        }
        return $data;
    }

    /** @param Package[] $packages */
    public function encodePackages(array $packages, string $language = 'chs', ?array $hideKeys = null): string
    {
        $hideKeys ??= [];
        $output = ['packages' => []];
        foreach ($packages as $package) {
            $output['packages'][] = $this->packageToJson($package, strtolower($language), $hideKeys);
        }

        $json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode Package Center response');
        }
        return $json;
    }

    /** @param Package[] $packages */
    public function outputPackages(array $packages, string $language = 'chs', ?array $hideKeys = null): void
    {
        try {
            echo $this->encodePackages($packages, $language, $hideKeys);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo '{"packages":[]}';
        }
    }
}
