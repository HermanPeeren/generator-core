<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Reference\ReferenceIndex;

/**
 * Reading a table, which is all this class does now.
 *
 * It arrived here from Exten-gen carrying two tables as constants, one per
 * modelled language. Those went back to the components that own them - a table
 * is data somebody writes or a generator produces - and what is shared is the
 * mechanism, because three components ask the same question of three different
 * models.
 *
 * So the cases are about the mechanism rather than about any language: walking
 * a path, applying conditions, and the several ways a stored form is not the
 * shape a reader would have chosen.
 */
final class ReferenceIndexTest extends TestCase
{
    /**
     * A table with one plain type and one that shares a row with another.
     *
     * @return array<string, array<string, mixed>>
     */
    private function table(): array
    {
        return [
            'Entity' => [
                'path'    => ['datamodel'],
                'idKey'   => 'entity_id',
                'nameKey' => 'entity_name',
                'client'  => ['selector' => 'entityName', 'nameToken' => 'entity_name', 'idToken' => 'entity_id'],
            ],
            'Field' => [
                'path'      => ['datamodel', 'field'],
                'idKey'     => 'field_id',
                'nameKey'   => 'field_name',
                'parentKey' => 'entity_id',
                'client'    => ['selector' => 'fieldName', 'nameToken' => 'field_name', 'idToken' => 'field_id'],
            ],
            'Concept' => [
                'path'    => ['languageEntities'],
                'idKey'   => 'key',
                'nameKey' => 'name',
                'when'    => [['path' => 'classifier.classifier_type', 'value' => 'Concept']],
                'client'  => ['selector' => 'conceptName', 'nameToken' => 'name', 'idToken' => 'key'],
            ],
        ];
    }

    private function model(): object
    {
        // Keyed groups rather than lists, because that is what Joomla's subform
        // field hands back: `datamodel0`, `datamodel1`, and so on.
        return (object) [
            'datamodel' => (object) [
                'datamodel0' => (object) [
                    'entity_id'   => 'e1',
                    'entity_name' => 'Balloon',
                    'field'       => (object) [
                        'field0' => (object) ['field_id' => 'f1', 'field_name' => 'colour', 'entity_id' => 'e1'],
                        'field1' => (object) ['field_id' => 'f2', 'field_name' => 'size', 'entity_id' => 'e1'],
                    ],
                ],
                'datamodel1' => (object) [
                    'entity_id'   => 'e2',
                    'entity_name' => 'Flight',
                    'field'       => (object) [
                        'field0' => (object) ['field_id' => 'f3', 'field_name' => 'departs', 'entity_id' => 'e2'],
                    ],
                ],
            ],
            'languageEntities' => (object) [
                'languageEntities0' => (object) [
                    'key'        => 'c-entity',
                    'name'       => 'Entity',
                    'classifier' => (object) ['classifier_type' => 'Concept'],
                ],
                'languageEntities1' => (object) [
                    'key'        => 'ci-named',
                    'name'       => 'INamed',
                    'classifier' => (object) ['classifier_type' => 'ConceptInterface'],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, string>> $entries
     *
     * @return string[]
     */
    private function names(array $entries): array
    {
        return array_map(static fn (array $entry): string => $entry['name'], $entries);
    }

    public function testAPathOfOneStepIsEveryRowOfThatGroup(): void
    {
        $index = ReferenceIndex::fromTable($this->table())->index($this->model());

        $this->assertSame(['Balloon', 'Flight'], $this->names($index['Entity']));
    }

    /**
     * A second step is every child of every one of those, with no step in
     * between for "each of the ones we just found".
     */
    public function testAPathOfTwoStepsFlattensEveryChildOfEveryParent(): void
    {
        $index = ReferenceIndex::fromTable($this->table())->index($this->model());

        $this->assertSame(['colour', 'size', 'departs'], $this->names($index['Field']));
    }

    /**
     * A child carries its parent, so a scoped dropdown can filter on it.
     */
    public function testAChildTypeCarriesWhichParentItBelongsTo(): void
    {
        $index = ReferenceIndex::fromTable($this->table())->index($this->model());

        $this->assertSame(['e1', 'e1', 'e2'], array_column($index['Field'], 'parent'));
        $this->assertArrayNotHasKey('parent', $index['Entity'][0]);
    }

    /**
     * A condition reads a dotted path, not another step.
     *
     * A non-repeating subform is stored as the group itself rather than as one
     * keyed row - `SubformField::filter()` branches on `multiple` - so
     * `classifier.classifier_type` is a read and never a walk.
     */
    public function testAConditionKeepsOnlyTheRowsThatSayTheyAreThatType(): void
    {
        $index = ReferenceIndex::fromTable($this->table())->index($this->model());

        $this->assertSame(['Entity'], $this->names($index['Concept']));
        $this->assertNotContains('INamed', $this->names($index['Concept']));
    }

    /**
     * An object with no id yet cannot be pointed at.
     *
     * Not a defect: the client assigns one the moment somebody names it, and
     * until then there is nothing for a reference to hold. Real stored forms
     * are full of rows somebody added and walked away from.
     */
    public function testARowWithNoIdIsLeftOutRatherThanOfferedBlank(): void
    {
        $model = $this->model();

        $model->datamodel->datamodel2 = (object) ['entity_id' => '', 'entity_name' => 'Half typed'];

        $index = ReferenceIndex::fromTable($this->table())->index($model);

        $this->assertSame(['Balloon', 'Flight'], $this->names($index['Entity']));
    }

    /**
     * A model that was never saved indexes to empty lists, not to nothing.
     *
     * The difference matters to the script: an absent type is a form and a
     * server that disagree, and it leaves the dropdown alone. An empty list is
     * an answer.
     */
    public function testAModelThatWasNeverSavedIndexesToEmptyLists(): void
    {
        $index = ReferenceIndex::fromTable($this->table())->index(null);

        $this->assertSame(['Entity', 'Field', 'Concept'], array_keys($index));

        foreach ($index as $type => $entries) {
            $this->assertSame([], $entries, $type . ' should be empty.');
        }
    }

    /**
     * A path that runs into something that is not there stops, quietly.
     *
     * A model is edited by people and half of one is the normal state; a
     * missing group is not an error, it is a group nobody has filled in.
     */
    public function testAPathThroughSomethingMissingYieldsNothing(): void
    {
        $index = ReferenceIndex::fromTable($this->table())->index((object) ['datamodel' => 'not a group']);

        $this->assertSame([], $index['Entity']);
        $this->assertSame([], $index['Field']);
    }

    public function testTheClientHalfIsHandedOverAsItWasWritten(): void
    {
        $types = ReferenceIndex::fromTable($this->table())->clientTypes();

        $this->assertSame(['Entity', 'Field', 'Concept'], array_keys($types));
        $this->assertSame('entityName', $types['Entity']['selector']);
    }

    /**
     * The payload is both halves at once, which is what a page carries.
     */
    public function testThePayloadIsTheIndexAndTheTypesTogether(): void
    {
        $index   = ReferenceIndex::fromTable($this->table());
        $payload = $index->payload($this->model());

        $this->assertSame(['index', 'types'], array_keys($payload));
        $this->assertSame($index->index($this->model()), $payload['index']);
        $this->assertSame($index->clientTypes(), $payload['types']);
    }
}
