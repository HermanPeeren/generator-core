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
 * One test a matched node must pass before its rule fires.
 *
 * The vocabulary is deliberately four words long. A rule set is going to be
 * edited in a form (that is what Gen-gen is for), and a form can offer a choice
 * between four operators; it cannot offer an expression language without
 * becoming a programming environment, which is the thing this design is trying
 * not to build.
 *
 * Anything a condition cannot say belongs in a named derivation, where it is
 * ordinary PHP with a name and a unit test.
 *
 * @since  0.2.0
 */
final class Condition
{
    /**
     * The property is present, whatever its value.
     *
     * The model marks things by presence rather than by value - an entity is a
     * value object because it *has* `isvalueobject`, not because that is true -
     * so this is the most used of the four.
     *
     * @since  0.2.0
     */
    public const HAS = 'has';

    /**
     * The property is absent.
     *
     * @since  0.2.0
     */
    public const MISSING = 'missing';

    /**
     * The property is present and equal to a value.
     *
     * @since  0.2.0
     */
    public const EQUALS = 'equals';

    /**
     * The property is absent, or present and not equal to a value.
     *
     * @since  0.2.0
     */
    public const NOT_EQUALS = 'notEquals';

    /**
     * The four, in the order a form should offer them.
     *
     * @var    string[]
     * @since  0.3.0
     */
    public const OPERATORS = [self::HAS, self::MISSING, self::EQUALS, self::NOT_EQUALS];

    /**
     * Constructor.
     *
     * @param   string  $operator  One of the four constants above.
     * @param   string  $path      Dotted path, read from the matched node.
     * @param   mixed   $value     What to compare with, for the two that compare.
     *
     * @since   0.2.0
     */
    private function __construct(
        public readonly string $operator,
        public readonly string $path,
        public readonly mixed $value = null
    ) {
    }

    /**
     * Read a condition from its stored form.
     *
     * @param   array<string, mixed>  $data   `{"operator": ..., "path": ..., "value": ...}`.
     * @param   string                $ruleId The rule it belongs to, for the error message.
     *
     * @return  self
     *
     * @throws  RuleException  When the operator is not one of the four.
     *
     * @since   0.2.0
     */
    public static function fromArray(array $data, string $ruleId): self
    {
        $operator = (string) ($data['operator'] ?? '');
        $path     = (string) ($data['path'] ?? '');

        if (!\in_array($operator, self::OPERATORS, true)) {
            throw RuleException::inRule(
                $ruleId,
                'unknown condition operator "' . $operator . '". Known: has, missing, equals, notEquals.'
            );
        }

        if ($path === '') {
            throw RuleException::inRule($ruleId, 'a condition needs a path.');
        }

        return new self($operator, $path, $data['value'] ?? null);
    }

    /**
     * Whether a node passes.
     *
     * @param   mixed  $node  The node the selector produced.
     *
     * @return  boolean
     *
     * @since   0.2.0
     */
    public function matches(mixed $node): bool
    {
        return match ($this->operator) {
            self::HAS        => PathReader::has($node, $this->path),
            self::MISSING    => !PathReader::has($node, $this->path),
            self::EQUALS     => PathReader::has($node, $this->path)
                                && PathReader::read($node, $this->path) === $this->value,
            self::NOT_EQUALS => !PathReader::has($node, $this->path)
                                || PathReader::read($node, $this->path) !== $this->value,
            default          => false,
        };
    }

    /**
     * Back to the stored form.
     *
     * @return  array<string, mixed>
     *
     * @since   0.2.0
     */
    public function toArray(): array
    {
        $data = ['operator' => $this->operator, 'path' => $this->path];

        if ($this->operator === self::EQUALS || $this->operator === self::NOT_EQUALS) {
            $data['value'] = $this->value;
        }

        return $data;
    }
}
