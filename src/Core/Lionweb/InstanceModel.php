<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Lionweb
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Lionweb;

use Yepr\Gen\Core\Package\PackageManifest;

/**
 * A chunk holding a *model*, read into the shape a project is stored in.
 *
 * {@see LionCoreLanguage} is the same step one level up: a chunk holding a
 * language becomes a stored metalanguage. This is its counterpart. A chunk of
 * either kind is the same sort of file - a flat list of nodes addressed by
 * metapointer - and the only difference is which language those metapointers
 * name. So the reader is the same reader; what changes is what you have to
 * know to make sense of what comes out.
 *
 * **It needs the language, and a manifest is the whole of what it needs.** A
 * chunk says `admin_view-child-admin_fields`, because a key is what survives
 * being moved between tools. A form stores its data under `admin_fields`,
 * because a name is what a person reads. The manifest is the only thing that
 * holds both halves, which is why it is the second argument and not the model
 * or the forms: a consumer that has imported a language has the manifest on
 * the row, long after the package it came in is gone.
 *
 * **Shape comes from the manifest too.** A containment the language calls a
 * list is stored as numbered groups - `admin_fields0`, `admin_fields1` - and
 * one it calls single is stored as one group under the bare name. Getting that
 * from the manifest is what format 4 added; before it, the only place that
 * said so was the generated form, and opening 126 of those to read one
 * attribute is not a design.
 *
 * **What it cannot place, it says.** A key the language does not define, a
 * child the chunk does not hold, a containment whose multiplicity nothing
 * states - none of those stop the read, and all of them are in
 * {@see diagnostics()}. A model that silently dropped a field would be worse
 * than one that arrived with a list of what it could not take.
 *
 * @since  0.16.0
 */
final class InstanceModel
{
    /**
     * What the stored shape puts on each group to say which concept wrote it.
     *
     * The same marker the generated forms carry as a hidden field, so a group
     * this produces is indistinguishable from one somebody filled in.
     */
    public const MARKER = 'LIonWeb_key';

    /**
     * What the stored shape puts on each group to say which node it was.
     *
     * A form has no use for it. The way back does: 38 of the 43 reference
     * targets in JCB's Hello World point at another node *in the same chunk*,
     * by id, so a writer that invented fresh ids would break every one of
     * them. The id is the only thing in a chunk that is nobody's to choose.
     *
     * @since  0.17.0
     */
    public const ID = 'LIonWeb_id';

    /**
     * What the root carries to say which language the model is written in.
     *
     * Not the same as the metalanguage's key: JCB's language calls itself
     * `jcb` and the package built from it is called `JCB`, and a metapointer
     * has to say the former. Recording it here is what lets a model go back
     * out without being told again what it already came in knowing.
     *
     * @since  0.17.0
     */
    public const LANGUAGE = 'LIonWeb_language';
    public const LANGUAGE_VERSION = 'LIonWeb_languageVersion';

    /** @var list<array{severity: string, code: string, message: string}> */
    private array $diagnostics = [];

    /**
     * Concept key => feature key => what the language says about that feature.
     *
     * @var array<string, array<string, array{name: string, multiple: bool|null}>>
     */
    private array $features = [];

    /**
     * Ids already placed, which is what stops a chunk that contains itself.
     *
     * @var array<string, true>
     */
    private array $placed = [];

    private function __construct(
        private readonly Chunk $chunk,
        private readonly PackageManifest $language
    ) {
        foreach ($this->language->concepts as $concept) {
            $byKey = [];

            foreach ($concept['features'] ?? [] as $feature) {
                $byKey[$feature['key']] = [
                    'name' => $feature['name'],
                    // Absent is not false: a manifest from before format 4
                    // says nothing, and that is a different answer from "one".
                    'multiple' => \array_key_exists('multiple', $feature)
                        ? $feature['multiple']
                        : null,
                ];
            }

            $this->features[$concept['key']] = $byKey;
        }
    }

    /**
     * @since  0.16.0
     */
    public static function read(Chunk $chunk, PackageManifest $language): self
    {
        return new self($chunk, $language);
    }

    /**
     * The model, in the shape a project row holds.
     *
     * No name: a model does not carry what the thing editing it should be
     * called, and inventing one from a node id would be a guess the caller is
     * better placed to make.
     *
     * @param  string|null  $rootId  Which node to read from, when the chunk
     *                               holds more than one root.
     *
     * @return array<string, mixed>
     *
     * @since  0.16.0
     */
    public function toStoredModel(?string $rootId = null): array
    {
        $this->diagnostics = [];
        $this->placed      = [];

        $root = $rootId ?? $this->root();

        if ($root === null) {
            return [];
        }

        $model = $this->node($root);

        // On the root only, because one model is written in one language and
        // repeating that on every node would be noise a person editing the
        // thing has to scroll past.
        $language = $this->chunk->nodes()[$root]['classifier']['language'] ?? '';
        $version  = $this->chunk->nodes()[$root]['classifier']['version'] ?? '';

        $model[self::LANGUAGE]         = \is_string($language) ? $language : '';
        $model[self::LANGUAGE_VERSION] = \is_string($version) ? $version : '';

        return $model;
    }

