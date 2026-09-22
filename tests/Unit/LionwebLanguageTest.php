<?php

declare(strict_types=1);

namespace Yepr\Gen\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Lionweb\Chunk;
use Yepr\Gen\Core\Lionweb\LionCoreLanguage;

/**
 * Reading a LionWeb language into the shape a metalanguage is stored in.
 *
 * The step that was missing between anything speaking LionWeb and this family.
 * Both sides model LionCore M3; a chunk is a flat list of nodes addressed by
 * metapointer and a stored metalanguage is a Joomla form's shape, and until
 * this there was nothing that turned one into the other.
 *
 * What is asserted here is what a converted language has to get right for
 * Meta-gen to generate forms from it: the kinds, the keys, inheritance, and
 * which of a feature's two type groups the type lands in. Every awkward detail
 * of the stored shape is a detail somebody would otherwise get wrong once per
 * importer.
 *
 * @since  0.7.0
 */
final class LionwebLanguageTest extends TestCase
{
    /**
     * A node, as a chunk serialises one.
     *
     * @param  array<string, string>         $properties
     * @param  array<string, list<string>>   $containments
     * @param  array<string, list<string>>   $references
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
        $asProperty = static function (string $key, string $value): array {
            $language = $key === 'LionCore-builtins-INamed-name'
                ? LionCoreLanguage::BUILTINS
                : LionCoreLanguage::M3;

            return [
                'property' => ['language' => $language, 'version' => '2024.1', 'key' => $key],
                'value'    => $value,
            ];
        };

        $meta = static fn (string $key): array => [
            'language' => LionCoreLanguage::M3, 'version' => '2024.1', 'key' => $key,
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
            $node['properties'][] = $asProperty($key, $value);
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
     * A small language with one of everything the stored shape can hold.
     *
     * @param  list<array<string, mixed>>  $extraNodes
     * @param  list<string>                $entities
     */
    private function chunk(array $extraNodes = [], array $entities = []): Chunk
    {
        $named = 'LionCore-builtins-INamed-name';

        $nodes = [
            $this->node('lang', 'Language', [
                $named => 'Demo', 'IKeyed-key' => 'demo', 'Language-version' => '1.0',
            ], [
                'Language-entities' => array_merge(
                    ['dt-string', 'enum-colour', 'iface', 'concept', 'anno'],
                    $entities
                ),
            ]),

            $this->node('dt-string', 'PrimitiveType', [$named => 'String', 'IKeyed-key' => 'k-string']),

            $this->node('enum-colour', 'Enumeration', [
                $named => 'Colour', 'IKeyed-key' => 'k-colour',
            ], ['Enumeration-literals' => ['lit-red', 'lit-blue']]),

            $this->node('lit-red', 'EnumerationLiteral', [$named => 'Red', 'IKeyed-key' => 'k-red']),
            $this->node('lit-blue', 'EnumerationLiteral', [$named => 'Blue', 'IKeyed-key' => 'k-blue']),

            $this->node('iface', 'Interface', [
                $named => 'INamed', 'IKeyed-key' => 'k-inamed',
            ], ['Classifier-features' => ['f-name']]),

            $this->node('f-name', 'Property', [
                $named => 'name', 'IKeyed-key' => 'k-f-name', 'Feature-optional' => 'false',
            ], [], ['Property-type' => ['dt-string']]),

            $this->node('concept', 'Concept', [
                $named             => 'Thing',
                'IKeyed-key'       => 'k-thing',
                'Concept-abstract' => 'false',
                'Concept-partition' => 'true',
            ], [
                'Classifier-features' => ['f-colour', 'f-parts', 'f-owner'],
            ], [
                'Concept-implements' => ['iface'],
            ]),

            $this->node('f-colour', 'Property', [
                $named => 'colour', 'IKeyed-key' => 'k-f-colour', 'Feature-optional' => 'true',
            ], [], ['Property-type' => ['enum-colour']]),

            $this->node('f-parts', 'Containment', [
                $named => 'parts', 'IKeyed-key' => 'k-f-parts', 'Link-multiple' => 'true',
            ], [], ['Link-type' => ['concept']]),

            $this->node('f-owner', 'Reference', [
                $named => 'owner', 'IKeyed-key' => 'k-f-owner',
            ], [], ['Link-type' => ['concept']]),

            $this->node('anno', 'Annotation', [
                $named => 'Deprecated', 'IKeyed-key' => 'k-deprecated',
            ], [], ['Annotation-annotates' => ['concept']]),
        ];

        return Chunk::fromArray([
            'serializationFormatVersion' => '2024.1',
            'languages' => [['key' => LionCoreLanguage::M3, 'version' => '2024.1']],
            'nodes'     => array_merge($nodes, $extraNodes),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $extraNodes
     * @param  list<string>                $entities
     *
     * @return array<string, mixed>
     */
    private function model(array $extraNodes = [], array $entities = []): array
    {
        return (new LionCoreLanguage($this->chunk($extraNodes, $entities)))->toStoredModel();
    }

    /**
     * @param  array<string, mixed>  $model
     *
     * @return array<string, mixed>
     */
    private function entity(array $model, string $key): array
    {
        foreach ($model['languageEntities'] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        $this->fail("No language entity keyed {$key}.");
    }

    // -- the language itself -------------------------------------------------

    public function testItReadsTheLanguagesOwnDetails(): void
    {
        $model = $this->model();

        $this->assertSame('Demo', $model['name']);
        $this->assertSame('1.0', $model['version']);
        $this->assertSame('Language', $model['LIonWeb_key']);
    }

    /**
     * A repeating group is an object keyed `languageEntities0`, in order, not a
     * list. `ConceptModel` reads either, but a form writes this.
     */
    public function testEntitiesAreStoredAsAKeyedGroupInOrder(): void
    {
        $model = $this->model();

        $this->assertSame(
            ['languageEntities0', 'languageEntities1', 'languageEntities2',
                'languageEntities3', 'languageEntities4'],
            array_keys($model['languageEntities'])
        );
        $this->assertSame('String', $model['languageEntities']['languageEntities0']['name']);
    }

    // -- classifiers ---------------------------------------------------------

    public function testAConceptCarriesItsFlagsAndWhatItImplements(): void
    {
        $concept = $this->entity($this->model(), 'k-thing')['classifier'];

        $this->assertSame('Concept', $concept['classifier_type']);
        $this->assertSame('0', $concept['concept']['abstract']);
        $this->assertSame('1', $concept['concept']['partition'], 'a partition is a root');
        $this->assertSame(
            ['implements0' => ['conceptInterface' => 'k-inamed']],
            $concept['concept']['implements']
        );
    }

    /**
     * LionCore calls it an Interface and the stored shape a ConceptInterface.
     * They are the same thing and the group is named after the stored one.
     */
    public function testAnInterfaceBecomesAConceptInterface(): void
    {
        $classifier = $this->entity($this->model(), 'k-inamed')['classifier'];

        $this->assertSame('ConceptInterface', $classifier['classifier_type']);
        $this->assertArrayHasKey('conceptInterface', $classifier);
        $this->assertSame('', $classifier['conceptInterface']['extends']);
    }

    public function testAnAnnotationCarriesWhatItAnnotates(): void
    {
        $classifier = $this->entity($this->model(), 'k-deprecated')['classifier'];

        $this->assertSame('Annotation', $classifier['classifier_type']);
        $this->assertSame('k-thing', $classifier['annotation']['annotates']);
    }

    // -- features ------------------------------------------------------------

    /**
     * A property's type sits on `property` and a link's on `link`. Both are
     * called `type`, which is the one thing about the two that is the same.
     */
    public function testAPropertyTakesItsTypeFromTheGroupThatMatches(): void
    {
        $feature = $this->entity($this->model(), 'k-inamed')['classifier']['feature']['feature0'];

        $this->assertSame('name', $feature['name']);
        $this->assertSame('k-f-name', $feature['key']);
        $this->assertSame('Property', $feature['feature_type']);
        $this->assertSame('k-string', $feature['property']['type']);
        $this->assertSame(
            'k-string',
            $feature['property']['typeReference_key'],
            'the dropdown stores what it resolved to as well as the field'
        );
        $this->assertArrayNotHasKey('link', $feature);
    }

    public function testAContainmentAndAReferenceAreBothLinksAndSayWhich(): void
    {
        $features = $this->entity($this->model(), 'k-thing')['classifier']['feature'];

        $parts = $features['feature1'];
        $owner = $features['feature2'];

        $this->assertSame('Link', $parts['feature_type']);
        $this->assertSame('Containment', $parts['link']['link_type']);
        $this->assertSame('1', $parts['link']['is_multiple']);
        $this->assertSame('k-thing', $parts['link']['type']);

        $this->assertSame('Reference', $owner['link']['link_type']);
        $this->assertSame('0', $owner['link']['is_multiple'], 'a link is single unless it says otherwise');
    }

    /**
     * LionWeb says required unless it says otherwise, and a form stores a
     * checkbox as "1" or "0" rather than true or false.
     */
    public function testOptionalIsStoredTheWayACheckboxIs(): void
    {
        $model = $this->model();

        $this->assertSame(
            '0',
            $this->entity($model, 'k-inamed')['classifier']['feature']['feature0']['is_optional']
        );
        $this->assertSame(
            '1',
            $this->entity($model, 'k-thing')['classifier']['feature']['feature0']['is_optional']
        );
    }

    // -- datatypes -----------------------------------------------------------

    public function testAPrimitiveTypeCarriesOnlyItsName(): void
    {
        $string = $this->entity($this->model(), 'k-string');

        $this->assertSame('DataType', $string['languageEntity_type']);
        $this->assertSame('PrimitiveType', $string['datatype']['dataType_type']);
        $this->assertSame('String', $string['name']);
    }

    public function testAnEnumerationCarriesItsLiteralsInOrder(): void
    {
        $colour = $this->entity($this->model(), 'k-colour');

        $this->assertSame('Enumeration', $colour['datatype']['dataType_type']);
        $this->assertSame(
            ['literals0' => ['name' => 'Red', 'key' => 'k-red', 'LIonWeb_key' => 'EnumerationLiteral'],
                'literals1' => ['name' => 'Blue', 'key' => 'k-blue', 'LIonWeb_key' => 'EnumerationLiteral']],
            $colour['datatype']['enumeration']['literals']
        );
    }

    // -- what it cannot carry, it says ---------------------------------------

    /**
     * The stored shape holds one supertype per interface because the form does.
     * A language that needs two is not refused, but it is not silently halved.
     */
    public function testAnInterfaceExtendingSeveralIsReported(): void
    {
        $named = 'LionCore-builtins-INamed-name';

        $chunk = $this->chunk([
            $this->node('iface2', 'Interface', [$named => 'IOther', 'IKeyed-key' => 'k-iother']),
            $this->node('iface3', 'Interface', [$named => 'IThird', 'IKeyed-key' => 'k-ithird']),
            $this->node('wide', 'Interface', [
                $named => 'IWide', 'IKeyed-key' => 'k-iwide',
            ], [], ['Interface-extends' => ['iface2', 'iface3']]),
        ], ['iface2', 'iface3', 'wide']);

        $reader = new LionCoreLanguage($chunk);
        $model  = $reader->toStoredModel();

        $this->assertSame(
            'k-iother',
            $this->entity($model, 'k-iwide')['classifier']['conceptInterface']['extends']
        );

        $this->assertSame(
            ['INTERFACE_MULTIPLE_EXTENDS'],
            array_column($reader->diagnostics(), 'code')
        );
    }

    /**
     * A property typed by a LionCore builtin gets a primitive type of its own.
     *
     * LionCore-builtins is a language in its own right, so `String` is a node
     * in *that* chunk and not in this one. A stored metalanguage cannot depend
     * on another language, so the builtins a language actually uses are
     * materialised locally - which is what a hand-written one does anyway, ER1
     * declaring its own String and Boolean.
     *
     * Emptying the type instead is what this did first, and it cost 481 of
     * JCB's properties their type.
     */
    public function testALionCoreBuiltinBecomesAPrimitiveTypeOfItsOwn(): void
    {
        $named = 'LionCore-builtins-INamed-name';

        $chunk = $this->chunk([
            $this->node('outside', 'Concept', [
                $named => 'Outside', 'IKeyed-key' => 'k-outside',
            ], ['Classifier-features' => ['f-text', 'f-count']]),
            $this->node('f-text', 'Property', [
                $named => 'stamp', 'IKeyed-key' => 'k-f-stamp',
            ], [], ['Property-type' => ['LionCore-builtins-String-2024-1']]),
            $this->node('f-count', 'Property', [
                $named => 'count', 'IKeyed-key' => 'k-f-count',
            ], [], ['Property-type' => ['LionCore-builtins-Integer-2024-1']]),
        ], ['outside']);

        $reader = new LionCoreLanguage($chunk);
        $model  = $reader->toStoredModel();

        $features = $this->entity($model, 'k-outside')['classifier']['feature'];

        // The version suffix is the node id's, not the key's. Keeping the key
        // is what lets the metapointer be put back together on the way out.
        $this->assertSame('LionCore-builtins-String', $features['feature0']['property']['type']);
        $this->assertSame('LionCore-builtins-Integer', $features['feature1']['property']['type']);

        $string = $this->entity($model, 'LionCore-builtins-String');

        $this->assertSame('String', $string['name']);
        $this->assertSame('PrimitiveType', $string['datatype']['dataType_type']);

        $this->assertSame([], $reader->diagnostics(), 'a builtin is ordinary, not a problem');
    }

    /**
     * Only the ones it used. A language that never mentions Boolean should not
     * grow one.
     */
    public function testOnlyTheBuiltinsALanguageUsesAreDeclared(): void
    {
        $model = $this->model();
        $names = array_column($model['languageEntities'], 'name');

        $this->assertNotContains('Boolean', $names);
        $this->assertNotContains('Integer', $names);
    }

    /**
     * A target that is neither here nor a builtin cannot be recovered, because
     * only the chunk that holds it knows its key. Guessing would put a dangling
     * reference in a form.
     */
    public function testATargetThatIsNeitherHereNorABuiltinIsReported(): void
    {
        $named = 'LionCore-builtins-INamed-name';

        $chunk = $this->chunk([
            $this->node('outside', 'Concept', [
                $named => 'Outside', 'IKeyed-key' => 'k-outside',
            ], ['Classifier-features' => ['f-foreign']]),
            $this->node('f-foreign', 'Property', [
                $named => 'stamp', 'IKeyed-key' => 'k-f-stamp',
            ], [], ['Property-type' => ['some-other-language-Money']]),
        ], ['outside']);

        $reader = new LionCoreLanguage($chunk);
        $model  = $reader->toStoredModel();

        $feature = $this->entity($model, 'k-outside')['classifier']['feature']['feature0'];

        $this->assertSame('', $feature['property']['type']);
        $this->assertContains('TARGET_NOT_IN_CHUNK', array_column($reader->diagnostics(), 'code'));
    }

    public function testAKindTheStoredShapeHasNoRoomForIsReported(): void
    {
        $chunk = $this->chunk([
            $this->node('sdt', 'StructuredDataType', [
                'LionCore-builtins-INamed-name' => 'Point', 'IKeyed-key' => 'k-point',
            ]),
        ], ['sdt']);

        $reader = new LionCoreLanguage($chunk);
        $model  = $reader->toStoredModel();

        foreach ($model['languageEntities'] as $row) {
            $this->assertNotSame('k-point', $row['key']);
        }

        $this->assertContains('ENTITY_KIND_UNSUPPORTED', array_column($reader->diagnostics(), 'code'));
    }

    /**
     * A clean language says nothing, or the warnings mean nothing.
     */
    public function testALanguageThatConvertsCleanlySaysNothing(): void
    {
        $reader = new LionCoreLanguage($this->chunk());

        $reader->toStoredModel();

        $this->assertSame([], $reader->diagnostics());
    }

    // -- refusals ------------------------------------------------------------

    public function testAModelRatherThanALanguageIsRefused(): void
    {
        $chunk = Chunk::fromArray([
            'serializationFormatVersion' => '2024.1',
            'languages' => [['key' => 'demo', 'version' => '1.0']],
            'nodes'     => [$this->node('n1', 'k-thing')],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no LionCore Language node/');

        (new LionCoreLanguage($chunk))->toStoredModel();
    }

    public function testSomethingThatIsNotAChunkIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Chunk::fromArray(['serializationFormatVersion' => '2024.1']);
    }
}
