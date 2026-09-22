<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Package
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Package;

/**
 * A metalanguage package, read back.
 *
 * The other half of 3.3, and the reason the step is *done when a package
 * round-trips* rather than when one is written: a format nothing reads is a
 * guess about what a format should be. This one is read by the test that
 * proves the forms in the zip are the forms the generator produced, and by the
 * screen that lists what is in a package before anybody installs it.
 *
 * **It reads, and it does not install.** Where a package's files end up on a
 * site is the importing component's business: this says what is in one and
 * what is wrong with it.
 *
 * **It came from Meta-gen at 3.4, when there was a second reader.** Meta-gen
 * wrote the format at 3.3 and kept it, on the precedent the reference dropdown
 * set - that lived in Exten-gen until three components needed it and moved
 * when they did. A format Exten-gen and Gen-gen both read is a mechanism, and
 * mechanisms live here.
 *
 * **It knows nothing about what a language *means*.** `model()` hands back the
 * decoded JSON and stops. Meta-gen turns that into its `ConceptModel`,
 * Exten-gen into whatever it needs to open a project with; a library that
 * returned one of those would make every consumer depend on one consumer's
 * idea of a language, which is the dependency this move exists to avoid.
 *
 * @since  0.5.0
 */
final class PackageReader
{
    /**
     * @param  array<string, string>  $files  Package-relative path => contents.
     *
     * @since  0.5.0
     */
    private function __construct(private readonly array $files)
    {
    }

