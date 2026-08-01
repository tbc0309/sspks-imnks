<?php

namespace SSpkS;

use SSpkS\Handler\BrowserAllPackagesListHandler;
use SSpkS\Handler\BrowserDeviceListHandler;
use SSpkS\Handler\BrowserDownloadHandler;
use SSpkS\Handler\BrowserPackageListHandler;
use SSpkS\Handler\BrowserRedirectHandler;
use SSpkS\Handler\NotFoundHandler;
use SSpkS\Handler\SitemapHandler;
use SSpkS\Handler\SynologyHandler;
use SSpkS\Handler\UpdateHandler;

final class Handler
{
    private const HANDLERS = [
        SitemapHandler::class,
        BrowserDownloadHandler::class,
        SynologyHandler::class,
        UpdateHandler::class,
        BrowserRedirectHandler::class,
        BrowserPackageListHandler::class,
        BrowserAllPackagesListHandler::class,
        BrowserDeviceListHandler::class,
        NotFoundHandler::class,
    ];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function handle(): void
    {
        if (!$this->isFrontControllerPath() && !$this->isSitemapPath()) {
            (new NotFoundHandler($this->config))->handle();
            return;
        }

        foreach (self::HANDLERS as $handlerClass) {
            $handler = new $handlerClass($this->config);
            if ($handler->canHandle()) {
                $handler->handle();
                return;
            }
        }
    }

    private function isFrontControllerPath(): bool
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePath = $this->getBasePath();
        $basePathWithoutSlash = $basePath === '/' ? '/' : rtrim($basePath, '/');
        $indexPath = $basePath . 'index.php';

        return $requestPath === $basePath
            || $requestPath === $basePathWithoutSlash
            || $requestPath === $indexPath;
    }

    private function isSitemapPath(): bool
    {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return $requestPath === $this->getBasePath() . 'sitemap.xml';
    }

    private function getBasePath(): string
    {
        $configuredBaseUrl = trim((string) ($this->config->site['base_url'] ?? ''));

        if ($configuredBaseUrl !== '') {
            $configuredPath = parse_url($configuredBaseUrl, PHP_URL_PATH);
            $basePath = is_string($configuredPath) && $configuredPath !== '' ? $configuredPath : '/';
        } else {
            $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $scriptDirectory = str_replace('\\', '/', dirname($scriptName));
            $basePath = ($scriptDirectory === '.' || $scriptDirectory === '/') ? '/' : '/' . trim($scriptDirectory, '/') . '/';
        }

        $basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');
        return $basePath === '/' ? '/' : $basePath . '/';
    }
}
