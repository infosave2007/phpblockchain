#!/usr/bin/env php
<?php
/**
 * Build release ZIP for deployment across nodes without overwriting local configuration/state.
 *
 * Included: core code, APIs, wallet, sync scripts, vendor (PHP deps), public assets.
 * Excluded: config/installation.json, config/.env, config/security.php, database storage, logs, runtime storage data, node-specific state.
 *
 * Output: release-YYYYmmdd-His.zip in project root (or specify --out=filename.zip)
 */

$root = realpath(__DIR__ . '/..');
if (!$root) { fwrite(STDERR, "Cannot resolve project root\n"); exit(1); }
chdir($root);

$options = getopt('', ['out::','tag::']);
$outName = $options['out'] ?? ('release-' . date('Ymd-His') . '.zip');
$tag = $options['tag'] ?? '';

$zipPath = $root . '/' . $outName;
if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create zip: $zipPath\n");
    exit(1);
}

$includeDirs = [
    'api','core','wallet','sync-service','explorer','web-installer','contracts','nodes','monitoring'
];
$includeFiles = [
    'quick_sync.php','network_sync.php','sync_manager.php','startup.php','server.php','cli.php','crypto-cli.php','check.php','index.php','composer.json','composer.lock','package.json','README.md','LICENSE'
];

$excludePatterns = [
    '#^config/installation\.json$#i',
    '#^config/\.env$#i',
    '#^config/security\.php$#i',
    '#^logs/.*#i',
    '#^storage/.*#i',
    '#^database/.*#i',
    '#^vendor/bin/.*#i',
    '#^vendor/composer/installed\.json$#i',
    '#^\.git/.*#i',
    '#^\.idea/.*#i',
    '#^tests/.*#i',
    '#^sync-service/sync_service\.log$#i',
];

// Add vendor fully (except bin) to ensure dependencies present
$includeVendor = true;

$addedCount = 0;
$skipped = 0;

$shouldExclude = function(string $rel) use ($excludePatterns): bool {
    foreach ($excludePatterns as $pat) {
        if (preg_match($pat, $rel)) return true;
    }
    return false;
};

$addFile = function(string $path) use (&$zip, $root, &$addedCount, $shouldExclude) {
    $rel = ltrim(str_replace($root, '', $path), '/');
    if ($shouldExclude($rel)) return;
    if (is_dir($path)) return;
    $zip->addFile($path, $rel);
    $addedCount++;
};

foreach ($includeDirs as $dir) {
    $full = $root . '/' . $dir;
    if (!is_dir($full)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $addFile($f->getPathname());
    }
}

foreach ($includeFiles as $file) {
    $full = $root . '/' . $file;
    if (file_exists($full) && is_file($full)) {
        $addFile($full);
    }
}

if ($includeVendor && is_dir($root . '/vendor')) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/vendor', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = ltrim(str_replace($root, '', $f->getPathname()), '/');
        if ($shouldExclude($rel)) continue;
        if ($f->isDir()) continue;
        $zip->addFile($f->getPathname(), $rel);
        $addedCount++;
    }
}

// Write marker file with metadata
$meta = [
    'built_at' => date('c'),
    'tag' => $tag,
    'added_files' => $addedCount,
    'php_version' => PHP_VERSION,
];
$zip->addFromString('RELEASE_META.json', json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

$zip->close();

echo "Release archive created: $zipPath\nFiles added: $addedCount\n";
exit(0);
