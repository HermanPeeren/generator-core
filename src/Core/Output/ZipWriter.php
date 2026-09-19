<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Output
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Output;

/**
 * Writes a FileCollection to an installable zip.
 *
 * Entry names always use forward slashes: Windows PowerShell and some PHP builds
 * will happily write backslashes, and Joomla's unpacker then creates files with
 * literal backslashes in their names on a Linux host, where the extension simply
 * fails to load.
 *
 * @since  0.2.0
 */
final class ZipWriter
{
    /**
     * Write a generated file set to an archive.
     *
     * @param   FileCollection  $files    The generated files.
     * @param   string          $zipPath  Absolute path of the archive to create.
     *
     * @return  string  The path of the archive that was written.
     *
     * @throws  \RuntimeException  When the directory or the archive cannot be created.
     *
     * @since   0.2.0
     */
    public function write(FileCollection $files, string $zipPath): string
    {
        $directory = \dirname($zipPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $directory));
        }

        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException(\sprintf('Cannot create the archive "%s".', $zipPath));
        }

        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Write a generated file set into a directory.
     *
     * Only used for regeneration in place, and only under a root the caller
     * controls: every target is re-checked against that root before writing.
     *
     * @param   FileCollection  $files  The generated files.
     * @param   string          $root   Absolute path of the output directory.
     *
     * @return  string[]  The relative paths that were written.
     *
     * @throws  \RuntimeException  When the root is missing or a target escapes it.
     *
     * @since   0.2.0
     */
    public function writeToDirectory(FileCollection $files, string $root): array
    {
        $realRoot = realpath($root);

        if ($realRoot === false) {
            throw new \RuntimeException(\sprintf('The output directory "%s" does not exist.', $root));
        }

        $written = [];

        foreach ($files as $path => $contents) {
            $target    = $realRoot . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $path);
            $directory = \dirname($target);

            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(\sprintf('Cannot create "%s".', $directory));
            }

            // Belt and braces: the collection already rejects traversal, but the
            // resolved parent must still sit under the root.
            $realParent = realpath($directory);

            if ($realParent === false || !str_starts_with($realParent . \DIRECTORY_SEPARATOR, $realRoot . \DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException(\sprintf('Refusing to write outside the output directory: "%s".', $path));
            }

            file_put_contents($target, $contents);
            $written[] = $path;
        }

        return $written;
    }
}
