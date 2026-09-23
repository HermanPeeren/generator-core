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
 * Everything a rule for one target is allowed to say.
 *
 * A rule set names selectors, derivations and templates. Which ones exist is
 * the target's business, and until now the only way to find out was to read the
 * target's PHP. That is fine for the engine, which is handed the registries,
 * and useless for anything that wants to *offer* the choices - a form, a
 * validator in another process, documentation.
 *
 * So a target publishes this: a flat, serialisable list of the names a rule may
 * use, beside the three fixed vocabularies the library itself defines. An
 * editor reads it and can then offer dropdowns rather than text boxes, without
 * loading a line of the target's code or knowing what a Joomla is.
 *
 * It is generated from the registries rather than written by hand, for the same
 * reason `updates.xml` is generated: a list repeated in two places is a list
 * that will disagree with itself, and the disagreement shows up as a form
 * offering a derivation nobody registered.
 *
 * @since  0.3.0
 */
final class Vocabulary
{
    /**
     * Constructor.
     *
     * @param   string    $target       Which target this describes.
     * @param   string[]  $selectors    Source patterns a rule may be written for.
     * @param   string[]  $derivations  Named functions a binding may call.
     * @param   string[]  $templates    Template identifiers, relative to the template set.
     * @param   array<string, array<int, mixed>>  $paths  What each selector *is*, for the ones written down.
     *
     * @since   0.3.0
     */
    public function __construct(
        public readonly string $target,
        public readonly array $selectors,
        public readonly array $derivations,
        public readonly array $templates,
        public readonly array $paths = []
    ) {
    }

    /**
     * The selectors this vocabulary describes rather than only names.
     *
     * The source half, added at 3.6. A vocabulary used to list what a rule may
     * *say* - four names - and nothing about what those names mean; the meaning
     * was a PHP closure in the target's own code, written against one language's
     * shape. A selector that is a path can travel with the vocabulary instead.
     *
     * Empty for a target that still registers closures, and that is not a
     * migration left half done: a target generating from something other than a
     * modelled language has nowhere to put a path, and `entities` meaning
     * "whatever this PHP says" is the honest answer for one of those.
     *
     * @return  array<string, array<int, mixed>>
     *
     * @since   0.9.0
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Whether a selector says what it is rather than only that it exists.
     *
     * @since   0.9.0
     */
    public function describes(string $selector): bool
    {
        return isset($this->paths[$selector]);
    }

    /**
     * Read a target's vocabulary off its registries.
     *
     * @param   string    $target       Which target this describes.
     * @param   Registry  $selectors    The target's selectors.
     * @param   Registry  $derivations  The target's derivations.
     * @param   string[]  $templates    Its template set, as identifiers.
     *
     * @return  self
     *
     * @since   0.3.0
     */
    public static function fromRegistries(
        string $target,
        Registry $selectors,
        Registry $derivations,
        array $templates
    ): self {
        sort($templates);

        return new self($target, $selectors->all(), $derivations->all(), $templates);
    }

    /**
     * Read one back.
     *
     * @param   array<string, mixed>  $data  The stored descriptor.
     *
     * @return  self
     *
     * @throws  RuleException  When a required part is missing.
     *
     * @since   0.3.0
     */
    public static function fromArray(array $data): self
    {
        $target = $data['target'] ?? null;

        if (!\is_string($target) || $target === '') {
            throw new RuleException('a vocabulary must name its target.');
        }

        foreach (['selectors', 'derivations', 'templates'] as $required) {
            if (!isset($data[$required]) || !\is_array($data[$required])) {
                throw new RuleException('the vocabulary for ' . $target . ' has no "' . $required . '" list.');
            }
        }

        // `selectorPaths` is optional, and its absence is what a target that
        // still registers closures looks like. A vocabulary written before 3.6
        // reads exactly as it did.
        $paths = [];

        foreach (\is_array($data['selectorPaths'] ?? null) ? $data['selectorPaths'] : [] as $name => $steps) {
            if (\is_string($name) && \is_array($steps)) {
                $paths[$name] = array_values($steps);
            }
        }

        $selectors = array_map(strval(...), array_values($data['selectors']));

        foreach (array_keys($paths) as $described) {
            if (!\in_array($described, $selectors, true)) {
                throw new RuleException(
                    'the vocabulary for ' . $target . ' describes a selector "' . $described
                    . '" it does not offer. A path for a selector nothing may name is a path'
                    . ' nothing will ever walk.'
                );
            }
        }

        return new self(
            $target,
            $selectors,
            array_map(strval(...), array_values($data['derivations'])),
            array_map(strval(...), array_values($data['templates'])),
            $paths
        );
    }

