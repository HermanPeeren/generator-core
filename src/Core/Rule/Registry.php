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
 * The named functions a rule set is allowed to call.
 *
 * Two of these exist at run time: one of selectors, which answer "which source
 * elements", and one of derivations, which answer "what is this variable's
 * value". Both are the same shape - a closed set of names, each with a callable
 * behind it - so they are the same class with a different label in its errors.
 *
 * Closed is the point. A rule set is data, and data that can name any callable
 * is data that can do anything; a rule set that can only name what a target
 * registered can be read, checked and edited without being trusted. It is also
 * what lets a test say "every name this rule set uses exists" before a model is
 * involved, which is the check that catches a typo in a rule file.
 *
 * @since  0.2.0
 */
final class Registry
{
    /**
     * The registered callables, by name.
     *
     * @var    array<string, callable>
     * @since  0.2.0
     */
    private array $entries = [];

    /**
     * Constructor.
     *
     * @param   string  $label  What these are, for the error message: "selector", "derivation".
     *
     * @since   0.2.0
     */
    public function __construct(private readonly string $label)
    {
    }

    /**
     * Register one.
     *
     * @param   string    $name      What a rule calls it.
     * @param   callable  $callable  What it does.
     *
     * @return  self  For chaining, since registration is usually a run of them.
     *
     * @throws  RuleException  When the name is already taken.
     *
     * @since   0.2.0
     */
    public function register(string $name, callable $callable): self
    {
        if (isset($this->entries[$name])) {
            throw new RuleException('the ' . $this->label . ' "' . $name . '" is registered twice.');
        }

        $this->entries[$name] = $callable;

        return $this;
    }

    /**
     * Whether a name is registered.
     *
     * @param   string  $name  The name.
     *
     * @return  boolean
     *
     * @since   0.2.0
     */
    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /**
     * Call one.
     *
     * @param   string   $name    The registered name.
     * @param   mixed[]  $args    What to call it with.
     * @param   string   $ruleId  The rule that asked, for the error message.
     *
     * @return  mixed
     *
     * @throws  RuleException  When nothing is registered under that name.
     *
     * @since   0.2.0
     */
    public function call(string $name, array $args, string $ruleId): mixed
    {
        if (!isset($this->entries[$name])) {
            throw RuleException::inRule(
                $ruleId,
                'unknown ' . $this->label . ' "' . $name . '". Registered: ' . $this->names() . '.'
            );
        }

        return ($this->entries[$name])(...$args);
    }

    /**
     * Every registered name, for an error message or a test.
     *
     * @return  string  Comma-separated, sorted.
     *
     * @since   0.2.0
     */
    public function names(): string
    {
        $names = array_keys($this->entries);
        sort($names);

        return $names === [] ? '(none)' : implode(', ', $names);
    }
}