    /**
     * Read a package out of a zip.
     *
     * @throws \RuntimeException  When the archive cannot be opened or holds nothing.
     *
     * @since  0.5.0
     */
    public static function fromZip(string $path): self
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException(\sprintf('Cannot open the package "%s".', $path));
        }

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || str_ends_with($name, '/')) {
                continue;
            }

            $contents = $zip->getFromIndex($i);

            if ($contents === false) {
                $zip->close();

                throw new \RuntimeException(\sprintf('Cannot read "%s" out of the package.', $name));
            }

            $files[$name] = $contents;
        }

        $zip->close();

        return new self($files);
    }

    /**
     * Read a package that has already been unpacked.
     *
     * The same thing from the other side, which is what makes the tree the
     * generator writes beside each zip worth writing: it can be read back with
     * the same code, so a person looking at the files and the component
     * reading the archive are not two different definitions of the package.
     *
     * @throws \RuntimeException  When the directory is not there.
     *
     * @since  0.5.0
     */
    public static function fromDirectory(string $root): self
    {
        $real = realpath($root);

        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException(\sprintf('There is no package at "%s".', $root));
        }

        $files = [];
        $tree  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($real) + 1));

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        return new self($files);
    }

    /**
     * Everything in the package, keyed by its path inside it.
     *
     * @return array<string, string>
     *
     * @since  0.5.0
     */
    public function files(): array
    {
        $files = $this->files;

        ksort($files);

        return $files;
    }

    /**
     * What the package says about itself.
     *
     * @throws \RuntimeException  When there is no manifest, or it is not JSON.
     *
     * @since  0.5.0
     */
    public function manifest(): PackageManifest
    {
        if (!isset($this->files[MetalanguagePackage::MANIFEST])) {
            throw new \RuntimeException('This is not a metalanguage package: it has no manifest.');
        }

        try {
            return PackageManifest::fromJson($this->files[MetalanguagePackage::MANIFEST]);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The manifest is not JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The language the package was generated from, as bytes.
     *
     * @throws \RuntimeException  When the package carries none.
     *
     * @since  0.5.0
     */
    public function modelJson(): string
    {
        if (!isset($this->files[MetalanguagePackage::MODEL])) {
            throw new \RuntimeException('The package carries no concept model.');
        }

        return $this->files[MetalanguagePackage::MODEL];
    }

    /**
     * The same thing decoded, and no further.
     *
     * A `stdClass` rather than anybody's model type. What a language *means*
     * is the consumer's business - Meta-gen reads this into a `ConceptModel`,
     * Exten-gen into what it opens a project with - and a library that
     * returned one of those would make every consumer depend on one
     * consumer's idea of a language.
     *
     * @throws \RuntimeException  When the model is missing or unreadable.
     *
     * @since  0.5.0
     */
    public function model(): object
    {
        try {
            $decoded = json_decode($this->modelJson(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The concept model is not JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!\is_object($decoded)) {
            throw new \RuntimeException(
                'The concept model must be a JSON object, got ' . get_debug_type($decoded) . '.'
            );
        }

        return $decoded;
    }

    /**
     * The generated forms, keyed by their path inside the package.
     *
     * @return array<string, string>
     *
     * @since  0.5.0
     */
    public function forms(): array
    {
        $forms = [];

        foreach ($this->files() as $path => $contents) {
            if (str_starts_with($path, MetalanguagePackage::FORMS) && str_ends_with($path, '.xml')) {
                $forms[$path] = $contents;
            }
        }

        return $forms;
    }

    /**
     * The reference table the dropdowns on those forms read.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException  When it is missing or unreadable.
     *
     * @since  0.5.0
     */
    public function references(): array
    {
        if (!isset($this->files[MetalanguagePackage::REFERENCES])) {
            throw new \RuntimeException('The package carries no reference table.');
        }

        try {
            $table = json_decode($this->files[MetalanguagePackage::REFERENCES], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The reference table is not JSON: ' . $e->getMessage(), 0, $e);
        }

        return \is_array($table) ? $table : [];
    }

    /**
     * The text on this language's forms, as Joomla will read it.
     *
     * Parsed the way `LanguageHelper::parseIniFile()` parses it - raw scanning
     * and then one substitution of the escaped quote - so that what this
     * answers is what a site would show rather than what the file looks like.
     *
     * @return array<string, string>
     *
     * @since  0.5.0
     */
    public function strings(): array
    {
        $path = $this->manifest()->language;

        if ($path === '' || !isset($this->files[$path])) {
            return [];
        }

        $parsed = @parse_ini_string($this->files[$path], false, \INI_SCANNER_RAW);

        if (!\is_array($parsed)) {
            return [];
        }

        $strings = [];

        foreach ($parsed as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $strings[$key] = str_replace('\\"', '"', $value);
            }
        }

        return $strings;
    }

    /**
     * Everything wrong with this package, in the words to show somebody.
     *
     * A list rather than an exception, because a person choosing a file to
     * import wants to be told what is wrong with the one they picked, and
     * because the first complaint is rarely the useful one.
     *
     * @return string[]  Empty when the package is sound.
     *
     * @since  0.5.0
     */
    public function problems(): array
    {
        try {
            $manifest = $this->manifest();
        } catch (\RuntimeException $e) {
            return [$e->getMessage()];
        }

        $problems = [];

        if ($manifest->format !== MetalanguagePackage::FORMAT) {
            $problems[] = 'This package is in format ' . $manifest->format
                . ' and this component reads format ' . MetalanguagePackage::FORMAT . '.';
        }

        if ($manifest->name === '') {
            $problems[] = 'The manifest names no language.';
        }

        foreach ($manifest->files as $path => $hash) {
            if (!isset($this->files[$path])) {
                $problems[] = 'The manifest lists ' . $path . ', which is not in the package.';

                continue;
            }

            if (hash('sha256', $this->files[$path]) !== $hash) {
                // A truncated zip is the ordinary way here: a form file that
                // lost its last bytes still loads, as a fieldset with nothing
                // in it, and reports no error anywhere.
                $problems[] = $path . ' is not the file the manifest describes.';
            }
        }

        foreach ($this->files as $path => $contents) {
            unset($contents);

            if ($path !== MetalanguagePackage::MANIFEST && !isset($manifest->files[$path])) {
                $problems[] = $path . ' is in the package and not in the manifest.';
            }
        }

        if ($this->forms() === []) {
            $problems[] = 'The package holds no forms, so there is nothing to edit a model with.';
        }

        sort($problems);

        return $problems;
    }
}
