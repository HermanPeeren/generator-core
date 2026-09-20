<?php

/**
 * Assembles the installable Joomla library.
 *
 *   php build/build.php        ->  build/lib_yepr_gen-<version>.zip
 *
 * The version comes from the manifest and from nowhere else, so there is one
 * place to change it and no second place to forget.
 *
 * Two things the staging step does deliberately:
 *
 * - Dependencies are resolved here, with --no-dev. The Joomla installer has no
 *   composer and not every host can run one, so the resolved vendor tree ships
 *   inside the package. That is ordinary Joomla practice.
 * - src/Core/Testing is left out. It exists for consuming projects' test suites,
 *   not for anything the library does at run time, and it is the only part that
 *   would drag PHPUnit onto a production site.
 */

declare(strict_types=1);

$root     = \dirname(__DIR__);
$manifest = $root . '/yepr_gen.xml';

$xml = simplexml_load_file($manifest);

if ($xml === false) {
    fwrite(STDERR, "Cannot read the manifest at {$manifest}.\n");
    exit(1);
}

$version = trim((string) $xml->version);
$library = trim((string) $xml->libraryname);

if ($version === '' || $library === '') {
    fwrite(STDERR, "The manifest needs both a version and a libraryname.\n");
    exit(1);
}

$staging = $root . '/build/tmp/' . $library;
$zipPath = $root . '/build/lib_' . $library . '-' . $version . '.zip';

echo "Building lib_{$library} {$version}\n";

// --- clean staging ----------------------------------------------------------

removeDirectory($root . '/build/tmp');

if (!mkdir($staging, 0755, true) && !is_dir($staging)) {
    fwrite(STDERR, "Cannot create {$staging}.\n");
    exit(1);
}

// --- the library's own code -------------------------------------------------

$skipped = 0;

copyTree($root . '/src', $staging . '/src', static function (string $relative) use (&$skipped): bool {
    // Testing/ is for consuming projects' suites, not for a production site.
    if (str_starts_with(str_replace('\\', '/', $relative), 'Core/Testing')) {
        $skipped++;

        return false;
    }

    return true;
});

echo '  src/                     copied (' . $skipped . " file(s) skipped from Core/Testing)\n";

// The browser half of the reference dropdown. The manifest's <media> element
// is what puts it under media/lib_yepr_gen on a site; this is what puts it in
// the package for the installer to find, and without it the element is defined
// nowhere and every reference dropdown silently keeps whatever the server
// rendered.
copyTree($root . '/media', $staging . '/media', static fn (string $relative): bool => true);

echo '  media/                   copied
';

copy($manifest, $staging . '/yepr_gen.xml');
copy($root . '/LICENSE.txt', $staging . '/LICENSE.txt');

// --- dependencies, resolved for production ----------------------------------

copy($root . '/composer.json', $staging . '/composer.json');

if (is_file($root . '/composer.lock')) {
    copy($root . '/composer.lock', $staging . '/composer.lock');
    $command = 'install';
} else {
    // The lock file is not committed for a library, so a build without one
    // resolves the constraints instead of refusing to run.
    $command = 'update';
}

$composer = sprintf(
    'composer %s --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir=%s 2>&1',
    $command,
    escapeshellarg($staging)
);

exec($composer, $output, $status);

if ($status !== 0) {
    fwrite(STDERR, "composer {$command} failed:\n" . implode("\n", $output) . "\n");
    exit(1);
}

// composer.json and the lock are build inputs, not things to ship.
@unlink($staging . '/composer.json');
@unlink($staging . '/composer.lock');

echo '  vendor/                  ' . count(glob($staging . '/vendor/*') ?: []) . " entries\n";

// --- the archive ------------------------------------------------------------

if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Cannot create {$zipPath}.\n");
    exit(1);
}

$files = 0;

foreach (walk($staging) as $absolute) {
    // Forward slashes always: a backslash in a zip entry becomes a literal
    // backslash in a filename on Linux, and the extension then fails to load.
    $entry = str_replace('\\', '/', substr($absolute, \strlen($staging) + 1));

    $zip->addFile($absolute, $entry);
    $files++;
}

$zip->close();

removeDirectory($root . '/build/tmp');

printf("\n%s\n  %d files, %s\n", basename($zipPath), $files, formatSize((int) filesize($zipPath)));

/**
 * Copy a directory tree, asking a filter about each file.
 *
 * @param  callable(string): bool  $keep  Given the path relative to $from.
 */
function copyTree(string $from, string $to, callable $keep): void
{
    foreach (walk($from) as $absolute) {
        $relative = substr($absolute, \strlen($from) + 1);

        if (!$keep($relative)) {
            continue;
        }

        $target    = $to . '/' . $relative;
        $directory = \dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            fwrite(STDERR, "Cannot create {$directory}.\n");
            exit(1);
        }

        copy($absolute, $target);
    }
}

/** @return iterable<string> Every file under a directory, in a stable order. */
function walk(string $directory): iterable
{
    if (!is_dir($directory)) {
        return [];
    }

    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

function formatSize(int $bytes): string
{
    return $bytes > 1048576
        ? number_format($bytes / 1048576, 1) . ' MB'
        : number_format($bytes / 1024, 1) . ' KB';
}
