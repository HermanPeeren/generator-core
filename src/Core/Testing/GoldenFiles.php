<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Testing
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Testing;

use Yepr\Gen\Core\Output\FileCollection;

/**
 * A fixture directory: models on one side, the output they must produce on the other.
 *
 *     <fixtures>/models/finder-recipes.json     the input
 *     <fixtures>/expected/finder-recipes/...    every file it must generate
 *
 * A golden file is not derived from a specification. It is output that somebody
 * looked at once and approved, committed so that the next run can be compared
 * against it byte for byte. For a generator that is close to ideal, because the
 * output *is* the product: a one line template change that quietly alters forty
 * files shows up as forty diffs in review rather than on a user's site.
 *
 * The cost is that a golden file is only as good as the moment it was blessed.
 * Accepting a change is therefore a separate, deliberate call to write() and not
 * a flag on the test run - the whole discipline is reading the diff first.
 *
 * No PHPUnit here: this is the part a build script or a bare PHP runner needs
 * too. GoldenTestCase is the thin layer that turns it into assertions.
 *
 * @since  0.5.0
 */
final class GoldenFiles
{
    /**
     * Constructor.
     *
     * @param   string  $fixtureDirectory  Directory holding models/ and expected/.
     * @param   string  $modelExtension    Extension of a model fixture, without the dot.
     *
     * @since   0.5.0
     */
    public function __construct(
        private readonly string $fixtureDirectory,
        private readonly string $modelExtension = 'json'
    ) {
    }

    /**
     * Every fixture name, sorted.
     *
     * Discovery rather than a list: dropping a model and its expected output
     * into the fixture directory is enough to have it checked from then on, with
     * no test to write and none to forget.
     *
     * @return  string[]  The fixture names, without extension.
     *
     * @since   0.5.0
     */
    public function names(): array
    {
        $pattern = $this->fixtureDirectory . '/models/*.' . $this->modelExtension;
        $names   = [];

        foreach (glob($pattern) ?: [] as $file) {
            $names[] = basename($file, '.' . $this->modelExtension);
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The path of one fixture's model file.
     *
     * @param   string  $name  The fixture name.
     *
     * @return  string  The absolute path.
     *
     * @throws  \OutOfBoundsException  When there is no such fixture.
     *
     * @since   0.5.0
     */
    public function modelPath(string $name): string
    {
        $path = $this->fixtureDirectory . '/models/' . $this->safe($name) . '.' . $this->modelExtension;

        if (!is_file($path)) {
            throw new \OutOfBoundsException(\sprintf('No fixture model "%s" in %s.', $name, $this->fixtureDirectory));
        }

        return $path;
    }

    /**
     * The contents of one fixture's model file.
     *
     * @param   string  $name  The fixture name.
     *
     * @return  string  The model as stored.
     *
     * @throws  \OutOfBoundsException  When there is no such fixture.
     *
     * @since   0.5.0
     */
    public function model(string $name): string
    {
        return (string) file_get_contents($this->modelPath($name));
    }

    /**
     * Where one fixture's approved output lives.
     *
     * @param   string  $name  The fixture name.
     *
     * @return  string  The absolute path of the directory.
     *
     * @since   0.5.0
     */
    public function expectedDirectory(string $name): string
    {
        return $this->fixtureDirectory . '/expected/' . $this->safe($name);
    }

    /**
     * One fixture's approved output, as path => contents.
     *
     * Contents are normalised to LF, so a checkout that landed with CRLF does
     * not fail against output generated on Linux.
     *
     * @param   string  $name  The fixture name.
     *
     * @return  array<string, string>  Relative path => contents, sorted by path.
     *
     * @since   0.5.0
     */
    public function expected(string $name): array
    {
        $root = $this->expectedDirectory($name);

        if (!is_dir($root)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));

            $files[$relative] = self::normalise((string) file_get_contents($file->getPathname()));
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Replace one fixture's approved output with what was just generated.
     *
     * The accept step, run deliberately after reading the diff. The directory is
     * emptied first, so a file the generator no longer produces disappears from
     * the golden set instead of lingering and silently passing forever.
     *
     * @param   string          $name   The fixture name.
     * @param   FileCollection  $files  The output to approve.
     *
     * @return  string[]  The relative paths written, sorted.
     *
     * @throws  \RuntimeException  When the directory cannot be prepared.
     *
     * @since   0.5.0
     */
    public function write(string $name, FileCollection $files): array
    {
        $root = $this->expectedDirectory($name);

        self::removeDirectory($root);

        if (!mkdir($root, 0755, true) && !is_dir($root)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $root));
        }

        $written = [];

        foreach ($files as $path => $contents) {
            $target    = $root . '/' . $path;
            $directory = \dirname($target);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(\sprintf('Cannot create "%s".', $directory));
            }

            file_put_contents($target, self::normalise($contents));
            $written[] = $path;
        }

        sort($written, SORT_STRING);

        return $written;
    }

    /**
     * Normalise text for comparison: LF, and exactly one trailing newline.
     *
     * @param   string  $text  The text.
     *
     * @return  string  The normalised text.
     *
     * @since   0.5.0
     */
    public static function normalise(string $text): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $text), "\n") . "\n";
    }

    /**
     * Reject a fixture name that could reach outside the fixture directory.
     *
     * A fixture name reaches the filesystem, and write() deletes a directory
     * built from it. That is worth checking once rather than trusting.
     *
     * @param   string  $name  The fixture name.
     *
     * @return  string  The name, unchanged.
     *
     * @throws  \InvalidArgumentException  When the name is not a plain fixture name.
     *
     * @since   0.5.0
     */
    private function safe(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a usable fixture name.', $name));
        }

        return $name;
    }

    /**
     * Delete a directory and everything under it.
     *
     * @param   string  $directory  The directory to remove.
     *
     * @return  void
     *
     * @since   0.5.0
     */
    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
