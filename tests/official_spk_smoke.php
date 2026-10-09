<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use SSpkS\Package\SynologyArchiveReader;

if ($argc < 2) {
    fwrite(STDERR, "用法：php official_spk_smoke.php <spk> [<spk> ...]\n");
    exit(2);
}

foreach (array_slice($argv, 1) as $filename) {
    $archive = new SynologyArchiveReader($filename);
    $entries = $archive->listEntries();
    $required = ['INFO', 'PACKAGE_ICON.PNG', 'PACKAGE_ICON_256.PNG'];
    foreach ($required as $entry) {
        if (!isset($entries[$entry])) {
            throw new RuntimeException(basename($filename) . '：缺少 ' . $entry);
        }
    }
    $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sspks-official-test-' . bin2hex(random_bytes(8));
    if (!mkdir($temporary, 0700)) {
        throw new RuntimeException('无法创建测试目录');
    }
    try {
        foreach ($required as $entry) {
            $target = $temporary . DIRECTORY_SEPARATOR . $entry;
            $archive->extractEntry($entry, $target);
            if (!is_file($target) || filesize($target) !== $entries[$entry]['size']) {
                throw new RuntimeException(basename($filename) . '：已提取文件大小不匹配：' . $entry);
            }
        }
        $info = file_get_contents($temporary . DIRECTORY_SEPARATOR . 'INFO');
        if ($info !== $archive->getHeaderInfo()) {
            throw new RuntimeException(basename($filename) . '：头部 INFO 与加密 INFO 内容不一致');
        }
        foreach (['PACKAGE_ICON.PNG', 'PACKAGE_ICON_256.PNG'] as $icon) {
            $signature = file_get_contents($temporary . DIRECTORY_SEPARATOR . $icon, false, null, 0, 8);
            if ($signature !== "\x89PNG\r\n\x1a\n") {
                throw new RuntimeException(basename($filename) . '：已提取图标不是 PNG：' . $icon);
            }
        }
    } finally {
        foreach ($required as $entry) {
            @unlink($temporary . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($temporary);
    }
    $tampered = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sspks-tampered-' . bin2hex(random_bytes(8)) . '.spk';
    try {
        if (!copy($filename, $tampered)) { throw new RuntimeException('Cannot copy the signature test fixture'); }
        $handle = fopen($tampered, 'r+b');
        try {
            $prefix = fread($handle, 8);
            $headerLength = unpack('Vlength', substr($prefix, 4, 4))['length'];
            $offset = 8 + $headerLength;
            fseek($handle, $offset);
            $byte = fgetc($handle);
            fseek($handle, $offset);
            fwrite($handle, chr(ord($byte) ^ 1));
        } finally { fclose($handle); }
        $rejected = false;
        try { new SynologyArchiveReader($tampered); } catch (RuntimeException $e) { $rejected = true; }
        if (!$rejected) { throw new RuntimeException('Tampered official archive signature was accepted'); }
    } finally { @unlink($tampered); }
    printf(
        "%s\t%d 个条目\t%s\n",
        basename($filename),
        count($entries),
        $archive->hasDirectory('WIZARD_UIFILES') ? '包含安装向导' : '无安装向导'
    );
}
