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
 * A rule set that cannot be read or cannot be run.
 *
 * Always about the rules themselves - an unknown selector, a binding kind that
 * does not exist, a target path with a placeholder nothing binds - never about
 * the model being generated. A bad model is a `ValidationException`; a bad rule
 * set is a programming error in the generator, and the two should not be caught
 * in the same place.
 *
 * @since  0.2.0
 */
final class RuleException extends \RuntimeException
{
    /**
     * Name the rule the problem is in, so the message is actionable.
     *
     * A rule set has dozens of entries that look alike. "Unknown selector
     * 'backendPage'" sends somebody grepping; "rule admin.index.model: unknown
     * selector 'backendPage'" does not.
     *
     * @param   string  $ruleId   The rule's id.
     * @param   string  $problem  What is wrong with it.
     *
     * @return  self
     *
     * @since   0.2.0
     */
    public static function inRule(string $ruleId, string $problem): self
    {
        return new self('rule ' . $ruleId . ': ' . $problem);
    }
}
