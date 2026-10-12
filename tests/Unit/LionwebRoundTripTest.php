<?php

declare(strict_types=1);

namespace Yepr\Gen\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Lionweb\Chunk;
use Yepr\Gen\Core\Lionweb\InstanceChunk;
use Yepr\Gen\Core\Lionweb\InstanceModel;
use Yepr\Gen\Core\Package\MetalanguagePackage;
use Yepr\Gen\Core\Package\PackageManifest;

/**
 * A model out and back, and the things that only break on the way back.
 *
 * `LionwebInstanceTest` checks the reader and the writer has its own rules,
 * but neither can see whether the two agree. The useful property is that a
 * chunk read into a model and written out again is the same chunk, and it is
 * the only one that catches a reader and a writer sharing a misunderstanding -
 * which is how a serialiser normally ends up agreeing with itself and nothing
 * else. The same reason `LionwebChunkBuilderTest` checks the builder against
 * the reader rather than against its own output.
 *
 * @since  0.17.0
 */
final class LionwebRoundTripTest extends TestCase
{
    private const LANGUAGE = 'demo';
    private const VERSION  = '1.0';

    /**
     * @param  array<string, string|null>   $properties
     * @param  array<string, list<string>>  $containments
     * @param  list<array{reference: ?string, resolveInfo: ?string}>  $targets
     *
     * @return array<string, mixed>
     */
    private function node(
        string $id,
        string $classifier,
        array $properties = [],
        array $containments = [],
        ?string $referenceKey = null,
        array $targets = [],
        ?string $parent = null
    ): array {
        $meta = static fn (string $key): array => [
            'language' => self::LANGUAGE, 'version' => self::VERSION, 'key' => $key,
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

        if ($referenceKey !== null) {
            $node['references'][] = ['reference' => $meta($referenceKey), 'targets' => $targets];
        }

        return $node;
    }

    /**
     * A library with a book, a chapter, and an author pointed at from each.
     *
     * One of each thing that behaves differently on the way back: a property, a
     * containment holding several, a reference inside the chunk, and a
     * reference to something outside it.
     *
     * @return array<string, mixed>
     */
    private function chunk(): array
    {
        return [
            'serializationFormatVersion' => '2024.1',
            'languages'                  => [['key' => self::LANGUAGE, 'version' => self::VERSION]],
            'nodes'                      => [
                // Both children are listed under a containment as well as
                // naming their parent. A chunk where those two disagree is
                // malformed, and a node nothing contains is simply not part of
                // the model - which is what the first draft of this fixture
                // got wrong, and the round trip noticed.
                $this->node('lib-1', 'library', ['library-k-name' => 'Arrakis'], [
                    'library-k-book'   => ['book-1'],
                    'library-k-author' => ['auth-1'],
                ]),
                $this->node(
                    'book-1',
                    'book',
                    ['book-k-title' => 'Dune'],
                    ['book-k-chapter' => ['ch-1', 'ch-2']],
                    'book-k-author',
                    [
                        // Both halves, the way a LionWeb tool writes a
                        // reference to a named node in the same chunk.
                        ['resolveInfo' => 'Herbert', 'reference' => 'auth-1'],
                        ['resolveInfo' => 'someone-elsewhere', 'reference' => null],
                    ],
                    'lib-1'
                ),
                $this->node('ch-1', 'chapter', ['chapter-k-heading' => 'One'], [], null, [], 'book-1'),
                $this->node('ch-2', 'chapter', ['chapter-k-heading' => 'Two'], [], null, [], 'book-1'),
                $this->node('auth-1', 'author', ['author-k-name' => 'Herbert'], [], null, [], 'lib-1'),
            ],
        ];
    }

    private function language(): PackageManifest
    {
        $concept = static fn (string $key, array $features): array => [
            'key' => $key, 'name' => ucfirst($key), 'features' => $features,
        ];

        return PackageManifest::fromJson((string) json_encode([
            'format'   => MetalanguagePackage::FORMAT,
            'name'     => 'Demo',
            'version'  => self::VERSION,
            'root'     => 'library',
            'concepts' => [
                $concept('library', [
                    ['key' => 'library-k-name', 'name' => 'name'],
                    ['key' => 'library-k-book', 'name' => 'book', 'multiple' => true],
                    ['key' => 'library-k-author', 'name' => 'author', 'multiple' => true],
                ]),
                $concept('book', [
                    ['key' => 'book-k-title', 'name' => 'title'],
                    ['key' => 'book-k-chapter', 'name' => 'chapter', 'multiple' => true],
                    ['key' => 'book-k-author', 'name' => 'author', 'multiple' => true],
                ]),
                $concept('chapter', [['key' => 'chapter-k-heading', 'name' => 'heading']]),
                $concept('author', [
                    ['key' => 'author-k-name', 'name' => 'name'],
                    ['key' => 'author-k-guid', 'name' => 'guid'],
                ]),
            ],
        ]));
    }

    /**
     * Nodes by id, with the key order settled, so two chunks can be compared
     * without the order they happen to list things in getting in the way.
     *
     * @param  array<string, mixed>  $chunk
     *
     * @return array<string, array<string, mixed>>
     */
    private function comparable(array $chunk): array
    {
        $nodes = [];

        foreach ($chunk['nodes'] as $node) {
            ksort($node);

            foreach (['properties', 'containments', 'references'] as $bucket) {
                usort(
                    $node[$bucket],
                    static fn (array $a, array $b): int
                        => json_encode($a) <=> json_encode($b)
                );
            }

            $nodes[$node['id']] = $node;
        }

        ksort($nodes);

        return $nodes;
    }

    /**
     * A chunk read in and written out is the same chunk.
     *
     * Not byte equality - the order nodes are listed in is nobody's business -
     * but every node, with the same id, the same classifier, and the same
     * three buckets.
     */
    public function testAChunkSurvivesBeingReadAndWrittenAgain(): void
    {
        $original = $this->chunk();
        $language = $this->language();

        $reader = InstanceModel::read(Chunk::fromArray($original), $language);
        $model  = $reader->toStoredModel();

        $this->assertSame([], $reader->diagnostics());

        $writer  = InstanceChunk::of($language);
        $written = $writer->write($model);

        $this->assertSame([], $writer->diagnostics());

        $this->assertSame($this->comparable($original), $this->comparable($written));
    }

    /**
     * The language the metapointers name survives too.
     *
     * The model records it because the metalanguage cannot: JCB's language
     * calls itself `jcb` and the package built from it is called `JCB`, and a
     * chunk written with the latter is a chunk about a different language.
     */
    public function testTheLanguageOfTheMetapointersSurvives(): void
    {
        $language = $this->language();
        $model    = InstanceModel::read(Chunk::fromArray($this->chunk()), $language)->toStoredModel();

        $written = InstanceChunk::of($language)->write($model);

        $this->assertSame(
            [['key' => self::LANGUAGE, 'version' => self::VERSION]],
            $written['languages']
        );
        $this->assertSame(self::LANGUAGE, $written['nodes'][0]['classifier']['language']);
    }

    /**
     * Every reference target in a chunk, in order, as reference and hint.
     *
     * @param  array<string, mixed>  $chunk
     *
     * @return list<array{reference: mixed, resolveInfo: mixed}>
     */
    private function targets(array $chunk): array
    {
        $targets = [];

        foreach ($chunk['nodes'] as $node) {
            foreach ($node['references'] as $reference) {
                foreach ($reference['targets'] as $target) {
                    $targets[] = [
                        'reference'   => $target['reference'] ?? null,
                        'resolveInfo' => $target['resolveInfo'] ?? null,
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * The fixture with a different author, and the hint the book gives it.
     *
     * @param  array<string, string>  $properties
     *
     * @return array<string, mixed>
     */
    private function withAuthor(array $properties, ?string $hint): array
    {
        $chunk = $this->chunk();

        $chunk['nodes'][4] = $this->node('auth-1', 'author', $properties, [], null, [], 'lib-1');
        $chunk['nodes'][1]['references'][0]['targets'][0]['resolveInfo'] = $hint;

        return $chunk;
    }

    /**
     * A reference inside the chunk keeps its id *and* its hint; one outside
     * keeps the hint.
     *
     * Which side a target is on is the distinction the writer cannot make
     * until every node exists, and the one that decides whether a reference
     * still points anywhere. Until 0.19.1 a reference inside lost its hint on
     * the way out. LionWeb carries both, and a reader in another tool may
     * only understand the second.
     */
    public function testAReferenceKeepsWhichSideOfTheChunkItPointsTo(): void
    {
        $language = $this->language();
        $model    = InstanceModel::read(Chunk::fromArray($this->chunk()), $language)->toStoredModel();

        $written = InstanceChunk::of($language)->write($model);

        $this->assertSame(
            [
                ['reference' => 'auth-1', 'resolveInfo' => 'Herbert'],
                ['reference' => null, 'resolveInfo' => 'someone-elsewhere'],
            ],
            $this->targets($written)
        );
    }

    /**
     * A property holding the target's own id is the hint, ahead of its name.
     *
     * That is how JCB writes them: every node is identified by its `guid`,
     * the node id is that guid, and the hint on a reference to it is the guid
     * again - which is what lets a tool without this chunk find the node.
     */
    public function testAnIdentityHeldInAPropertyIsTheHint(): void
    {
        $original = $this->withAuthor(['author-k-name' => 'Herbert', 'author-k-guid' => 'auth-1'], 'auth-1');
        $language = $this->language();

        $reader = InstanceModel::read(Chunk::fromArray($original), $language);
        $model  = $reader->toStoredModel();

        $this->assertSame([], $reader->diagnostics());

        $written = InstanceChunk::of($language)->write($model);

        $this->assertSame(['reference' => 'auth-1', 'resolveInfo' => 'auth-1'], $this->targets($written)[0]);
        $this->assertSame($this->comparable($original), $this->comparable($written));
    }

    /**
     * A target with nothing to be found by keeps its id and no hint.
     */
    public function testATargetWithNoNameHasNoHint(): void
    {
        $original = $this->withAuthor([], null);
        $language = $this->language();

        $model   = InstanceModel::read(Chunk::fromArray($original), $language)->toStoredModel();
        $written = InstanceChunk::of($language)->write($model);

        $this->assertSame(['reference' => 'auth-1', 'resolveInfo' => null], $this->targets($written)[0]);
        $this->assertSame($this->comparable($original), $this->comparable($written));
    }

    /**
     * A hint the way back cannot work out again is said on the way in.
     *
     * The stored model keeps the id of a target in the chunk and nothing
     * else, so a hint following some rule of its own is lost. The reference
     * still resolves; the reader says the hint will not come back rather than
     * leaving it to a diff of two files.
     */
    public function testAHintThatWillNotComeBackIsReported(): void
    {
        $original = $this->withAuthor(['author-k-name' => 'Herbert'], 'F. Herbert');
        $language = $this->language();

        $reader = InstanceModel::read(Chunk::fromArray($original), $language);
        $model  = $reader->toStoredModel();

        $this->assertSame(['RESOLVE_INFO_NOT_KEPT'], array_column($reader->diagnostics(), 'code'));
        $this->assertSame('auth-1', $model['book']['book0']['author'][0]);

        $written = InstanceChunk::of($language)->write($model);

        $this->assertSame(['reference' => 'auth-1', 'resolveInfo' => 'Herbert'], $this->targets($written)[0]);
    }

    /**
     * JCB's Hello World comes back with every reference as it went in.
     *
     * 43 targets: 38 inside the chunk, with the target's guid as the hint,
     * and 5 outside it with nothing but. The chunk is a copy of JcbInOut's
     * `data/hello-world.instance.lionweb.json`. The manifest is the least the
     * chunk itself implies - a concept per classifier, a feature per key -
     * because what is under test is the references, not JCB's language.
     */
    public function testJcbHelloWorldKeepsEveryReference(): void
    {
        $json = file_get_contents(\dirname(__DIR__) . '/Fixtures/lionweb/jcb-hello-world.instance.lionweb.json');
        $this->assertIsString($json);

        $original = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($original);

        $language = $this->languageImpliedBy($original);
        $reader   = InstanceModel::read(Chunk::fromArray($original), $language);
        $model    = $reader->toStoredModel();

        $this->assertSame([], $reader->diagnostics());

        $writer  = InstanceChunk::of($language);
        $written = $writer->write($model);

        // Sorted, because the writer lists nodes in the order it walks them.
        $sorted = static function (array $targets): array {
            usort($targets, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

            return $targets;
        };

        $before = $this->targets($original);

        $this->assertCount(43, $before);
        $this->assertCount(38, array_filter($before, static fn (array $t): bool => $t['reference'] !== null));
        $this->assertSame($sorted($before), $sorted($this->targets($written)));
        $this->assertSame($this->comparable($original), $this->comparable($written));
    }

    /**
     * A manifest with a concept for every classifier in a chunk and a feature
     * for every key: properties without `multiple`, links with it.
     *
     * @param  array<string, mixed>  $chunk
     */
    private function languageImpliedBy(array $chunk): PackageManifest
    {
        $concepts = [];
        $root     = '';

        foreach ($chunk['nodes'] as $node) {
            $concept = $node['classifier']['key'];

            if ($node['parent'] === null) {
                $root = $concept;
            }

            $concepts[$concept] ??= [];

            foreach ($node['properties'] as $property) {
                $key = $property['property']['key'];

                $concepts[$concept][$key] ??= ['key' => $key, 'name' => $key];
            }

            foreach (['containments' => 'containment', 'references' => 'reference'] as $bucket => $field) {
                foreach ($node[$bucket] as $link) {
                    $key = $link[$field]['key'];

                    $concepts[$concept][$key] = ['key' => $key, 'name' => $key, 'multiple' => true];
                }
            }
        }

        return PackageManifest::fromJson((string) json_encode([
            'format'   => MetalanguagePackage::FORMAT,
            'name'     => 'JCB',
            'version'  => $chunk['languages'][0]['version'],
            'root'     => $root,
            'concepts' => array_map(
                static fn (string $key, array $features): array => [
                    'key' => $key, 'name' => $key, 'features' => array_values($features),
                ],
                array_keys($concepts),
                array_values($concepts)
            ),
        ]));
    }

    /**
     * A group added in the editor has no id, and that is said out loud.
     *
     * A form has no field for `LIonWeb_id` unless the language's generator
     * puts one there, so a row somebody added has none. It still has to go
     * out, and it still has to be a different node from every other, so an id
     * is derived from where it sits - and anything that pointed at it before
     * does not any more, which is the part worth a warning.
     */
    public function testAGroupWithNoIdIsWrittenAndReported(): void
    {
        $language = $this->language();
        $model    = InstanceModel::read(Chunk::fromArray($this->chunk()), $language)->toStoredModel();

        $model['book']['book0']['chapter']['chapter2'] = [
            'heading'                => 'Three',
            InstanceModel::MARKER    => 'chapter',
        ];

        $writer  = InstanceChunk::of($language);
        $written = $writer->write($model);

        $this->assertSame(['ID_INVENTED'], array_column($writer->diagnostics(), 'code'));
        $this->assertCount(6, $written['nodes']);
    }

    /**
     * An empty property is not an absent one.
     *
     * The reader already tells them apart - an absent property has no key in
     * the model at all - so a writer that turned `""` into nothing would be
     * overruling it. Four properties of every JCB `dynamic_get` are empty
     * strings that were in the chunk they came from, and dropping them made
     * 45 of Hello World's 53 nodes come back different.
     */
    public function testAnEmptyPropertyIsNotDroppedOnTheWayBack(): void
    {
        $original = $this->chunk();

        $original['nodes'][2]['properties'][] = [
            'property' => [
                'language' => self::LANGUAGE, 'version' => self::VERSION,
                'key' => 'chapter-k-heading',
            ],
            'value' => '',
        ];
        $original['nodes'][2]['properties'] = [array_pop($original['nodes'][2]['properties'])];

        $language = $this->language();
        $model    = InstanceModel::read(Chunk::fromArray($original), $language)->toStoredModel();

        $this->assertSame('', $model['book']['book0']['chapter']['chapter0']['heading']);

        $written = InstanceChunk::of($language)->write($model);

        $this->assertSame($this->comparable($original), $this->comparable($written));
    }

    /**
     * A model that never came from a chunk has to be told which language.
     */
    public function testAModelNamingNoLanguageIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not say which LionWeb language');

        InstanceChunk::of($this->language())->write([
            'name'                => 'typed by hand',
            InstanceModel::MARKER => 'library',
        ]);
    }

    /**
     * And it can be, which is what lets a project written from scratch leave.
     */
    public function testTheLanguageMayBeGivenInstead(): void
    {
        $written = InstanceChunk::of($this->language(), 'demo', '1.0')->write([
            'name'                => 'typed by hand',
            InstanceModel::MARKER => 'library',
            InstanceModel::ID     => 'lib-9',
        ]);

        $this->assertSame('lib-9', $written['nodes'][0]['id']);
        $this->assertSame([['key' => 'demo', 'version' => '1.0']], $written['languages']);
    }
}
