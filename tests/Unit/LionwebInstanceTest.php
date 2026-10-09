<?php

declare(strict_types=1);

namespace Yepr\Gen\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Lionweb\Chunk;
use Yepr\Gen\Core\Lionweb\InstanceModel;
use Yepr\Gen\Core\Package\MetalanguagePackage;
use Yepr\Gen\Core\Package\PackageManifest;

/**
 * Reading a LionWeb model into the shape a project is stored in.
 *
 * The counterpart of `LionwebLanguageTest`, one level down: that one turns a
 * chunk holding a *language* into a metalanguage, this one turns a chunk
 * holding a *model written in* a language into the data a form edits.
 *
 * What is asserted is what the form on the other side needs to be right about:
 * that a key becomes the name the form stores under, that a list becomes
 * numbered groups and a single one does not, and that anything the language
 * cannot account for is reported rather than dropped. Each of those is a thing
 * somebody would otherwise get wrong once per importer.
 *
 * @since  0.16.0
 */
final class LionwebInstanceTest extends TestCase
{
    private const LANGUAGE = 'demo';

    /**
     * A node, as a chunk serialises one.
     *
     * @param  array<string, string|null>  $properties
     * @param  array<string, list<string>>  $containments
     * @param  array<string, list<string>>  $references
     *
     * @return array<string, mixed>
     */
    private function node(
        string $id,
        string $classifier,
        array $properties = [],
        array $containments = [],
        array $references = [],
        ?string $parent = null
    ): array {
        $meta = static fn (string $key): array => [
            'language' => self::LANGUAGE, 'version' => '1.0', 'key' => $key,
        ];

        $node = [
            'id'           => $id,
            'classifier'   => $meta($classifier),
            'properties'   => [],
            'containments' => [],
            'references'   => [],
            'annotations'  => [],
            'parent'       => $parent,
        ];

        foreach ($properties as $key => $value) {
            $node['properties'][] = ['property' => $meta($key), 'value' => $value];
        }

        foreach ($containments as $key => $children) {
            $node['containments'][] = ['containment' => $meta($key), 'children' => $children];
        }

        foreach ($references as $key => $targets) {
            $node['references'][] = [
                'reference' => $meta($key),
                'targets'   => array_map(
                    static fn (string $t): array => ['resolveInfo' => null, 'reference' => $t],
                    $targets
                ),
            ];
        }

        return $node;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function chunk(array $nodes): Chunk
    {
        return Chunk::fromArray([
            'serializationFormatVersion' => '2024.1',
            'languages'                  => [['key' => self::LANGUAGE, 'version' => '1.0']],
            'nodes'                      => $nodes,
        ]);
    }

    /**
     * A language naming two concepts and what they hold.
     *
     * @param  array<string, list<array{key: string, name: string, multiple?: bool}>>  $concepts
     */
    private function language(array $concepts, int $format = MetalanguagePackage::FORMAT): PackageManifest
    {
        $rows = [];

        foreach ($concepts as $key => $features) {
            $rows[] = ['key' => $key, 'name' => ucfirst($key), 'features' => $features];
        }

        return PackageManifest::fromJson((string) json_encode([
            'format'   => $format,
            'name'     => 'Demo',
            'version'  => '1.0',
            'root'     => 'book',
            'concepts' => $rows,
        ]));
    }

    /**
     * A key becomes the name the form stores under.
     *
     * This is the whole reason the manifest is an argument. A chunk says
     * `book-k-title` because that is what survives being moved between tools;
     * a form stores `title` because that is what a person reads.
     */
    public function testAFeatureKeyBecomesTheNameTheFormStoresUnder(): void
    {
        $model = InstanceModel::read(
            $this->chunk([$this->node('b1', 'book', ['book-k-title' => 'Dune'])]),
            $this->language(['book' => [['key' => 'book-k-title', 'name' => 'title']]])
        );

        $stored = $model->toStoredModel();

        $this->assertSame('Dune', $stored['title']);
        $this->assertSame('book', $stored[InstanceModel::MARKER]);
        $this->assertSame([], $model->diagnostics());
    }

    /**
     * A list becomes numbered groups; a single one does not.
     *
     * The difference the manifest's `multiple` exists for. Both of these hold
     * exactly one child, so nothing but the language can tell them apart - and
     * a form reading the wrong shape finds no data at all.
     */
    public function testAListBecomesNumberedGroupsAndASingleOneDoesNot(): void
    {
        $nodes = [
            $this->node('b1', 'book', [], ['book-k-chapter' => ['c1'], 'book-k-cover' => ['v1']]),
            $this->node('c1', 'chapter', ['chapter-k-heading' => 'One'], [], [], 'b1'),
            $this->node('v1', 'cover', ['cover-k-colour' => 'red'], [], [], 'b1'),
        ];

        $model = InstanceModel::read($this->chunk($nodes), $this->language([
            'book' => [
                ['key' => 'book-k-chapter', 'name' => 'chapter', 'multiple' => true],
                ['key' => 'book-k-cover', 'name' => 'cover', 'multiple' => false],
            ],
            'chapter' => [['key' => 'chapter-k-heading', 'name' => 'heading']],
            'cover'   => [['key' => 'cover-k-colour', 'name' => 'colour']],
        ]));

        $stored = $model->toStoredModel();

        $this->assertSame(['chapter0'], array_keys($stored['chapter']));
        $this->assertSame('One', $stored['chapter']['chapter0']['heading']);

        // Not numbered, and not wrapped: the group is the row.
        $this->assertSame('red', $stored['cover']['colour']);
        $this->assertSame([], $model->diagnostics());
    }

    /**
     * Several children settle the question on their own.
     *
     * A package from before format 4 says nothing about multiplicity, and two
     * children cannot be one value whatever the language meant - so that case
     * needs no warning, only the obvious answer.
     */
    public function testSeveralChildrenSettleAnUnstatedMultiplicity(): void
    {
        $nodes = [
            $this->node('b1', 'book', [], ['book-k-chapter' => ['c1', 'c2']]),
            $this->node('c1', 'chapter', ['chapter-k-heading' => 'One'], [], [], 'b1'),
            $this->node('c2', 'chapter', ['chapter-k-heading' => 'Two'], [], [], 'b1'),
        ];

        $model = InstanceModel::read($this->chunk($nodes), $this->language([
            'book'    => [['key' => 'book-k-chapter', 'name' => 'chapter']],
            'chapter' => [['key' => 'chapter-k-heading', 'name' => 'heading']],
        ], 3));

        $stored = $model->toStoredModel();

        $this->assertSame(['chapter0', 'chapter1'], array_keys($stored['chapter']));
        $this->assertSame([], $model->diagnostics());
    }

    /**
     * One child and an unstated multiplicity is the ambiguous case, and says so.
     *
     * Either shape is defensible and they are not interchangeable, so the read
     * continues with the one every language so far has meant - and records that
     * it had to choose.
     */
    public function testOneChildAndNoStatedMultiplicityIsReported(): void
    {
        $nodes = [
            $this->node('b1', 'book', [], ['book-k-chapter' => ['c1']]),
            $this->node('c1', 'chapter', ['chapter-k-heading' => 'One'], [], [], 'b1'),
        ];

        $model = InstanceModel::read($this->chunk($nodes), $this->language([
            'book'    => [['key' => 'book-k-chapter', 'name' => 'chapter']],
            'chapter' => [['key' => 'chapter-k-heading', 'name' => 'heading']],
        ], 3));

        $stored = $model->toStoredModel();

        $this->assertSame(['chapter0'], array_keys($stored['chapter']));
        $this->assertSame(
            ['MULTIPLICITY_UNKNOWN'],
            array_column($model->diagnostics(), 'code')
        );
    }

    /**
     * A reference stores what it points at.
     */
    public function testAReferenceStoresWhatItPointsAt(): void
    {
        $nodes = [
            $this->node('b1', 'book', [], [], ['book-k-author' => ['a1']]),
            $this->node('a1', 'author', ['author-k-name' => 'Herbert']),
        ];

        $model = InstanceModel::read($this->chunk($nodes), $this->language([
            'book'   => [['key' => 'book-k-author', 'name' => 'author', 'multiple' => false]],
            'author' => [['key' => 'author-k-name', 'name' => 'name']],
        ]));

        // Two roots here - the author is referenced, not contained - so the
        // language's own root concept picks which one is read.
        $stored = $model->toStoredModel('b1');

        $this->assertSame('a1', $stored['author']);
    }

    /**
     * A target outside the chunk is still a target.
     *
     * LionWeb lets one carry a null `reference` and a `resolveInfo` instead,
     * which is what pointing into a partition nobody sent looks like. JCB's
     * Hello World is all of these - its fields point at fieldtypes kept in
     * another repository - and a reader taking only the node id gives every
     * one of those fields no type at all.
     */
    public function testATargetOutsideTheChunkIsReadFromItsResolveInfo(): void
    {
        $node = $this->node('b1', 'book');

        $node['references'][] = [
            'reference' => ['language' => self::LANGUAGE, 'version' => '1.0', 'key' => 'book-k-author'],
            'targets'   => [['resolveInfo' => 'author-guid-9f2a', 'reference' => null]],
        ];

        $model = InstanceModel::read($this->chunk([$node]), $this->language([
            'book' => [['key' => 'book-k-author', 'name' => 'author', 'multiple' => false]],
        ]));

        $stored = $model->toStoredModel();

        $this->assertSame('author-guid-9f2a', $stored['author']);
        $this->assertSame([], $model->diagnostics());
    }

    /**
     * And one carrying neither is reported.
     */
    public function testATargetNamingNothingIsReported(): void
    {
        $node = $this->node('b1', 'book');

        $node['references'][] = [
            'reference' => ['language' => self::LANGUAGE, 'version' => '1.0', 'key' => 'book-k-author'],
            'targets'   => [['resolveInfo' => null, 'reference' => null]],
        ];

        $model = InstanceModel::read($this->chunk([$node]), $this->language([
            'book' => [['key' => 'book-k-author', 'name' => 'author', 'multiple' => false]],
        ]));

        $stored = $model->toStoredModel();

        $this->assertSame('', $stored['author']);
        $this->assertSame(['UNRESOLVABLE_TARGET'], array_column($model->diagnostics(), 'code'));
    }

    /**
     * What the language cannot account for is reported, not dropped quietly.
     */
    public function testAKeyTheLanguageDoesNotDefineIsReported(): void
    {
        $model = InstanceModel::read(
            $this->chunk([$this->node('b1', 'book', [
                'book-k-title' => 'Dune',
                'book-k-isbn'  => '9780441013593',
            ])]),
            $this->language(['book' => [['key' => 'book-k-title', 'name' => 'title']]])
        );

        $stored = $model->toStoredModel();

        $this->assertSame(['title', InstanceModel::MARKER], array_keys($stored));
        $this->assertSame(['UNKNOWN_FEATURE'], array_column($model->diagnostics(), 'code'));
    }

    /**
     * A child the chunk does not hold is reported and skipped.
     *
     * That is what a partition somebody did not send looks like from in here,
     * and it is a gap in the model rather than a reason to refuse all of it.
     */
    public function testAChildTheChunkDoesNotHoldIsReported(): void
    {
        $nodes = [
            $this->node('b1', 'book', [], ['book-k-chapter' => ['c1', 'missing']]),
            $this->node('c1', 'chapter', ['chapter-k-heading' => 'One'], [], [], 'b1'),
        ];

        $model = InstanceModel::read($this->chunk($nodes), $this->language([
            'book'    => [['key' => 'book-k-chapter', 'name' => 'chapter', 'multiple' => true]],
            'chapter' => [['key' => 'chapter-k-heading', 'name' => 'heading']],
        ]));

        $stored = $model->toStoredModel();

        $this->assertSame(['chapter0'], array_keys($stored['chapter']));
        $this->assertSame(['MISSING_CHILD'], array_column($model->diagnostics(), 'code'));
    }

    /**
     * A chunk with nothing uncontained in it has no model to read.
     */
    public function testAChunkWithNoRootSaysSo(): void
    {
        $nodes = [
            $this->node('b1', 'book', [], ['book-k-chapter' => ['b1']], [], 'b1'),
        ];

        $model  = InstanceModel::read($this->chunk($nodes), $this->language(['book' => []]));
        $stored = $model->toStoredModel();

        $this->assertSame([], $stored);
        $this->assertSame(['NO_ROOT'], array_column($model->diagnostics(), 'code'));
    }
}
