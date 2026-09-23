<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Lionweb
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Lionweb;

/**
 * One node of a chunk, being written.
 *
 * The shape a serialised node has is fussy in ways that are easy to get
 * slightly wrong and hard to notice: properties, containments and references
 * are three parallel lists rather than one map; a containment holds child
 * *ids* while a reference holds targets that carry an id and a resolveInfo;
 * `annotations` and `parent` are required even when there is nothing to say.
 * Assembling that by hand is how a chunk ends up almost right.
 *
 * **A feature appears once.** Adding a second child under a containment that
 * is already there appends to it rather than starting a rival entry with the
 * same key, because a reader takes the first it finds and would silently drop
 * the rest.
 *
 * @since  0.8.0
 */
final class NodeBuilder
{
    /** @var array<string, array{pointer: MetaPointer, value: string}> */
    private array $properties = [];

    /** @var array<string, array{pointer: MetaPointer, children: list<string>}> */
    private array $containments = [];

    /** @var array<string, array{pointer: MetaPointer, targets: list<array{resolveInfo: string|null, reference: string|null}>}> */
    private array $references = [];

    /** @var list<string> */
    private array $annotations = [];

    private ?string $parent = null;

    /**
     * @since  0.8.0
     */
    public function __construct(
        public readonly string $id,
        private readonly MetaPointer $classifier
    ) {
    }

    public function classifier(): MetaPointer
    {
        return $this->classifier;
    }

    /**
     * Set a property.
     *
     * A null value removes it rather than writing null: an unset optional
     * property is *omitted* from a well-formed chunk, and readers that cannot
     * tell absent from null - lionweb-python's enumerations, for one - break on
     * the difference.
     *
     * Booleans are written as LionWeb writes them, which is the strings "true"
     * and "false".
     *
     * @since  0.8.0
     */
    public function property(MetaPointer $pointer, string|int|float|bool|null $value): self
    {
        if ($value === null) {
            unset($this->properties[$pointer->key]);

            return $this;
        }

        $this->properties[$pointer->key] = [
            'pointer' => $pointer,
            'value'   => \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
        ];

        return $this;
    }

    /**
     * Hold a child under a containment.
     *
     * The child is named by id; whether it is in the chunk is the chunk's
     * business, and {@see ChunkBuilder::danglingReferences()} is where that is
     * asked.
     *
     * @since  0.8.0
     */
    public function child(MetaPointer $pointer, string $childId): self
    {
        $this->containments[$pointer->key] ??= ['pointer' => $pointer, 'children' => []];
        $this->containments[$pointer->key]['children'][] = $childId;

        return $this;
    }

    /**
     * Declare a containment that holds nothing.
     *
     * Rarely wanted - an empty containment and an absent one mean the same to
     * every reader - but a caller mirroring another chunk may want to.
     *
     * @since  0.8.0
     */
    public function emptyContainment(MetaPointer $pointer): self
    {
        $this->containments[$pointer->key] ??= ['pointer' => $pointer, 'children' => []];

        return $this;
    }

    /**
     * Point at another node.
     *
     * `resolveInfo` is what the target was called, kept so a reader that cannot
     * find the id still knows what was meant. Worth passing: it is the only
     * thing that survives a reference into a partition somebody did not send.
     *
     * @since  0.8.0
     */
    public function reference(MetaPointer $pointer, ?string $targetId, ?string $resolveInfo = null): self
    {
        $this->references[$pointer->key] ??= ['pointer' => $pointer, 'targets' => []];
        $this->references[$pointer->key]['targets'][] = [
            'resolveInfo' => $resolveInfo,
            'reference'   => $targetId,
        ];

        return $this;
    }

    /**
     * @since  0.8.0
     */
    public function annotation(string $annotationId): self
    {
        $this->annotations[] = $annotationId;

        return $this;
    }

    /**
     * Say which node holds this one.
     *
     * Null is a root. A chunk needs exactly one node without a parent for
     * anything to start reading from, which is a partition.
     *
     * @since  0.8.0
     */
    public function parent(?string $parentId): self
    {
        $this->parent = $parentId;

        return $this;
    }

    public function parentId(): ?string
    {
        return $this->parent;
    }

    /**
     * Every node id this one names, whether as a child or as a target.
     *
     * @return list<string>
     */
    public function mentions(): array
    {
        $ids = [];

        foreach ($this->containments as $containment) {
            foreach ($containment['children'] as $child) {
                $ids[] = $child;
            }
        }

        foreach ($this->references as $reference) {
            foreach ($reference['targets'] as $target) {
                if ($target['reference'] !== null && $target['reference'] !== '') {
                    $ids[] = $target['reference'];
                }
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     *
     * @since  0.8.0
     */
    public function toArray(): array
    {
        $properties = [];

        foreach ($this->properties as $property) {
            $properties[] = [
                'property' => $property['pointer']->toArray(),
                'value'    => $property['value'],
            ];
        }

        $containments = [];

        foreach ($this->containments as $containment) {
            $containments[] = [
                'containment' => $containment['pointer']->toArray(),
                'children'    => $containment['children'],
            ];
        }

        $references = [];

        foreach ($this->references as $reference) {
            $references[] = [
                'reference' => $reference['pointer']->toArray(),
                'targets'   => $reference['targets'],
            ];
        }

        // Every one of these is present even when empty, because the format
        // says so and readers written against the format are entitled to it.
        return [
            'id'           => $this->id,
            'classifier'   => $this->classifier->toArray(),
            'properties'   => $properties,
            'containments' => $containments,
            'references'   => $references,
            'annotations'  => $this->annotations,
            'parent'       => $this->parent,
        ];
    }
}
