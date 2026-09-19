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
 * Builds a rule's target path out of its bound variables.
 *
 * `administrator/components/com_{componentName|lower}/src/Table/{entityName}Table.php`
 * is the whole language: a placeholder names a bound variable, and an optional
 * filter changes its case. Nothing else - no concatenation, no conditionals, no
 * arithmetic.
 *
 * That is enough because the paths in a real target are built from names the
 * model already carries, and it is small enough that a form can offer the
 * filters as a dropdown.
 *
 * An unknown variable or filter throws rather than expanding to nothing. A path
 * that silently loses a segment produces a file in the wrong place, which
 * installs, and is then very hard to explain.
 *
 * @since  0.2.0
 */
final class Interpolator
{
    /**
     * The filters a placeholder may use.
     *
     * @var    string[]
     * @since  0.2.0
     */
    private const FILTERS = ['lower', 'upper', 'ucfirst', 'lcfirst'];

    /**
     * Expand `{name}` and `{name|filter}` against a set of values.
     *
     * @param   string                $pattern  The path pattern.
     * @param   array<string, mixed>  $values   Bound variables.
     * @param   string                $ruleId   The rule, for the error message.
     *
     * @return  string
     *
     * @throws  RuleException  On an unknown placeholder, an unknown filter, or a
     *                         value that is not a string.
     *
     * @since   0.2.0
     */
    public static function expand(string $pattern, array $values, string $ruleId): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?:\|([a-z]+))?\}/',
            static function (array $match) use ($values, $ruleId, $pattern): string {
                $name   = $match[1];
                $filter = $match[2] ?? '';

                if (!\array_key_exists($name, $values)) {
                    throw RuleException::inRule(
                        $ruleId,
                        'path "' . $pattern . '" uses {' . $name . '}, which the rule does not bind.'
                    );
                }

                $value = $values[$name];

                if (!\is_string($value) && !\is_int($value)) {
                    throw RuleException::inRule(
                        $ruleId,
                        'path "' . $pattern . '" uses {' . $name . '}, which is a '
                        . get_debug_type($value) . ' and cannot go in a path.'
                    );
                }

                $value = (string) $value;

                if ($filter === '') {
                    return $value;
                }

                if (!\in_array($filter, self::FILTERS, true)) {
                    throw RuleException::inRule(
                        $ruleId,
                        'path "' . $pattern . '" uses the filter |' . $filter
                        . ', which does not exist. Known: ' . implode(', ', self::FILTERS) . '.'
                    );
                }

                return match ($filter) {
                    'lower'   => strtolower($value),
                    'upper'   => strtoupper($value),
                    'ucfirst' => ucfirst($value),
                    'lcfirst' => lcfirst($value),
                };
            },
            $pattern
        );
    }

    /**
     * The variable names a pattern refers to.
     *
     * Lets a rule set be checked without running it: every placeholder must be
     * bound, and a test can say so before a model is anywhere near.
     *
     * @param   string  $pattern  The path pattern.
     *
     * @return  string[]
     *
     * @since   0.2.0
     */
    public static function placeholders(string $pattern): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?:\|[a-z]+)?\}/', $pattern, $matches);

        return array_values(array_unique($matches[1]));
    }
}
