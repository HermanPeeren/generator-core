<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Metalanguage
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Joomla\Metalanguage;

use Yepr\Gen\Core\Package\PackageReader;

/**
 * Putting an imported metalanguage's files on the site: step 3.4.
 *
 * **Installing is a plain unpack**, and that is the point of the format rather
 * than a shortcut. A package declares where it expects to live, because a
 * subform's `formsource` is resolved as `JPATH_ROOT . '/' . $formsource` and
 * cannot be package relative - so the paths were decided when the forms were
 * generated. Nothing here rewrites any XML on the way in, which is what makes
 * 3.3's round-trip proof worth anything: the forms that run are the bytes
 * Meta-gen produced.
 *
 * **It refuses before it writes anything.** `PackageReader::problems()` is
 * asked first and the whole install stops on the first complaint, because half
 * an installed language is worse than none: the root form would open and the
 * subform three levels down would render as an empty box, with nothing
 * reported anywhere. That is the failure this family has met three times.
 *
 * **It touches no database**, which is why it is separate from
 * `MetalanguageImporter`. Everything that can go wrong with a package goes
 * wrong in here - a zip that is not one, a manifest that does not match its
 * files, an entry named `../../configuration.php` - and none of it was
 * reachable from a test while validating and recording were one class.
 *
 * @since  0.6.0
 */
final class MetalanguageInstaller
{
    /**
     * @param  string  $siteRoot  Absolute path of the site, which is what `formRoot` is relative to.
     *
     * @since  0.6.0
     */
    public function __construct(private readonly string $siteRoot)
    {
    }

    /**
     * The manifest of the last package installed.
     *
     * @var string
     *
     * @since  0.6.0
     */
    private string $manifestJson = '';

    /**
     * Install one package, replacing that language and version if it is here.
     *
     * Replacing rather than refusing: re-importing is how somebody fixes a
     * language they have just changed in Meta-gen, and a version they are
     * still working on gets re-exported a dozen times before it is finished.
     * A version that is finished gets a new number, which is what the number
     * is for.
     *
     * @param  string  $archive  Absolute path of the zip to read.
     *
     * @return MetalanguageEntry  What was installed.
     *
     * @throws \RuntimeException  When the package is refused, with every reason in the message.
     *
     * @since  0.6.0
     */
    public function install(string $archive): MetalanguageEntry
    {
        $package  = PackageReader::fromZip($archive);
        $problems = $package->problems();

        if ($problems !== []) {
            throw new \RuntimeException(
                'This is not a metalanguage package that can be installed: ' . implode(' ', $problems)
            );
        }

        $manifest = $package->manifest();

        if ($manifest->root === '') {
            // A language nobody has marked a partition on packages fine - that
            // is an ordinary state for one being written - but there is no
            // form a project in it would open at, so importing one would put a
            // choice in the dropdown that cannot be chosen.
            throw new \RuntimeException(
                'This language has no root classifier, so no project could be written in it. '
                . 'Mark one of its concepts as a partition in Meta-gen and export it again.'
            );
        }

        $target = rtrim($this->siteRoot, '/\\') . '/' . rtrim($manifest->formRoot, '/');

        $this->replaceTree($target, $package->files());

        $entry = new MetalanguageEntry(
            $manifest->key,
            $manifest->version,
            $manifest->name,
            $manifest->root,
            rtrim($manifest->formRoot, '/') . '/',
            $manifest->language,
            false,
            0,
            $manifest->concepts
        );

        $this->manifestJson = $manifest->toJson();

        return $entry;
    }

    /**
     * Write the package's files under one directory, and nothing else.
     *
     * Every path is re-checked against the root it is going under even though
     * `FileCollection` already refused traversal on the way in, because this
     * set did not come through a `FileCollection` - it came out of a zip
     * somebody uploaded, and a zip may name `../../configuration.php`.
     *
     * @param  array<string, string>  $files
     *
     * @throws \RuntimeException  When a path escapes, or a file cannot be written.
     *
     * @since  0.6.0
     */
    private function replaceTree(string $root, array $files): void
    {
        $real = rtrim(str_replace('\\', '/', $root), '/');

        foreach (array_keys($files) as $path) {
            $clean = str_replace('\\', '/', $path);

            if ($clean === '' || str_starts_with($clean, '/') || preg_match('~(^|/)\.\.(/|$)~', $clean)) {
                throw new \RuntimeException('The package names a path outside itself: ' . $path);
            }
        }

        foreach ($files as $path => $contents) {
            $target    = $real . '/' . str_replace('\\', '/', $path);
            $directory = \dirname($target);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Cannot create ' . $directory);
            }

            if (file_put_contents($target, $contents) === false) {
                throw new \RuntimeException('Cannot write ' . $target);
            }
        }
    }

    /**
     * The manifest of the package last installed, as bytes.
     *
     * The row `MetalanguageImporter` writes keeps it whole, so that what a
     * site believes about an imported language can be compared against the
     * package it came from without unpacking anything again.
     *
     * @since  0.6.0
     */
    public function manifestJson(): string
    {
        return $this->manifestJson;
    }
}
