<?php

namespace SSpkS\Package;

use SSpkS\Config;

/**
 * @property string $spk
 * @property string $spk_url
 * @property string $displayname
 * @property string $package
 * @property string $version
 * @property string $description
 * @property string $maintainer
 * @property string $maintainer_url
 * @property string $distributor
 * @property string $distributor_url
 * @property string $support_url
 * @property array $arch
 * @property array $thumbnail
 * @property array $thumbnail_url
 * @property array $snapshot
 * @property array $snapshot_url
 * @property bool $beta
 * @property bool $run_as_root
 * @property string $os_min_ver
 * @property string $os_max_ver
 * @property array $exclude_arch
 * @property array $model
 * @property array $exclude_model
 * @property string $install_dep_services
 * @property bool $silent_install
 * @property bool $silent_uninstall
 * @property bool $silent_upgrade
 * @property string $auto_upgrade_from
 * @property string $install_dep_packages
 * @property bool $qinst
 * @property bool $qupgrade
 * @property bool $qstart
 */
class Package
{
    private Config $config;
    private string $filepath;
    private string $filepathNoExt;
    private string $filename;
    private string $filenameNoExt;
    private string $metafile;
    private string $wizfile;
    private string $nowizfile;
    private ?array $metadata = null;
    private bool $archiveValidated = false;

    public function __construct(Config $config, string $filename)
    {
        $this->config = $config;
        if (!preg_match('/\.spk$/i', $filename)) {
            throw new \Exception('文件不是 .spk 格式：' . $filename);
        }
        if (!file_exists($filename)) {
            throw new \Exception('找不到文件：' . $filename);
        }
        $this->filepath = $filename;
        $this->filename = basename($filename);
        $this->filenameNoExt = pathinfo($this->filename, PATHINFO_FILENAME);
        $this->filepathNoExt = $this->config->paths['cache'] . $this->filenameNoExt;
        $this->metafile = $this->filepathNoExt . '.nfo';
        $this->wizfile = $this->filepathNoExt . '.wiz';
        $this->nowizfile = $this->filepathNoExt . '.nowiz';
        if (!is_dir(dirname($this->metafile)) && !mkdir(dirname($this->metafile), 0770, true)) {
            throw new \RuntimeException('无法创建套件缓存目录');
        }
        $packageMtime = filemtime($this->filepath);
        if ($packageMtime === false) {
            throw new \RuntimeException('无法读取套件文件的修改时间');
        }
        if (file_exists($this->metafile) && filemtime($this->metafile) < $packageMtime) {
            $staleFiles = array_merge(
                [$this->metafile, $this->wizfile, $this->nowizfile],
                glob($this->filepathNoExt . '_thumb_*.png') ?: [],
                glob($this->filepathNoExt . '_screen_*.png') ?: []
            );
            foreach ($staleFiles as $staleFile) {
                if (is_file($staleFile)) {
                    @unlink($staleFile);
                }
            }
        }
        $this->collectMetadata();
    }

    public function __get(string $name)
    {
        return $this->metadata[$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        $this->metadata[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->metadata[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->metadata[$name]);
    }

    public function __sleep(): array
    {
        return ['metadata'];
    }

    public function parseBool($value): bool
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
        }
        return in_array($value, ['true', 'yes', '1', 1, true], true);
    }

    private function fixBoolIfExist(string $prop): void
    {
        if (isset($this->metadata[$prop])) {
            $this->metadata[$prop] = $this->parseBool($this->metadata[$prop]);
        }
    }

