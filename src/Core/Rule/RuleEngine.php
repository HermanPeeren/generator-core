<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Rule
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Rule;

use Yepr\Gen\Core\Template\RendererInterface;

/**
 * Runs a rule set against a model.
 *
 * This is the whole of "how it is written out" that used to be mixed into every
 * generator: walk the selected nodes, check the conditions, resolve the
 * bindings, render, and hand the result to whoever is collecting files. It is
 * about a hundred lines, it is the same hundred lines for every target, and
 * nothing in it knows what a Joomla is.
 *
 * It does not write anything. The caller passes an emitter, which is how the
 * consuming generator keeps its own bookkeeping - counting overwrites, say -
 * rather than having the engine guess at it.
 *
 * @since  0.2.0
 */
final class RuleEngine
{
    /**
     * Constructor.
     *
     * @param   RendererInterface  $renderer     Turns a template plus variables into text.
     * @param   Registry           $selectors    Which source elements a rule applies to.
     * @param   Registry           $derivations  The computed half of the bindings.
     *
     * @since   0.2.0
     */
    public function __construct(
        private readonly RendererInterface $renderer,
        private readonly Registry $selectors,
        private readonly Registry $derivations
    ) {
    }

    /**
     * Run a rule set and emit what it produces.
     *
     * @param   RuleSet                              $rules  The rules, in order.
     * @param   object                               $model  The decoded source model.
     * @param   callable(string, string, Rule): void $emit   Given path, contents and the rule.
     *
     * @return  string[]  One line per file, in the words the user is shown.
     *
     * @throws  RuleException  When a rule names something that does not exist.
     *
     * @since   0.2.0
     */
    public function run(RuleSet $rules, object $model, callable $emit): array
    {
        $log = [];

        foreach ($rules->blocks() as [$selector, $blockRules]) {
            $nodes = $this->selectors->call($selector, [$model], $blockRules[0]->id);

            if (!is_iterable($nodes)) {
                throw RuleException::inRule(
                    $blockRules[0]->id,
                    'the selector "' . $selector . '" returned a ' . get_debug_type($nodes)
                    . '; a selector yields the nodes its rules apply to.'
                );
            }

            // Node-major within a block: everything one page produces, then the
            // next page. See RuleSet for why that ordering is load-bearing.
            foreach ($nodes as $node) {
                foreach ($blockRules as $rule) {
                    if (!$rule->matches($node)) {
                        continue;
                    }

                    $path = $this->emit($rule, $node, $model, $emit);

                    $log[] = basename($path) . ' generated';
                }
            }
        }

        return $log;
    }

    /**
     * Render one rule for one node, and emit the file.
     *
     * @param   Rule                                 $rule   The rule.
     * @param   mixed                                $node   The node it matched.
     * @param   object                               $model  The decoded source model.
     * @param   callable(string, string, Rule): void $emit   Given path, contents and the rule.
     *
     * @return  string  The target path that was written.
     *
     * @since   0.2.0
     */
    private function emit(Rule $rule, mixed $node, object $model, callable $emit): string
    {
        $variables = $this->bind($rule, $node, $model);
        $path      = Interpolator::expand($rule->target, $variables, $rule->id);

        $emit($path, $this->renderer->render($rule->template, $variables), $rule);

        return $path;
    }

    /**
     * Resolve a rule's bindings into template variables.
     *
     * Fragments come last and see everything else, because a fragment is part
     * of the same file and reads the same names.
     *
     * @param   Rule    $rule   The rule.
     * @param   mixed   $node   The node it matched.
     * @param   object  $model  The decoded source model.
     *
     * @return  array<string, mixed>
     *
     * @since   0.2.0
     */
    private function bind(Rule $rule, mixed $node, object $model): array
    {
        $variables = [];
        $fragments = [];

        foreach ($rule->bind as $name => $binding) {
            if ($binding->kind === Binding::FRAGMENTS) {
                $fragments[$name] = $binding;

                continue;
            }

            $variables[$name] = match ($binding->kind) {
                Binding::LITERAL => $binding->value,
                Binding::PATH    => PathReader::read($model, (string) $binding->value),
                Binding::NODE    => PathReader::read($node, (string) $binding->value),
                Binding::DERIVE  => $this->derivations->call((string) $binding->value, [$node, $model], $rule->id),
                default          => null,
            };
        }

        foreach ($fragments as $name => $binding) {
            $variables[$name] = $this->renderFragments($binding, $rule, $node, $model, $variables);
        }

        return $variables;
    }

    /**
     * Render a repeated fragment and join the results.
     *
     * @param   Binding               $binding    The fragments binding.
     * @param   Rule                  $rule       The rule it belongs to.
     * @param   mixed                 $node       The node the rule matched.
     * @param   object                $model      The decoded source model.
     * @param   array<string, mixed>  $variables  The rule's other bindings, already resolved.
     *
     * @return  string
     *
     * @since   0.2.0
     */
    private function renderFragments(
        Binding $binding,
        Rule $rule,
        mixed $node,
        object $model,
        array $variables
    ): string {
        $repetitions = $this->derivations->call((string) $binding->value, [$node, $model], $rule->id);

        if (!is_iterable($repetitions)) {
            throw RuleException::inRule(
                $rule->id,
                'the derivation "' . $binding->value . '" is used for fragments, so it must yield one set of '
                . 'variables per repetition; it returned a ' . get_debug_type($repetitions) . '.'
            );
        }

        $rendered = [];

        foreach ($repetitions as $own) {
            $rendered[] = $this->renderer->render(
                (string) $binding->template,
                array_merge($variables, (array) $own)
            );
        }

        return implode($binding->glue, $rendered);
    }
}
