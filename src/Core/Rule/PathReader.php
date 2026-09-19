<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Rule
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Rule;

/**
 * Reads a dotted path out of a decoded model.
 *
 * A rule is data, so the places it reads from are strings:
 * `extensions.component.manifest.copyright` rather than
 * `$project->extensions->component->manifest->copyright`. This is what turns
 * that string back into a value.
 *
 * Objects and arrays are both walked, because a model that arrived as JSON is
 * a tree of `stdClass` while one assembled in a test is often arrays, and a
 * rule should not have to know which it is looking at.
 *
 * A missing step is not an error here. Half the model is optional - a page
 * without links, a field without a default - and a rule that wants a missing
 * step to be fatal says so with a condition instead.
 *
 * @since  0.2.0
 */
final class PathReader
{
    /**
     * Read a dotted path, or null when any step of it is absent.
     *
     * @param   mixed   $subject  Object, array, or anything (which reads as null).
     * @param   string  $path     Dot-separated, for example `manifest.version`.
     *
     * @return  mixed  The value found, or null.
     *
     * @since   0.2.0
     */
    public static function read(mixed $subject, string $path): mixed
    {
        if ($path === '') {
            return $subject;
        }

        $value = $subject;

        foreach (explode('.', $path) as $step) {
            if (\is_object($value)) {
                if (!property_exists($value, $step)) {
                    return null;
                }

                $value = $value->{$step};

                continue;
            }

            if (\is_array($value)) {
                if (!\array_key_exists($step, $value)) {
                    return null;
                }

                $value = $value[$step];

                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * Whether every step of a path is present.
     *
     * Distinct from `read() !== null`, because a property that is present and
     * null is a different thing from one that was never written - which is
     * exactly the distinction the model uses to mean "this entity is a value
     * object" or "this reference is multiple".
     *
     * @param   mixed   $subject  Object, array, or anything (which reads as absent).
     * @param   string  $path     Dot-separated.
     *
     * @return  boolean
     *
     * @since   0.2.0
     */
    public static function has(mixed $subject, string $path): bool
    {
        if ($path === '') {
            return true;
        }

        $value = $subject;

        foreach (explode('.', $path) as $step) {
            if (\is_object($value) && property_exists($value, $step)) {
                $value = $value->{$step};

                continue;
            }

            if (\is_array($value) && \array_key_exists($step, $value)) {
                $value = $value[$step];

                continue;
            }

            return false;
        }

        return true;
    }
}
