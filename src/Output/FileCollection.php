<?php

/**
 * @package     GeneratorCore
 * @subpackage  Output
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\GeneratorCore\Output;

/**
 * The result of a generation run: paths mapped to contents, held in memory.
 *
 * Generators write here and never touch the filesystem, which is what makes them
 * testable without a temp directory - and means a malformed model cannot write a
 * file anywhere, because at generation time there is no filesystem in play at all.
 *
 * Path containment is enforced on the way in rather than on the way out, so that
 * an invalid path fails at the generator that produced it.
 *
 * @implements \IteratorAggregate<string, string>
 *
 * @since  0.2.0
 */
final class FileCollection implements \IteratorAggregate, \Countable
{
    /**
     * The generated files, path => contents.
     *
     * @var    array<string, string>
     * @since  0.2.0
     */
    private array $files = [];

    /**
     * Add a generated file.
     *
     * @param   string  $path      The path inside the package.
     * @param   string  $contents  The file contents.
     *
     * @return  void
     *
     * @throws  \LogicException             When two generators claim the same path.
     * @throws  \InvalidArgumentException   When the path is not safe or not relative.
     *
     * @since   0.2.0
     */
    public function add(string $path, string $contents): void
    {
        $path = self::normalise($path);

        if (isset($this->files[$path])) {
            throw new \LogicException(\sprintf('The file "%s" was generated twice.', $path));
        }

        $this->files[$path] = $contents;
    }

    /**
     * Add or overwrite a generated file.
     *
     * Used when a later step deliberately rewrites an earlier file, such as a
     * merge of protected regions.
     *
     * @param   string  $path      The path inside the package.
     * @param   string  $contents  The file contents.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function replace(string $path, string $contents): void
    {
        $this->files[self::normalise($path)] = $contents;
    }

    /**
     * Whether a file was generated at this path.
     *
     * @param   string  $path  The path inside the package.
     *
     * @return  boolean  True when the file exists in the collection.
     *
     * @since   0.2.0
     */
    public function has(string $path): bool
    {
        return isset($this->files[self::normalise($path)]);
    }

    /**
     * Get the contents of one generated file.
     *
     * @param   string  $path  The path inside the package.
     *
     * @return  string  The file contents.
     *
     * @throws  \OutOfBoundsException  When no file was generated at that path.
     *
     * @since   0.2.0
     */
    public function get(string $path): string
    {
        $path = self::normalise($path);

        if (!isset($this->files[$path])) {
            throw new \OutOfBoundsException(\sprintf('No generated file "%s".', $path));
        }

        return $this->files[$path];
    }

    /**
     * All generated files, sorted by path so that output is deterministic.
     *
     * @return  array<string, string>  Path => contents.
     *
     * @since   0.2.0
     */
    public function all(): array
    {
        $files = $this->files;
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * The paths of all generated files, sorted.
     *
     * @return  string[]  The paths.
     *
     * @since   0.2.0
     */
    public function paths(): array
    {
        return array_keys($this->all());
    }

    /**
     * Iterate the generated files in path order.
     *
     * @return  \ArrayIterator<string, string>  An iterator over path => contents.
     *
     * @since   0.2.0
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    /**
     * How many files were generated.
     *
     * @return  integer  The number of files.
     *
     * @since   0.2.0
     */
    public function count(): int
    {
        return \count($this->files);
    }

    /**
     * Reduce a path to a safe, relative, forward-slashed form.
     *
     * Rejects absolute paths, drive letters, traversal and control characters.
     * Note that traversal is rejected, not resolved: a path that tries to climb
     * out is a bug in a generator, and silently rewriting it would hide that.
     *
     * @param   string  $path  The path to check.
     *
     * @return  string  The normalised, forward-slashed relative path.
     *
     * @throws  \InvalidArgumentException  When the path is empty, absolute, or escapes.
     *
     * @since   0.2.0
     */
    public static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '') {
            throw new \InvalidArgumentException('An empty path cannot be generated.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new \InvalidArgumentException('Path contains control characters.');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new \InvalidArgumentException(\sprintf('Path "%s" must be relative.', $path));
        }

        $segments = explode('/', $path);
        $clean    = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new \InvalidArgumentException(\sprintf('Path "%s" tries to leave the output directory.', $path));
            }

            $clean[] = $segment;
        }

        if ($clean === []) {
            throw new \InvalidArgumentException(\sprintf('Path "%s" does not name a file.', $path));
        }

        return implode('/', $clean);
    }
}
