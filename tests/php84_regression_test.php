<?php
require dirname(__DIR__) . '/vendor/autoload.php';
use SSpkS\Config;
use SSpkS\Package\Package;
use SSpkS\Package\BrowserPackageCatalog;
use SSpkS\Package\BrowserImageObfuscator;
use SSpkS\Handler\UpdateHandler;
use think\facade\Db;
$repo = dirname(__DIR__);
$oldCwd = getcwd();
$temp = sys_get_temp_dir() . '/sspks-regression-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$remove = static function (string $dir) use (&$remove): void {
    foreach (new FilesystemIterator($dir) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { $remove($entry->getPathname()); }
        else { @unlink($entry->getPathname()); }
    }
    @rmdir($dir);
};
$check = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
try {
    foreach (['conf', 'cache', 'runtime', 'packages2025', 'languages'] as $dir) { mkdir($temp . '/' . $dir, 0700); }
    foreach (glob($repo . '/conf/*.yaml') as $file) { copy($file, $temp . '/conf/' . basename($file)); }
    foreach (glob($repo . '/languages/*.php') ?: [] as $file) { copy($file, $temp . '/languages/' . basename($file)); }
    file_put_contents($temp . '/conf/database.yaml', "database:\n  type: sqlite\n  database: runtime/test.sqlite\n  prefix: test_\nmanagement_password: test-only\n");
    putenv('SSPKS_DB_TYPE=sqlite');
    putenv('SSPKS_DB_SQLITE_PATH=' . $temp . '/runtime/test.sqlite');
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr;q=0,en;q=1';
    chdir($temp);
    $config = Config::getInstance($temp);
    $config->baseUrl = 'http://localhost/';
    $config->baseUrlRelative = '/';
    $config->paths = array_replace($config->paths, ['packages' => 'packages2025/', 'themes' => $repo . '/themes/']);
    $build = static function (string $name, string $version, bool $wizard = false) use ($temp, $repo): string {
        $tar = $temp . '/' . bin2hex(random_bytes(8)) . '.tar';
        $archive = new PharData($tar);
        $archive->addFromString('INFO', "package=\"Regression$name\"\nversion=\"$version\"\ndisplayname=\"Default title\"\ndisplayname_enu=\"English title\"\narch=\"x86_64\"\nos_min_ver=\"7.2.1-69057\"\ndescription=\"Regression test\"\nmaintainer=\"Test\"\nqinst=\"0\"\nqupgrade=\"0\"\nqstart=\"0\"\n");
        $archive->addFile($repo . '/themes/material/images/default_package_icon_72.png', 'PACKAGE_ICON.PNG');
        $archive->addFile($repo . '/themes/material/images/default_package_icon_120.png', 'PACKAGE_ICON_256.PNG');
        $archive->addFile($repo . '/themes/material/images/default_package_icon_72.png', 'screen_1.png');
        if ($wizard) { $archive->addFromString('WIZARD_UIFILES/install_uifile', '[]'); }
        unset($archive);
        $file = $temp . '/packages2025/' . $name . '.spk';
        rename($tar, $file);
        return $file;
    };
    $foo = $build('foo', '1.0-1', true);
    $package = new Package($config, $foo);
    $check(!$package->qinst && !$package->qupgrade && !$package->qstart, 'Explicit zero quick flags must be false');
    $wizard = new ReflectionMethod(Package::class, 'hasWizardDir');
    $check($wizard->invoke($package), 'Wizard directory must be detected on all platforms');
    $mtime = filemtime($foo);
    $size = filesize($foo);
    unlink($foo);
    $build('foo', '2.0-1', true);
    touch($foo, $mtime);
    clearstatcache();
    $check(filesize($foo) === $size, 'Replacement fixture must preserve size');
    $check((new Package($config, $foo))->version === '2.0-1', 'Same-stat replacement must invalidate metadata');
    $foobar = $build('foobar', '1.0-1');
    new Package($config, $foobar);
    $check(count((new Package($config, $foo))->snapshot) === 1, 'Screenshots must not leak between filename prefixes');
    $handler = new UpdateHandler($config);
    $rateLimit = new ReflectionMethod(UpdateHandler::class, 'updateAuthFailures');
    file_put_contents($temp . '/runtime/refresh-auth-rate.json', 'invalid JSON');
    $unavailable = false;
    try { $rateLimit->invoke($handler, 'read'); } catch (RuntimeException $e) { $unavailable = true; }
    $check($unavailable, 'Corrupt authentication state must fail closed');
    unlink($temp . '/runtime/refresh-auth-rate.json');
    $check($rateLimit->invoke($handler, 'record') === 1, 'Authentication failure must be persisted');
    $check($rateLimit->invoke($handler, 'read') === 1, 'Authentication failure must survive a second read');
    $check($rateLimit->invoke($handler, 'clear') === 0, 'Successful authentication must clear failures');
    $update = static function () use ($config): array {
        $job = new SSpkS\IndexUpdateJob($config);
        $state = $job->start('verify');
        for ($i = 0; $state['type'] !== 'complete' && $i < 1000; $i++) {
            $state = $job->step($state['job'], 0);
        }
        if ($state['type'] !== 'complete') { throw new RuntimeException('Index task did not finish'); }
        return $state;
    };
    $result = $update();
    $check($result['success'] === 2 && Db::name('spk')->count() === 2, 'PHP 8.4 index job must insert all packages');
    $result = $update();
    $check($result['unchanged'] === 2, 'Complete verification must reuse unchanged parsed records');
    $build('foo', '3.0-1');
    Db::execute("CREATE TRIGGER regression_fail BEFORE UPDATE ON test_spk BEGIN SELECT RAISE(ABORT,'test rollback'); END");
    $result = $update();
    Db::execute('DROP TRIGGER regression_fail');
    $check($result['failed'] === 1 && Db::name('spk')->count() === 2, 'Failed update must preserve the previous package record');
    $update(); // Retry the failed replacement before testing download resolution.
    $resolver = new SSpkS\Package\BrowserDownloadResolver($config);
    $token = basename(parse_url($resolver->urlForMd5(md5_file($foo)), PHP_URL_QUERY));
    parse_str($token, $downloadQuery);
    $check($resolver->resolve($downloadQuery['download']) !== null, 'Download map must resolve an indexed package');
    $resolvePath = new ReflectionMethod($resolver, 'resolvePackageFile');
    $outside = $temp . '/outside.spk'; file_put_contents($outside, 'outside');
    $check($resolvePath->invoke($resolver, $outside) === null, 'Download path must stay inside the package directory');
    $check($resolvePath->invoke($resolver, "x\0.spk") === null && $resolver->resolve('../x') === null, 'Invalid download paths and tokens must be rejected');
    foreach (["\xd9\x05x", str_repeat("\x91", 18) . "\xc0", "\xc0\xc0"] as $bytes) {
        $rejected = false;
        try { SSpkS\Package\BoundedMessagePackReader::decode($bytes); }
        catch (Throwable $e) { $rejected = true; }
        $check($rejected, 'Malformed or excessive MessagePack input must be rejected');
    }
    $catalog = new BrowserPackageCatalog($config);
    $rows = $catalog->getAll(true);
    $check(count($rows) === 2 && $rows[0]['displayname'] === 'English title', 'Browser catalog must localize package names');
    $check($catalog->getImageFailureCount() === 0, 'Browser images must convert successfully');
    unlink($foo); unlink($foobar);
    $rejected = false;
    try { $update(); }
    catch (RuntimeException $e) { $rejected = true; }
    $check($rejected && Db::name('spk')->count() === 2, 'Empty scan must preserve the previous index by default');
    $bad = $temp . '/cache/bad.png';
    file_put_contents($bad, 'invalid image');
    $images = new BrowserImageObfuscator($config);
    $check($images->publishUrls([$bad]) === [] && $images->getFailureCount() === 1, 'Malformed image must be rejected');
    $large = $temp . '/cache/large.png';
    $png = file_get_contents($repo . '/themes/material/images/default_package_icon_72.png');
    file_put_contents($large, substr_replace($png, pack('NN', 10000, 10000), 16, 8));
    $check($images->publishUrls([$large]) === [], 'Excessive image dimensions must be rejected before decoding');
    $originalBasePath = $config->basePath;
    $originalPaths = $config->paths;
    $config->basePath = $repo;
    $config->paths = array_replace($config->paths,['themes'=>'themes/']);
    $originalQuery = $_GET;
    $_GET = ['arch'=>['x86_64']];
    set_error_handler(static function (int $level,string $message): bool { throw new RuntimeException($message); });
    try { new \SSpkS\Output\HtmlOutput($config); }
    finally { restore_error_handler(); $_GET = $originalQuery; $config->basePath = $originalBasePath; $config->paths = $originalPaths; }
    echo "PHP 8.4 regression tests passed.\n";
} finally {
    chdir($oldCwd);
    Db::connect()->close();
    $remove($temp);
}
