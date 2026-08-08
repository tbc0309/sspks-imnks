<?php

namespace SSpkS\Handler;

use SSpkS\Device\DeviceList;
use think\facade\Db;

final class SitemapHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        if (!$this->isReadRequest() || $_GET !== []) {
            return false;
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return substr($requestPath, -12) === '/sitemap.xml';
    }

    public function handle(): void
    {
        try {
            $deviceList = new DeviceList($this->config);
            $urls = [
                $this->config->baseUrl,
                $this->config->baseUrl . '?packages=fulllist',
            ];
            foreach ($deviceList->getKnownArchitectures() as $architecture) {
                $urls[] = $this->config->baseUrl . '?arch=' . rawurlencode($architecture);
            }

            $lastModified = $this->getLastModifiedTime();
            $body = $this->buildXml($urls, $lastModified);
            $etag = '"' . hash('sha256', $body) . '"';

            header('Content-Type: application/xml; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=300');
            header('ETag: ' . $etag);
            if ($lastModified > 0) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            }

            if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
                http_response_code(304);
                return;
            }
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                echo $body;
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Sitemap generation failed: ' . $e->getMessage());
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                echo 'The sitemap is temporarily unavailable.';
            }
        }
    }

    /** @param string[] $urls */
    private function buildXml(array $urls, int $lastModified): string
    {
        $lastmod = $lastModified > 0 ? gmdate('Y-m-d', $lastModified) : '';
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($url, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
            if ($lastmod !== '') {
                $xml[] = '    <lastmod>' . $lastmod . '</lastmod>';
            }
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';
        return implode("\n", $xml) . "\n";
    }

    private function getLastModifiedTime(): int
    {
        $timestamps = [];
        $modelsPath = $this->config->basePath . DIRECTORY_SEPARATOR
            . ltrim((string) $this->config->paths['models'], '/\\');
        $modelsMtime = is_file($modelsPath) ? filemtime($modelsPath) : false;
        if ($modelsMtime !== false) {
            $timestamps[] = (int) $modelsMtime;
        }

        try {
            $packageMtime = Db::name('Spk')->max('filemtime');
            if (is_numeric($packageMtime) && (int) $packageMtime > 0) {
                $timestamps[] = (int) $packageMtime;
            }
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to obtain package timestamps for the sitemap: ' . $e->getMessage());
        }

        return $timestamps === [] ? 0 : max($timestamps);
    }
}
