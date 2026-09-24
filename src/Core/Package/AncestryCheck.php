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
 * Nothing here looks at features. A child may add a property to a concept, and
 * a parent's rule that never names it is unaffected; a child that *removed* one
 * would break a parent's binding, and catching that needs the parent's rule
 * file rather than its manifest. That is a real gap and it is named in the
 * plan rather than half-closed here.
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
        $byKey  = [];
        $byName = [];

        foreach ($child as $concept) {
            $key  = (string) ($concept['key'] ?? '');
            $name = (string) ($concept['name'] ?? '');

            if ($key !== '') {
                $byKey[$key] = $name;
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
            }
        }

        return $problems;
    }
}
