<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Rule
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Rule;

use Yepr\Gen\Core\Reference\ReferenceIndex;

/**
 * Which nodes of a model a rule applies to, written down rather than coded.
 *
 * Step 3.6. A selector used to be a PHP closure a target registered:
 * `Joomla6Selectors::entities()` reaches into `$model->datamodel`, which is
 * ER1's word for it, in a class ER1's component ships. A generator modelled for
 * some other metalanguage could name that selector and get nothing, because the
 * closure is about one language's shape and says so nowhere.
 *
 * A path is the same thing as data: a list of steps from the model's root to
 * the nodes a rule iterates over. `root` is no steps at all; ER1's `entities`
 * is one step into `datamodel`.
 *
 * **Two kinds of step, because two kinds of thing are worth selecting.**
 * Descending into a containment is the ordinary one. Following a reference is
 * the other, and it is the reason a path is not just a dotted string: ER1's
 * back-end pages are not *in* the component, they are *pointed at* from it -
 * `Sections.backendsection` holds references and the pages themselves live in a
 * flat list beside them. The imperative generator did that join inline, twice,
 * with a page map rebuilt at the top of each, which is why "the back-end pages"
 * was a thing you re-derived rather than a thing you could name.
 *
 * A follow step is resolved through the language's own reference table - the
 * `references.json` its package carries - so nothing here needs to know what a
 * concept model is. The table already says where each type lives and which
 * property holds its id, and that is exactly what following a reference needs.
 *
 * **Order is part of the answer.** A selector yields nodes in model order and
 * that order reaches the output: ER1's first back-end index page becomes the
 * generated component's default view. Following references keeps the order of
 * the references, not the order of the things they point at.
 *
 * @since  0.8.0
 */
final class SelectorPath
{
    /**
     * A step that descends into a containment.
     *
     * @since  0.8.0
     */
    public const CONTAIN = 'contain';

    /**
     * A step that follows a reference to the node it names.
     *
     * @since  0.8.0
     */
    public const FOLLOW = 'follow';

    /**
     * A step that is every node of one concept, wherever they live.
     *
     * The other two walk *from* somewhere; this one does not, which is why it
     * is a step of its own rather than a path with no steps. "Every Entity in
     * the project" is the selector a person reaches for first, and a modelled
     * language can offer it without being asked: the reference table already
     * says where each type lives, so a concept is a selector for free.
     *
     * Only ever the first step of a path, because it ignores what came before.
     *
     * @since  0.10.0
     */
    public const ALL = 'all';

    /**
     * @param  array<int, array<string, string>>  $steps  In the order they are walked.
     *
     * @since  0.8.0
     */
    private function __construct(private readonly array $steps)
    {
    }

    /**
     * Read a path out of its stored form.
     *
     * A step is `{"contain": "datamodel"}` or
     * `{"follow": "page_reference", "to": "Page"}`. Anything else is refused
     * here rather than ignored while walking, because a selector that silently
     * skipped a step it did not understand would return the wrong nodes and
     * look like it worked.
     *
     * @param  array<int, mixed>  $steps
     *
     * @throws RuleException  When a step is not one of the two kinds.
     *
     * @since  0.8.0
     */
    public static function fromArray(array $steps, string $name = ''): self
    {
        $read = [];

        foreach ($steps as $position => $step) {
            if (!\is_array($step)) {
                throw new RuleException(
                    'Step ' . ($position + 1) . ' of the selector "' . $name . '" is a '
                    . get_debug_type($step) . '; a step is an object naming what it does.'
                );
            }

            if (isset($step[self::CONTAIN]) && \is_string($step[self::CONTAIN])) {
                $read[] = [self::CONTAIN => $step[self::CONTAIN]];

                continue;
            }

            if (isset($step[self::ALL]) && \is_string($step[self::ALL])) {
                if ($position !== 0) {
                    throw new RuleException(
                        'Step ' . ($position + 1) . ' of the selector "' . $name
                        . '" is an "' . self::ALL . '", which ignores the steps before it.'
                        . ' It can only be the first.'
                    );
                }

                $read[] = [self::ALL => $step[self::ALL]];

                continue;
            }

            if (
                isset($step[self::FOLLOW], $step['to'])
                && \is_string($step[self::FOLLOW]) && \is_string($step['to'])
            ) {
                $read[] = [self::FOLLOW => $step[self::FOLLOW], 'to' => $step['to']];

                continue;
            }

            throw new RuleException(
                'Step ' . ($position + 1) . ' of the selector "' . $name
                . '" is neither a "' . self::CONTAIN . '" nor a "' . self::FOLLOW
                . '" with a "to".'
            );
        }

        return new self($read);
    }

