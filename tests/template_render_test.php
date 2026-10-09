<?php

// Escalate PHP 8.4 warnings before autoloading so dependency declaration warnings fail the test.
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require dirname(__DIR__) . '/vendor/autoload.php';

use Mustache\Engine;
use Mustache\Loader\FilesystemLoader;
use Mustache\Logger\StreamLogger;

$root = dirname(__DIR__);
$base = $root . '/themes/material/templates';
$engine = new Engine([
    'loader' => new FilesystemLoader($base),
    'partials_loader' => new FilesystemLoader($base . '/partials'),
    'charset' => 'utf-8',
    'entity_flags' => ENT_QUOTES,
    'logger' => new StreamLogger('php://stderr'),
]);
$check = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
$packs = glob($root . '/languages/*.php') ?: [];
if ($packs === []) { $packs = [null]; }
$rendered = 0;
foreach ($packs as $packFile) {
    $pack = $packFile === null ? ['strings' => [], 'meta' => ['html_lang' => 'zh-CN']] : require $packFile;
    $variables = array_replace($pack['strings'], [
        'htmlLang' => $pack['meta']['html_lang'],
        'siteName' => 'Template test',
        'pageTitle' => '<script id="probe">bad</script>',
        'seoDescription' => 'Quotes " and apostrophe \'',
        'baseUrlRelative' => '/',
        'themeUrl' => '/themes/material/',
        'official_label' => $pack['strings']['official_label'] ?? '官方',
        'cardMaintainer' => 'Maintainer label',
        'cardLatestVersion' => 'Version label',
        'packagelist' => [[
            'package' => 'TestPackage', 'version' => '1.0-1', 'arch' => ['x86_64'],
            'displayname' => 'Package <name>', 'description' => '<img src=x onerror=alert(1)>',
            'maintainer' => 'Package author', 'packageMaintainer' => 'Package author',
            'maintainerUrl' => 'https://example.com/?q="quoted"',
            'thumbnail_url' => ['/test.webp'], 'os_min_ver' => '7.2.1-69057',
            'official' => true, 'run_as_root' => true, 'beta' => true,
            'downloadEnabled' => true, 'downloadUrl' => '/?download=token',
        ]],
    ]);
    foreach (glob($base . '/*.mustache') as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $html = $engine->loadTemplate($name)->render($variables);
        $check(str_contains($html, '<!DOCTYPE html>'), $name . ': head partial missing');
        $check(!str_contains($html, '<script id="probe">'), $name . ': title must be escaped');
        if ($name === 'html_update') {
            $check(preg_match('/<span class="update-summary__details">([\s\S]*?)<\/span>/',$html,$summary)===1,'Update detail counters must render in one group');
            $check(preg_match('/^[（(].*[）)]$/u',trim(strip_tags($summary[1])))===1,'Update detail counters must retain parentheses');
            foreach (['added','changed','unchanged','deleted'] as $counter) {
                $check(substr_count($html,'data-'.$counter.'-count')===1,'Update counter missing or duplicated: '.$counter);
            }
            $check(strpos($html,'update-summary__details')<strpos($html,'update-results'),'Counters must precede result lists');
        }
        if ($name === 'html_packagelist') {
            $check(str_contains($html, 'Package &lt;name&gt;'), 'Package display name must be escaped');
            $check(!str_contains($html, '<img src=x onerror='), 'Package description must be escaped');
            $check(str_contains($html, 'spk-official-badge') && str_contains($html, 'spk-beta-badge'), 'Package badges must remain visible');
            $check(str_contains($html, 'Package author'), 'Package maintainer must not be shadowed by a translated label');
            if ($packFile !== null) {
                $check(!str_contains($html, 'spk-root'), 'Multilingual edition must not display a ROOT badge');
                $check(str_contains($html, 'Maintainer label'), 'Card label must resolve from the outer context');
            } else {
                $check(str_contains($html, 'spk-root-badge'), 'Private edition ROOT badge must remain visible');
            }
        }
        $rendered++;
    }
}
$check(str_contains((new Engine(['entity_flags' => ENT_QUOTES]))->render('{{value}}', ['value' => "'\""]), '&#039;&quot;'), 'Both quote types must be escaped');
restore_error_handler();
printf("Template rendering passed: %d templates, no PHP warnings or deprecations.\n", $rendered);
