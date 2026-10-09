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
 * A stored model, written back out as a LionWeb chunk.
 *
 * The way back from {@see InstanceModel}. That turns a chunk into the data a
 * form edits; this turns the data a form edits into a chunk, so a model can
 * leave this family as well as arrive in it. A blueprint imported from JCB,
 * edited in Exten-gen, and exported again is the case it exists for.
 *
 * **Identity is not this class's to invent.** A chunk addresses everything by
 * node id, and in JCB's Hello World 38 of the 43 reference targets point at
 * another node in the same chunk by that id. Fresh ids would break every one
 * of them, so the reader records each node's id as `LIonWeb_id` and this reads
 * it back. A group that arrives without one is reported, and gets a
 * deterministic id derived from where it sits - which is enough for a model
 * that was never in a chunk, and not enough for one that was.
 *
 * **What is a property, what is a link.** The manifest says `multiple` for a
 * link and leaves it off a property, which is what tells the two apart; among
 * links, a value holding rows is a containment and a value holding strings is
 * a reference. Both halves of that come from the manifest's own rule rather
 * than from guessing at names, and a value whose shape contradicts it is
 * reported rather than written somewhere it does not belong.
 *
 * **It does not validate the language.** Whether a required feature is absent,
 * or a property holds something its datatype would refuse, is a question for
 * whoever reads the chunk. What this reports is what would make the chunk
 * itself unreadable - a duplicate id, a child that is not here - which
 * {@see ChunkBuilder} already knows how to find.
 *
 * @since  0.17.0
 */
final class InstanceChunk
{
    /** @var list<array{severity: string, code: string, message: string}> */
    private array $diagnostics = [];

    /**
     * The language every metapointer in this chunk names, once `write()` has
     * settled whether it came from the caller or from the model.
     */
    private string $inUseKey = '';
    private string $inUseVersion = '';

    /**
     * References waiting for the rest of the chunk to exist.
     *
     * @var list<array{node: string, pointer: MetaPointer, target: string}>
     */
    private array $pending = [];

    /**
     * Concept key => feature name => what the language says about it.
     *
     * By *name*, because that is what a stored model keys its data by - the
     * opposite way round from the reader, which has keys and wants names.
     *
     * @var array<string, array<string, array{key: string, multiple: bool|null, link: bool}>>
     */
    private array $features = [];

    private function __construct(
        private readonly PackageManifest $language,
        private readonly string $languageKey,
        private readonly string $languageVersion
    ) {
        foreach ($this->language->concepts as $concept) {
            $byName = [];

            foreach ($concept['features'] ?? [] as $feature) {
                $byName[$feature['name']] = [
                    'key' => $feature['key'],
                    'multiple' => \array_key_exists('multiple', $feature)
                        ? $feature['multiple']
                        : null,
                    // The manifest writes `multiple` for a link and leaves it
                    // off a property. That is the only thing in it that tells
                    // the two apart, and the packager's rule, not a guess.
                    'link' => \array_key_exists('multiple', $feature),
                ];
            }

            $this->features[$concept['key']] = $byName;
        }
    }

    /**
     * @param  string  $languageKey  The LionWeb language the metapointers name.
     *                               Empty takes it from the model's root, which
     *                               is where the reader left it.
     *
     * @since  0.17.0
     */
    public static function of(
        PackageManifest $language,
        string $languageKey = '',
        string $languageVersion = ''
    ): self {
        return new self($language, $languageKey, $languageVersion);
    }

    /**
     * @param  array<string, mixed>  $stored
     *
     * @return array<string, mixed>  The chunk, as a serialisation.
     *
     * @throws \RuntimeException  When the model names no language to write in.
     *
     * @since  0.17.0
     */
    public function write(array $stored): array
    {
        $this->diagnostics = [];
        $this->pending     = [];

        $key = $this->languageKey !== ''
            ? $this->languageKey
            : (string) ($stored[InstanceModel::LANGUAGE] ?? '');

        $version = $this->languageKey !== ''
            ? $this->languageVersion
            : (string) ($stored[InstanceModel::LANGUAGE_VERSION] ?? '');

        if ($key === '') {
            throw new \RuntimeException(
                'This model does not say which LionWeb language it is written in, and none'
                . ' was given. A model that did not come from a chunk has to be told.'
            );
        }

        $this->inUseKey     = $key;
        $this->inUseVersion = $version;

        $builder = (new ChunkBuilder())->usesLanguage($key, $version);

        $this->node($builder, $stored, null, 'root');
        $this->resolve($builder);

        foreach ($builder->errors() as $error) {
            $this->note('error', 'CHUNK', $error);
        }

        foreach ($builder->danglingReferences() as $dangling) {
            $this->note('info', 'DANGLING_REFERENCE', $dangling);
        }

        return $builder->toArray();
    }

