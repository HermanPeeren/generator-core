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
 * Walking what a language derives from: step 4.5.
 *
 * A class of its own, and for the reason `MetalanguageInstaller` is one:
 * everything that can go wrong with an ancestry goes wrong in here, and a
 * catalogue needs a `DatabaseInterface` that this suite will not boot. So the
 * graph walk takes a resolver - anything that can turn a key and a version into
 * an entry - and `MetalanguageCatalogue` passes it a lookup against its table.
 *
 * Three behaviours that are decisions rather than details:
 *
 * **A cycle is survived, not thrown.** A derives from B derives from A is
 * something Meta-gen can produce by hand, and a screen that cannot fill a
 * dropdown until somebody fixes a language is a screen nobody can fix a
 * language on. The walk keeps what it has seen and stops, so the answer is the
 * acyclic part of the ancestry; `isCyclic()` is how a screen says the rest.
 *
 * **A parent this site has not imported is skipped.** A derived language whose
 * parent was never installed is usable for everything except that parent's
 * generators, which beats refusing to open it. `missing()` is how a screen says
 * which.
 *
 * **Nearest first, and the entry itself is not in the result.** The caller has
 * it, and a list that included it would make "the language or one of its
 * ancestors" read as two different things in every caller.
 *
 * @since  0.11.0
 */
final class Ancestry
{
    /**
     * @param  \Closure(string, string): ?MetalanguageEntry  $resolve  Finds an entry, or null.
     *
     * @since  0.11.0
     */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    /**
     * The ancestors of one entry, nearest first.
     *
     * @return  MetalanguageEntry[]
     *
     * @since   0.11.0
     */
    public function of(MetalanguageEntry $entry): array
    {
        $found = [];

        $this->walk($entry, $found, [self::id($entry->key, $entry->version) => true]);

        return array_values($found);
    }

    /**
     * The entry and its ancestors, nearest first, with the entry at the front.
     *
     * What almost every caller actually wants: "this language, or one it
     * derives from". Named so that the callers do not each write the same
     * array_merge and one of them forget the order.
     *
     * @return  MetalanguageEntry[]
     *
     * @since   0.11.0
     */
    public function withSelf(MetalanguageEntry $entry): array
    {
        return [$entry, ...$this->of($entry)];
    }

    /**
     * The parents an ancestry names and nothing resolves.
     *
     * @return  array<int, array{key: string, version: string}>
     *
     * @since   0.11.0
     */
    public function missing(MetalanguageEntry $entry): array
    {
        $missing = [];
        $seen    = [self::id($entry->key, $entry->version) => true];

        foreach ($this->withSelf($entry) as $node) {
            foreach ($node->dependsOn as $parent) {
                $id = self::id((string) $parent['key'], (string) $parent['version']);

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;

                if (($this->resolve)((string) $parent['key'], (string) $parent['version']) === null) {
                    $missing[] = ['key' => (string) $parent['key'], 'version' => (string) $parent['version']];
                }
            }
        }

        return $missing;
    }

    /**
     * Whether any path from here returns to something already on it.
     *
     * @since  0.11.0
     */
    public function isCyclic(MetalanguageEntry $entry): bool
    {
        return $this->cyclic($entry, []);
    }

    /**
     * One step of the walk.
     *
     * @param  array<string, MetalanguageEntry>  $found
     * @param  array<string, true>               $seen
     *
     * @since  0.11.0
     */
    private function walk(MetalanguageEntry $entry, array &$found, array $seen): void
    {
        foreach ($entry->dependsOn as $parent) {
            $id = self::id((string) $parent['key'], (string) $parent['version']);

            if (isset($seen[$id]) || isset($found[$id])) {
                continue;
            }

            $ancestor = ($this->resolve)((string) $parent['key'], (string) $parent['version']);

            if ($ancestor === null) {
                continue;
            }

            $found[$id] = $ancestor;

            $this->walk($ancestor, $found, $seen + [$id => true]);
        }
    }

    /**
     * @param  array<string, true>  $path
     *
     * @since  0.11.0
     */
    private function cyclic(MetalanguageEntry $entry, array $path): bool
    {
        $path = $path + [self::id($entry->key, $entry->version) => true];

        foreach ($entry->dependsOn as $parent) {
            $id = self::id((string) $parent['key'], (string) $parent['version']);

            if (isset($path[$id])) {
                return true;
            }

            $ancestor = ($this->resolve)((string) $parent['key'], (string) $parent['version']);

            if ($ancestor !== null && $this->cyclic($ancestor, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A language's identity, which is the pair and never the key alone.
     *
     * @since  0.11.0
     */
    private static function id(string $key, string $version): string
    {
        return $key . '|' . $version;
    }
}
