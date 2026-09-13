<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use SSpkS\Package\Package;
use SSpkS\Package\PackageFilter;

// Test compatibility and build selection without reading SPK files or a database.
$filter = (new ReflectionClass(PackageFilter::class))->newInstanceWithoutConstructor();
$matching = new ReflectionMethod(PackageFilter::class, 'isMatchingOsVersion');
$preferred = new ReflectionMethod(PackageFilter::class, 'isPreferredPackage');
$matching->setAccessible(true);
$preferred->setAccessible(true);
$make = static function (string $version, string $minimum, string $maximum = ''): Package {
    $package = (new ReflectionClass(Package::class))->newInstanceWithoutConstructor();
    $package->version = $version;
    $package->os_min_ver = $minimum;
    $package->os_max_ver = $maximum;
    return $package;
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$old = $make('1.0-1', '7.1-42661');
$new = $make('1.0-1', '7.2-64570');
$filter->setOsVersionFilter('7.1-42962');
$check($matching->invoke($filter, $old), 'DSM 7.1 must accept the 7.1 build');
$check(!$matching->invoke($filter, $new), 'DSM 7.1 must reject the 7.2 build');
$filter->setOsVersionFilter('7.2-64570');
$check($matching->invoke($filter, $old) && $matching->invoke($filter, $new), 'DSM 7.2 must accept both builds');
$check($preferred->invoke($filter, $new, $old), 'Equal versions must prefer the 7.2 build');
$check(!$preferred->invoke($filter, $old, $new), 'Selection must not depend on input order');
$check(!$matching->invoke($filter, $make('1.0-1', '7.2-69057')), 'DSM build numbers must be checked');
$check(!$matching->invoke($filter, $make('1.0-1', '7.1-42661', '7.1-42962')), 'Maximum DSM versions must be checked');
$check($preferred->invoke($filter, $make('2.0-1', '7.1-42661'), $new), 'Package versions must retain priority');
$filter->setOsVersionFilter(null);
$check(!$preferred->invoke($filter, $new, $old), 'Browser selection must remain unchanged without a DSM version');
echo "DSM package selection tests passed.\n";
