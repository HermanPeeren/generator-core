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
 * Where one template variable's value comes from.
 *
 * Four kinds, and the split between them is the whole point of separating the
 * mapping from the code. Three of them are pure data - read this path, use this
 * constant - and are the majority. The fourth names a function, which is the
 * honest way to say "this one is a computation": it stays PHP, it keeps a name,
 * and it can be unit-tested on its own.
 *
 * What is *not* here is an expression language. A rule set that could compute
 * would be a program stored as JSON, which is worse than a program stored as
 * PHP in every way that matters - no analysis, no debugger, no type checking.
 * The named derivation is the seam: everything expressible as a lookup is data,
 * and everything else is code that data can call.
 *
 * @since  0.2.0
 */
final class Binding
{
    /**
     * A constant written into the rule.
     *
     * @since  0.2.0
     */
    public const LITERAL = 'literal';

    /**
     * A dotted path, read from the model root.
     *
     * @since  0.2.0
     */
    public const PATH = 'path';

    /**
     * A dotted path, read from the node the selector matched.
     *
     * @since  0.2.0
     */
    public const NODE = 'node';

    /**
     * A named function in the derivation registry.
     *
     * @since  0.2.0
     */
    public const DERIVE = 'derive';

    /**
     * A repeated template fragment, concatenated.
     *
     * A derivation yields one set of variables per repetition; the fragment is
     * rendered once for each and the results joined. This exists because some
     * generated files are assembled from a template plus N copies of a smaller
     * one - the many-to-many methods on a Joomla table, for instance - and
     * without it that assembly would have to happen inside a derivation, which
     * would mean handing a renderer to code that is supposed to be a lookup.
     *
     * @since  0.2.0
     */
    public const FRAGMENTS = 'fragments';

    /**
     * Constructor.
     *
     * @param   string   $kind      One of the five constants above.
     * @param   mixed    $value     Literal value, path, or derivation name.
     * @param   ?string  $template  The fragment template, for FRAGMENTS only.
     * @param   string   $glue      What to join rendered fragments with.
     *
     * @since   0.2.0
     */
    private function __construct(
        public readonly string $kind,
        public readonly mixed $value,
        public readonly ?string $template = null,
        public readonly string $glue = ''
    ) {
    }

    /**
     * Read a binding from its stored form.
     *
     * The stored form is a single-key object - `{"path": "name"}` - because
     * that is what reads well in a rule file and what a form maps onto without
     * a wrapper. The key is the kind.
     *
     * @param   array<string, mixed>  $data    The stored binding.
     * @param   string                $name    The variable it binds, for the error message.
     * @param   string                $ruleId  The rule it belongs to, likewise.
     *
     * @return  self
     *
     * @throws  RuleException  When the kind is unknown, or FRAGMENTS has no template.
     *
     * @since   0.2.0
     */
    public static function fromArray(array $data, string $name, string $ruleId): self
    {
        $kinds = [self::LITERAL, self::PATH, self::NODE, self::DERIVE, self::FRAGMENTS];
        $found = array_values(array_intersect($kinds, array_keys($data)));

        if (\count($found) !== 1) {
            throw RuleException::inRule(
                $ruleId,
                'binding "' . $name . '" must name exactly one of: ' . implode(', ', $kinds) . '.'
            );
        }

        $kind = $found[0];

        if ($kind !== self::FRAGMENTS) {
            return new self($kind, $data[$kind]);
        }

        $template = $data['template'] ?? null;

        if (!\is_string($template) || $template === '') {
            throw RuleException::inRule($ruleId, 'binding "' . $name . '" is fragments but names no template.');
        }

        return new self($kind, $data[$kind], $template, (string) ($data['glue'] ?? ''));
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
        $data = [$this->kind => $this->value];

        if ($this->kind === self::FRAGMENTS) {
            $data['template'] = $this->template;

            if ($this->glue !== '') {
                $data['glue'] = $this->glue;
            }
        }

        return $data;
    }
}
