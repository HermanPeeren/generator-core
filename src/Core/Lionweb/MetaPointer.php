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
 * What a LionWeb chunk points with: a language, its version, and a key.
 *
 * Every classifier and every feature in a chunk is addressed this way rather
 * than by name, which is the whole reason a model can move between tools that
 * were not written for each other. A name is a property like any other and two
 * things may share one; a key is an identity.
 *
 * The version belongs to the *language*, not to the serialization format. They
 * are often the same string and that has cost a day before now.
 *
 * @since  0.8.0
 */
final class MetaPointer
{
    /**
     * @since  0.8.0
     */
    public function __construct(
        public readonly string $language,
        public readonly string $version,
        public readonly string $key
    ) {
    }

    /**
     * Read one out of a chunk.
     *
     * @param  array<string, mixed>  $pointer
     *
     * @since  0.8.0
     */
    public static function fromArray(array $pointer): self
    {
        return new self(
            (string) ($pointer['language'] ?? ''),
            (string) ($pointer['version'] ?? ''),
            (string) ($pointer['key'] ?? '')
        );
    }

    /**
     * Another pointer into the same language and version.
     *
     * Almost every pointer a writer makes differs from the last only in its
     * key, so saying so is shorter than repeating the language each time.
     *
     * @since  0.8.0
     */
    public function withKey(string $key): self
    {
        return new self($this->language, $this->version, $key);
    }

    public function equals(self $other): bool
    {
        return $this->language === $other->language
            && $this->version === $other->version
            && $this->key === $other->key;
    }

    /**
     * @return array{language: string, version: string, key: string}
     *
     * @since  0.8.0
     */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'version'  => $this->version,
            'key'      => $this->key,
        ];
    }
}
