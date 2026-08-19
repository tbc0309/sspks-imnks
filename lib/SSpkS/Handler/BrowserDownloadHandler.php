<?php

namespace SSpkS\Handler;

use SSpkS\Package\BrowserDownloadResolver;

final class BrowserDownloadHandler extends AbstractHandler
{
    public function canHandle(): bool
    {
        $queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
        return $this->isReadRequest()
            && preg_match('/^download=[A-Za-z0-9_-]{12}$/D', $queryString) === 1
            && isset($_GET['download'])
            && is_string($_GET['download']);
    }

    public function handle(): void
    {
        if (empty($this->config->browser_download['enabled'])
            || empty($this->config->browser_url_obfuscation['spk_downloads'])) {
            $this->notFound();
            return;
        }

        $resolved = (new BrowserDownloadResolver($this->config))->resolve((string) $_GET['download']);
        if ($resolved === null) {
            $this->notFound();
            return;
        }

        $filename = $resolved['filename'];
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'package.spk';
        header('Content-Type: application/octet-stream');
        $contentDisposition = 'attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
        header('Content-Disposition: ' . $contentDisposition);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');
        header('X-Accel-Redirect: ' . $resolved['internalUri']);
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
}
