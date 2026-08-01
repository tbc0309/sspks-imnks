<?php

namespace SSpkS;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use think\facade\Cache;
use think\facade\Db;

/**
 * @property array $site
 * @property array $appearance
 * @property array $models
 * @property array $browser_download
 * @property array $browser_url_obfuscation
 * @property array $advertisement
 * @property array $packages
 * @property array $paths
 * @property array $excludedSynoServices
 * @property array $language
 * @property array $footer
 * @property array $update
 * @property string $basePath
 * @property string $baseUrl
 * @property string $baseUrlRelative
 * @property string $SSPKS_COMMIT
 * @property string $SSPKS_BRANCH
 */
final class Config
{
    private const DEFAULTS = [
        'site' => [
            'name' => 'SSPKS-IMNKS',
            'theme' => 'material',
            'base_url' => '',
            'redirectindex' => null,
            'description' => '',
            'keywords' => [],
        ],
        'appearance' => [
            'default_palette' => 'teal',
            'show_runtime_badges' => true,
        ],
        'models' => [
            'show_all_by_default' => false,
            'initial_count_desktop' => 24,
            'initial_count_mobile' => 12,
            'priority_models' => [],
        ],
        'browser_download' => ['enabled' => false],
        'browser_url_obfuscation' => [
            'package_images' => true,
            'spk_downloads' => true,
        ],
        'advertisement' => [
            'enabled' => false,
            'interval_seconds' => 6,
            'items' => [],
        ],
        'packages' => [
            'file_mask' => '*.spk',
            'maintainer' => '',
            'maintainer_url' => '',
            'distributor' => '',
            'distributor_url' => '',
            'support_url' => '',
            'changelog_ad_enabled' => false,
            'changelog_ad' => '',
        ],
        'paths' => [
            'cache' => 'cache/',
            'models' => 'conf/synology_models.yaml',
            'packages' => 'packages/',
            'themes' => 'themes/',
        ],
        'excludedSynoServices' => [],
        'language' => [
            'fixed' => '',
            'show_selector' => true,
        ],
        'footer' => [
            'columns' => [],
        ],
        'update' => [
            'action' => 'refresh',
        ],
    ];

    private const SITE_ENV = [
        'SSPKS_SITE_NAME' => ['site', 'name'],
        'SSPKS_SITE_THEME' => ['site', 'theme'],
        'SSPKS_SITE_REDIRECTINDEX' => ['site', 'redirectindex'],
        'SSPKS_BASE_URL' => ['site', 'base_url'],
        'SSPKS_PACKAGES_FILE_MASK' => ['packages', 'file_mask'],
        'SSPKS_PACKAGES_MAINTAINER' => ['packages', 'maintainer'],
        'SSPKS_PACKAGES_MAINTAINER_URL' => ['packages', 'maintainer_url'],
        'SSPKS_PACKAGES_DISTRIBUTOR' => ['packages', 'distributor'],
        'SSPKS_PACKAGES_DISTRIBUTOR_URL' => ['packages', 'distributor_url'],
        'SSPKS_PACKAGES_SUPPORT_URL' => ['packages', 'support_url'],
    ];

    private const MYSQL_DATABASE_ENV = [
        'SSPKS_DB_HOST' => 'hostname',
        'SSPKS_DB_PORT' => 'hostport',
        'SSPKS_DB_NAME' => 'database',
        'SSPKS_DB_USER' => 'username',
        'SSPKS_DB_PASSWORD' => 'password',
    ];

    private string $basePath;
    private array $config;
    private array $databaseConfig;
    private static ?self $instance = null;

    public static function getInstance(string $basePath, string $cfgFile = 'conf/sspks.yaml'): self
    {
        if (self::$instance === null) {
            self::$instance = new self($basePath, $cfgFile);
        }
        return self::$instance;
    }

