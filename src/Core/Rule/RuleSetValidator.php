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
 * Checks a rule set without running it.
 *
 * Everything here is answerable from the rules alone - no model, no renderer,
 * no output. That is the point: a typo in a selector name is a mistake in the
 * rule file, and it should be reported as one, on every build, rather than
 * surfacing as a missing file in somebody's generated component months later.
 *
 * What it cannot see is whether the templates exist and whether they read
 * variables nothing binds. Both depend on the template set, which the core does
 * not have an opinion about, so those checks belong to the target that owns it.
 *
 * @since  0.2.0
 */
final class RuleSetValidator
{
    /**
     * Constructor.
     *
     * @param   Registry  $selectors    The selectors a rule may name.
     * @param   Registry  $derivations  The derivations a binding may name.
     *
     * @since   0.2.0
     */
    public function __construct(
        private readonly Registry $selectors,
        private readonly Registry $derivations
    ) {
    }

    /**
     * Every problem in a rule set, as readable lines.
     *
     * Returns them all rather than throwing on the first, because a rule file
     * is usually edited in bulk and fixing one typo per run is a poor way to
     * spend an afternoon.
     *
     * @param   RuleSet  $rules  The rules to check.
     *
     * @return  string[]  Empty when the set is sound.
     *
     * @since   0.2.0
     */
    public function problems(RuleSet $rules): array
    {
        $problems = [];

        foreach ($rules as $rule) {
            if (!$this->selectors->has($rule->for)) {
                $problems[] = 'rule ' . $rule->id . ': unknown selector "' . $rule->for
                    . '". Registered: ' . $this->selectors->names() . '.';
            }

            foreach ($rule->bind as $name => $binding) {
                $problems = array_merge($problems, $this->bindingProblems($rule, $name, $binding));
            }

            $problems = array_merge($problems, $this->pathProblems($rule));
        }

        return $problems;
    }

    /**
     * What is wrong with one binding.
     *
     * @param   Rule     $rule     The rule it belongs to.
     * @param   string   $name     The variable it binds.
     * @param   Binding  $binding  The binding.
     *
     * @return  string[]
     *
     * @since   0.2.0
     */
    private function bindingProblems(Rule $rule, string $name, Binding $binding): array
    {
        if ($binding->kind !== Binding::DERIVE && $binding->kind !== Binding::FRAGMENTS) {
            return [];
        }

        if ($this->derivations->has((string) $binding->value)) {
            return [];
        }

        return [
            'rule ' . $rule->id . ': binding "' . $name . '" names the derivation "'
            . $binding->value . '", which is not registered. Registered: '
            . $this->derivations->names() . '.',
        ];
    }

    /**
     * Whether the target path can be built from what the rule binds.
     *
     * @param   Rule  $rule  The rule.
     *
     * @return  string[]
     *
     * @since   0.2.0
     */
    private function pathProblems(Rule $rule): array
    {
        $problems = [];

        foreach (Interpolator::placeholders($rule->target) as $placeholder) {
            if (!isset($rule->bind[$placeholder])) {
                $problems[] = 'rule ' . $rule->id . ': target path uses {' . $placeholder
                    . '}, which the rule does not bind.';
            }
        }

        if (str_starts_with($rule->target, '/') || preg_match('#(^|/)\.\.(/|$)#', $rule->target) === 1) {
            $problems[] = 'rule ' . $rule->id . ': target path "' . $rule->target
                . '" leaves the package.';
        }

        return $problems;
    }

    /**
     * Throw unless the set is sound.
     *
     * @param   RuleSet  $rules  The rules to check.
     *
     * @return  void
     *
     * @throws  RuleException  Listing every problem found.
     *
     * @since   0.2.0
     */
    public function assertValid(RuleSet $rules): void
    {
        $problems = $this->problems($rules);

        if ($problems !== []) {
            throw new RuleException(
                \count($problems) . " problem(s) in the rule set:\n  " . implode("\n  ", $problems)
            );
        }
    }
}
