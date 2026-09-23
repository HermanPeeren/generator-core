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
 * A LionWeb serialization chunk, read.
 *
 * The interchange format itself and nothing above it: a flat list of nodes,
 * each with an id, a classifier and its properties, containments and
 * references, every one of them addressed by a *metapointer* - a language, a
 * version and a key - rather than by name. Reading that is fiddly in the same
 * way reading a stored Joomla form is fiddly, and for the same reason it is
 * done once, here.
 *
 * **It knows nothing about LionCore.** A chunk holding a language and a chunk
 * holding a model written in one are the same kind of file; the only difference
 * is which language the metapointers name. That is what lets one reader serve
 * both, and why anything that knows what `Concept` means lives in
 * {@see LionCoreLanguage} instead.
 *
 * **Keys, not names.** A node's name is a property like any other and two nodes
 * may share one. Identity is the node id, and a feature's identity is its key -
 * which is the whole reason a model can be exchanged at all, and the thing an
 * importer loses first if it starts matching on names.
 *
 * @since  0.7.0
 */
final class Chunk
{
    /**
     * @param  array<string, array<string, mixed>>  $nodes  Node id => the node, as serialised.
     * @param  list<array{key: string, version: string}>  $languages
     *
     * @since  0.7.0
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $languages,
        private readonly string $formatVersion
    ) {
    }

    /**
     * @param  array<string, mixed>  $chunk
     *
     * @throws \InvalidArgumentException  When this is not a chunk.
     *
     * @since  0.7.0
     */
    public static function fromArray(array $chunk): self
    {
        if (!\is_array($chunk['nodes'] ?? null)) {
            throw new \InvalidArgumentException(
                'A LionWeb chunk must hold a "nodes" list; this one holds '
                . get_debug_type($chunk['nodes'] ?? null) . '.'
            );
        }

        $nodes = [];

        foreach ($chunk['nodes'] as $node) {
            // A node with no id cannot be pointed at, contained or referenced,
            // so there is nothing the rest of the chunk could say about it.
            if (\is_array($node) && ($node['id'] ?? '') !== '') {
                $nodes[(string) $node['id']] = $node;
            }
        }

        $languages = [];

        foreach ($chunk['languages'] ?? [] as $language) {
            if (\is_array($language) && isset($language['key'])) {
                $languages[] = [
                    'key'     => (string) $language['key'],
                    'version' => (string) ($language['version'] ?? ''),
                ];
            }
        }

        return new self(
            $nodes,
            $languages,
            (string) ($chunk['serializationFormatVersion'] ?? '')
        );
    }

    /**
     * @throws \JsonException             When the string is not JSON.
     * @throws \InvalidArgumentException  When the JSON is not a chunk.
     *
     * @since  0.7.0
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException(
                'A LionWeb chunk must be a JSON object, got ' . get_debug_type($decoded) . '.'
            );
        }

        return self::fromArray($decoded);
    }

    public function formatVersion(): string
    {
        return $this->formatVersion;
    }

    /**
     * @return list<array{key: string, version: string}>
     */
    public function languages(): array
    {
        return $this->languages;
    }

    /**
     * The version this chunk declares for one language, if it declares it.
     */
    public function versionOf(string $languageKey): ?string
    {
        foreach ($this->languages as $language) {
            if ($language['key'] === $languageKey) {
                return $language['version'];
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function classifierKey(string $id): ?string
    {
        $key = $this->nodes[$id]['classifier']['key'] ?? null;

        return \is_string($key) ? $key : null;
    }

    /**
     * Every node of one classifier, in the order the chunk lists them.
     *
     * @return list<string>  Node ids.
     */
    public function idsOf(string $classifierKey): array
    {
        $found = [];

        foreach ($this->nodes as $id => $node) {
            if (($node['classifier']['key'] ?? null) === $classifierKey) {
                $found[] = (string) $id;
            }
        }

        return $found;
    }

    /**
     * A property's value, or null when the node does not carry it.
     *
     * Null rather than an empty string, because an unset optional property is
     * *omitted* from a well-formed chunk rather than serialised as null, and a
     * caller that cannot tell the two apart will write an empty value where
     * there was none.
     */
    public function property(string $id, string $key): ?string
    {
        foreach ($this->nodes[$id]['properties'] ?? [] as $property) {
            if (($property['property']['key'] ?? null) === $key) {
                $value = $property['value'] ?? null;

                return $value === null ? null : (string) $value;
            }
        }

        return null;
    }

    /**
     * A boolean property. LionWeb serialises these as the strings "true" and
     * "false", so an absent one is the caller's default rather than false.
     */
    public function flag(string $id, string $key, bool $default = false): bool
    {
        $value = $this->property($id, $key);

        return $value === null ? $default : $value === 'true';
    }

    /**
     * The children a node holds under one containment, in order.
     *
     * @return list<string>  Node ids.
     */
    public function children(string $id, string $key): array
    {
        foreach ($this->nodes[$id]['containments'] ?? [] as $containment) {
            if (($containment['containment']['key'] ?? null) === $key) {
                return array_values(array_filter(
                    array_map('strval', $containment['children'] ?? []),
                    fn (string $child): bool => $child !== ''
                ));
            }
        }

        return [];
    }

    /**
     * What a node points at under one reference, in order.
     *
     * A target may name a node this chunk does not hold - that is what a
     * reference into another partition looks like - so these are ids to look
     * up, not ids that are certainly here.
     *
     * @return list<string>  Node ids.
     */
    public function targets(string $id, string $key): array
    {
        foreach ($this->nodes[$id]['references'] ?? [] as $reference) {
            if (($reference['reference']['key'] ?? null) !== $key) {
                continue;
            }

            $targets = [];

            foreach ($reference['targets'] ?? [] as $target) {
                $resolved = (string) ($target['reference'] ?? '');

                if ($resolved !== '') {
                    $targets[] = $resolved;
                }
            }

            return $targets;
        }

        return [];
    }

    /**
     * The first target, for a reference that holds at most one.
     */
    public function target(string $id, string $key): ?string
    {
        return $this->targets($id, $key)[0] ?? null;
    }

    /**
     * The chunk as it was read, so one that came in can go back out.
     *
     * Nodes keep the order they arrived in and nothing is normalised: a chunk
     * read and written unchanged is the same chunk, which is what makes the
     * reader and the writer testable against each other rather than each only
     * against itself.
     *
     * @return array<string, mixed>
     *
     * @since  0.8.0
     */
    public function toArray(): array
    {
        $languages = [];

        foreach ($this->languages as $language) {
            $languages[] = ['key' => $language['key'], 'version' => $language['version']];
        }

        return [
            'serializationFormatVersion' => $this->formatVersion,
            'languages'                  => $languages,
            'nodes'                      => array_values($this->nodes),
        ];
    }

    /**
     * The nodes nothing contains, in the order the chunk lists them.
     *
     * @return list<string>  Node ids.
     */
    public function roots(): array
    {
        $roots = [];

        foreach ($this->nodes as $id => $node) {
            if (($node['parent'] ?? null) === null) {
                $roots[] = (string) $id;
            }
        }

        return $roots;
    }
}
