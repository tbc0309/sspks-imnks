<?php

namespace SSpkS\Handler;

use SSpkS\Model\Spk;
use SSpkS\Output\HtmlOutput;
use SSpkS\Package\BrowserImageObfuscator;
use SSpkS\Package\BrowserPackageCatalog;
use SSpkS\Package\Package;
use SSpkS\Package\PackageFinder;
use think\facade\Cache;
use think\facade\Db;

final class UpdateHandler extends AbstractHandler
{
    private const AUTH_FAILURE_LIMIT = 5;
    private const AUTH_FAILURE_WINDOW = 60;
    private const CHECKPOINT_INTERVAL = 50;
    private const CHECKPOINT_VERSION = 1;
    private const CHECKPOINT_MAX_BYTES = 67108864;

    public function canHandle(): bool
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $updatePath = $this->config->baseUrlRelative;

        return in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)
            && $requestPath === $updatePath
            && count($_GET) === 1
            && isset($_GET['action'])
            && is_string($_GET['action'])
            && hash_equals($this->config->update['action'], $_GET['action']);
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->outputPage();
            return;
        }

        $this->runRefresh();
    }

    private function outputPage(): void
    {
        header('Cache-Control: no-store');
        $output = new HtmlOutput($this->config);
        $output->setVariable('updateEndpoint', $this->config->baseUrlRelative . '?action=' . rawurlencode($this->config->update['action']));
        $output->setVariable('hideSetupInfo', true);
        $output->setVariable('noIndex', true);
        $output->setVariable('pageTitle', $this->language->get('update_title') . ' - ' . $this->config->site['name']);
        $output->setTemplate('html_update');
        $output->output();
    }

    private function runRefresh(): void
    {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');

        if ($this->updateAuthFailures('read') >= self::AUTH_FAILURE_LIMIT) {
            $this->rejectRefreshAuthentication();
            return;
        }

        $configuredPassword = (string) ($this->config->update_token ?? '');
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $providedPassword = stripos($authorization, 'Bearer ') === 0 ? trim(substr($authorization, 7)) : trim((string) ($_SERVER['HTTP_X_SSPKS_TOKEN'] ?? ''));

        if ($configuredPassword === ''
            || $providedPassword === ''
            || !hash_equals($configuredPassword, $providedPassword)) {
            $this->updateAuthFailures('record');
            $this->rejectRefreshAuthentication();
            return;
        }
        $this->updateAuthFailures('clear');

        @set_time_limit(0);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break;
            }
        }
        ob_implicit_flush(true);

        $lockDir = $this->config->basePath . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0770, true)) {
            $this->emit(['type' => 'error', 'message' => 'Unable to create the update lock directory.']);
            return;
        }
        $lockFile = $lockDir . DIRECTORY_SEPARATOR . 'package-refresh.lock';
        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            $this->emit(['type' => 'error', 'message' => 'An update is already running. Please try again later.']);
            return;
        }

        try {
            try {
                $result = $this->updateData(function (array $event) {
                    $this->emit($event);
                });
                try {
                    Cache::clear();
                } catch (\Throwable $e) {
                    error_log('[SSpkS] Post-refresh cache cleanup failed: ' . $e->getMessage());
                }
                $thumbnailWarning = $this->rebuildBrowserImages();
                $this->clearUpdateCheckpoint();
                $this->emit([
                    'type' => 'complete',
                    'percent' => 100,
                    'success' => $result['success'],
                    'failed' => $result['failed'],
                    'message' => $thumbnailWarning === ''
                        ? 'Package index update completed.'
                        : 'Package index update completed, but browser thumbnail generation failed: ' . $thumbnailWarning,
                ]);
            } catch (\Throwable $e) {
                error_log('[SSpkS] Package index refresh failed: ' . $e->getMessage());
                $detail = trim($e->getMessage());
                $this->emit([
                    'type' => 'error',
                    'message' => $detail === ''
                        ? 'Update failed; the existing index was preserved.'
                        : 'Update failed: ' . $detail . ' (the existing index was preserved)',
                ]);
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function rebuildBrowserImages(): string
    {
        try {
            $images = new BrowserImageObfuscator($this->config);
            $images->clearPublishedImages();
            if (empty($this->config->browser_url_obfuscation['package_images'])) {
                return '';
            }
            $this->emit([
                'type' => 'progress',
                'percent' => 98,
                'message' => 'Regenerating browser WebP thumbnails…',
            ]);
            $catalog = new BrowserPackageCatalog($this->config);
            $packages = $catalog->getAll(true);
            if ($packages !== [] && $images->countPublishedImages() === 0) {
                throw new \RuntimeException('The server lacks GD/Imagick WebP support or the cache directory is not writable');
            }
            if ($catalog->getImageFailureCount() > 0) {
                throw new \RuntimeException($catalog->getImageFailureCount() . ' browser thumbnails could not be generated; check the PHP error log');
            }
            return '';
        } catch (\Throwable $e) {
            error_log('[SSpkS] Browser WebP regeneration failed: ' . $e->getMessage());
            return trim($e->getMessage());
        }
    }

    private function emit(array $event): void
    {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        echo ($json === false ? '{"type":"error","message":"Unable to encode update status."}' : $json) . "\n";
        @ob_flush();
        flush();
    }

    private function rejectRefreshAuthentication(): void
    {
        usleep(250000);
        http_response_code(404);
        $this->emit(['type' => 'error', 'message' => 'The management password is invalid or not configured.']);
    }

    private function updateAuthFailures(string $action): int
    {
        $directory = $this->config->basePath . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            error_log('[SSpkS] Failed to create the refresh authentication rate-limit directory.');
            return 0;
        }

        $filename = $directory . DIRECTORY_SEPARATOR . 'refresh-auth-rate.json';
        $handle = @fopen($filename, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            error_log('[SSpkS] Failed to lock the refresh authentication rate-limit file.');
            return 0;
        }

        try {
            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = is_string($raw) && strlen($raw) <= 512 * 1024 ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $now = time();
            $oldest = $now - self::AUTH_FAILURE_WINDOW;
            foreach ($data as $key => $timestamps) {
                if (!is_array($timestamps)) {
                    unset($data[$key]);
                    continue;
                }
                $timestamps = array_values(array_filter($timestamps, static function ($timestamp) use ($oldest): bool {
                    return is_int($timestamp) && $timestamp > $oldest;
                }));
                if ($timestamps === []) {
                    unset($data[$key]);
                } else {
                    $data[$key] = $timestamps;
                }
            }

            $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $clientKey = hash('sha256', $remoteAddress);
            $attempts = $data[$clientKey] ?? [];
            if ($action === 'record') {
                $attempts[] = $now;
                $data[$clientKey] = $attempts;
            } elseif ($action === 'clear') {
                unset($data[$clientKey]);
                $attempts = [];
            }

            if (count($data) > 1024) {
                $data = array_slice($data, -1024, null, true);
            }
            $encoded = json_encode($data);
            if (is_string($encoded)) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $encoded);
                fflush($handle);
                @chmod($filename, 0600);
            }
            return count($attempts);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function updateData(callable $emit): array
    {
        $spkModel = new Spk();
        $tableName = $spkModel->getTable();
        $existingPackages = Db::table($tableName)->column('md5,filemtime,filesize', 'spk');
        $files = (new PackageFinder($this->config))->getAllPackageFiles();
        $total = count($files);
        $fileWeights = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            $size = filesize($file);
            $fileWeights[$file] = $size === false ? 1 : max(1, (int) $size);
            $totalBytes += $fileWeights[$file];
        }
        $processedBytes = 0;
        $allData = [];
        $success = 0;
        $failed = 0;
        $checkpointEntries = $this->loadUpdateCheckpoint();
        if ($checkpointEntries !== []) {
            $checkpointEntries = array_intersect_key(
                $checkpointEntries,
                array_fill_keys($files, true)
            );
        }

        $emit([
            'type' => 'start',
            'percent' => 0,
            'total' => $total,
            'totalBytes' => $totalBytes,
            'message' => $total > 0
                ? ($checkpointEntries === [] ? 'Starting package file scan…' : 'Previous update checkpoint loaded; resuming scan…')
                : 'No SPK files found.',
        ]);

        foreach ($files as $index => $file) {
            $label = basename($file);
            $filemtime = filemtime($file);
            $filesize = filesize($file);
            if ($filemtime !== false && $filesize !== false) {
                $checkpointEntry = $checkpointEntries[$file] ?? null;
                if ($this->isReusableCheckpointEntry($checkpointEntry, $file, $filemtime, $filesize)) {
                    $row = $checkpointEntry['row'];
                    $allData[] = $row;
                    $success++;
                    $processedBytes += $fileWeights[$file];
                    $emit([
                        'type' => 'success',
                        'name' => $label,
                        'detail' => (string) $row['displayname'] . ' · ' . (string) $row['version'] . ' (checkpoint)',
                    ]);
                    $emit([
                        'type' => 'progress',
                        'percent' => $this->scanPercent($processedBytes, $totalBytes),
                        'processed' => $index + 1,
                        'total' => $total,
                        'message' => 'Reusing checkpoint: ' . $label,
                    ]);
                    continue;
                }
            }

            $checkpointSaved = false;
            try {
                $pkg = new Package($this->config, $file);
                $filePath = $this->config->basePath . DIRECTORY_SEPARATOR . $pkg->spk;
                if (!is_file($filePath)) {
                    throw new \RuntimeException('File does not exist or is not readable');
                }

                $filemtime = filemtime($filePath);
                $filesize = filesize($filePath);
                if ($filemtime === false || $filesize === false) {
                    throw new \RuntimeException('Unable to read file attributes');
                }

                $cached = $existingPackages[$pkg->spk] ?? null;
                $canReuseHash = is_array($cached)
                    && (int) ($cached['filemtime'] ?? -1) === $filemtime
                    && (int) ($cached['filesize'] ?? -1) === $filesize
                    && preg_match('/^[a-f0-9]{32}$/i', (string) ($cached['md5'] ?? '')) === 1;
                if ($canReuseHash) {
                    $md5 = (string) $cached['md5'];
                    $emit([
                        'type' => 'progress',
                        'percent' => $this->scanPercent($processedBytes + $fileWeights[$file], $totalBytes),
                        'processed' => $index,
                        'total' => $total,
                        'message' => 'Reusing checksum: ' . $label,
                    ]);
                } else {
                    $md5 = $this->hashFileInChunks(
                        $filePath,
                        $filesize,
                        $label,
                        $processedBytes,
                        $totalBytes,
                        $index,
                        $total,
                        $emit
                    );
                    clearstatcache(true, $filePath);
                    if (filesize($filePath) !== $filesize || filemtime($filePath) !== $filemtime) {
                        throw new \RuntimeException('File changed while calculating its checksum; restart the update');
                    }
                }
                if ($md5 === '') {
                    throw new \RuntimeException('Unable to calculate file checksum');
                }

                $pkg->filesize = $filesize;
                $pkg->md5 = $md5;
                $row = [
                    'displayname' => $pkg->displayname ?? '',
                    'package' => $pkg->package,
                    'version' => $pkg->version,
                    'arch' => implode(',', $pkg->arch),
                    'os_min_ver' => $pkg->os_min_ver,
                    'beta' => $pkg->beta ? 1 : 0,
                    'spk' => $pkg->spk,
                    'filesize' => $filesize,
                    'md5' => $md5,
                    'filemtime' => $filemtime,
                    'params' => serialize($pkg),
                ];
                $allData[] = $row;
                $checkpointEntries[$file] = [
                    'filemtime' => $filemtime,
                    'filesize' => $filesize,
                    'row' => $row,
                ];
                if (($index + 1) % self::CHECKPOINT_INTERVAL === 0) {
                    $this->saveUpdateCheckpoint($checkpointEntries);
                    $checkpointSaved = true;
                }
                $success++;
                $emit([
                    'type' => 'success',
                    'name' => $label,
                    'detail' => $pkg->displayname . ' · ' . $pkg->version,
                ]);
            } catch (\Throwable $e) {
                $failed++;
                error_log('[SSpkS] Ignored invalid package ' . $label . ': ' . $e->getMessage());
                if (($index + 1) % self::CHECKPOINT_INTERVAL === 0 && !$checkpointSaved) {
                    $this->saveUpdateCheckpoint($checkpointEntries);
                    $checkpointSaved = true;
                }
                $emit([
                    'type' => 'failure',
                    'name' => $label,
                    'detail' => $e->getMessage(),
                ]);
            }

            if (($index + 1) % self::CHECKPOINT_INTERVAL === 0 && !$checkpointSaved) {
                $this->saveUpdateCheckpoint($checkpointEntries);
                $checkpointSaved = true;
            }
            $processedBytes += $fileWeights[$file];
            $emit([
                'type' => 'progress',
                'percent' => $this->scanPercent($processedBytes, $totalBytes),
                'processed' => $index + 1,
                'total' => $total,
                'message' => $checkpointSaved
                    ? 'Update checkpoint saved (' . ($index + 1) . '/' . $total . ')'
                    : 'Checking packages…',
            ]);
            if (connection_aborted()) {
                $this->saveUpdateCheckpoint($checkpointEntries);
                throw new \RuntimeException('Browser connection was interrupted; scan progress was saved');
            }
        }

        if ($allData === []) {
            if ($total === 0) {
                throw new \RuntimeException('No .spk files were found in the packages directory.');
            }
            throw new \RuntimeException('No valid DSM 7 packages are available to write to the database; review the failure list');
        }

        $this->saveUpdateCheckpoint($checkpointEntries);
        $emit(['type' => 'progress', 'percent' => 95, 'message' => 'Writing database index…']);
        Db::startTrans();
        try {
            Db::table($tableName)->delete(true);
            $spkModel->saveAll($allData);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['success' => $success, 'failed' => $failed];
    }

    private function checkpointFilename(): string
    {
        return $this->config->basePath . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'package-refresh.checkpoint';
    }

    private function checkpointFingerprint(): string
    {
        $packageClass = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Package'
            . DIRECTORY_SEPARATOR . 'Package.php';
        return hash('sha256', serialize([
            'packages' => $this->config->packages,
            'package_path' => $this->config->paths['packages'] ?? '',
            'package_code' => is_file($packageClass) ? hash_file('sha256', $packageClass) : '',
        ]));
    }

    private function loadUpdateCheckpoint(): array
    {
        $filename = $this->checkpointFilename();
        if (!is_file($filename)) {
            return [];
        }
        $size = filesize($filename);
        if ($size === false || $size < 1 || $size > self::CHECKPOINT_MAX_BYTES) {
            $this->clearUpdateCheckpoint();
            return [];
        }

        $raw = file_get_contents($filename);
        if (!is_string($raw)) {
            return [];
        }
        $checkpoint = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($checkpoint)
            || ($checkpoint['version'] ?? null) !== self::CHECKPOINT_VERSION
            || !hash_equals($this->checkpointFingerprint(), (string) ($checkpoint['fingerprint'] ?? ''))
            || !isset($checkpoint['entries'])
            || !is_array($checkpoint['entries'])) {
            $this->clearUpdateCheckpoint();
            return [];
        }
        return $checkpoint['entries'];
    }

    private function isReusableCheckpointEntry($entry, string $file, int $filemtime, int $filesize): bool
    {
        if (!is_array($entry)
            || (int) ($entry['filemtime'] ?? -1) !== $filemtime
            || (int) ($entry['filesize'] ?? -1) !== $filesize
            || !isset($entry['row'])
            || !is_array($entry['row'])) {
            return false;
        }

        $row = $entry['row'];
        $requiredFields = [
            'displayname', 'package', 'version', 'arch', 'os_min_ver', 'beta',
            'spk', 'filesize', 'md5', 'filemtime', 'params',
        ];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $row) || !is_scalar($row[$field])) {
                return false;
            }
        }
        return (string) $row['spk'] === $file
            && (int) $row['filemtime'] === $filemtime
            && (int) $row['filesize'] === $filesize
            && preg_match('/^[a-f0-9]{32}$/iD', (string) $row['md5']) === 1;
    }

    private function saveUpdateCheckpoint(array $entries): void
    {
        $filename = $this->checkpointFilename();
        $temporary = $filename . '.tmp';
        $payload = serialize([
            'version' => self::CHECKPOINT_VERSION,
            'fingerprint' => $this->checkpointFingerprint(),
            'entries' => $entries,
        ]);
        if (strlen($payload) > self::CHECKPOINT_MAX_BYTES) {
            throw new \RuntimeException('Update checkpoint exceeds the size limit');
        }
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write update checkpoint');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $filename)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to replace update checkpoint');
        }
    }

    private function clearUpdateCheckpoint(): void
    {
        $filename = $this->checkpointFilename();
        if (is_file($filename) && !@unlink($filename)) {
            error_log('[SSpkS] Failed to remove refresh checkpoint: ' . $filename);
        }
        $temporary = $filename . '.tmp';
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }

    private function scanPercent(int $processedBytes, int $totalBytes): int
    {
        return $totalBytes > 0 ? min(90, (int) floor(($processedBytes / $totalBytes) * 90)) : 0;
    }

    private function hashFileInChunks(
        string $filePath,
        int $fileSize,
        string $label,
        int $processedBytes,
        int $totalBytes,
        int $fileIndex,
        int $fileTotal,
        callable $emit
    ): string {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open file for MD5 calculation');
        }

        $context = hash_init('md5');
        $chunkSize = 8 * 1024 * 1024;
        $emitInterval = 16 * 1024 * 1024;
        $hashedBytes = 0;
        $lastEmittedBytes = 0;
        $lastEmittedAt = microtime(true);

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new \RuntimeException('An error occurred while reading the file');
                }
                if ($chunk === '') {
                    if (!feof($handle)) {
                        throw new \RuntimeException('No data was returned while reading the file');
                    }
                    break;
                }

                hash_update($context, $chunk);
                $hashedBytes += strlen($chunk);
                $now = microtime(true);
                if (($hashedBytes - $lastEmittedBytes) >= $emitInterval || ($now - $lastEmittedAt) >= 1.0) {
                    $filePercent = $fileSize > 0 ? min(100, (int) floor(($hashedBytes / $fileSize) * 100)) : 100;
                    $emit([
                        'type' => 'progress',
                        'percent' => $this->scanPercent($processedBytes + min($hashedBytes, $fileSize), $totalBytes),
                        'processed' => $fileIndex,
                        'total' => $fileTotal,
                        'filePercent' => $filePercent,
                        'message' => 'Calculating MD5: ' . $label . ' (' . $filePercent . '%)',
                    ]);
                    $lastEmittedBytes = $hashedBytes;
                    $lastEmittedAt = $now;
                }

                if (connection_aborted()) {
                    throw new \RuntimeException('Browser connection was interrupted');
                }
            }

            return hash_final($context);
        } finally {
            fclose($handle);
        }
    }
}