    private function collectMetadata(): void
    {
        if (!is_null($this->metadata)) {
            return;
        }
        $this->extractIfMissing('INFO', $this->metafile);
        $this->metadata = $this->parseInfoFile($this->metafile);

        $requiredFields = ['package', 'version', 'os_min_ver', 'description', 'arch', 'maintainer'];
        foreach ($requiredFields as $field) {
            if (!isset($this->metadata[$field]) || trim((string) $this->metadata[$field]) === '') {
                throw new \RuntimeException($this->filename . '：缺少 DSM 7 必需的 INFO 字段：' . $field);
            }
        }
        if (preg_match('/[:\/><|=]/', (string) $this->metadata['package'])) {
            throw new \RuntimeException($this->filename . '：package 字段包含禁止使用的字符');
        }
        if (!$this->isValidPackageVersion((string) $this->metadata['version'])) {
            throw new \RuntimeException($this->filename . '：DSM 套件版本格式无效');
        }
        if (!preg_match('/^[a-z0-9_]+(?:\s+[a-z0-9_]+)*$/i', trim((string) $this->metadata['arch']))) {
            throw new \RuntimeException($this->filename . '：架构列表格式无效');
        }
        $minimumDsmVersion = (string) $this->metadata['os_min_ver'];
        if (!self::isValidDsmVersion($minimumDsmVersion)
            || self::compareDsmVersions($minimumDsmVersion, '7.0-40000') < 0) {
            throw new \RuntimeException($this->filename . '：DSM 7 的 os_min_ver 不能低于 7.0-40000');
        }
        $maximumDsmVersion = trim((string) ($this->metadata['os_max_ver'] ?? ''));
        if ($maximumDsmVersion !== ''
            && (!self::isValidDsmVersion($maximumDsmVersion)
                || self::compareDsmVersions($maximumDsmVersion, $minimumDsmVersion) < 0)) {
            throw new \RuntimeException($this->filename . '：os_max_ver 格式无效或低于 os_min_ver');
        }
        if (!isset($this->metadata['displayname'])) {
            $this->metadata['displayname'] = $this->metadata['package'];
        }
        $this->metadata['spk'] = $this->filepath;

        $this->metadata['arch'] = $this->normaliseList((string) $this->metadata['arch'], true);
        foreach (['exclude_arch', 'model', 'exclude_model'] as $listField) {
            if (isset($this->metadata[$listField])) {
                $this->metadata[$listField] = $this->normaliseList((string) $this->metadata[$listField], true);
            }
        }

        $this->fixBoolIfExist('silent_install');
        $this->fixBoolIfExist('silent_uninstall');
        $this->fixBoolIfExist('silent_upgrade');

        $this->metadata['beta'] = $this->isBeta();
        $this->metadata['run_as_root'] = $this->runsAsRoot();

        $qValue = !$this->hasWizardDir();
        $this->metadata['thumbnail'] = $this->getThumbnails();
        $this->metadata['snapshot'] = $this->getSnapshots();
        foreach (['qinst', 'qupgrade', 'qstart'] as $quickProperty) {
            $this->metadata[$quickProperty] = !empty($this->metadata[$quickProperty]) ? $this->parseBool($this->metadata[$quickProperty]) : $qValue;
        }
    }

