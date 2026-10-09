# SSPKS-IMNKS

English | [简体中文](README.md)

A multilingual, self-hosted SPK repository for Synology DSM 7, derived from [jdel/sspks](https://github.com/jdel/sspks). Features include 21 UI languages, model/architecture filtering, ordinary and official keytype 3 SPKs, incremental indexing and resumable updates.

[Author-maintained site](https://spk7.imnks.com/)

![Interface preview](docs/images/sspks-imnks-demo.png)


## Features

- 21 UI languages, browser detection or a configured language, manual switching and cookie persistence.
- Responsive Material interface with four palettes, model search, priority models and progressive package cards.
- Filtering by model, architecture, DSM version and stable/beta channel, with localized package names and descriptions.
- Ordinary TAR SPKs and official keytype 3 encrypted SPKs, official badges and runtime badges.
- SQLite or MySQL/MariaDB indexing, incremental MD5 reuse, chunked full verification, live progress and resumable jobs.
- Converted browser images, optional browser downloads and URL obfuscation, configurable footer links and banner rotation.

Simplified Chinese and English are maintained directly. Other languages are AI-translated; wording improvements are welcome.

## 1. Requirements

- PHP 8.4 or later with PHP-FPM.
- Extensions: json, pdo, pdo_sqlite, pdo_mysql, phar, sodium and mbstring. Use GD with WebP support or Imagick for images.
- PHP must be able to write to cache/ and runtime/. Resumable tasks require pdo_sqlite even when the main index uses MySQL.

Release packages include vendor/; no dependency installation is required.

## 2. Deploy and configure

1. Download the release ZIP and extract it into your web directory.
2. Edit conf/sspks.yaml: set site.base_url to the public URL and choose a hard-to-guess update.action. Set browser_download.enabled to true to allow browser downloads.
3. Edit conf/database.yaml and set management_password. SQLite is the default and creates tables automatically. For MySQL/MariaDB, configure the connection and import wd_spk2.sql with a matching table prefix.
4. Apply the Nginx routing and access restrictions below. Replace the domain, root, PHP-FPM socket and download alias. Enable HTTPS, run nginx -t and reload.
5. Update the Sitemap URL in robots.txt.

Language follows the browser by default. Use language.fixed for a default language (such as chs or enu) and language.show_selector for the language menu. Other interface options are documented in conf/sspks.yaml comments.


### Common settings

Site settings are in conf/sspks.yaml; database settings and the management password are in conf/database.yaml.

| Setting | Purpose |
| --- | --- |
| site.name / site.base_url | Site name and public URL, including a trailing slash |
| update.action | Index-management action: 3–128 letters, digits, dots, underscores or hyphens |
| language.fixed / language.show_selector | Default language and language-switching menu |
| paths.packages | SPK directory, default packages/ |
| appearance.default_palette | Default palette: teal, ocean, violet or dark |
| appearance.show_runtime_badges | Show Docker, PHP, Python and other runtime badges |
| models.show_all_by_default / models.priority_models | Initial model display and priority models |
| browser_download.enabled | Browser downloads; independent of DSM Package Center downloads |
| browser_url_obfuscation | Image and browser SPK download URL obfuscation |
| advertisement / footer | Banner rotation and footer links |

Language codes: chs, cht, csy, dan, enu, fre, ger, hun, ita, jpn, krn, nld, nor, plk, ptb, ptg, rus, spn, sve, tha and trk. Leave language.fixed blank for detection. A configured language is enforced when the selector is disabled.

Obfuscated browser SPK downloads require the internal Nginx download location. When changing the package directory, update both paths.packages and the alias. Browser downloads are disabled by default; DSM Package Center remains available.

## 3. Add packages and refresh the index

1. Place DSM 7 .spk files in packages/, or the directory configured by paths.packages.
2. Open https://your-domain/?action=your-configured-value, enter the management password and click Update index.
3. Check the package list after completion.
4. In DSM, open Package Center → Settings → Package Sources → Add and enter a name and your public site URL.

Daily updates process new or changed SPKs and reuse MD5 for unchanged size/mtime. Editing a matching .nfo in cache/ also updates metadata when you refresh the index. Full verification recalculates all MD5 values. Work runs in short batches; reopen the page and enter the password to resume. Parsing failures or an empty package directory preserve existing records.

Package Center responses are cached by model/architecture, DSM base version/build, language and update channel, without separate Update patch numbers. The cache expires after one hour and is cleared when indexing completes.


Success includes added, changed and unchanged files; deletion is counted separately. The total is the number of scanned SPKs. Each package commits independently, so completed records take effect before the entire job ends. Use Full verification for content replaced without changing size or modification time.

Extracted files use the SPK filename: .nfo contains metadata, .source tracks invalidation, and wizard markers and images are stored separately. Normally, do not edit .source. Package Center responses use at most 4096 cache slots; monitor runtime/cache disk usage.

## 4. Nginx configuration

This example uses the domain root. Only cache images are public. The internal download alias must match your package directory and remain internal. Configure your CDN to preserve JSON errors from management requests and bypass caching for them.

```nginx
server {
    listen 80;
    server_name packages.example.com;
    root /var/www/sspks-imnks;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_intercept_errors off;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }

    location ^~ /_sspks_download/ {
        internal;
        alias /var/www/sspks-imnks/packages/;
        default_type application/octet-stream;
    }

    location ~ ^/(?:conf|languages|lib|runtime|vendor)(?:/|$) {
        deny all;
    }

    location ~* \.(?:ya?ml|sql|sqlite3?|log|lock|mustache)$ {
        deny all;
    }

    location ~ /\.(?!well-known(?:/|$)) {
        deny all;
    }

    location = /composer.json {
        deny all;
    }

    location ^~ /packages/ {
        autoindex off;
        try_files $uri =404;
        types { application/octet-stream spk; }
    }

    location ^~ /cache/ {
        if ($uri !~* \.(?:png|webp)$) { return 404; }
        autoindex off;
        try_files $uri =404;
        add_header X-Content-Type-Options nosniff always;
    }

    client_max_body_size 16m;
}
```

For a subdirectory deployment, adjust site.base_url, Nginx locations and aliases accordingly.

## 5. Upgrade and maintain

- Back up configuration, index databases and edited .nfo files.
- Keep conf/, package directories, cache/ and runtime/ when replacing application files, then refresh the index. Parser or SPK changes may regenerate .nfo files.
- runtime/ holds databases and tasks; cache/ holds extracted content. Block public access to configuration, source, logs and cache metadata, and monitor disk space.
- Encrypted official SPKs require sodium. Keytype 3 is supported; other historical or future formats may not be.
- Download counts are placeholders, not actual usage statistics.


## Troubleshooting

- **Wrong password or rate limit:** Check management_password or the SSPKS_UPDATE_TOKEN environment variable. Wrong passwords return 401; after repeated failures, 429 requires a one-minute wait. Authentication failures do not start a job.
- **Interrupted update:** Reopen the configured action and enter the password to resume. Ensure runtime/ is writable and pdo_sqlite is enabled. Prevent CDN HTML error-page substitution.
- **Missing packages:** Check the directory, .spk files, index results, architecture and minimum DSM requirements. Consult server logs for parsing errors.
- **Image or download failures:** Check cache/ permissions, image extensions and the internal download alias. Browser download settings do not affect DSM downloads.

## License

Derived from [jdel/sspks](https://github.com/jdel/sspks), licensed under [GNU GPL v3](LICENSE). Third-party SPKs retain their own licenses.
