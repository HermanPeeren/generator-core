<?php

/**
 * @package     Yepr Gen
 * @subpackage  Core
 *
 * @copyright   Copyright (C) Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Reference;

use Yepr\Gen\Core\Rule\PathReader;

/**
 * Everything in a stored model that something else can point at.
 *
 * A reference in a model is an identifier. A person choosing one needs a name,
 * so every reference dropdown needs the same thing: for each kind of object,
 * the id and name of each one in the model. Computing it once and putting it in
 * the page is what lets the client answer the same question about objects that
 * are not in the database yet - which is the whole of "you should not have to
 * save before you can refer to something".
 *
 * **It carries no table of its own, and that is the point.** This arrived in
 * Exten-gen with two of them written out as constants - one for ER1, one for
 * LionCore M3 - and said in its own docblock that they were a map rather than a
 * method per type *because Meta-gen generates this from a concept model*. Meta-gen
 * does now. So a table is data a component owns or a generator writes, and what
 * is shared is the mechanism that reads one: three components edit models with
 * reference dropdowns, and a mechanism written three times is a contract that
 * drifts.
 *
 * **The shape of a table.** One entry per object type, each saying where the
 * server looks and how the browser looks:
 *
 * ```
 * 'Concept' => [
 *     'path'      => ['languageEntities'],   repeating groups to walk
 *     'idKey'     => 'key',
 *     'nameKey'   => 'name',
 *     'parentKey' => 'entity_id',            optional, for a scoped child type
 *     'when'      => [['path' => 'classifier.classifier_type', 'value' => 'Concept']],
 *     'client'    => [
 *         'selector'  => 'languageEntityName',
 *         'nameToken' => 'name',
 *         'idToken'   => 'key',
 *         'when'      => [['token' => 'classifier__classifier_type', 'value' => 'Concept']],
 *     ],
 * ]
 * ```
 *
 * A condition is written twice, once as a path through stored JSON and once as
 * a token in an element id, because neither spelling can be derived from the
 * other without knowing how Joomla builds element ids. Knowing that is the
 * consumer's business; applying it is this class's.
 *
 * One flat list per object type, each entry `{id, name}`, and a child type also
 * carries `parent`. Uniform on purpose: a dropdown scoped to a parent - the
 * fields of one entity - filters, and a dropdown that is not, does not. The
 * alternative, nesting children under their parent, needs the reader to know
 * which types are nested before it can read the structure.
 *
 * @since  0.4.0
 */
final class ReferenceIndex
{
    /**
     * @param  array<string, array<string, mixed>>  $types  What this kind of model offers.
     *
     * @since  0.4.0
     */
    private function __construct(private readonly array $types)
    {
    }

    /**
     * An index over one table.
     *
     * Nothing is validated here. A table is written by a component or produced
     * by a generator, and in both cases a missing key is a defect where it was
     * produced - somewhere with a test that can say which entry and why, rather
     * than here, where all that is left is a dropdown with nothing in it.
     *
     * @param  array<string, array<string, mixed>>  $types
     *
     * @since  0.4.0
     */
    public static function fromTable(array $types): self
    {
        return new self($types);
    }

    /**
     * Everything one stored model offers, by type.
     *
     * @param  ?object  $model  The model as stored, or null for one that has
     *                          never been saved.
     *
     * @return array<string, list<array{id: string, name: string, parent?: string}>>
     *
     * @since  0.4.0
     */
    public function index(?object $model): array
    {
        $index = [];

        foreach ($this->types as $type => $definition) {
            $index[$type] = [];

            foreach ($this->at($model, $definition['path']) as $node) {
                if (!$this->matches($node, $definition['when'] ?? [])) {
                    continue;
                }

                $id = $this->stringAt($node, $definition['idKey']);

                // An object with no id yet cannot be pointed at. That is not a
                // defect: the client assigns one the moment somebody names it.
                if ($id === '') {
                    continue;
                }

                $entry = ['id' => $id, 'name' => $this->stringAt($node, $definition['nameKey'])];

                if (isset($definition['parentKey'])) {
                    $entry['parent'] = $this->stringAt($node, $definition['parentKey']);
                }

                $index[$type][] = $entry;
            }
        }

        return $index;
    }

    /**
     * How the client finds each type's rows in the form.
     *
     * The same table, read from the other end. The browser cannot be told
     * "look for entities": it needs the class on the name input, how that
     * input's element id relates to the hidden id beside it, and - for a type
     * that is one kind of a shared row - which other input in the row decides
     * whether it counts.
     *
     * @return array<string, array<string, mixed>>
     *
     * @since  0.4.0
     */
    public function clientTypes(): array
    {
        return array_map(static fn (array $definition): array => $definition['client'], $this->types);
    }

    /**
     * The whole payload a page carries, which is both halves at once.
     *
     * Every consumer needs exactly this and used to assemble it itself. One
     * method means one shape, and the script reading it has one thing to expect.
     *
     * @return array{index: array<string, list<array<string, string>>>, types: array<string, array<string, mixed>>}
     *
     * @since  0.4.0
     */
    public function payload(?object $model): array
    {
        return ['index' => $this->index($model), 'types' => $this->clientTypes()];
    }

    /**
     * Whether a row is of the type being indexed.
     *
     * @param  array<int, array<string, string>>  $conditions
     *
     * @since  0.4.0
     */
    private function matches(object $node, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if ((string) PathReader::read($node, $condition['path']) !== $condition['value']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every node at a path through the model.
     *
     * Each step takes the object at that key and yields its values, because a
     * repeating group is stored as an object keyed `datamodel0`, `datamodel1`
     * and so on rather than as an array - that is what Joomla's subform field
     * hands back. So `['datamodel']` is every entity, and `['datamodel',
     * 'field']` is every field of every entity, with no step needed for
     * "each of the ones we just found".
     *
     * A non-repeating subform is stored as the group itself rather than as one
     * keyed row - `SubformField::filter()` branches on `multiple` - which is
     * why a condition like `classifier.classifier_type` is a plain dotted read
     * and not another step here.
     *
     * @param  string[]  $path
     *
     * @return list<object>
     *
     * @since  0.4.0
     */
    private function at(?object $node, array $path): array
    {
        if ($node === null) {
            return [];
        }

        $current = [$node];

        foreach ($path as $step) {
            $next = [];

            foreach ($current as $one) {
                if (!property_exists($one, $step) || !\is_object($one->{$step})) {
                    continue;
                }

                $next = array_merge($next, array_values((array) $one->{$step}));
            }

            $current = array_values(array_filter($next, \is_object(...)));
        }

        return $current;
    }

    /**
     * @since  0.4.0
     */
    private function stringAt(object $node, string $key): string
    {
        return property_exists($node, $key) && is_scalar($node->{$key}) ? (string) $node->{$key} : '';
    }
}