    /**
     * Read one from a file.
     *
     * @param   string  $path  Absolute path to a `.json` descriptor.
     *
     * @return  self
     *
     * @throws  RuleException  When the file cannot be read or does not parse.
     *
     * @since   0.3.0
     */
    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new RuleException('cannot read the vocabulary at ' . $path . '.');
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuleException('the vocabulary at ' . $path . ' is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!\is_array($data)) {
            throw new RuleException('a vocabulary must be a JSON object.');
        }

        return self::fromArray($data);
    }

    /**
     * The condition operators, which the library defines and no target extends.
     *
     * @return  string[]
     *
     * @since   0.3.0
     */
    public function operators(): array
    {
        return Condition::OPERATORS;
    }

    /**
     * The binding kinds, likewise.
     *
     * @return  string[]
     *
     * @since   0.3.0
     */
    public function bindingKinds(): array
    {
        return Binding::KINDS;
    }

    /**
     * The filters a target path placeholder may use, likewise.
     *
     * @return  string[]
     *
     * @since   0.3.0
     */
    public function pathFilters(): array
    {
        return Interpolator::FILTERS;
    }

    /**
     * Back to the stored form.
     *
     * The three fixed lists are written out too. They are the library's, not the
     * target's, but a descriptor that carries them can be read by an editor
     * pinned to a different version of the library - and one that silently
     * offers the wrong operators is worse than one that says which it knows.
     *
     * @return  array<string, mixed>
     *
     * @since   0.3.0
     */
    public function toArray(): array
    {
        $data = [
            'target'       => $this->target,
            'selectors'    => $this->selectors,
            'derivations'  => $this->derivations,
            'templates'    => $this->templates,
            'operators'    => $this->operators(),
            'bindingKinds' => $this->bindingKinds(),
            'pathFilters'  => $this->pathFilters(),
        ];

        // Left out entirely when there are none, so a target that registers
        // closures round-trips to the descriptor it had before 3.6 rather than
        // to one carrying an empty object nobody wrote.
        if ($this->paths !== []) {
            $data['selectorPaths'] = $this->paths;
        }

        return $data;
    }

    /**
     * Back to JSON, formatted the way a descriptor is committed.
     *
     * @return  string
     *
     * @since   0.3.0
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * What in a rule set this vocabulary cannot account for.
     *
     * The same question `RuleSetValidator` answers from live registries, asked
     * from the descriptor instead - which is how an editor checks a rule set it
     * is about to save without being able to run it.
     *
     * @param   RuleSet  $rules  The rules to check.
     *
     * @return  string[]  Empty when everything the rules name is in the vocabulary.
     *
     * @since   0.3.0
     */
    public function problems(RuleSet $rules): array
    {
        $problems = [];

        foreach ($rules as $rule) {
            if (!\in_array($rule->for, $this->selectors, true)) {
                $problems[] = 'rule ' . $rule->id . ': "' . $rule->for . '" is not a selector of ' . $this->target . '.';
            }

            if (!\in_array($rule->template, $this->templates, true)) {
                $problems[] = 'rule ' . $rule->id . ': "' . $rule->template . '" is not a template of '
                    . $this->target . '.';
            }

            foreach ($rule->when as $condition) {
                if (!\in_array($condition->operator, $this->operators(), true)) {
                    $problems[] = 'rule ' . $rule->id . ': "' . $condition->operator . '" is not an operator.';
                }
            }

            foreach ($rule->bind as $name => $binding) {
                $problems = array_merge($problems, $this->bindingProblems($rule->id, $name, $binding));
            }
        }

        return $problems;
    }

    /**
     * What one binding names that this vocabulary does not have.
     *
     * @param   string   $ruleId   The rule it belongs to.
     * @param   string   $name     The variable it binds.
     * @param   Binding  $binding  The binding.
     *
     * @return  string[]
     *
     * @since   0.3.0
     */
    private function bindingProblems(string $ruleId, string $name, Binding $binding): array
    {
        $problems = [];

        if (!\in_array($binding->kind, $this->bindingKinds(), true)) {
            $problems[] = 'rule ' . $ruleId . ': binding "' . $name . '" is of no known kind.';
        }

        if (
            ($binding->kind === Binding::DERIVE || $binding->kind === Binding::FRAGMENTS)
            && !\in_array((string) $binding->value, $this->derivations, true)
        ) {
            $problems[] = 'rule ' . $ruleId . ': binding "' . $name . '" names "' . $binding->value
                . '", which is not a derivation of ' . $this->target . '.';
        }

        if (
            $binding->kind === Binding::FRAGMENTS
            && !\in_array((string) $binding->template, $this->templates, true)
        ) {
            $problems[] = 'rule ' . $ruleId . ': binding "' . $name . '" renders "' . $binding->template
                . '", which is not a template of ' . $this->target . '.';
        }

        return $problems;
    }
}
