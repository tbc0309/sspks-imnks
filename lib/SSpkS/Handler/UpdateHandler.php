<?php

namespace SSpkS\Handler;

use SSpkS\Output\HtmlOutput;

final class UpdateHandler extends AbstractHandler
{
    private const AUTH_FAILURE_LIMIT = 5;
    private const AUTH_FAILURE_WINDOW = 60;

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
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            if ($this->updateAuthFailures('read') >= self::AUTH_FAILURE_LIMIT) {
                $this->rejectRefreshAuthentication(true);
                return;
            }
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            $provided = stripos($authorization, 'Bearer ') === 0 ? trim(substr($authorization, 7)) : trim((string) ($_SERVER['HTTP_X_SSPKS_TOKEN'] ?? ''));
            $configured = (string) ($this->config->update_token ?? '');
            if ($provided === '' || $configured === '' || !hash_equals($configured, $provided)) {
                $this->updateAuthFailures('record');
                $this->rejectRefreshAuthentication();
                return;
            }
            $this->updateAuthFailures('clear');
        } catch (\Throwable $e) {
            http_response_code(503);
            $this->emit(['type'=>'error','code'=>'auth_unavailable','message'=>'Authentication service unavailable.']);
            return;
        }
        // Keep an individual PHP execution comfortably below the CDN's request limit.
        $phpLimit = (int) ini_get('max_execution_time');
        $limit = $phpLimit > 0 ? min(20, $phpLimit) : 20;
        @set_time_limit($limit);
        ignore_user_abort(true);
        $budget = max(0.1, min(5.0, $limit / 3));
        $lockFile = $this->config->basePath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'package-refresh.lock';
        $directory = dirname($lockFile);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            http_response_code(503);
            $this->emit(['type'=>'error','message'=>'Cannot create index task directory.']);
            return;
        }
        $lock = @fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            http_response_code(409);
            $this->emit(['type'=>'error','code'=>'busy','message'=>'An index batch is still running.']);
            return;
        }
        try {
            $operation = $_POST['operation'] ?? 'start';
            $mode = $_POST['mode'] ?? 'incremental';
            $id = $_POST['job'] ?? '';
            $after = $_POST['after'] ?? '0';
            if (!is_string($operation) || !is_string($mode) || !is_string($id)
                || !is_string($after) || strlen($after) > 12 || !ctype_digit($after)) {
                throw new \InvalidArgumentException('Invalid index request');
            }
            $job = new \SSpkS\IndexUpdateJob($this->config);
            switch ($operation) {
                case 'start': $result = $job->start($mode); break;
                case 'step': $result = $job->step($id,(int)$after,$budget); break;
                case 'status': $result = $job->snapshot($id,(int)$after); break;
                default: throw new \InvalidArgumentException('Unknown index operation');
            }
            $this->emit($result);
        } catch (\Throwable $e) {
            http_response_code($e instanceof \InvalidArgumentException ? 400 : 422);
            error_log('[SSpkS] Index batch failed: ' . $e->getMessage());
            $this->emit(['type'=>'error','message'=>$e->getMessage()]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function emit(array $event): void
    {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        echo ($json === false ? '{"type":"error","message":"Unable to encode update status."}' : $json) . "\n";

    }

    private function rejectRefreshAuthentication(bool $limited = false): void
    {
        http_response_code($limited ? 429 : 401);
        if ($limited) { header('Retry-After: 60'); }
        $this->emit(['type'=>'error','code'=>$limited ? 'auth_rate_limited' : 'auth_invalid',
            'message'=>$limited ? 'Too many authentication failures. Try again later.' : 'The management password is invalid or not configured.']);
    }

    private function updateAuthFailures(string $action): int
    {
        $directory = $this->config->basePath . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            error_log('[SSpkS] Failed to create the refresh authentication rate-limit directory.');
            throw new \RuntimeException('Authentication rate limiter is unavailable');
        }

        $filename = $directory . DIRECTORY_SEPARATOR . 'refresh-auth-rate.json';
        $handle = @fopen($filename, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            error_log('[SSpkS] Failed to lock the refresh authentication rate-limit file.');
            throw new \RuntimeException('Authentication rate limiter cannot be locked');
        }

        try {
            rewind($handle);
            $raw = stream_get_contents($handle, 512 * 1024 + 1);
            if (!is_string($raw) || strlen($raw) > 512 * 1024) {
                throw new \RuntimeException('Authentication rate-limit state exceeds the size limit');
            }
            $data = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Authentication rate-limit state is corrupt');
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
            if (!is_string($encoded) || !rewind($handle) || !ftruncate($handle, 0)
                || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Cannot persist authentication rate-limit state');
            }
            @chmod($filename, 0600);
            return count($attempts);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

}