    private function __construct(string $basePath, string $cfgFile)
    {
        $this->basePath = $basePath;
        $siteConfig = $this->readYaml($basePath . DIRECTORY_SEPARATOR . $cfgFile);
        $databaseFile = $this->readYaml($basePath . DIRECTORY_SEPARATOR . 'conf/database.yaml');

        if (!isset($databaseFile['database']) || !is_array($databaseFile['database'])) {
            throw new \RuntimeException('数据库配置结构无效');
        }
        foreach (self::DEFAULTS as $section => $defaultValue) {
            if (isset($siteConfig[$section])
                && is_array($defaultValue)
                && !is_array($siteConfig[$section])) {
                throw new \RuntimeException('配置段必须是 YAML 映射：' . $section);
            }
        }

        $this->config = array_replace_recursive(self::DEFAULTS, $siteConfig);
        $this->databaseConfig = $databaseFile['database'];
        $this->applyEnvironmentOverrides();
        $this->normaliseDatabaseOptions();
        $this->normaliseOptions();

        $this->config['SSPKS_COMMIT'] = trim($this->env('SSPKS_COMMIT'));
        $this->config['SSPKS_BRANCH'] = trim($this->env('SSPKS_BRANCH'));
        $this->config['update_token'] = trim((string) ($databaseFile['management_password'] ?? ''));
        $updateToken = trim($this->env('SSPKS_UPDATE_TOKEN'));
        if ($updateToken !== '') {
            $this->config['update_token'] = $updateToken;
        }
        $this->config['basePath'] = $this->basePath;

        $this->setCache();
        $this->connectDatabase();
    }