    /**
     * @return list<array{severity: string, code: string, message: string}>
     *
     * @since  0.16.0
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * The node to read from, when the caller did not say.
     *
     * One root is the ordinary case and needs no help. Several is a chunk
     * holding more than one partition, and then the language's own root
     * concept is the better guess than "the first one" - but it is still a
     * guess, so it is recorded as one.
     */
    private function root(): ?string
    {
        $roots = $this->chunk->roots();

        if (\count($roots) === 1) {
            return $roots[0];
        }

        if ($roots === []) {
            $this->note('error', 'NO_ROOT', 'This chunk holds no node that nothing contains.');

            return null;
        }

        foreach ($roots as $id) {
            if ($this->chunk->classifierKey($id) === $this->language->root) {
                $this->note(
                    'warning',
                    'SEVERAL_ROOTS',
                    'This chunk holds ' . \count($roots) . ' roots; reading from ' . $id
                    . ', the one classified as ' . $this->language->root . '.'
                );

                return $id;
            }
        }

        $this->note(
            'warning',
            'SEVERAL_ROOTS',
            'This chunk holds ' . \count($roots) . ' roots and none is classified as '
            . $this->language->root . '; reading from ' . $roots[0] . '.'
        );

        return $roots[0];
    }

    /**
     * One node, and everything it contains.
     *
     * @return array<string, mixed>
     */
    private function node(string $id): array
    {
        $concept = (string) $this->chunk->classifierKey($id);
        $known   = $this->features[$concept] ?? null;

        if ($known === null) {
            $this->note(
                'warning',
                'UNKNOWN_CONCEPT',
                'Node ' . $id . ' is a ' . $concept . ', which this language does not define.'
            );

            $known = [];
        }

        $this->placed[$id] = true;

        $row = [];

        foreach ($this->chunk->propertiesOf($id) as $key => $value) {
            $name = $this->nameOf($known, $concept, $key, $id);

            if ($name !== null) {
                $row[$name] = $value;
            }
        }

        foreach ($this->chunk->referencesOf($id) as $key => $targets) {
            $name = $this->nameOf($known, $concept, $key, $id);

            if ($name === null) {
                continue;
            }

            $pointed = [];

            foreach ($targets as $target) {
                // The node id when the target is in this chunk, and the
                // resolveInfo when it is not. Both are the same answer to the
                // only question a stored model asks - what does this point at
                // - and preferring the id keeps a reference inside the chunk
                // reading as it did.
                $at = $target['reference'] ?? $target['resolveInfo'] ?? null;

                if ($at === null) {
                    $this->note(
                        'warning',
                        'UNRESOLVABLE_TARGET',
                        'A target of ' . $name . ' on ' . $id
                        . ' names neither a node nor anything to resolve it by.'
                    );

                    continue;
                }

                $pointed[] = $at;
            }

            // A reference stores what it points at, and the form behind it is
            // a dropdown holding one of them. Where the language says a
            // reference holds several, the list goes through as a list - no
            // language has asked for that yet, and inventing a separator here
            // would be deciding for the one that does.
            $row[$name] = ($known[$key]['multiple'] ?? false) === true
                ? $pointed
                : ($pointed[0] ?? '');
        }

        foreach ($this->chunk->containmentsOf($id) as $key => $children) {
            $name = $this->nameOf($known, $concept, $key, $id);

            if ($name === null) {
                continue;
            }

            $rows = [];

            foreach ($children as $child) {
                if (!$this->chunk->has($child)) {
                    $this->note(
                        'warning',
                        'MISSING_CHILD',
                        'Node ' . $id . ' contains ' . $child . ' under ' . $name
                        . ', which this chunk does not hold.'
                    );

                    continue;
                }

                if (isset($this->placed[$child])) {
                    $this->note(
                        'warning',
                        'CONTAINED_TWICE',
                        'Node ' . $child . ' is contained more than once; it is placed under '
                        . $name . ' of ' . $id . ' only the first time.'
                    );

                    continue;
                }

                $rows[] = $this->node($child);
            }

            $row[$name] = $this->group($name, $rows, $known[$key]['multiple'] ?? null, $id);
        }

        $row[self::MARKER] = $concept;
        $row[self::ID]     = $id;

        return $row;
    }

    /**
     * Children under one containment, in the shape the form expects.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @return array<string, mixed>
     */
    private function group(string $name, array $rows, ?bool $multiple, string $parent): array
    {
        if ($multiple === null) {
            // More than one child cannot be one value, whatever the language
            // meant, so the evidence settles it and there is nothing to report.
            // One child or none leaves it genuinely open, and the two shapes
            // are not interchangeable: a form expecting numbered groups finds
            // nothing in a bare one, and the data is simply not on screen.
            //
            // A list is the better guess. Every containment in every language
            // this has met - ER1, LionCore M3, JCB's 122 - is one, and a
            // package old enough not to say is old enough to predate anything
            // that is not. It is still a guess, and it is recorded as one.
            if (\count($rows) <= 1) {
                $this->note(
                    'warning',
                    'MULTIPLICITY_UNKNOWN',
                    'This language does not say whether ' . $name . ' holds one value or a list'
                    . ' (on ' . $parent . '); stored as a list. A package in format 4 or'
                    . ' later says which.'
                );
            }

            $multiple = true;
        }

        if (!$multiple) {
            return $rows[0] ?? [];
        }

        $numbered = [];

        foreach ($rows as $position => $contents) {
            $numbered[$name . $position] = $contents;
        }

        return $numbered;
    }

    /**
     * What this language calls the feature with that key, if it calls it
     * anything.
     *
     * @param  array<string, array{name: string, multiple: bool|null}>  $known
     */
    private function nameOf(array $known, string $concept, string $key, string $id): ?string
    {
        if (isset($known[$key])) {
            return $known[$key]['name'];
        }

        $this->note(
            'warning',
            'UNKNOWN_FEATURE',
            'Node ' . $id . ' carries ' . $key . ', which ' . $concept
            . ' does not define in this language.'
        );

        return null;
    }

    private function note(string $severity, string $code, string $message): void
    {
        $this->diagnostics[] = [
            'severity' => $severity,
            'code'     => $code,
            'message'  => $message,
        ];
    }
}
