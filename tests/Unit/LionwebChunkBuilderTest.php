<?php

declare(strict_types=1);

namespace Yepr\Gen\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Lionweb\Chunk;
use Yepr\Gen\Core\Lionweb\ChunkBuilder;
use Yepr\Gen\Core\Lionweb\MetaPointer;

/**
 * Writing a LionWeb chunk.
 *
 * The other half of the reader, and the thing that lets a model *leave* this
 * family rather than only arrive in it.
 *
 * The test that carries the most weight is the last one: what the builder
 * writes, the reader reads, and it reads the same things back. Either half
 * checked only against itself would agree with its own misunderstanding of the
 * format - which is the usual way a serialiser ends up almost right.
 *
 * @since  0.8.0
 */
final class LionwebChunkBuilderTest extends TestCase
{
    private MetaPointer $m3;

    protected function setUp(): void
    {
        $this->m3 = new MetaPointer('demo', '1.0', 'Concept');
    }

    private function builder(): ChunkBuilder
    {
        $builder = new ChunkBuilder();

        $root = $builder->node('root', $this->m3->withKey('Partition'));
        $root->property($this->m3->withKey('name'), 'Everything')
            ->child($this->m3->withKey('holds'), 'thing-1')
            ->child($this->m3->withKey('holds'), 'thing-2');

        $builder->node('thing-1', $this->m3)
            ->property($this->m3->withKey('name'), 'First')
            ->property($this->m3->withKey('count'), 3)
            ->property($this->m3->withKey('ready'), true)
            ->reference($this->m3->withKey('mate'), 'thing-2', 'Second')
            ->parent('root');

        $builder->node('thing-2', $this->m3)
            ->property($this->m3->withKey('name'), 'Second')
            ->property($this->m3->withKey('ready'), false)
            ->parent('root');

        return $builder;
    }

    // -- the shape of what comes out -----------------------------------------

    public function testAChunkCarriesItsFormatAndTheLanguagesItPointsInto(): void
    {
        $chunk = $this->builder()->toArray();

        $this->assertSame('2024.1', $chunk['serializationFormatVersion']);
        $this->assertSame([['key' => 'demo', 'version' => '1.0']], $chunk['languages']);
        $this->assertCount(3, $chunk['nodes']);
    }

    /**
     * A language is declared by using it, not by remembering to say so.
     */
    public function testUsingAClassifierDeclaresItsLanguage(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('n', new MetaPointer('other', '2.0', 'Thing'));

        $this->assertSame([['key' => 'other', 'version' => '2.0']], $builder->toArray()['languages']);
    }

    /**
     * Two versions of one language is a chunk no reader can act on, so it is
     * refused where it happens rather than written and puzzled over.
     */
    public function testOneLanguageCannotBeAtTwoVersions(): void
    {
        $builder = new ChunkBuilder();

        $builder->usesLanguage('demo', '1.0');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot also declare it at 2\.0/');

        $builder->usesLanguage('demo', '2.0');
    }

    public function testTwoNodesCannotShareAnId(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('n', $this->m3);

        $this->expectException(\InvalidArgumentException::class);

        $builder->node('n', $this->m3);
    }

    /**
     * Every node carries all five lists even when they are empty, because the
     * format says so and a reader written against the format may rely on it.
     */
    public function testANodeWithNothingInItIsStillAWholeNode(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('bare', $this->m3);

        $node = $builder->toArray()['nodes'][0];

        $this->assertSame(
            ['id', 'classifier', 'properties', 'containments', 'references', 'annotations', 'parent'],
            array_keys($node)
        );
        $this->assertSame([], $node['properties']);
        $this->assertNull($node['parent']);
    }

    // -- properties ----------------------------------------------------------

    /**
     * LionWeb writes a boolean as the string "true" or "false".
     */
    public function testABooleanIsWrittenTheWayLionwebWritesOne(): void
    {
        $chunk = $this->builder()->toArray();
        $first = $chunk['nodes'][1];

        $values = array_column($first['properties'], 'value', null);

        $this->assertContains('true', $values);
        $this->assertContains('3', $values, 'and a number is a string too');
    }

