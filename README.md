# SSPKS-IMNKS

[English](README.md) | [简体中文](README.zh-CN.md)

A multilingual, self-hosted SPK package repository for Synology DSM 7, derived from [jdel/sspks](https://github.com/jdel/sspks).

**Live site:** Visit [spk7.imnks.com](https://spk7.imnks.com/) to see the original SSPKS-IMNKS deployment maintained by the project author.

![SSPKS-IMNKS demo](docs/images/sspks-imnks-demo.png)

## Why this edition

- 21 complete UI languages with browser detection, an optional fixed default, and remembered visitor selection.
- Simplified Chinese and English are maintained directly; all other language packs are AI-translated and may require native-speaker review.
- MySQL/MariaDB and SQLite3 backends. SQLite is the demonstration default and is usually the simpler choice for a repository with only a few hundred packages.
- Responsive Material interface with four palettes, model search, priority models, progressive package cards, runtime badges, Synology branding, configurable footer links, and an advertisement carousel.
- DSM 7 metadata validation, guarded SPK archive reading, model/architecture filtering, and localized package descriptions.
- Resumable index refreshes with checkpoints every 50 packages and streamed MD5 reads in 8 MiB chunks.
- Transactional index replacement: a failed database write preserves the previous index.
- Generated WebP browser thumbnails and optional obfuscation of image and SPK download URLs.

## Requirements

- PHP 7.4 or later
- PHP extensions: `json`, `pdo`, `phar`, plus `pdo_sqlite` and/or `pdo_mysql`
- A web server with PHP-FPM or equivalent
- Write access to `cache/` and `runtime/`

The committed `vendor/` directory was installed for PHP 7.4 and is ready for a PHP 7.4 deployment. For another PHP version, rebuild it on that runtime:

```bash
composer install --no-dev --classmap-authoritative --no-interaction
```

## Quick start

1. Copy the project to the web root.
2. Edit `conf/sspks.yaml` and `conf/database.yaml`.
3. Change the example URL and both management/database passwords before going online.
4. Put DSM 7 `.spk` files in `packages/`.
5. Make `cache/` and `runtime/` writable by PHP.
6. Change `update.action`, open `/?action={configured-action}`, and enter the management password.

SQLite creates its schema automatically. For MySQL/MariaDB, import `wd_spk2.sql`. An SQLite schema reference is provided in `wd_spk2.sqlite.sql`.

## Recommended Nginx configuration

The application handles its own friendly 404 page. Nginx must therefore send requests for files that do not exist to `index.php` while continuing to serve real static assets directly. The example below also blocks sensitive source/configuration paths and provides the internal download location required when browser SPK URL obfuscation is enabled.

```nginx
server {
    listen 80;
    server_name packages.example.com;
    root /var/www/sspks-imnks;
    index index.php;

    # Existing assets are served directly; every unknown URL reaches the
    # application router so SSPKS-IMNKS can render its own 404 page.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Only the front controller may execute PHP.
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php7.4-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }

    # Required by X-Accel-Redirect for obfuscated browser downloads.
    # Keep this internal and make the alias match paths.packages.
    location ^~ /_sspks_download/ {
        internal;
        alias /var/www/sspks-imnks/packages/;
        default_type application/octet-stream;
    }

    # Never expose application source, dependencies, configuration, or runtime data.
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

    # SPK files must remain reachable by DSM Package Center. Disable listing,
    # but do not block the packages directory itself.
    location ^~ /packages/ {
        autoindex off;
        try_files $uri =404;
        types { application/octet-stream spk; }
    }

    client_max_body_size 16m;
}
```

Replace the domain, project root, PHP-FPM socket, and package alias for your server. If the project is installed below a URL prefix instead of the domain root, also adjust `site.base_url` and the matching Nginx locations. Test with `nginx -t` before reloading Nginx. Do not use `error_page 404 /index.php`; `try_files` lets the application distinguish its own routes and render the bundled 404 page correctly.

## Configuration notes

### Language

Set `language.fixed` in `conf/sspks.yaml` for the first visit. Leave it blank to detect the browser language and fall back to English. When `language.show_selector` is `true`, visitors may switch language and the choice is remembered in a cookie. When it is `false`, the selector is hidden and the configured language is enforced.

Supported codes: `chs`, `cht`, `csy`, `dan`, `enu`, `fre`, `ger`, `hun`, `ita`, `jpn`, `krn`, `nld`, `nor`, `plk`, `ptb`, `ptg`, `rus`, `spn`, `sve`, `tha`, and `trk`.

### Public URL and robots.txt

The included configuration intentionally uses `https://packages.example.com/`. Before deployment, replace it in `conf/sspks.yaml` and update the `Sitemap` line in `robots.txt` to your actual public domain. Keeping the example value is safe but prevents search engines from discovering the correct sitemap.

### Private refresh action

Set `update.action` in `conf/sspks.yaml` to a hard-to-guess URL-safe value, then open `https://your-domain/?action={configured-action}` to refresh the index. The value must contain 3–128 letters, numbers, dots, underscores, or hyphens. Only the configured action is accepted. This reduces routine discovery of the management page, while the management password and authentication rate limit remain the primary protections.

### Database

SQLite is convenient for small and medium repositories because it needs no separate database server, keeps data in one ignored runtime file, and has a smaller administration surface. MySQL/MariaDB remains useful for remote database hosting, centralized backups, monitoring, or heavier concurrent workloads.

## Download count limitation

Download counting is **not implemented**. The Synology Package Center response currently contains demonstration values only:

- `download_count`: `2026`
- `recent_download_count`: `0`

Their initial values are hard-coded in `lib/SSpkS/Output/JsonOutput.php`, inside `packageToJson()`. They are not stored, incremented, deduplicated, or calculated over a time window. Change those two values there if different placeholders are required; do not present them as real statistics.

## Security checklist

- Replace all example URLs and passwords.
- Block direct HTTP access to `conf/`, `lib/`, `vendor/`, `runtime/`, and template sources.
- Keep SQLite files and refresh checkpoints under `runtime/`; these are excluded by `.gitignore`.
- Do not expose MySQL/MariaDB directly to the public internet.
- Review every third-party SPK and its license before publication.
- Keep generated cache files, databases, logs, `.env`, and `.spk` packages out of Git.

## Main differences from upstream

| Area | jdel/sspks upstream | SSPKS-IMNKS |
| --- | --- | --- |
| Target | General SSPKS base | DSM 7-focused validation and presentation |
| Database | No database; package metadata is read from files | MySQL/MariaDB and SQLite3 index backends |
| Languages | Upstream language set | 21 UI language packs and persistent switching |
| Interface | Original theme | Responsive Material UI, palettes, model tools, ads, configurable footer |
| Refresh | Standard indexing | Streaming hash reads, progress events, checkpoints, resume support |
| Browser assets | Direct package assets | Generated WebP thumbnails and optional URL obfuscation |
| PHP 7.4 deployment | Composer installation required | Prebuilt PHP 7.4 `vendor/` included |

This is a derivative project, not a drop-in patch set. Review configuration and database migration requirements before replacing an existing installation.

## License and credits

Derived from [jdel/sspks](https://github.com/jdel/sspks). Distributed under [GNU GPL v3](LICENSE) (`GPL-3.0-only`). Third-party SPK packages retain their own licenses.
