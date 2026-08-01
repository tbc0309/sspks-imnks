<?php

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: accelerometer=(), autoplay=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data: https:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' https://hm.baidu.com https://hmcdn.baidu.com; connect-src 'self' https://hm.baidu.com https://hmcdn.baidu.com; upgrade-insecure-requests");

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo 'Composer dependencies are missing. Run composer install --no-dev.';
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
chdir(__DIR__);

use SSpkS\Config;
use SSpkS\Handler;
use SSpkS\Language;

try {
    $config = Config::getInstance(__DIR__, 'conf/sspks.yaml');
    Language::getInstance($config);
    unset($_GET['lang']);
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = rtrim(str_replace('\\', '/', dirname($requestPath)), '/');
    $config->baseUrlRelative = ($basePath === '' || $basePath === '.') ? '/' : $basePath . '/';

    // Use the canonical URL so a forged Host header cannot contaminate responses.
    $configuredBaseUrl = trim($config->site['base_url'] ?? '');
    if ($configuredBaseUrl !== '') {
        $config->baseUrl = rtrim($configuredBaseUrl, '/') . '/';
    } else {
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/i', $host)) {
            throw new \InvalidArgumentException('Invalid Host header');
        }
        $config->baseUrl = $scheme . '://' . $host . $config->baseUrlRelative;
    }

    $handler = new Handler($config);
    $handler->handle();
} catch (\Throwable $e) {
    error_log('[SSpkS] Request handling failed: ' . $e->getMessage());
    $status = $e instanceof \InvalidArgumentException ? 400 : 500;
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $unique = isset($_GET['unique']) && is_string($_GET['unique']) ? $_GET['unique'] : '';
    if (strpos($unique, 'synology_') === 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"packages":[]}';
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $title = $status === 400 ? 'Invalid request' : 'Service unavailable';
        $message = $status === 400 ? 'The request parameters or host name are invalid.' : 'Please try again later.';
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>' . $title . '</title></head>';
        echo '<body><main><h1>' . $title . '</h1><p>' . $message . '</p></main></body></html>';
    }
}