    /**
     * An unset optional property is omitted, not written as null.
     *
     * A reader that cannot tell absent from null breaks on the difference -
     * lionweb-python's enumerations do - so there is no way to ask for one.
     */
    public function testSettingAPropertyToNullRemovesIt(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('n', $this->m3)
            ->property($this->m3->withKey('name'), 'something')
            ->property($this->m3->withKey('name'), null);

        $this->assertSame([], $builder->toArray()['nodes'][0]['properties']);
    }

    /**
     * A feature appears once. A reader takes the first entry with a given key
     * and would silently drop a second.
     */
    public function testASecondChildJoinsTheContainmentItIsAlreadyIn(): void
    {
        $chunk = $this->builder()->toArray();
        $root  = $chunk['nodes'][0];

        $this->assertCount(1, $root['containments']);
        $this->assertSame(['thing-1', 'thing-2'], $root['containments'][0]['children']);
    }

    public function testAReferenceKeepsWhatTheTargetWasCalled(): void
    {
        $chunk = $this->builder()->toArray();

        $this->assertSame(
            [['resolveInfo' => 'Second', 'reference' => 'thing-2']],
            $chunk['nodes'][1]['references'][0]['targets']
        );
    }

    // -- what a reader would refuse ------------------------------------------

    public function testACleanChunkHasNothingWrongWithIt(): void
    {
        $this->assertSame([], $this->builder()->errors());
        $this->assertSame([], $this->builder()->danglingReferences());
        $this->assertSame(['root'], $this->builder()->roots());
    }

    public function testAnIdAReaderWillNotAcceptIsReported(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('[[[COMPANY]]]', $this->m3);

        $this->assertStringContainsString('not one a LionWeb chunk will accept', $builder->errors()[0]);
    }

    public function testAParentThatIsNotHereIsReported(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('orphan', $this->m3)->parent('nobody');

        $this->assertStringContainsString('which this chunk does not hold', $builder->errors()[0]);
    }

    public function testAChunkWithNoRootIsReported(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('a', $this->m3)->parent('b');
        $builder->node('b', $this->m3)->parent('a');

        $this->assertContains(
            'Every node has a parent, so this chunk has no root to read from.',
            $builder->errors()
        );
    }

    /**
     * A target that is not here is reported but not refused: that is what a
     * reference into a partition somebody did not send looks like.
     */
    public function testATargetThatIsNotHereIsReportedButAllowed(): void
    {
        $builder = new ChunkBuilder();

        $builder->node('n', $this->m3)
            ->reference($this->m3->withKey('mate'), 'elsewhere', 'Elsewhere');

        $this->assertSame(['elsewhere'], $builder->danglingReferences());
        $this->assertSame([], $builder->errors());
    }

    // -- the two halves against each other -----------------------------------

    /**
     * What the builder writes, the reader reads - and reads the same.
     *
     * Either half checked only against itself would agree with its own
     * misunderstanding of the format, which is the usual way a serialiser ends
     * up almost right.
     */
    public function testWhatIsWrittenIsWhatIsRead(): void
    {
        $chunk = Chunk::fromJson($this->builder()->toJson());

        $this->assertSame('2024.1', $chunk->formatVersion());
        $this->assertSame('1.0', $chunk->versionOf('demo'));
        $this->assertSame(['root'], $chunk->roots());

        $this->assertSame('Everything', $chunk->property('root', 'name'));
        $this->assertSame(['thing-1', 'thing-2'], $chunk->children('root', 'holds'));

        $this->assertSame('Concept', $chunk->classifierKey('thing-1'));
        $this->assertSame(['thing-1', 'thing-2'], $chunk->idsOf('Concept'));

        $this->assertTrue($chunk->flag('thing-1', 'ready'));
        $this->assertFalse($chunk->flag('thing-2', 'ready'));
        $this->assertTrue(
            $chunk->flag('thing-2', 'never-set', true),
            'an absent flag is the default, not false'
        );

        $this->assertSame('thing-2', $chunk->target('thing-1', 'mate'));
        $this->assertNull($chunk->property('thing-2', 'count'), 'and what was never written is absent');
    }

    /**
     * A chunk that came in can go back out unchanged, which is the other way
     * round and the one that catches a writer normalising something quietly.
     */
    public function testAChunkReadAndWrittenAgainIsTheSameChunk(): void
    {
        $original = $this->builder()->toArray();

        $this->assertSame($original, Chunk::fromArray($original)->toArray());
    }
}
