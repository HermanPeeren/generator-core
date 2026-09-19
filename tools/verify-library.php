<?php

/**
 * Verify the built library against a real Joomla, then leave no trace.
 *
 *   php tools/verify-library.php /path/to/joomla
 *
 * It does what the installer does with the files, then lets Joomla's own
 * namespacemap builder run - the same class the `extension - namespacemap`
 * plugin calls after an install - and checks both halves of the autoloading
 * story: this library's namespace, registered by Joomla from the manifest, and
 * the vendored third-party tree, registered by the library itself.
 *
 * It deliberately never loads this repository's vendor/autoload.php, because the
 * claim under test is "no composer at run time".
 *
 * Installing by hand through the Joomla interface proves the same thing, but
 * only once, and only where somebody remembers to do it.
 *
 * Everything it touches is restored on exit, including on failure.
 */

declare(strict_types=1);

$site = $argv[1] ?? null;
$zip  = $argv[2] ?? null;

if ($site === null) {
    fwrite(STDERR, "Usage: php tools/verify-library.php <path-to-joomla> [path-to-zip]\n");
    exit(1);
}

$site = rtrim(str_replace('\\', '/', $site), '/');

if (!is_file($site . '/libraries/src/Version.php')) {
    fwrite(STDERR, "{$site} does not look like a Joomla installation.\n");
    exit(1);
}

if ($zip === null) {
    $found = glob(\dirname(__DIR__) . '/build/lib_yepr_gen-*.zip') ?: [];

    if ($found === []) {
        fwrite(STDERR, "No package in build/. Run php build/build.php first.\n");
        exit(1);
    }

    sort($found, SORT_STRING);
    $zip = (string) end($found);
}

$libraryDir  = $site . '/libraries/yepr_gen';
$manifestDst = $site . '/administrator/manifests/libraries/yepr_gen.xml';
$cacheFile   = $site . '/administrator/cache/autoload_psr4.php';
$cacheBackup = sys_get_temp_dir() . '/autoload_psr4.backup.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$remove = static function (string $dir) use (&$remove): void {
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        is_dir("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }

    rmdir($dir);
};

$versionSource = (string) file_get_contents($site . '/libraries/src/Version.php');
preg_match('/MAJOR_VERSION\s*=\s*(\d+)/', $versionSource, $major);
preg_match('/MINOR_VERSION\s*=\s*(\d+)/', $versionSource, $minor);
preg_match('/PATCH_VERSION\s*=\s*(\d+)/', $versionSource, $patch);

printf("Joomla %s.%s.%s at %s\n", $major[1] ?? '?', $minor[1] ?? '?', $patch[1] ?? '?', $site);
printf("Package %s\n\n", basename($zip));

// --- refuse to touch a site that already has it -----------------------------

if (is_dir($libraryDir) || is_file($manifestDst)) {
    $fail('the library is already installed on that site; refusing to overwrite it');
}

if (!is_file($cacheFile)) {
    $fail('that site has no autoload_psr4.php yet; open it in a browser once first');
}

copy($cacheFile, $cacheBackup);

// Restores on every exit path, including a failed assertion.
register_shutdown_function(static function () use ($remove, $libraryDir, $manifestDst, $cacheFile, $cacheBackup): void {
    $remove($libraryDir);
    @unlink($manifestDst);

    if (is_file($cacheBackup)) {
        copy($cacheBackup, $cacheFile);
        unlink($cacheBackup);
    }
});

// --- 1. install the files, as the installer would ---------------------------

$archive = new ZipArchive();

if ($archive->open($zip) !== true) {
    $fail("cannot open {$zip}");
}

$archive->extractTo($libraryDir);
$archive->close();

copy($libraryDir . '/yepr_gen.xml', $manifestDst);

echo "1. files installed          libraries/yepr_gen, manifest in administrator/manifests/libraries\n";

// --- 2. let Joomla rebuild its namespace map --------------------------------

\define('_JEXEC', 1);
\define('JPATH_ROOT', $site);
\define('JPATH_SITE', $site);
\define('JPATH_BASE', $site);
\define('JPATH_ADMINISTRATOR', $site . '/administrator');
\define('JPATH_LIBRARIES', $site . '/libraries');
\define('JPATH_MANIFESTS', $site . '/administrator/manifests');
\define('JPATH_CACHE', $site . '/administrator/cache');
\define('JPATH_PLUGINS', $site . '/plugins');
\define('JPATH_API', $site . '/api');
\define('JPATH_THEMES', $site . '/templates');

require $site . '/libraries/vendor/autoload.php';
require $site . '/libraries/namespacemap.php';

unlink($cacheFile);

$map = new JNamespacePsr4Map();
$map->create();

if (!is_file($cacheFile)) {
    $fail('Joomla did not write autoload_psr4.php');
}

$entries = require $cacheFile;

if (!isset($entries['Yepr\\Gen\\'])) {
    $fail('Yepr\\Gen\\ is not in the map Joomla built; the manifest namespace was not picked up');
}

echo '2. namespace registered     Yepr\\Gen\\ => ' . $entries['Yepr\\Gen\\'][0] . "\n";
echo "   built by Joomla's own JNamespacePsr4Map, with no help from us\n";

// --- 3. resolve a library class, with no composer anywhere ------------------

$map->load();

if (!class_exists(Yepr\Gen\Core\Pipeline::class)) {
    $fail('Yepr\\Gen\\Core\\Pipeline did not resolve');
}

echo "3. our class resolves       Yepr\\Gen\\Core\\Pipeline, through the map alone\n";

// --- 4. resolve a vendored third-party class -------------------------------

if (class_exists(Twig\Environment::class, false)) {
    $fail('Twig was already loaded, so this step would prove nothing');
}

// Constructing the renderer is what registers the bundled vendor tree.
$renderer = Yepr\Gen\Core\Template\TwigRenderer::forTemplates(['t' => 'class {{ name }} {}']);

if (!class_exists(Twig\Environment::class, false)) {
    $fail('Twig did not load from the bundled vendor tree');
}

echo "4. vendored Twig resolves   registered by TwigRenderer itself, no require_once by a consumer\n";

// --- 5. and the thing actually generates ------------------------------------

$rendered = $renderer->render('t', ['name' => 'Flight']);

if ($rendered !== "class Flight {}\n") {
    $fail('the renderer produced ' . var_export($rendered, true));
}

echo '5. generation works         ' . trim($rendered) . "\n";

echo "\nPASS - the site has been restored to how it was.\n";