    /**
     * @return list<array{severity: string, code: string, message: string}>
     *
     * @since  0.17.0
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * One group, and everything under it.
     *
     * @param  array<string, mixed>  $row
     *
     * @return string|null  The id it was written under, or null when it could
     *                      not be written at all.
     */
    private function node(ChunkBuilder $builder, array $row, ?string $parent, string $path): ?string
    {
        $concept = (string) ($row[InstanceModel::MARKER] ?? '');

        if ($concept === '') {
            $this->note(
                'warning',
                'NO_CONCEPT',
                'The group at ' . $path . ' does not say which concept it is, so there is'
                . ' nothing to classify it as.'
            );

            return null;
        }

        $id    = $this->idFor($row, $path);
        $known = $this->features[$concept] ?? null;

        if ($known === null) {
            $this->note(
                'warning',
                'UNKNOWN_CONCEPT',
                'The group at ' . $path . ' is a ' . $concept
                . ', which this language does not define.'
            );

            $known = [];
        }

        $node = $builder->node($id, $this->pointer($concept))->parent($parent);

        foreach ($row as $name => $value) {
            if ($this->reserved((string) $name)) {
                continue;
            }

            $feature = $known[$name] ?? null;

            if ($feature === null) {
                $this->note(
                    'warning',
                    'UNKNOWN_FEATURE',
                    $path . '.' . $name . ' is not a feature of ' . $concept . ' in this language.'
                );

                continue;
            }

            $this->feature($builder, $node, $feature, (string) $name, $value, $id, $path);
        }

        return $id;
    }

    /**
     * One feature of one group, into whichever of the three buckets it belongs.
     *
     * @param  array{key: string, multiple: bool|null, link: bool}  $feature
     */
    private function feature(
        ChunkBuilder $builder,
        NodeBuilder $node,
        array $feature,
        string $name,
        mixed $value,
        string $id,
        string $path
    ): void {
        $pointer = $this->pointer($feature['key']);

        if (!$feature['link']) {
            if (\is_array($value)) {
                $this->note(
                    'warning',
                    'SHAPE',
                    $path . '.' . $name . ' is a property, but holds a list rather than a value.'
                );

                return;
            }

            // As it stands, empty string included. The reader already tells an
            // absent property from an empty one - absent has no key in the
            // model at all - so turning one into the other here would be this
            // class overruling it, and 4 properties of every JCB dynamic_get
            // are empty strings that were in the chunk it came from.
            $node->property($pointer, $value);

            return;
        }

        $members = \is_array($value) ? $value : ($value === '' ? [] : [$value]);

        // Rows are a containment; strings are a reference. The manifest says
        // which features are links and this says which kind, because the
        // difference is visible in the value and nowhere in the manifest.
        $rows = array_filter($members, '\is_array');

        if ($rows !== [] && \count($rows) !== \count($members)) {
            $this->note(
                'warning',
                'SHAPE',
                $path . '.' . $name . ' mixes groups and plain values, so it is neither a'
                . ' containment nor a reference.'
            );

            return;
        }

        if ($rows !== []) {
            $written = [];

            foreach ($members as $position => $contents) {
                $child = $this->node($builder, $contents, $id, $path . '.' . $name . '.' . $position);

                if ($child !== null) {
                    $written[] = $child;
                }
            }

            if ($written === []) {
                $node->emptyContainment($pointer);

                return;
            }

            foreach ($written as $child) {
                $node->child($pointer, $child);
            }

            return;
        }

        foreach ($members as $target) {
            $target = (string) $target;

            if ($target === '') {
                continue;
            }

            // Held over. Inside the chunk a target is a node id and outside it
            // is all the resolving anybody gets, and which one it is cannot be
            // known until every node has been written - a reference to a node
            // that comes later in the walk would otherwise be demoted to a
            // hint, and 38 of the 43 in JCB's Hello World are exactly that.
            $this->pending[] = ['node' => $id, 'pointer' => $pointer, 'target' => $target];
        }
    }

    /**
     * Every reference, once there is a whole chunk to resolve against.
     */
    private function resolve(ChunkBuilder $builder): void
    {
        foreach ($this->pending as $reference) {
            $node = $builder->get($reference['node']);

            if ($node === null) {
                continue;
            }

            $inside = $builder->has($reference['target']);

            $node->reference(
                $reference['pointer'],
                $inside ? $reference['target'] : null,
                $inside ? null : $reference['target']
            );
        }
    }

    /**
     * The id a group goes back out under.
     *
     * @param  array<string, mixed>  $row
     */
    private function idFor(array $row, string $path): string
    {
        $id = (string) ($row[InstanceModel::ID] ?? '');

        if ($id !== '') {
            return $id;
        }

        // A group somebody added in the editor, or one whose id a form dropped
        // because it has no field for it. Deterministic so that writing the
        // same model twice gives the same chunk, and said out loud because
        // anything that pointed at this node by its old id no longer does.
        $derived = 'x-' . substr(sha1($path), 0, 16);

        $this->note(
            'warning',
            'ID_INVENTED',
            'The group at ' . $path . ' carries no node id, so it was written as '
            . $derived . '. Anything that referred to it by its own id no longer reaches it.'
        );

        return $derived;
    }

    private function reserved(string $name): bool
    {
        return \in_array($name, [
            InstanceModel::MARKER,
            InstanceModel::ID,
            InstanceModel::LANGUAGE,
            InstanceModel::LANGUAGE_VERSION,
        ], true);
    }

    private function pointer(string $key): MetaPointer
    {
        return new MetaPointer($this->inUseKey, $this->inUseVersion, $key);
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
