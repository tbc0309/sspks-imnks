<?php

namespace SSpkS;

final class Language
{
    public const SUPPORTED = [
        'chs', 'cht', 'csy', 'dan', 'enu', 'fre', 'ger', 'hun', 'ita', 'jpn',
        'krn', 'nld', 'nor', 'plk', 'ptb', 'ptg', 'rus', 'spn', 'sve', 'tha', 'trk',
    ];

    private const ACCEPT_LANGUAGE_MAP = [
        'zh-cn' => 'chs', 'zh-sg' => 'chs', 'zh-hans' => 'chs',
        'zh-tw' => 'cht', 'zh-hk' => 'cht', 'zh-mo' => 'cht', 'zh-hant' => 'cht',
        'cs' => 'csy', 'da' => 'dan', 'en' => 'enu', 'fr' => 'fre', 'de' => 'ger',
        'hu' => 'hun', 'it' => 'ita', 'ja' => 'jpn', 'ko' => 'krn', 'nl' => 'nld',
        'no' => 'nor', 'nb' => 'nor', 'nn' => 'nor', 'pl' => 'plk',
        'pt-br' => 'ptb', 'pt' => 'ptg', 'ru' => 'rus', 'es' => 'spn',
        'sv' => 'sve', 'th' => 'tha', 'tr' => 'trk',
    ];

    private Config $config;
    private string $code;
    private array $strings;
    private array $meta;
    private static ?self $instance = null;

    public static function getInstance(Config $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->code = $this->detectLanguage();
        $fallback = $this->loadPack('enu');
        $selected = $this->code === 'enu' ? $fallback : $this->loadPack($this->code);
        $this->strings = array_replace($fallback['strings'], $selected['strings']);
        $this->meta = array_replace($fallback['meta'], $selected['meta']);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function meta(string $key, string $fallback = ''): string
    {
        return isset($this->meta[$key]) && is_scalar($this->meta[$key])
            ? (string) $this->meta[$key]
            : $fallback;
    }

    public function all(): array
    {
        return $this->strings;
    }

    public function get(string $key, array $values = []): string
    {
        $text = isset($this->strings[$key]) && is_scalar($this->strings[$key])
            ? (string) $this->strings[$key]
            : $key;
        foreach ($values as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    public function options(): array
    {
        $options = [];
        foreach (self::SUPPORTED as $code) {
            $pack = $this->loadPack($code);
            $options[] = [
                'code' => $code,
                'name' => (string) ($pack['meta']['name'] ?? strtoupper($code)),
                'selected' => $code === $this->code,
            ];
        }
        return $options;
    }

    private function detectLanguage(): string
    {
        $fixed = strtolower(trim((string) ($this->config->language['fixed'] ?? '')));
        if (!in_array($fixed, self::SUPPORTED, true)) {
            $fixed = '';
        }
        $allowSwitch = (bool) ($this->config->language['show_selector'] ?? true);
        if ($fixed !== '' && !$allowSwitch) {
            return $fixed;
        }

        $requested = isset($_GET['lang']) && is_string($_GET['lang'])
            ? strtolower(trim($_GET['lang']))
            : '';
        if (in_array($requested, self::SUPPORTED, true)) {
            setcookie('sspks_language', $requested, [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            return $requested;
        }

        $cookie = isset($_COOKIE['sspks_language']) && is_string($_COOKIE['sspks_language'])
            ? strtolower(trim($_COOKIE['sspks_language']))
            : '';
        if (in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }

        if ($fixed !== '') {
            return $fixed;
        }

        $acceptLanguage = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        foreach (explode(',', $acceptLanguage) as $part) {
            $locale = trim(explode(';', $part, 2)[0]);
            if (isset(self::ACCEPT_LANGUAGE_MAP[$locale])) {
                return self::ACCEPT_LANGUAGE_MAP[$locale];
            }
            $primary = explode('-', $locale, 2)[0];
            if (isset(self::ACCEPT_LANGUAGE_MAP[$primary])) {
                return self::ACCEPT_LANGUAGE_MAP[$primary];
            }
        }

        return 'enu';
    }

    private function loadPack(string $code): array
    {
        if (!in_array($code, self::SUPPORTED, true)) {
            throw new \InvalidArgumentException('Unsupported language pack: ' . $code);
        }
        $filename = $this->config->basePath . DIRECTORY_SEPARATOR . 'languages'
            . DIRECTORY_SEPARATOR . $code . '.php';
        $pack = require $filename;
        if (!is_array($pack)
            || !isset($pack['meta'], $pack['strings'])
            || !is_array($pack['meta'])
            || !is_array($pack['strings'])) {
            throw new \RuntimeException('Invalid language pack: ' . $code);
        }
        return $pack;
    }
}
