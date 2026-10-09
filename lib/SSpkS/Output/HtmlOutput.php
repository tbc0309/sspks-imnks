<?php

namespace SSpkS\Output;

use Mustache\Engine;
use Mustache\Loader\FilesystemLoader;
use Mustache\Logger\StreamLogger;
use SSpkS\Config;
use SSpkS\Language;
use SSpkS\SiteStatistics;

final class HtmlOutput
{
    private Config $config;
    private Language $language;
    private Engine $mustache;
    private array $tplVars = [];
    private string $template = '';

    public function __construct(Config $config)
    {
        $this->config = $config;
        $language = Language::getInstance($config);
        $this->language = $language;
        $tplBase  = $this->config->basePath . DIRECTORY_SEPARATOR . $this->config->paths['themes'];
        $tplBase .= $this->config->site['theme'] . DIRECTORY_SEPARATOR . 'templates';

        $this->mustache = new Engine([
            'loader'          => new FilesystemLoader($tplBase),
            'partials_loader' => new FilesystemLoader($tplBase . '/partials'),
            'charset'         => 'utf-8',
            'entity_flags'    => ENT_QUOTES,
            'logger'          => new StreamLogger('php://stderr'),
        ]);

        $siteName = trim((string) ($this->config->site['name'] ?? 'SSPkS'));
        $seoDescription = trim((string) ($this->config->site['description'] ?? $siteName));
        $seoKeywords = $this->config->site['keywords'] ?? [];
        if (is_array($seoKeywords)) {
            $seoKeywords = implode(', ', array_filter(array_map('trim', $seoKeywords)));
        }

        $canonicalUrl = $this->config->baseUrl;
        if (isset($_GET['packages']) && $_GET['packages'] === 'fulllist') {
            $canonicalUrl .= '?packages=fulllist';
        } elseif (isset($_GET['arch'])
            && is_string($_GET['arch'])
            && preg_match('/^[a-z0-9_]+$/i', trim((string) $_GET['arch'])) === 1) {
            $canonicalUrl .= '?arch=' . rawurlencode(strtolower(trim((string) $_GET['arch'])));
        }

        $this->setVariable('siteName', $siteName);
        foreach ($language->all() as $key => $value) {
            $this->setVariable($key, $value);
        }
        // Card label aliases avoid collisions with package fields in Mustache contexts.
        $cardLabels = [
            'cardLatestVersion' => 'latest_version',
            'cardRuntime' => 'runtime',
            'cardMinimumDsm' => 'minimum_dsm',
            'cardInternalName' => 'internal_name',
            'cardDisplayName' => 'display_name',
            'cardMaintainer' => 'maintainer',
            'cardArchitectures' => 'architectures',
            'cardDetails' => 'details',
            'cardDownload' => 'download',
            'cardDownloadDisabled' => 'download_disabled',
        ];
        foreach ($cardLabels as $templateVariable => $languageKey) {
            $this->setVariable($templateVariable, $language->get($languageKey));
        }
        $languageOptions = $language->options();
        foreach ($languageOptions as &$languageOption) {
            $query = $_GET;
            $query['lang'] = $languageOption['code'];
            $languageOption['url'] = $this->config->baseUrlRelative . '?' . http_build_query($query);
        }
        unset($languageOption);
        $this->setVariable('languageOptions', $languageOptions);
        $this->setVariable(
            'showLanguageSelector',
            $this->config->language['show_selector']
        );
        $this->setVariable('languageCode', $language->code());
        $this->setVariable('htmlLang', $language->meta('html_lang', 'en-US'));
        $this->setVariable('ogLocale', $language->meta('og_locale', 'en_US'));
        $this->setVariable('i18nJson', json_encode($language->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->setVariable('pageTitle', $siteName);
        $this->setVariable('seoDescription', $seoDescription);
        $this->setVariable('seoKeywords', trim((string) $seoKeywords));
        $this->setVariable('canonicalUrl', $canonicalUrl);
        $this->setVariable('baseUrl', $this->config->baseUrl);
        $structuredData = '';
        if ($_GET === []) {
            $encodedStructuredData = json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => $this->config->baseUrl,
                'inLanguage' => $language->meta('html_lang', 'en-US'),
            ], JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT);
            $structuredData = $encodedStructuredData === false ? '' : $encodedStructuredData;
        }
        $this->setVariable('websiteStructuredData', $structuredData);
        $currentYear = (int) date('Y');
        $this->setVariable('currentYear', $currentYear);
        $this->setVariable('footerColumns', $this->config->footer['columns']);
        try {
            $statistics = (new SiteStatistics($this->config))->get();
            $this->setVariable('headerStatistics', true);
            $this->setVariable('modelCount', $statistics['models']);
            $this->setVariable('packageCount', $statistics['packages']);
            $this->setVariable('headerStatisticsText', $language->get('stats', [
                'models' => $statistics['models'],
                'packages' => $statistics['packages'],
            ]));
        } catch (\Throwable $e) {
            error_log('[SSpkS] Failed to read site statistics: ' . $e->getMessage());
            $this->setVariable('headerStatistics', false);
        }
        $this->setVariable('baseUrlRelative', $this->config->baseUrlRelative);
        $themeUrl = $this->config->baseUrlRelative . $this->config->paths['themes']
            . $this->config->site['theme'] . '/';
        $this->setVariable('themeUrl', $themeUrl);
        $themePath = dirname($tplBase);
        $mtime = static function (string $file): int {
            return is_file($file) ? (int) filemtime($file) : 0;
        };
        $this->setVariable('styleVersion', $mtime($themePath . '/css/style.css'));
        $this->setVariable('scriptVersion', $mtime($themePath . '/js/script.js'));
        $this->setVariable('updateVersion', $mtime($themePath . '/js/update.js'));
        $this->setVariable('socialImageUrl', $this->config->baseUrl . 'logo.png');
        $this->setVariable('commitHash', $this->config->SSPKS_COMMIT);
        $this->setVariable('branch', $this->config->SSPKS_BRANCH);
        $this->setVariable('defaultPalette', $this->config->appearance['default_palette']);
        $this->setVariable('modelShowAllByDefault', $this->config->models['show_all_by_default'] ? 'true' : 'false');
        $this->setVariable('modelInitialDesktop', $this->config->models['initial_count_desktop']);
        $this->setVariable('modelInitialMobile', $this->config->models['initial_count_mobile']);
        $advertisementItems = $this->config->advertisement['items'];
        foreach ($advertisementItems as $index => &$advertisementItem) {
            $advertisementItem['index'] = $index;
            $advertisementItem['position'] = $index + 1;
        }
        unset($advertisementItem);
        $advertisementWidth = 1200;
        $advertisementHeight = 240;
        $imageUrl = (string) ($advertisementItems[0]['image_url'] ?? '');
        $imageHost = parse_url($imageUrl, PHP_URL_HOST);
        if ($imageUrl !== '' && ($imageHost === null || $imageHost === parse_url($this->config->baseUrl, PHP_URL_HOST))) {
            $imagePath = rawurldecode((string) parse_url($imageUrl, PHP_URL_PATH));
            $baseUrlPath = (string) $this->config->baseUrlRelative;
            if ($baseUrlPath !== '/' && strpos($imagePath, $baseUrlPath) === 0) {
                $imagePath = substr($imagePath, strlen($baseUrlPath));
            }
            $localImage = realpath($this->config->basePath . DIRECTORY_SEPARATOR . ltrim($imagePath, '/\\'));
            $localRoot = realpath($this->config->basePath);
            $rootPrefix = $localRoot === false ? '' : $localRoot . DIRECTORY_SEPARATOR;
            $inside = $localImage !== false && $rootPrefix !== ''
                && (DIRECTORY_SEPARATOR === '\\'
                    ? strncasecmp($localImage, $rootPrefix, strlen($rootPrefix)) === 0
                    : strpos($localImage, $rootPrefix) === 0);
            if ($inside && is_file($localImage) && filesize($localImage) <= 20 * 1024 * 1024) {
                $dimensions = @getimagesize($localImage);
                if (is_array($dimensions) && $dimensions[0] > 0 && $dimensions[1] > 0
                    && $dimensions[0] <= 8192 && $dimensions[1] <= 8192) {
                    [$advertisementWidth, $advertisementHeight] = $dimensions;
                }
            }
        }
        $this->setVariable('advertisementWidth', $advertisementWidth);
        $this->setVariable('advertisementHeight', $advertisementHeight);
        $this->setVariable('advertisementEnabled', $this->config->advertisement['enabled'] && $advertisementItems !== []);
        $this->setVariable('advertisementItems', $advertisementItems);
        $this->setVariable('advertisementHasMultiple', count($advertisementItems) > 1);
        $this->setVariable('advertisementInterval', $this->config->advertisement['interval_seconds'] * 1000);
    }

    public function setVariable(string $name, $value): void
    {
        $this->tplVars[$name] = $value;
    }

    public function setTemplate(string $tplName): void
    {
        $this->template = $tplName;
    }

    public function output(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Content-Language: ' . $this->language->meta('html_lang', 'en-US'));
        header('Vary: Accept-Language, Cookie');
        if (!$this->hasResponseHeader('Cache-Control')) {
            header('Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        $tpl = $this->mustache->loadTemplate($this->template);
        echo $tpl->render($this->tplVars);
    }

    private function hasResponseHeader(string $name): bool
    {
        $prefix = strtolower($name) . ':';
        foreach (headers_list() as $header) {
            if (strpos(strtolower($header), $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
