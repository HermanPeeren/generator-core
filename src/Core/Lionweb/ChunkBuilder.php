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
 * A LionWeb serialization chunk, being written.
 *
 * The other half of {@see Chunk}, and the thing that lets a model *leave* this
 * family rather than only arrive in it. A chunk holding a language and a chunk
 * holding a model written in one are the same kind of file, so this writes
 * either: what differs is which language the pointers name, and that is the
 * caller's business.
 *
 * **It does not know what it is writing.** No notion of Concept, of a project,
 * of ER1. Anything that does belongs above it, the same way
 * {@see LionCoreLanguage} sits above the reader. A writer that understood one
 * language would have to be written again for the next.
 *
 * **What it does know is what a reader will refuse.** Node ids that are not
 * legal, two nodes claiming one id, a chunk with no root, a child or a target
 * naming a node that is not here: all of them produce a file that looks
 * finished and is not, and all of them are cheaper to find before the file
 * exists than after somebody else's importer has choked on it. {@see errors()}
 * asks, and {@see toArray()} does not - writing a chunk somebody wants to
 * inspect is a reasonable thing to do.
 *
 * @since  0.8.0
 */
final class ChunkBuilder
{
    /**
     * A node id LionWeb will accept.
     *
     * The format allows rather more than this; this is the safe subset every
     * implementation agrees on, and the one a chunk written here sticks to.
     */
    private const ID_PATTERN = '#^[a-zA-Z0-9_-]+$#';

    /** @var array<string, NodeBuilder> */
    private array $nodes = [];

    /** @var array<string, string> language key => version */
    private array $languages = [];

    /**
     * @since  0.8.0
     */
    public function __construct(private readonly string $formatVersion = '2024.1')
    {
    }

    /**
     * Declare a language this chunk points into.
     *
     * Declaring the same language twice with different versions is a mistake
     * worth making loudly rather than resolving quietly: a chunk saying a
     * language is at two versions is one no reader can act on.
     *
     * @throws \InvalidArgumentException
     *
     * @since  0.8.0
     */
    public function usesLanguage(string $key, string $version): self
    {
        if (isset($this->languages[$key]) && $this->languages[$key] !== $version) {
            throw new \InvalidArgumentException(
                "This chunk already declares {$key} at version {$this->languages[$key]}, "
                . "so it cannot also declare it at {$version}."
            );
        }

        $this->languages[$key] = $version;

        return $this;
    }

    /**
     * Start a node, and declare the language its classifier comes from.
     *
     * The node is part of the chunk from here on; what comes back is the same
     * node, to go on describing.
     *
     * @throws \InvalidArgumentException  When the id is already taken.
     *
     * @since  0.8.0
     */
    public function node(string $id, MetaPointer $classifier): NodeBuilder
    {
        if (isset($this->nodes[$id])) {
            throw new \InvalidArgumentException(
                "This chunk already holds a node with id {$id}; two nodes cannot share one."
            );
        }

        $this->usesLanguage($classifier->language, $classifier->version);

        return $this->nodes[$id] = new NodeBuilder($id, $classifier);
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function get(string $id): ?NodeBuilder
    {
        return $this->nodes[$id] ?? null;
    }

    public function count(): int
    {
        return \count($this->nodes);
    }

    /**
     * The nodes nothing holds, which is what a reader starts from.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        $roots = [];

        foreach ($this->nodes as $id => $node) {
            if ($node->parentId() === null) {
                $roots[] = $id;
            }
        }

        return $roots;
    }

    /**
     * Ids this chunk names but does not hold.
     *
     * A reference to another partition is a legitimate reason to have one, so
     * this reports rather than refuses - but a *child* that is not here is
     * always a mistake, and {@see errors()} treats the two differently.
     *
     * @return list<string>
     */
    public function danglingReferences(): array
    {
        $missing = [];

        foreach ($this->nodes as $node) {
            foreach ($node->mentions() as $id) {
                if (!isset($this->nodes[$id])) {
                    $missing[$id] = true;
                }
            }
        }

        return array_keys($missing);
    }

    /**
     * What is wrong with this chunk, in the words somebody would need.
     *
     * @return list<string>
     *
     * @since  0.8.0
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->nodes as $id => $node) {
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                $errors[] = "Node id '{$id}' is not one a LionWeb chunk will accept; "
                    . 'ids are letters, digits, hyphens and underscores.';
            }

            $parent = $node->parentId();

            if ($parent !== null && !isset($this->nodes[$parent])) {
                $errors[] = "Node {$id} names {$parent} as its parent, which this chunk does not hold.";
            }
        }

        $roots = $this->roots();

        if ($this->nodes !== [] && $roots === []) {
            $errors[] = 'Every node has a parent, so this chunk has no root to read from.';
        }

        return $errors;
    }

    /**
     * The chunk, ready to be written.
     *
     * Nodes come out in the order they were added, which is what makes the
     * same model produce the same file: a caller that wants them sorted sorts
     * what it feeds in, and a caller that wants a readable order gets the one
     * it chose.
     *
     * @return array<string, mixed>
     *
     * @since  0.8.0
     */
    public function toArray(): array
    {
        $languages = [];

        foreach ($this->languages as $key => $version) {
            $languages[] = ['key' => $key, 'version' => $version];
        }

        // Sorted, because the declaration order of languages says nothing and
        // a file that reorders between runs is a file that diffs badly.
        usort($languages, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        $nodes = [];

        foreach ($this->nodes as $node) {
            $nodes[] = $node->toArray();
        }

        return [
            'serializationFormatVersion' => $this->formatVersion,
            'languages'                  => $languages,
            'nodes'                      => $nodes,
        ];
    }

    /**
     * @throws \JsonException
     *
     * @since  0.8.0
     */
    public function toJson(bool $pretty = true): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | ($pretty ? JSON_PRETTY_PRINT : 0)
        ) . "\n";
    }
}
