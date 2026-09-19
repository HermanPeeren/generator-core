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
 * One transformation rule: which source elements produce which target file.
 *
 * Read it as a sentence. *For* each node a selector yields, *when* it passes
 * these conditions, render this *template* to this *target* path, *binding*
 * these variables.
 *
 * That sentence used to be spread over a hundred lines of imperative PHP per
 * generator, where the loop, the condition, the template name and the output
 * path were four unrelated statements that happened to be near each other, and
 * where the variables arrived through a `$templateVariables` array that every
 * branch added to and nobody reset. Which file came from which template for
 * which model element was not written down anywhere; it had to be reconstructed
 * by reading the control flow.
 *
 * Writing it down is what makes the next step possible: a rule you can read is
 * a rule you can store, edit in a form and generate.
 *
 * @since  0.2.0
 */
final class Rule
{
    /**
     * Constructor.
     *
     * @param   string                    $id        Unique within the set; names it in errors and logs.
     * @param   string                    $for       Selector name: which source elements this applies to.
     * @param   Condition[]               $when      All must pass.
     * @param   string                    $template  Template identifier, within the target's template set.
     * @param   string                    $target    Output path pattern, within the package.
     * @param   array<string, Binding>    $bind      Template variable => where its value comes from.
     *
     * @since   0.2.0
     */
    public function __construct(
        public readonly string $id,
        public readonly string $for,
        public readonly array $when,
        public readonly string $template,
        public readonly string $target,
        public readonly array $bind
    ) {
    }

    /**
     * Read a rule from its stored form.
     *
     * @param   array<string, mixed>  $data  The stored rule.
     *
     * @return  self
     *
     * @throws  RuleException  When a required part is missing or malformed.
     *
     * @since   0.2.0
     */
    public static function fromArray(array $data): self
    {
        $id = (string) ($data['id'] ?? '');

        if ($id === '') {
            throw new RuleException('every rule needs an id; found one without.');
        }

        $parts = [];

        foreach (['for', 'template', 'target'] as $required) {
            $value = $data[$required] ?? null;

            if (!\is_string($value) || $value === '') {
                throw RuleException::inRule($id, 'needs a "' . $required . '".');
            }

            $parts[$required] = $value;
        }

        $when = [];

        foreach ((array) ($data['when'] ?? []) as $condition) {
            $when[] = Condition::fromArray((array) $condition, $id);
        }

        $bind = [];

        foreach ((array) ($data['bind'] ?? []) as $name => $binding) {
            $bind[(string) $name] = Binding::fromArray((array) $binding, (string) $name, $id);
        }

        return new self($id, $parts['for'], $when, $parts['template'], $parts['target'], $bind);
    }

    /**
     * Whether a node passes every condition.
     *
     * @param   mixed  $node  The node the selector produced.
     *
     * @return  boolean
     *
     * @since   0.2.0
     */
    public function matches(mixed $node): bool
    {
        foreach ($this->when as $condition) {
            if (!$condition->matches($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Back to the stored form.
     *
     * Round-tripping matters: Gen-gen will read a rule set, put it in a form,
     * and write it back. Anything this loses is something an edit would silently
     * delete.
     *
     * @return  array<string, mixed>
     *
     * @since   0.2.0
     */
    public function toArray(): array
    {
        $data = [
            'id'       => $this->id,
            'for'      => $this->for,
            'template' => $this->template,
            'target'   => $this->target,
        ];

        if ($this->when !== []) {
            $data['when'] = array_map(static fn (Condition $c): array => $c->toArray(), $this->when);
        }

        if ($this->bind !== []) {
            $data['bind'] = array_map(static fn (Binding $b): array => $b->toArray(), $this->bind);
        }

        return $data;
    }
}