    /**
     * Preserve UTF-8 and backslashes while parsing INFO.
     * @return array<string,string>
     */
    private function parseInfoFile(string $filename): array
    {
        $content = file_get_contents($filename);
        if ($content === false) {
            throw new \RuntimeException('无法读取套件中的 INFO 文件：' . $this->filename);
        }

        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        if (strpos($content, "\0") !== false || preg_match('//u', $content) !== 1) {
            throw new \RuntimeException($this->filename . '：INFO 必须使用有效的 UTF-8 编码');
        }

        $metadata = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lineCount = count($lines);
        for ($lineNumber = 0; $lineNumber < $lineCount; $lineNumber++) {
            $line = $lines[$lineNumber];
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9_]*)\s*=(.*)$/u', $line, $matches)) {
                throw new \RuntimeException(sprintf('%s：INFO 第 %d 行语法无效', $this->filename, $lineNumber + 1));
            }
            $value = trim($matches[2]);
            $valueLineNumber = $lineNumber + 1;
            while (!$this->isCompleteQuotedInfoValue(rtrim($value)) && ++$lineNumber < $lineCount) {
                $value .= "\n" . $lines[$lineNumber];
            }
            $metadata[$matches[1]] = $this->decodeInfoValue(rtrim($value), $valueLineNumber);
        }

        if ($metadata === []) {
            throw new \RuntimeException('套件中的 INFO 文件无效：' . $this->filename);
        }
        return $metadata;
    }

    private function decodeInfoValue(string $value, int $lineNumber): string
    {
        if ($value === '' || ($value[0] !== '"' && $value[0] !== "'")) {
            return $value;
        }

        $quote = $value[0];
        if (strlen($value) < 2 || substr($value, -1) !== $quote) {
            throw new \RuntimeException(sprintf('%s：INFO 第 %d 行的引号没有闭合', $this->filename, $lineNumber));
        }

        $inner = substr($value, 1, -1);
        if ($quote === "'") {
            return $inner;
        }

        $decoded = '';
        $length = strlen($inner);
        for ($index = 0; $index < $length; $index++) {
            $character = $inner[$index];
            if ($character === '\\' && $index + 1 < $length) {
                $next = $inner[$index + 1];
                if (in_array($next, ['\\', '"', '$', '`'], true)) {
                    $decoded .= $next;
                    $index++;
                    continue;
                }
            }
            $decoded .= $character;
        }
        return $decoded;
    }

    private function isCompleteQuotedInfoValue(string $value): bool
    {
        if ($value === '' || ($value[0] !== '"' && $value[0] !== "'")) {
            return true;
        }

        $quote = $value[0];
        $backslashes = 0;
        for ($index = strlen($value) - 1; $index > 0; $index--) {
            if ($value[$index] !== $quote) {
                continue;
            }
            if ($quote === "'") {
                return $index === strlen($value) - 1;
            }
            for ($offset = $index - 1; $offset >= 0 && $value[$offset] === '\\'; $offset--) {
                $backslashes++;
            }
            return $index === strlen($value) - 1 && $backslashes % 2 === 0;
        }
        return false;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    private function runsAsRoot(): bool
    {
        foreach ($this->metadata as $field => $value) {
            if (stripos((string) $field, 'description') !== 0 || !is_scalar($value)) {
                continue;
            }
            $description = (string) $value;
            if (stripos($description, 'root权限') !== false || stripos($description, 'root privileges') !== false) {
                return true;
            }
        }

        try {
            $archive = $this->openArchive();
            if (!isset($archive['conf/privilege'])) {
                return false;
            }
            $entry = $archive['conf/privilege'];
            if ($entry->getSize() > 1024 * 1024) {
                error_log('[SSpkS] Unexpected conf/privilege size in ' . $this->filename);
                return false;
            }
            $content = $entry->getContent();
        } catch (\Throwable $e) {
            return false;
        }
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        $privilege = json_decode($content, true);
        if (!is_array($privilege)) {
            error_log('[SSpkS] Failed to parse conf/privilege in ' . $this->filename);
            return false;
        }
        return strtolower(trim((string) ($privilege['defaults']['run-as'] ?? ''))) === 'root';
    }

    public function extractIfMissing(string $inPkgName, string $targetFile): bool
    {
        if (file_exists($targetFile)) {
            return true;
        }
        $tmp_dir = sys_get_temp_dir();
        self::ensureAvailableSpace($tmp_dir, 'TMP');
        self::ensureAvailableSpace(dirname($targetFile), '套件缓存');
        $p = $this->openArchive();
        $workDir = $tmp_dir . DIRECTORY_SEPARATOR . 'sspks-' . bin2hex(random_bytes(8));
        if (!mkdir($workDir, 0700)) {
            throw new \RuntimeException('无法创建临时解压目录');
        }
        $tmpExtractedFilepath = $workDir . DIRECTORY_SEPARATOR . $inPkgName;
        try {
            $p->extractTo($workDir, $inPkgName);
            if (!is_file($tmpExtractedFilepath) || !copy($tmpExtractedFilepath, $targetFile)) {
                throw new \RuntimeException('无法从 ' . $this->filename . ' 缓存文件：' . $inPkgName);
            }
        } finally {
            if (is_file($tmpExtractedFilepath)) {
                @unlink($tmpExtractedFilepath);
            }
            @rmdir($workDir);
        }
        return true;
    }

    private static function ensureAvailableSpace(string $dir, string $friendlyName): void
    {
        $free = @disk_free_space($dir);
        if (!empty($free) && $free < 2 * 1024 * 1024) {
            throw new \Exception($friendlyName . ' 目录仅剩 ' . $free . ' 字节可用空间，磁盘空间不足');
        }
    }

    public function hasWizardDir(): bool
    {
        if (file_exists($this->wizfile)) {
            return true;
        }

        if (file_exists($this->nowizfile)) {
            return false;
        }

        $p = $this->openArchive();
        foreach ($p as $file) {
            if (substr($file, strrpos($file, '/') + 1) === 'WIZARD_UIFILES') {
                touch($this->wizfile);
                return true;
            }
        }
        touch($this->nowizfile);
        return false;
    }

    private function openArchive(): \PharData
    {
        if (!$this->archiveValidated) {
            TarArchiveGuard::assertNoPharMetadata($this->filepath);
            $this->archiveValidated = true;
        }

        try {
            return new \PharData($this->filepath, \Phar::CURRENT_AS_FILEINFO | \Phar::KEY_AS_FILENAME);
        } catch (\UnexpectedValueException $e) {
            throw new \Exception('套件文件不可读取：' . $this->filepath, 0, $e);
        }
    }

    public function getThumbnails(string $pathPrefix = ''): array
    {
        $thumbnailSources = [
            '72' => [
                'file' => 'PACKAGE_ICON.PNG',
                'info' => 'package_icon',
            ],
            '120' => [
                'file' => 'PACKAGE_ICON_256.PNG',
                'info' => 'package_icon_256',
            ],
        ];
        $thumbnails = [];
        foreach ($thumbnailSources as $size => $sourceList) {
            $thumbName = $this->filepathNoExt . '_thumb_' . $size . '.png';
            try {
                $this->extractIfMissing($sourceList['file'], $thumbName);
            } catch (\Exception $e) {
                if (isset($this->metadata[$sourceList['info']])) {
                    $icon = base64_decode($this->metadata[$sourceList['info']], true);
                    if ($icon !== false && strlen($icon) <= 5 * 1024 * 1024) {
                        file_put_contents($thumbName, $icon, LOCK_EX);
                    }
                }
            }

            if (file_exists($thumbName)) {
                $thumbnails[] = $pathPrefix . $thumbName;
            } else {
                $themeUrl = $this->config->paths['themes'] . $this->config->site['theme'] . '/';
                $thumbnails[] = $pathPrefix . $themeUrl . 'images/default_package_icon_' . $size . '.png';
            }
        }
        return $thumbnails;
    }

    public function getSnapshots(string $pathPrefix = ''): array
    {
        $i = 1;
        while ($i <= 20) {
            try {
                $this->extractIfMissing('screen_' . $i . '.png', $this->filepathNoExt . '_screen_' . $i . '.png');
                $i++;
            } catch (\Exception $e) {
                break;
            }
        }
        $snapshots = [];
        foreach (glob($this->filepathNoExt . '*_screen_*.png') ?: [] as $snapshot) {
            $snapshots[] = $pathPrefix . $snapshot;
        }
        return $snapshots;
    }

    public function isCompatibleToArch(string $arch): bool
    {
        $excludedArchitectures = $this->metadata['exclude_arch'] ?? [];
        if (is_string($excludedArchitectures)) {
            $excludedArchitectures = $this->normaliseList($excludedArchitectures);
        }
        if (in_array($arch, $excludedArchitectures, true)) {
            return false;
        }

        $architectures = $this->metadata['arch'];
        if (is_string($architectures)) {
            $architectures = $this->normaliseList($architectures);
        }
        return in_array($arch, $architectures, true) || in_array('noarch', $architectures, true);
    }

    public function isCompatibleToFirmware(string $version): bool
    {
        if (self::compareDsmVersions($this->metadata['os_min_ver'], $version) > 0) {
            return false;
        }

        $maximumVersion = trim((string) ($this->metadata['os_max_ver'] ?? ''));
        return $maximumVersion === '' || self::compareDsmVersions($maximumVersion, $version) >= 0;
    }

    public static function compareDsmVersions(string $left, string $right): int
    {
        $normalize = static function (string $version): array {
            $parts = preg_split('/[-._]/', $version);
            return array_pad(array_map('intval', $parts), 3, 0);
        };

        return $normalize($left) <=> $normalize($right);
    }

    public static function comparePackageVersions(string $left, string $right): int
    {
        $leftParts = array_map('intval', preg_split('/[._-]/', $left) ?: []);
        $rightParts = array_map('intval', preg_split('/[._-]/', $right) ?: []);
        $length = max(count($leftParts), count($rightParts));

        return array_pad($leftParts, $length, 0) <=> array_pad($rightParts, $length, 0);
    }

    private function normaliseList(string $value, bool $lowercase = false): array
    {
        $items = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($lowercase) {
            $items = array_map('strtolower', $items ?: []);
        }
        return array_values(array_unique($items ?: []));
    }

    private function isValidPackageVersion(string $version): bool
    {
        if (preg_match('/^\d+(?:[._-]\d+)+$/D', $version) !== 1) {
            return false;
        }

        foreach (preg_split('/[._-]/', $version) as $part) {
            if (!self::isUnsignedInt32($part)) {
                return false;
            }
        }
        return true;
    }

    private static function isValidDsmVersion(string $version): bool
    {
        if (preg_match('/^(\d+)\.(\d+)-(\d+)$/D', $version, $matches) !== 1) {
            return false;
        }

        return self::isUnsignedInt32($matches[1]) && self::isUnsignedInt32($matches[2]) && self::isUnsignedInt32($matches[3]);
    }

    private static function isUnsignedInt32(string $value): bool
    {
        $normalised = ltrim($value, '0');
        if ($normalised === '') {
            return true;
        }

        return strlen($normalised) < 10 || (strlen($normalised) === 10 && strcmp($normalised, '2147483647') <= 0);
    }

    public function isBeta(): bool
    {
        return (isset($this->metadata['beta']) && $this->parseBool($this->metadata['beta']));
    }
}