    /**
     * The steps, as they were written down.
     *
     * @return array<int, array<string, string>>
     *
     * @since  0.8.0
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * The nodes this path reaches in one model.
     *
     * @param  object           $model       The stored model, decoded.
     * @param  ?ReferenceIndex  $references  The language's reference table, for follow steps.
     *
     * @return object[]  In model order.
     *
     * @throws RuleException  When a follow step names a type the table does not carry.
     *
     * @since  0.8.0
     */
    public function walk(object $model, ?ReferenceIndex $references = null): array
    {
        $here = [$model];

        foreach ($this->steps as $step) {
            if (isset($step[self::ALL])) {
                $here = array_values($this->typed($step[self::ALL], $model, $references));

                continue;
            }

            $here = isset($step[self::CONTAIN])
                ? self::descend($here, $step[self::CONTAIN])
                : $this->follow($here, $step, $model, $references);
        }

        return $here;
    }

    /**
     * Every node of one type.
     *
     * @return array<string, object>
     *
     * @throws RuleException  When the language does not carry the type.
     *
     * @since  0.10.0
     */
    private function typed(string $type, object $model, ?ReferenceIndex $references): array
    {
        if ($references === null) {
            throw new RuleException(
                'The selector is every "' . $type
                . '" and no reference table was given to find them with.'
            );
        }

        if (!$references->knows($type)) {
            throw new RuleException(
                'The selector is every "' . $type . '", which this language does not have.'
            );
        }

        return $references->nodes($model, $type);
    }

    /**
     * One step into a containment, over every node reached so far.
     *
     * A containment holds one thing or many, and a stored model spells the
     * second as a group of numbered keys rather than as a list - so both are
     * flattened here and a path does not have to say which it expected. A
     * containment nobody filled in yields nothing, which is not an error: a
     * project with no entities is a project somebody has just started.
     *
     * @param  object[]  $nodes
     *
     * @return object[]
     *
     * @since  0.8.0
     */
    private static function descend(array $nodes, string $name): array
    {
        $next = [];

        foreach ($nodes as $node) {
            $value = $node->{$name} ?? null;

            if (\is_object($value)) {
                // Nothing in it is nothing to select, whichever kind it is: an
                // empty group has no rows, and a contained node nobody has
                // filled in has no fields for a rule to read.
                if (get_object_vars($value) === []) {
                    continue;
                }

                // Either one contained thing or a group of them, and the stored
                // form says which by how it keys them: a repeating group is
                // `datamodel0`, `datamodel1` - the containment's own name and a
                // number. A single contained node is keyed by its own fields.
                //
                // Guessing instead - "are all its values objects?" - gets
                // `extensions` wrong, because the one thing it contains is an
                // object too. That read the component as a group of one and
                // then looked for the component inside itself.
                $rows = [];

                foreach (get_object_vars($value) as $key => $row) {
                    if (\is_object($row) && preg_match('/^' . preg_quote($name, '/') . '\d+$/', (string) $key) === 1) {
                        $rows[] = $row;
                    }
                }

                $next = array_merge($next, $rows === [] ? [$value] : $rows);

                continue;
            }

            if (\is_array($value)) {
                $next = array_merge($next, array_values(array_filter($value, '\is_object')));
            }
        }

        return $next;
    }

    /**
     * One step along a reference, over every node reached so far.
     *
     * @param  object[]        $nodes
     * @param  array<string, string>  $step
     *
     * @return object[]
     *
     * @throws RuleException  When the table does not carry the type being followed.
     *
     * @since  0.8.0
     */
    private function follow(array $nodes, array $step, object $model, ?ReferenceIndex $references): array
    {
        if ($references === null) {
            throw new RuleException(
                'The selector follows a reference to "' . $step['to']
                . '" and no reference table was given to follow it with.'
            );
        }

        if (!$references->knows($step['to'])) {
            throw new RuleException(
                'The selector follows a reference to "' . $step['to']
                . '", which this language does not have.'
            );
        }

        $targets = $references->nodes($model, $step['to']);
        $next    = [];

        foreach ($nodes as $node) {
            $id = $node->{$step[self::FOLLOW]} ?? null;

            if (!is_scalar($id) || (string) $id === '') {
                continue;
            }

            // A reference to something that is not there is skipped rather than
            // fatal. The forms can produce one by deleting a page a section
            // still names, and the imperative generator indexed it straight out
            // and stopped with an undefined key that named nothing.
            if (isset($targets[(string) $id])) {
                $next[] = $targets[(string) $id];
            }
        }

        return $next;
    }
}