    private function readYaml(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('找不到配置文件：' . $file);
        }
        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if (!is_array($parsed)) {
            throw new \RuntimeException('配置文件必须包含 YAML 映射结构：' . $file);
        }
        return $parsed;
    }

    private function applyEnvironmentOverrides(): void
    {
        foreach (self::SITE_ENV as $envName => $path) {
            $value = $this->env($envName);
            if ($value !== '') {
                $this->config[$path[0]][$path[1]] = $value;
            }
        }
        $configuredDatabaseType = strtolower(trim((string) ($this->databaseConfig['type'] ?? 'mysql')));
        $databaseType = strtolower(trim($this->env('SSPKS_DB_TYPE')));
        if ($databaseType === 'sqlite3') {
            $databaseType = 'sqlite';
        }
        if ($databaseType !== '') {
            $this->databaseConfig['type'] = $databaseType;
        }
        if (in_array(strtolower(trim((string) ($this->databaseConfig['type'] ?? 'mysql'))), ['sqlite', 'sqlite3'], true)) {
            $databasePath = trim($this->env('SSPKS_DB_PATH'));
            if ($databasePath !== '') {
                $this->databaseConfig['database'] = $databasePath;
            } elseif ($databaseType === 'sqlite'
                && !in_array($configuredDatabaseType, ['sqlite', 'sqlite3'], true)) {
                $this->databaseConfig['database'] = 'runtime/sspks.sqlite3';
            }
        } else {
            foreach (self::MYSQL_DATABASE_ENV as $envName => $key) {
                $value = $this->env($envName);
                if ($value !== '') {
                    $this->databaseConfig[$key] = $value;
                }
            }
        }
    }

    private function normaliseOptions(): void
    {
        $theme = trim((string) $this->config['site']['theme']);
        if (preg_match('/^[a-z0-9_-]+$/iD', $theme) !== 1) {
            throw new \RuntimeException('主题名称格式无效');
        }
        $this->config['site']['theme'] = $theme;

        $baseUrl = trim((string) $this->config['site']['base_url']);
        if ($baseUrl !== '' && !$this->isAbsoluteHttpUrl($baseUrl)) {
            throw new \RuntimeException('site.base_url 必须是有效的 HTTP 或 HTTPS 网址');
        }
        $this->config['site']['base_url'] = $baseUrl;

        $palettes = ['teal', 'ocean', 'violet', 'dark'];
        $palette = strtolower(trim((string) $this->config['appearance']['default_palette']));
        $this->config['appearance']['default_palette'] = in_array($palette, $palettes, true) ? $palette : 'teal';
        $this->config['appearance']['show_runtime_badges'] = (bool) $this->config['appearance']['show_runtime_badges'];

        $this->config['models']['show_all_by_default'] = (bool) $this->config['models']['show_all_by_default'];
        $this->config['models']['initial_count_desktop'] = max(1, (int) $this->config['models']['initial_count_desktop']);
        $this->config['models']['initial_count_mobile'] = max(1, (int) $this->config['models']['initial_count_mobile']);
        $priorityModels = $this->config['models']['priority_models'];
        if (!is_array($priorityModels)) {
            $priorityModels = [];
        }
        $normalisedPriorityModels = [];
        foreach ($priorityModels as $model) {
            if (!is_scalar($model)) {
                continue;
            }
            $model = trim((string) $model);
            if ($model === '' || strlen($model) > 100) {
                continue;
            }
            $normalisedPriorityModels[strtolower($model)] = $model;
        }
        $this->config['models']['priority_models'] = array_values($normalisedPriorityModels);
        $this->config['browser_download']['enabled'] = (bool) $this->config['browser_download']['enabled'];
        $this->config['browser_url_obfuscation']['package_images'] = (bool) $this->config['browser_url_obfuscation']['package_images'];
        $this->config['browser_url_obfuscation']['spk_downloads'] = (bool) $this->config['browser_url_obfuscation']['spk_downloads'];
        $this->config['advertisement']['enabled'] = (bool) $this->config['advertisement']['enabled'];
        $this->config['advertisement']['interval_seconds'] = max(3, min(60, (int) $this->config['advertisement']['interval_seconds']));
        $this->config['advertisement']['items'] = $this->normaliseAdvertisementItems($this->config['advertisement']['items']);
        $this->config['packages']['changelog_ad_enabled'] = (bool) $this->config['packages']['changelog_ad_enabled'];
        $this->config['language']['show_selector'] = (bool) $this->config['language']['show_selector'];
        $this->config['footer'] = $this->normaliseFooter($this->config['footer']);

        $updateAction = trim((string) ($this->config['update']['action'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,127}$/iD', $updateAction) !== 1) {
            throw new \RuntimeException('update.action must contain 3 to 128 URL-safe characters');
        }
        $this->config['update']['action'] = $updateAction;
    }

    private function normaliseFooter($footer): array
    {
        if (!is_array($footer)) {
            return ['columns' => []];
        }
        $columns = [];
        foreach (($footer['columns'] ?? []) as $column) {
            if (!is_array($column)) {
                continue;
            }
            $title = trim((string) ($column['title'] ?? ''));
            $links = [];
            foreach (($column['links'] ?? []) as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $label = trim((string) ($link['label'] ?? ''));
                $url = trim((string) ($link['url'] ?? ''));
                if ($label !== '' && $this->isAbsoluteHttpUrl($url)) {
                    $links[] = ['label' => $label, 'url' => $url];
                }
            }
            if ($title !== '' && $links !== []) {
                $columns[] = ['title' => $title, 'links' => $links];
            }
        }
        return ['columns' => $columns];
    }

    private function normaliseDatabaseOptions(): void
    {
        $type = strtolower(trim((string) ($this->databaseConfig['type'] ?? 'mysql')));
        if ($type === 'sqlite3') {
            $type = 'sqlite';
        }
        if (!in_array($type, ['mysql', 'sqlite'], true)) {
            throw new \RuntimeException('database.type 仅支持 mysql 或 sqlite');
        }

        $prefix = (string) ($this->databaseConfig['prefix'] ?? 'wd_');
        if (preg_match('/^[a-z0-9_]*$/iD', $prefix) !== 1) {
            throw new \RuntimeException('数据库表前缀只能包含字母、数字和下划线');
        }

        if ($type === 'mysql') {
            $this->databaseConfig['type'] = 'mysql';
            $this->databaseConfig['prefix'] = $prefix;
            return;
        }

        $database = trim((string) ($this->databaseConfig['database'] ?? ''));
        if ($database === '') {
            throw new \RuntimeException('SQLite 数据库文件路径不能为空');
        }
        if (!$this->isAbsoluteFilesystemPath($database)) {
            $database = $this->basePath . DIRECTORY_SEPARATOR
                . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $database);
        }

        $directory = dirname($database);
        if (!is_dir($directory)
            && !mkdir($directory, 0770, true)
            && !is_dir($directory)) {
            throw new \RuntimeException('无法创建 SQLite 数据库目录：' . $directory);
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('SQLite 数据库目录不可写：' . $directory);
        }

        $this->databaseConfig = [
            'type' => 'sqlite',
            'database' => $database,
            'prefix' => $prefix,
            'debug' => (bool) ($this->databaseConfig['debug'] ?? false),
        ];
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        return strpos($path, '/') === 0
            || strpos($path, '\\') === 0
            || preg_match('/^[a-z]:[\\\\\/]/iD', $path) === 1;
    }

    private function normaliseAdvertisementItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalised = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $imageUrl = trim((string) ($item['image_url'] ?? ''));
            $targetUrl = trim((string) ($item['target_url'] ?? ''));
            if (!$this->isAllowedAdvertisementUrl($imageUrl)
                || !$this->isAllowedAdvertisementUrl($targetUrl)) {
                continue;
            }
            $alt = trim((string) ($item['alt'] ?? '广告'));
            $normalised[] = [
                'image_url' => $imageUrl,
                'target_url' => $targetUrl,
                'alt' => $alt === '' ? '广告' : $alt,
            ];
        }
        return $normalised;
    }

    private function isAllowedAdvertisementUrl(string $url): bool
    {
        if ($this->isAbsoluteHttpUrl($url)) {
            return true;
        }
        return strpos($url, '/') === 0 && strpos($url, '//') !== 0;
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function setCache(): void
    {
        Cache::config([
            'default' => 'file',
            'stores' => [
                'file' => [
                    'type' => 'File',
                    'path' => $this->basePath . '/runtime/cache/',
                    'prefix' => '',
                    'expire' => 0,
                ],
            ],
        ]);
    }

    private function connectDatabase(): void
    {
        $type = (string) $this->databaseConfig['type'];
        $extension = $type === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        if (!extension_loaded($extension)) {
            throw new \RuntimeException('当前数据库配置需要启用 PHP 扩展：' . $extension);
        }

        Db::setConfig([
            'default' => $type,
            'connections' => [$type => $this->databaseConfig],
        ]);
        if ($type === 'sqlite') {
            $this->initialiseSqliteDatabase();
        }
    }

    private function initialiseSqliteDatabase(): void
    {
        $table = '"' . $this->databaseConfig['prefix'] . 'spk"';
        $indexBase = 'idx_' . $this->databaseConfig['prefix'] . 'spk';
        Db::execute('PRAGMA busy_timeout = 5000');
        Db::execute('PRAGMA foreign_keys = ON');
        Db::execute('PRAGMA journal_mode = WAL');
        Db::execute(
            'CREATE TABLE IF NOT EXISTS ' . $table . ' ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT,'
            . '"displayname" TEXT NOT NULL DEFAULT \'\','
            . '"package" TEXT NOT NULL,'
            . '"version" TEXT NOT NULL,'
            . '"arch" TEXT NOT NULL DEFAULT \'\','
            . '"os_min_ver" TEXT NOT NULL,'
            . '"beta" INTEGER NOT NULL DEFAULT 0,'
            . '"spk" TEXT NOT NULL UNIQUE,'
            . '"filesize" INTEGER NOT NULL DEFAULT 0,'
            . '"md5" TEXT NOT NULL DEFAULT \'\','
            . '"filemtime" INTEGER NOT NULL DEFAULT 0,'
            . '"params" TEXT NOT NULL,'
            . '"create_time" INTEGER NOT NULL DEFAULT 0,'
            . '"update_time" INTEGER DEFAULT NULL'
            . ')'
        );
        Db::execute('CREATE INDEX IF NOT EXISTS "' . $indexBase . '_package" ON ' . $table . ' ("package")');
        Db::execute('CREATE INDEX IF NOT EXISTS "' . $indexBase . '_filter" ON ' . $table . ' ("beta", "os_min_ver")');
        @chmod((string) $this->databaseConfig['database'], 0660);
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        return is_string($value) ? $value : '';
    }

    public function __get(string $name)
    {
        return $this->config[$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        $this->config[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->config[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->config[$name]);
    }
}
