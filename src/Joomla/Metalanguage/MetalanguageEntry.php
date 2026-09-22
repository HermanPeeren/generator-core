<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Metalanguage
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Joomla\Metalanguage;

/**
 * One metalanguage a site has: step 3.4.
 *
 * A key and a version identify it, and both are needed - the plan settled that
 * a site holds as many languages as have been imported and that two versions of
 * one language sit beside each other, so a project recording only the key would
 * not say which forms open it.
 *
 * **A component's own shipped language is one of these too**, and that is the
 * point. Exten-gen ships ER1 and offers it in the same list as every imported
 * language, so that when 3.5 turns ER1 into a package it becomes an ordinary
 * row and nothing above this changes. `isBuiltIn()` is the only thing that
 * tells the two apart, and it exists so that the things which genuinely differ
 * - where the root form is, and whether there is a generated reference table -
 * have somewhere to ask.
 *
 * **This is the library's rather than a component's** because Exten-gen and
 * Gen-gen both keep one of these lists: Exten-gen to know which forms open a
 * project, Gen-gen to know which concepts a rule may select. What differs
 * between them is the table it is stored in and whether they ship a language of
 * their own, so those are arguments and everything else is here.
 *
 * @since  0.6.0
 */
final class MetalanguageEntry
{
    /**
     * @param  string  $key          The language's name as it appears in a path.
     * @param  string  $version      Which version of it.
     * @param  string  $name         What it calls itself.
     * @param  string  $root         The classifier a model of it opens at, or '' for the built-in.
     * @param  string  $formRoot     Where its forms live, from the site root, with a trailing slash.
     * @param  string  $languageFile Its language file, relative to `formRoot`.
     * @param  bool      $builtIn   Whether the component ships this rather than having imported it.
     * @param  int       $id        Its row id, or 0 for a built-in, which is a row nowhere.
     * @param  array<int, array{key: string, name: string}>  $concepts  The classifiers it holds, which is what a rule may name.
     * @param  string    $rootForm  Where its root form is, when that is not derived from the package layout.
     *
     * @since  0.6.0
     */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $name,
        public readonly string $root,
        public readonly string $formRoot,
        public readonly string $languageFile,
        public readonly bool $builtIn = false,
        public readonly int $id = 0,
        public readonly array $concepts = [],
        public readonly string $rootForm = ''
    ) {
    }

    /**
     * One row of a component's metalanguage table.
     *
     * @param  object  $row  As the database hands it back.
     *
     * @since  0.6.0
     */
    public static function fromRow(object $row): self
    {
        return new self(
            (string) ($row->lang_key ?? ''),
            (string) ($row->version ?? ''),
            (string) ($row->name ?? ''),
            (string) ($row->root ?? ''),
            (string) ($row->form_root ?? ''),
            (string) ($row->language_file ?? ''),
            false,
            (int) ($row->id ?? 0),
            self::conceptsIn((string) ($row->manifest ?? ''))
        );
    }

    /**
     * The concepts a stored manifest names.
     *
     * Read out of the manifest kept on the row rather than by unpacking the
     * package again, and defended all the way down because this is a text
     * column: a manifest written by an older Meta-gen has no `concepts` in it
     * at all, and the answer for one of those is an empty list rather than an
     * error on a screen that is only trying to fill a dropdown.
     *
     * @return array<int, array{key: string, name: string}>
     *
     * @since  0.6.0
     */
    private static function conceptsIn(string $manifest): array
    {
        if ($manifest === '') {
            return [];
        }

        try {
            $decoded = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded) || !\is_array($decoded['concepts'] ?? null)) {
            return [];
        }

        $concepts = [];

        foreach ($decoded['concepts'] as $concept) {
            if (\is_array($concept) && \is_string($concept['key'] ?? null) && \is_string($concept['name'] ?? null)) {
                $concepts[] = ['key' => $concept['key'], 'name' => $concept['name']];
            }
        }

        return $concepts;
    }

    /**
     * The concepts, as a value-to-label map for a dropdown.
     *
     * Keyed by key rather than by name, because the key is what a rule stores:
     * renaming a concept in Meta-gen then changes what the rule *reads* as and
     * not what it points at. A name is what a person recognises, so it is what
     * is shown.
     *
     * @return array<string, string>
     *
     * @since  0.6.0
     */
    public function conceptChoices(): array
    {
        $choices = [];

        foreach ($this->concepts as $concept) {
            if ($concept['key'] !== '') {
                $choices[$concept['key']] = $concept['name'] === '' ? $concept['key'] : $concept['name'];
            }
        }

        return $choices;
    }

    /**
     * Whether this is the set the component ships.
     *
     * @since  0.6.0
     */
    public function isBuiltIn(): bool
    {
        return $this->builtIn;
    }

    /**
     * How one language is named where somebody picks one.
     *
     * @since  0.6.0
     */
    public function label(): string
    {
        return $this->name . ' ' . $this->version;
    }

    /**
     * The value a project stores to say it is written in this one.
     *
     * @since  0.6.0
     */
    public function binding(): string
    {
        return $this->key . '|' . $this->version;
    }

    /**
     * Whether a stored binding names this entry.
     *
     * An empty binding is the built-in's, for the reason `BUILT_IN_KEY` gives.
     *
     * @since  0.6.0
     */
    public function answersTo(string $key, string $version): bool
    {
        if ($key === '') {
            return $this->builtIn;
        }

        return $this->key === $key && $this->version === $version;
    }

    /**
     * The site-relative path of the form a model of this language opens at.
     *
     * An imported language's is named after its root classifier, the way its
     * package names every other form. A component's own shipped language says
     * where its is, because nothing about it came out of a package - and the
     * two are deliberately the same shape, so that turning a shipped language
     * into a package changes what is passed in here and nothing that reads it.
     *
     * @since  0.6.0
     */
    public function rootFormPath(): string
    {
        if ($this->rootForm !== '') {
            return $this->formRoot . $this->rootForm;
        }

        return $this->formRoot . 'forms/' . lcfirst($this->root) . '.xml';
    }

    /**
     * The site-relative path of its reference table, or '' for the built-in.
     *
     * A built-in has none: a shipped language's table is PHP the component
     * ships, and that is exactly the difference turning it into a package
     * removes.
     *
     * @since  0.6.0
     */
    public function referenceTablePath(): string
    {
        return $this->builtIn ? '' : $this->formRoot . 'forms/references.json';
    }
}
