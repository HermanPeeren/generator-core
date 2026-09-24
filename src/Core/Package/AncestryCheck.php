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
 * What a language may change about the one it derives from: step 4.5.
 *
 * **It may add. It may not remove or rename.** That is the whole rule, and it
 * is not a style preference - it is what makes the parent's generators run over
 * the child's models at all.
 *
 * The failure it prevents is silent, which is why it is a refusal at import
 * rather than a note in a document. A parent's rule selects `Entity`; the child
 * renamed it to `Thing`; the selector returns nothing; every rule written for
 * `Entity` fires zero times; and out comes a package with a manifest and a
 * third of its files, looking exactly like a package that worked. 3.6 found
 * that shape of failure by hand and wrote a refusal for it one level down; this
 * is the same refusal one level up.
 *
 * **Both halves of a concept's identity are checked, and for different
 * reasons.** A stored model points at a concept by *key*, so a key that
 * disappears is a model whose references stop resolving. A rule selects a
 * concept by *name* - 3.6 decided that deliberately, because `for: c-entity` is
 * a rule nobody can check by eye - so a name that changes is a rule that
 * silently selects nothing. A child that renames `Entity` to `Thing` while
 * keeping its key breaks the rules without breaking the data, which is the more
 * confusing of the two and the one a key-only check would miss.
 *
 * **Features are checked the same way, when the parent says what it has.** A
 * child may add a property and a parent's rule that never names it is
 * unaffected; a child that *removed* one breaks every binding whose path walks
 * through it, and that is the same silence one level down - a binding that
 * resolves to nothing renders an empty string into a generated file.
 *
 * The features compared are *effective* ones, inherited included, so a child
 * that moved a property up to a supertype has not removed it. Meta-gen resolves
 * that before writing the manifest, because the hierarchy is not in there.
 *
 * A parent whose manifest lists no features for a concept is not checked for
 * them. That is a package written before 4.5's second half, and "this package
 * does not say" is not the same as "this concept has none" - which is why an
 * empty list and an absent one are different things here, and why a manifest
 * writes `features: []` for a concept that genuinely has none.
 *
 * @since  0.11.0
 */
final class AncestryCheck
{
    /**
     * What the child breaks about the parent, or an empty list.
     *
     * Every problem is collected rather than the first being thrown, because a
     * person fixing a language wants the list and not one item of it - the same
     * reason `PackageReader::problems()` is a list.
     *
     * @param   array<int, array{key: string, name: string}>  $parent  The parent's concepts.
     * @param   array<int, array{key: string, name: string}>  $child   The child's.
     * @param   string                                        $label   How to name the parent in a message.
     *
     * @return  string[]  What is wrong, in the words shown to whoever is importing.
     *
     * @since   0.11.0
     */
    public static function problems(array $parent, array $child, string $label = 'the language it derives from'): array
    {
        $byKey       = [];
        $byName      = [];
        $childByKey  = [];

        foreach ($child as $concept) {
            $key  = (string) ($concept['key'] ?? '');
            $name = (string) ($concept['name'] ?? '');

            if ($key !== '') {
                $byKey[$key]      = $name;
                $childByKey[$key] = $concept;
            }

            if ($name !== '') {
                $byName[$name] = $key;
            }
        }

        $problems = [];

        foreach ($parent as $concept) {
            $key  = (string) ($concept['key'] ?? '');
            $name = (string) ($concept['name'] ?? '');

            if ($key === '') {
                // A parent that says nothing about a concept's key cannot have
                // that key checked, and a package built before keys were
                // carried is a package with none at all. Not the child's fault.
                continue;
            }

            if (!isset($byKey[$key])) {
                $problems[] = $label . ' has a concept "' . ($name ?: $key)
                    . '" that this one does not: a model written in it would stop resolving.';

                continue;
            }

            if ($name !== '' && $byKey[$key] !== $name) {
                $problems[] = $label . ' calls ' . $key . ' "' . $name . '" and this one calls it "'
                    . $byKey[$key] . '": rules written for it select by name, so they would select nothing.';

                continue;
            }

            $problems = array_merge($problems, self::featureProblems(
                $concept,
                $childByKey[$key] ?? [],
                $label,
                $name ?: $key
            ));
        }

        return $problems;
    }

    /**
     * What the child breaks about one concept's features.
     *
     * @param   array<string, mixed>  $parent  The parent's concept.
     * @param   array<string, mixed>  $child   The child's concept of the same key.
     * @param   string                $label   How to name the parent.
     * @param   string                $on      How to name the concept.
     *
     * @return  string[]
     *
     * @since   0.13.0
     */
    private static function featureProblems(array $parent, array $child, string $label, string $on): array
    {
        if (!\is_array($parent['features'] ?? null)) {
            // The parent's manifest does not say, which is what a package built
            // before this looks like. Nothing to compare against, and refusing
            // on that would refuse deriving from every language imported before
            // today.
            return [];
        }

        $byKey  = [];
        $byName = [];

        foreach (\is_array($child['features'] ?? null) ? $child['features'] : [] as $feature) {
            $key  = (string) ($feature['key'] ?? '');
            $name = (string) ($feature['name'] ?? '');

            if ($key !== '') {
                $byKey[$key] = $name;
            }

            if ($name !== '') {
                $byName[$name] = $key;
            }
        }

        $problems = [];

        foreach ($parent['features'] as $feature) {
            $key  = (string) ($feature['key'] ?? '');
            $name = (string) ($feature['name'] ?? '');

            if ($name === '') {
                continue;
            }

            // By key when there is one, because that is what tells a rename
            // from a removal - and a rename is the more confusing failure, the
            // data still being where it was.
            if ($key !== '' && isset($byKey[$key])) {
                if ($byKey[$key] !== $name) {
                    $problems[] = $on . '.' . $name . ' in ' . $label . ' is called "' . $byKey[$key]
                        . '" here: a path through it walks by name, so it would walk into nothing.';
                }

                continue;
            }

            if (!isset($byName[$name])) {
                $problems[] = $label . ' has ' . $on . '.' . $name
                    . ' and this one does not: a binding that walks through it would resolve to nothing.';
            }
        }

        return $problems;
    }
}
