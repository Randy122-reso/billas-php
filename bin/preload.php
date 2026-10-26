<?php

declare(strict_types=1);

/**
 * Optional OPcache preload helper for production images.
 *
 * Usage in php.ini / FPM pool:
 *   opcache.preload=/app/bin/preload.php
 *   opcache.preload_user=root
 *
 * The script compiles application classes without executing request logic.
 * Composer's autoloader is loaded first so inherited interfaces are known.
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found\n");
    exit(1);
}
require $autoload;

if (!function_exists('opcache_compile_file')) {
    fwrite(STDERR, "OPcache is not enabled; nothing to preload\n");
    exit(0);
}

$directories = [$root . '/src', $root . '/config'];
$count = 0;
$failed = 0;

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        if (@opcache_compile_file($file->getPathname())) {
            $count++;
        } else {
            $failed++;
        }
    }
}

fwrite(STDERR, sprintf("Billas preload: %d files compiled, %d skipped\n", $count, $failed));
