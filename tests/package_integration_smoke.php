<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use SSpkS\Config;
use SSpkS\Package\Package;

if ($argc < 2) {
    fwrite(STDERR, "用法：php package_integration_smoke.php <spk> [<spk> ...]\n");
    exit(2);
}

$root = dirname(__DIR__);
$config = Config::getInstance($root, 'conf/sspks.yaml');

foreach (array_slice($argv, 1) as $filename) {
    $stem = pathinfo(basename($filename), PATHINFO_FILENAME);
    $cachePrefix = rtrim($config->paths['cache'], '/\\') . DIRECTORY_SEPARATOR . $stem;
    $cleanup = static function () use ($cachePrefix): void {
        foreach (glob($cachePrefix . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    };
    $cleanup();
    try {
        $package = new Package($config, $filename);
        $metadata = $package->getMetadata();
        foreach (['package', 'version', 'arch', 'description', 'thumbnail', 'snapshot'] as $field) {
            if (!array_key_exists($field, $metadata)) {
                throw new RuntimeException(basename($filename) . '：缺少元数据字段 ' . $field);
            }
        }
        if (count($metadata['thumbnail']) !== 2) {
            throw new RuntimeException(basename($filename) . '：应生成两种尺寸的缩略图');
        }
        printf("%s\t%s\t%s\n", basename($filename), $metadata['package'], $metadata['version']);
    } finally {
        $cleanup();
    }
}
