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
 * An ordered list of rules, and the blocks they fall into.
 *
 * **Order is part of the meaning, not a detail.** Two things depend on it.
 * Where two rules derive the same output path, the later one wins, exactly as
 * the hand-written generators' last write did. And a template that registers
 * something while it renders - a language string, say - contributes in the
 * order it ran, so reordering rules reorders that file's contents.
 *
 * **Consecutive rules sharing a selector form a block, and a block runs
 * node-major**: for each backend page, emit its controller, its model, its view
 * and its layout, rather than every controller and then every model. That is
 * how the imperative version ran, so it keeps the output identical; it is also
 * how the rules read aloud, so it is not a compatibility hack.
 *
 * @implements \IteratorAggregate<int, Rule>
 *
 * @since  0.2.0
 */
final class RuleSet implements \IteratorAggregate, \Countable
{
    /**
     * Constructor.
     *
     * @param   Rule[]  $rules  In the order they run.
     *
     * @throws  RuleException  When two rules share an id.
     *
     * @since   0.2.0
     */
    public function __construct(private readonly array $rules)
    {
        $seen = [];

        foreach ($rules as $rule) {
            if (isset($seen[$rule->id])) {
                throw new RuleException('two rules share the id "' . $rule->id . '".');
            }

            $seen[$rule->id] = true;
        }
    }

    /**
     * Read a rule set from its stored form.
     *
     * @param   array<int, array<string, mixed>>  $data  A list of stored rules.
     *
     * @return  self
     *
     * @throws  RuleException  When any rule is malformed.
     *
     * @since   0.2.0
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $rule): Rule => Rule::fromArray($rule),
            array_values($data)
        ));
    }

    /**
     * Read a rule set from JSON.
     *
     * @param   string  $json  A JSON array of rules.
     *
     * @return  self
     *
     * @throws  RuleException  When the JSON is invalid or any rule is malformed.
     *
     * @since   0.2.0
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuleException('the rule set is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!\is_array($data)) {
            throw new RuleException('a rule set must be a JSON array of rules.');
        }

        return self::fromArray($data);
    }

    /**
     * Read a rule set from a file.
     *
     * @param   string  $path  Absolute path to a `.json` rule set.
     *
     * @return  self
     *
     * @throws  RuleException  When the file cannot be read or does not parse.
     *
     * @since   0.2.0
     */
    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new RuleException('cannot read the rule set at ' . $path . '.');
        }

        return self::fromJson($json);
    }

    /**
     * Only the rules whose id starts with a prefix, in order.
     *
     * How a generator that owns one concern runs its share of a single shared
     * rule file: `admin.mvc.` selects the back-end MVC rules and leaves the
     * rest alone. One file keeps the whole mapping readable in one place; the
     * prefix keeps the running of it in the same order it has always run.
     *
     * @param   string  $prefix  Matched against the start of each id.
     *
     * @return  self
     *
     * @since   0.2.0
     */
    public function withPrefix(string $prefix): self
    {
        return new self(array_values(array_filter(
            $this->rules,
            static fn (Rule $rule): bool => str_starts_with($rule->id, $prefix)
        )));
    }

    /**
     * The rules grouped into blocks of consecutive rules sharing a selector.
     *
     * @return  array<int, array{0: string, 1: Rule[]}>  Selector name, then its rules.
     *
     * @since   0.2.0
     */
    public function blocks(): array
    {
        $blocks = [];

        foreach ($this->rules as $rule) {
            $last = array_key_last($blocks);

            if ($last !== null && $blocks[$last][0] === $rule->for) {
                $blocks[$last][1][] = $rule;

                continue;
            }

            $blocks[] = [$rule->for, [$rule]];
        }

        return $blocks;
    }

    /**
     * Back to the stored form.
     *
     * @return  array<int, array<string, mixed>>
     *
     * @since   0.2.0
     */
    public function toArray(): array
    {
        return array_map(static fn (Rule $rule): array => $rule->toArray(), $this->rules);
    }

    /**
     * Back to JSON, formatted the way a rule file is committed.
     *
     * @return  string
     *
     * @since   0.2.0
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        );
    }

    /**
     * The rules, in order.
     *
     * @return  \ArrayIterator<int, Rule>
     *
     * @since   0.2.0
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rules);
    }

    /**
     * How many rules there are.
     *
     * @return  integer
     *
     * @since   0.2.0
     */
    public function count(): int
    {
        return \count($this->rules);
    }
}
