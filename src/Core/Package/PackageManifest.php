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
 * What a package says about itself.
 *
 * The plan asks for a manifest "naming the language, its version and its root
 * classifier", and those three are the identity: a project written in a
 * language records the first two (3.4), and the third is the form a model of
 * it opens at. Everything else here exists so that a reader can refuse a
 * package instead of half-loading one.
 *
 * **It names the language's concepts, by key and by name.** A consumer that
 * has to offer them - Gen-gen, where a rule says which concepts it applies to -
 * would otherwise have to read the concept model and know how a language is
 * stored, which is Meta-gen's business and not a thing to teach three
 * components. The manifest is where a package says what is in it.
 *
 * The key travels beside the name because they answer different questions. A
 * name is what a person picks from a list; a key is what a rule should *store*,
 * so that renaming a concept in Meta-gen does not silently unpick every rule
 * written against it. It is also the half a LionWeb metapointer needs, which
 * is what a chunk exporter would come here for.
 *
 * **The file list carries a hash each**, which is the only way "the forms in
 * it are the forms the generator produced" is a question with an answer. A
 * reader that merely finds the files it expects cannot tell a truncated zip
 * from a complete one, and a form file that lost its last bytes is a form
 * Joomla loads as an empty fieldset - a screen with nothing on it, and no
 * error anywhere. The manifest does not hash itself, for the obvious reason.
 *
 * @since  0.5.0
 */
final class PackageManifest
{
    /**
     * @param  string                 $name      The language's own name.
     * @param  string                 $key       That name as it appears in a path.
     * @param  string                 $version   The language's version, or `0.0.0`.
     * @param  string                 $root      The root classifier's name, or '' when the language has none.
     * @param  string                 $formRoot  Where the package expects to be unpacked, from the site root.
     * @param  string                 $language  The package-relative path of the language file.
     * @param  string                 $tag       The language tag that file is for.
     * @param  array<int, array{key: string, name: string}>  $concepts  The classifiers the language holds, in the order it declares them.
     * @param  array<string, string>  $files     Package-relative path => sha256 of its contents.
     * @param  int                    $format    The package format version.
     * @param  string                 $generated When this package was built, as an ISO 8601 instant.
     *
     * @since  0.5.0
     */
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        public readonly string $version,
        public readonly string $root,
        public readonly string $formRoot,
        public readonly string $language,
        public readonly string $tag,
        public readonly array $concepts,
        public readonly array $files,
        public readonly int $format = MetalanguagePackage::FORMAT,
        public readonly string $generated = ''
    ) {
    }

    /**
     * Read a manifest back out of its own JSON.
     *
     * Everything is read defensively, because this is the one file in the
     * package that arrives from somewhere else. A reader's job is to say what
     * is wrong with a package, and it cannot do that if constructing the
     * manifest is what throws.
     *
     * @throws \JsonException  When the file is not JSON at all.
     *
     * @since  0.5.0
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \InvalidArgumentException(
                'A manifest must be a JSON object, got ' . get_debug_type($data) . '.'
            );
        }

        $files = [];

        foreach (\is_array($data['files'] ?? null) ? $data['files'] : [] as $path => $hash) {
            if (\is_string($path) && \is_string($hash)) {
                $files[$path] = $hash;
            }
        }

        // A package built before this field existed simply has none, and an
        // empty list is the right answer for one: it holds classifiers, but
        // nothing in it says which. Refusing such a package would be refusing
        // one that is otherwise complete.
        $concepts = [];

        foreach (\is_array($data['concepts'] ?? null) ? $data['concepts'] : [] as $concept) {
            if (\is_array($concept) && \is_string($concept['key'] ?? null) && \is_string($concept['name'] ?? null)) {
                $concepts[] = ['key' => $concept['key'], 'name' => $concept['name']];
            }
        }

        return new self(
            self::text($data, 'name'),
            self::text($data, 'key'),
            self::text($data, 'version'),
            self::text($data, 'root'),
            self::text($data, 'formRoot'),
            self::text($data, 'language'),
            self::text($data, 'tag'),
            $concepts,
            $files,
            \is_int($data['format'] ?? null) ? $data['format'] : 0,
            self::text($data, 'generated')
        );
    }

    /**
     * The manifest as it is written into the package.
     *
     * @return array<string, mixed>
     *
     * @since  0.5.0
     */
    public function toArray(): array
    {
        return [
            'format'    => $this->format,
            'name'      => $this->name,
            'key'       => $this->key,
            'version'   => $this->version,
            'root'      => $this->root,
            'formRoot'  => $this->formRoot,
            'language'  => $this->language,
            'tag'       => $this->tag,
            'generated' => $this->generated,
            'concepts'  => $this->concepts,
            'files'     => $this->files,
        ];
    }

    /**
     * The manifest as the bytes that go in the package.
     *
     * @throws \JsonException  When something in it cannot be encoded.
     *
     * @since  0.5.0
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * How this language is named where a person chooses one.
     *
     * @since  0.5.0
     */
    public function label(): string
    {
        return $this->name . ' ' . $this->version;
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @since  0.5.0
     */
    private static function text(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        return \is_string($value) ? $value : '';
    }
}
