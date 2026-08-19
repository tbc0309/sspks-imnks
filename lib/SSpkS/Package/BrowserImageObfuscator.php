<?php

namespace SSpkS\Package;

use SSpkS\Config;

final class BrowserImageObfuscator
{
    private const WEBP_QUALITY = 86;
    private const TOKEN_VERSION = "webp-v1-q86\0";

    private Config $config;
    private ?array $rootCache = null;
    private ?string $publishDirectory = null;
    private int $failureCount = 0;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @return string[] */
    public function publishUrls(array $paths): array
    {
        $urls = [];
        foreach ($paths as $path) {
            if (!is_scalar($path)) {
                continue;
            }
            $source = $this->resolveAllowedPath((string) $path);
            if ($source === null) {
                $this->failureCount++;
                continue;
            }

            $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
            $token = $this->sourceToken($source);
            if ($token === '') {
                $this->failureCount++;
                continue;
            }

            try {
                $filename = $token . '.webp';
                $this->publishWebp($source, $filename, $extension);
                $urls[] = $this->publicUrl($filename);
            } catch (\Throwable $e) {
                $this->failureCount++;
                error_log('[SSpkS] Failed to publish a browser image: ' . $e->getMessage());
            }
        }
        return $urls;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    private function sourceToken(string $source): string
    {
        $context = hash_init('sha256');
        hash_update($context, self::TOKEN_VERSION);
        if (!hash_update_file($context, $source)) {
            return '';
        }
        $digest = hash_final($context, true);
        return rtrim(strtr(base64_encode(substr($digest, 0, 9)), '+/', '-_'), '=');
    }

    public function clearPublishedImages(): void
    {
        $directory = $this->publishDirectory();
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || !$entry->isFile()) {
                continue;
            }
            if (!@unlink($entry->getPathname())) {
                throw new \RuntimeException('Unable to delete stale browser image: ' . $entry->getFilename());
            }
        }
    }

    public function countPublishedImages(): int
    {
        $files = glob($this->publishDirectory() . DIRECTORY_SEPARATOR . '*.webp');
        return is_array($files) ? count($files) : 0;
    }

    private function publishWebp(string $source, string $filename, string $sourceExtension): void
    {
        $directory = $this->publishDirectory();
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($destination)) {
            return;
        }

            // Use a copy so source changes cannot desynchronize the token and content.
        $temporary = $destination . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if ($sourceExtension === 'webp') {
                if (!@copy($source, $temporary)) {
                    throw new \RuntimeException('Unable to copy browser WebP image');
                }
            } else {
                $this->convertToWebp($source, $temporary);
            }
            $this->assertWebpFile($temporary);
            @chmod($temporary, 0644);
            if (!@rename($temporary, $destination)) {
                if (!is_file($destination)) {
                    throw new \RuntimeException('Unable to publish browser WebP image');
                }
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function convertToWebp(string $source, string $destination): void
    {
        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            $contents = @file_get_contents($source);
            $image = $contents === false ? false : @imagecreatefromstring($contents);
            if ($image === false) {
                throw new \RuntimeException('GD was unable to decode the source image');
            }
            try {
                if (function_exists('imagepalettetotruecolor')) {
                    @imagepalettetotruecolor($image);
                }
                @imagealphablending($image, true);
                @imagesavealpha($image, true);
                if (!@imagewebp($image, $destination, self::WEBP_QUALITY)) {
                    throw new \RuntimeException('GD was unable to encode the WebP image');
                }
            } finally {
                @imagedestroy($image);
            }
            return;
        }

        if (class_exists(\Imagick::class) && \Imagick::queryFormats('WEBP') !== []) {
            $image = new \Imagick($source);
            try {
                $image->setIteratorIndex(0);
                $image->setImageFormat('webp');
                $image->setImageCompressionQuality(self::WEBP_QUALITY);
                $image->stripImage();
                if (!$image->writeImage($destination)) {
                    throw new \RuntimeException('Imagick was unable to encode the WebP image');
                }
            } finally {
                $image->clear();
                $image->destroy();
            }
            return;
        }

        throw new \RuntimeException('PHP GD or Imagick with WebP support is required');
    }

    private function assertWebpFile(string $filename): void
    {
        $signature = @file_get_contents($filename, false, null, 0, 12);
        if (!is_string($signature)
            || strlen($signature) !== 12
            || substr($signature, 0, 4) !== 'RIFF'
            || substr($signature, 8, 4) !== 'WEBP') {
            throw new \RuntimeException('Generated browser image is not a valid WebP file');
        }
    }

    private function publishDirectory(): string
    {
        if ($this->publishDirectory !== null) {
            return $this->publishDirectory;
        }

        $basePath = realpath($this->config->basePath);
        if ($basePath === false) {
            throw new \RuntimeException('Invalid application directory');
        }
        $cachePath = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $this->config->paths['cache']), DIRECTORY_SEPARATOR);
        $directory = $basePath . DIRECTORY_SEPARATOR . $cachePath . DIRECTORY_SEPARATOR . 'i';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create browser image cache directory');
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !$this->isInside($realDirectory, $basePath)) {
            throw new \RuntimeException('Invalid browser image cache directory');
        }

        $this->publishDirectory = $realDirectory;
        return $this->publishDirectory;
    }

    private function publicUrl(string $filename): string
    {
        $cachePath = trim(str_replace('\\', '/', (string) $this->config->paths['cache']), '/');
        return $this->config->baseUrlRelative . $cachePath . '/i/' . rawurlencode($filename);
    }

    private function resolveAllowedPath(string $path): ?string
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            return null;
        }

        $basePath = realpath($this->config->basePath);
        if ($basePath === false) {
            return null;
        }
        $normalised = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $isAbsolute = preg_match('/^(?:[a-z]:[\\\\\/]|[\\\\\/]{1,2})/i', $normalised) === 1;
        $candidate = $isAbsolute ? $normalised : $basePath . DIRECTORY_SEPARATOR . ltrim($normalised, DIRECTORY_SEPARATOR);
        $absolute = realpath($candidate);
        if ($absolute === false || !is_file($absolute) || !$this->isAllowedImage($absolute)) {
            return null;
        }
        return $absolute;
    }

    private function isAllowedImage(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return false;
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > 20 * 1024 * 1024) {
            return false;
        }
        foreach ($this->allowedRoots() as $root) {
            if ($this->isInside($path, $root)) {
                return true;
            }
        }
        return false;
    }

    private function isInside(string $path, string $root): bool
    {
        $pathForComparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $rootForComparison = DIRECTORY_SEPARATOR === '\\' ? strtolower($rootPrefix) : $rootPrefix;
        return strpos($pathForComparison, $rootForComparison) === 0;
    }

    /** @return string[] */
    private function allowedRoots(): array
    {
        if ($this->rootCache !== null) {
            return $this->rootCache;
        }

        $relativeRoots = [
            (string) $this->config->paths['packages'],
            (string) $this->config->paths['cache'],
            (string) $this->config->paths['themes'] . $this->config->site['theme'] . '/images/',
        ];
        $roots = [];
        foreach ($relativeRoots as $relativeRoot) {
            $relativeRoot = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeRoot), DIRECTORY_SEPARATOR);
            $root = realpath($this->config->basePath . DIRECTORY_SEPARATOR . $relativeRoot);
            if ($root !== false && is_dir($root)) {
                $roots[] = $root;
            }
        }
        $this->rootCache = array_values(array_unique($roots));
        return $this->rootCache;
    }
}
