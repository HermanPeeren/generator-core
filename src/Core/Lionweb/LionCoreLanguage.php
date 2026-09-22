<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Lionweb
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Lionweb;

/**
 * A LionWeb chunk holding a language, read into the shape a metalanguage is
 * stored in.
 *
 * The missing step between anything that speaks LionWeb and this family. Both
 * sides model LionCore M3 and neither can read the other: a chunk is a flat
 * list of nodes addressed by metapointer, while a stored metalanguage is a
 * Joomla form's shape - repeating groups keyed `languageEntities0`, a subtype
 * chosen by a radio with a group named after it, a key on every row. They are
 * two serialisations of the same metamodel, and this is the translation.
 *
 * **It produces data, not a model.** What comes back is exactly what Meta-gen
 * stores in `form_data` and what a package carries as `model.json`, which is
 * the point: a converted language then goes everywhere a hand-written one goes,
 * through machinery that already exists, rather than down a second path that
 * would have to be kept in step.
 *
 * **Keys survive, because keys are the identity.** A feature's key is what a
 * model written in this language stores against it, and it is the only thing
 * that can carry a value back out to LionWeb afterwards. Names are for people.
 *
 * **What it cannot carry, it says.** {@see diagnostics()} lists everything the
 * stored shape has no room for rather than dropping it quietly - an interface
 * extending more than one interface, a datatype kind with no equivalent, a
 * feature whose type is in another language.
 *
 * @since  0.7.0
 */
final class LionCoreLanguage
{
    public const M3       = 'LionCore-M3';
    public const BUILTINS = 'LionCore-builtins';

    /** Classifier keys: what a node in a LionCore chunk *is*. */
    private const LANGUAGE   = 'Language';
    private const CONCEPT    = 'Concept';
    private const INTERFACE  = 'Interface';
    private const ANNOTATION = 'Annotation';
    private const ENUM       = 'Enumeration';
    private const LITERAL    = 'EnumerationLiteral';
    private const PRIMITIVE  = 'PrimitiveType';
    private const PROPERTY   = 'Property';
    private const CONTAINMENT = 'Containment';
    private const REFERENCE  = 'Reference';

    /** Feature keys, as LionCore names its own. */
    private const NAME        = 'LionCore-builtins-INamed-name';
    private const KEY         = 'IKeyed-key';
    private const VERSION     = 'Language-version';
    private const ENTITIES    = 'Language-entities';
    private const FEATURES    = 'Classifier-features';
    private const LITERALS    = 'Enumeration-literals';
    private const ABSTRACT    = 'Concept-abstract';
    private const PARTITION   = 'Concept-partition';
    private const OPTIONAL    = 'Feature-optional';
    private const MULTIPLE    = 'Link-multiple';
    private const EXTENDS_C   = 'Concept-extends';
    private const IMPLEMENTS_C = 'Concept-implements';
    private const EXTENDS_I   = 'Interface-extends';
    private const EXTENDS_A   = 'Annotation-extends';
    private const IMPLEMENTS_A = 'Annotation-implements';
    private const ANNOTATES   = 'Annotation-annotates';
    private const PROP_TYPE   = 'Property-type';
    private const LINK_TYPE   = 'Link-type';

    /**
     * What the stored shape puts in `LIonWeb_key` on each kind of row. Nothing
     * reads them as identity - they say which form wrote the row - but a
     * converted language that left them out would not look like one somebody
     * typed, and the difference would show up somewhere eventually.
     */
    private const MARKERS = [
        'language'    => 'Language',
        'entity'      => 'LanguageEntity',
        'concept'     => 'LanguageEntity.Classifier.Concept',
        'interface'   => 'LanguageEntity.Classifier.ConceptInterface',
        'annotation'  => 'LanguageEntity.Classifier.Annotation',
        'primitive'   => 'DataType.PrimitiveType',
        'enumeration' => 'LanguageEntity.DataType.Enumeration',
        'feature'     => 'Feature',
        'property'    => 'Feature.Property',
        'link'        => 'Feature.Link',
    ];

    /** @var list<array{severity: string, code: string, message: string}> */
    private array $diagnostics = [];

    /**
     * Builtin datatypes this language turned out to use, key => name.
     *
     * LionCore-builtins is a language in its own right, and every language
     * leans on it: a property typed `String` points at a node in *that* chunk,
     * not this one. A stored metalanguage has no notion of depending on another
     * language, so the builtins a language actually uses are materialised as
     * primitive types of its own - which is what a hand-written one does too,
     * ER1 declaring its own `String` and `Boolean`.
     *
     * Keyed by the builtin's real LionCore key, so the metapointer can be put
     * back together on the way out.
     *
     * @var array<string, string>
     */
    private array $builtins = [];

    public function __construct(private readonly Chunk $chunk)
    {
    }

    /**
     * Read the language in this chunk into the stored metalanguage shape.
     *
     * @return array<string, mixed>  The metalanguage, as Meta-gen stores one.
     *
     * @throws \RuntimeException  When the chunk holds no language node.
     *
     * @since  0.7.0
     */
    public function toStoredModel(): array
    {
        $languages = $this->chunk->idsOf(self::LANGUAGE);

        if ($languages === []) {
            throw new \RuntimeException(
                'This chunk holds no LionCore Language node, so it is a model rather than a language.'
            );
        }

        if (\count($languages) > 1) {
            $this->diag(
                'warning',
                'MANY_LANGUAGES',
                'The chunk holds ' . \count($languages) . ' languages; the first is the one read.'
            );
        }

        $language = $languages[0];
        $this->builtins = [];

        $rows = [];

        foreach ($this->chunk->children($language, self::ENTITIES) as $entity) {
            $row = $this->entity($entity);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // The builtins go first, the way a hand-written language declares its
        // primitives before the classifiers that use them. They are only known
        // once every feature has been read, which is why this is assembled
        // here rather than as the entities are converted.
        $entities = [];
        $position = 0;

        foreach ($this->builtinRows() as $row) {
            $entities['languageEntities' . $position++] = $row;
        }

        foreach ($rows as $row) {
            $entities['languageEntities' . $position++] = $row;
        }

        return [
            'id'               => '0',
            'name'             => $this->chunk->property($language, self::NAME) ?? '',
            'version'          => $this->chunk->property($language, self::VERSION) ?? '',
            'LIonWeb_key'      => self::MARKERS['language'],
            'languageEntities' => $entities,
        ];
    }

    /**
     * @return list<array{severity: string, code: string, message: string}>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * One language entity: a classifier of some kind, or a datatype.
     *
     * @return array<string, mixed>|null
     */
    private function entity(string $id): ?array
    {
        $kind = $this->chunk->classifierKey($id);

        $row = [
            'name'                => $this->chunk->property($id, self::NAME) ?? '',
            'key'                 => $this->chunk->property($id, self::KEY) ?? '',
            'languageEntity_type' => '',
        ];

        switch ($kind) {
            case self::CONCEPT:
                $row['languageEntity_type'] = 'Classifier';
                $row['classifier'] = [
                    'classifier_type' => 'Concept',
                    'concept'         => [
                        'abstract'    => $this->bit($id, self::ABSTRACT),
                        'partition'   => $this->bit($id, self::PARTITION),
                        'extends'     => $this->keyOf($this->chunk->target($id, self::EXTENDS_C)),
                        'implements'  => $this->implemented($id, self::IMPLEMENTS_C),
                        'LIonWeb_key' => self::MARKERS['concept'],
                    ],
                    'feature'         => $this->features($id),
                ];
                break;

            case self::INTERFACE:
                // LionCore lets an interface extend several; the stored shape
                // holds one, because the form holds one. Saying so beats
                // producing a language quietly missing half its inheritance.
                $supertypes = $this->chunk->targets($id, self::EXTENDS_I);

                if (\count($supertypes) > 1) {
                    $this->diag(
                        'warning',
                        'INTERFACE_MULTIPLE_EXTENDS',
                        ($this->chunk->property($id, self::NAME) ?? $id) . ' extends '
                        . \count($supertypes) . ' interfaces; a stored metalanguage holds one, '
                        . 'so the others are not carried.'
                    );
                }

                $row['languageEntity_type'] = 'Classifier';
                $row['classifier'] = [
                    'classifier_type'  => 'ConceptInterface',
                    'conceptInterface' => [
                        'extends'     => $this->keyOf($supertypes[0] ?? null),
                        'LIonWeb_key' => self::MARKERS['interface'],
                    ],
                    'feature'          => $this->features($id),
                ];
                break;

            case self::ANNOTATION:
                $row['languageEntity_type'] = 'Classifier';
                $row['classifier'] = [
                    'classifier_type' => 'Annotation',
                    'annotation'      => [
                        'annotates'   => $this->keyOf($this->chunk->target($id, self::ANNOTATES)),
                        'multiple'    => '0',
                        'extends'     => $this->keyOf($this->chunk->target($id, self::EXTENDS_A)),
                        'implements'  => $this->implemented($id, self::IMPLEMENTS_A),
                        'LIonWeb_key' => self::MARKERS['annotation'],
                    ],
                    'feature'         => $this->features($id),
                ];
                break;

            case self::PRIMITIVE:
                $row['languageEntity_type'] = 'DataType';
                $row['datatype'] = [
                    'dataType_type' => 'PrimitiveType',
                    'primitiveType' => ['LIonWeb_key' => self::MARKERS['primitive']],
                ];
                break;

            case self::ENUM:
                $row['languageEntity_type'] = 'DataType';
                $row['datatype'] = [
                    'dataType_type' => 'Enumeration',
                    'enumeration'   => [
                        'literals'    => $this->literals($id),
                        'LIonWeb_key' => self::MARKERS['enumeration'],
                    ],
                ];
                break;

            default:
                // A StructuredDataType, or something from a LionCore newer than
                // this. Dropping it silently would produce a language whose
                // properties point at a type that is not there.
                $this->diag(
                    'warning',
                    'ENTITY_KIND_UNSUPPORTED',
                    'A language entity of kind ' . ($kind ?? 'unknown')
                    . ' has no place in a stored metalanguage; it is not carried.'
                );

                return null;
        }

        $row['LIonWeb_key'] = self::MARKERS['entity'];

        return $row;
    }

    /**
     * A classifier's own features, keyed the way a repeating group is stored.
     *
     * @return array<string, mixed>
     */
    private function features(string $id): array
    {
        $features = [];
        $position = 0;

        foreach ($this->chunk->children($id, self::FEATURES) as $feature) {
            $row = $this->feature($feature);

            if ($row !== null) {
                $features['feature' . $position++] = $row;
            }
        }

        return $features;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function feature(string $id): ?array
    {
        $kind = $this->chunk->classifierKey($id);

        $row = [
            'name' => $this->chunk->property($id, self::NAME) ?? '',
            'key'  => $this->chunk->property($id, self::KEY) ?? '',
            // A form stores an unticked checkbox as "0", and LionCore says a
            // feature is required unless it says otherwise.
            'is_optional' => $this->bit($id, self::OPTIONAL),
        ];

        if ($kind === self::PROPERTY) {
            $type = $this->keyOf($this->chunk->target($id, self::PROP_TYPE), $id, 'type');

            $row['feature_type'] = 'Property';
            $row['property']     = [
                'type'              => $type,
                // The dropdown stores the same value twice: once as the field
                // and once as what the reference resolved to.
                'typeReference_key' => $type,
                'LIonWeb_key'       => self::MARKERS['property'],
            ];
        } elseif ($kind === self::CONTAINMENT || $kind === self::REFERENCE) {
            $row['feature_type'] = 'Link';
            $row['link']         = [
                'type'        => $this->keyOf($this->chunk->target($id, self::LINK_TYPE), $id, 'type'),
                'link_type'   => $kind === self::CONTAINMENT ? 'Containment' : 'Reference',
                'is_multiple' => $this->bit($id, self::MULTIPLE),
                'LIonWeb_key' => self::MARKERS['link'],
            ];
        } else {
            $this->diag(
                'warning',
                'FEATURE_KIND_UNSUPPORTED',
                'A feature of kind ' . ($kind ?? 'unknown') . ' is not carried.'
            );

            return null;
        }

        $row['classifier_key'] = '';
        $row['LIonWeb_key']    = self::MARKERS['feature'];

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function literals(string $id): array
    {
        $literals = [];
        $position = 0;

        foreach ($this->chunk->children($id, self::LITERALS) as $literal) {
            if ($this->chunk->classifierKey($literal) !== self::LITERAL) {
                continue;
            }

            $literals['literals' . $position++] = [
                'name'        => $this->chunk->property($literal, self::NAME) ?? '',
                'key'         => $this->chunk->property($literal, self::KEY) ?? '',
                'LIonWeb_key' => 'EnumerationLiteral',
            ];
        }

        return $literals;
    }

    /**
     * The interfaces a classifier implements, as the stored repeating group.
     *
     * @return array<string, mixed>
     */
    private function implemented(string $id, string $reference): array
    {
        $rows     = [];
        $position = 0;

        foreach ($this->chunk->targets($id, $reference) as $target) {
            $rows['implements' . $position++] = ['conceptInterface' => $this->keyOf($target)];
        }

        return $rows;
    }

    /**
     * A node id turned into the key everything in a stored metalanguage points
     * by.
     *
     * The stored shape refers to things by key, and the chunk refers to them by
     * node id; they are not the same and only the chunk can map between them.
     * A target this chunk does not hold is one in another language - a property
     * typed by a builtin, most often - and the key is unrecoverable, so it is
     * reported rather than guessed at.
     */
    private function keyOf(?string $id, ?string $owner = null, string $what = ''): string
    {
        if ($id === null) {
            return '';
        }

        if ($this->chunk->has($id)) {
            return $this->chunk->property($id, self::KEY) ?? '';
        }

        $builtin = self::builtin($id);

        if ($builtin !== null) {
            $this->builtins[$builtin['key']] = $builtin['name'];

            return $builtin['key'];
        }

        // Named after the target rather than after what points at it, so a
        // language with sixty features of one missing type says so once.
        $this->diag(
            'warning',
            'TARGET_NOT_IN_CHUNK',
            'The ' . ($what ?: 'target') . ' ' . $id . ' is not in this chunk and is not a '
            . 'LionCore builtin, so it cannot be carried into a stored metalanguage.'
        );

        return '';
    }

    /**
     * A node id that names a LionCore builtin datatype, taken apart.
     *
     * Builtin ids carry the language version - `LionCore-builtins-String-2024-1`
     * - while the key does not. Stripping it back to the key is what makes the
     * type survive a trip out to LionWeb again.
     *
     * @return array{key: string, name: string}|null
     */
    private static function builtin(string $id): ?array
    {
        if (preg_match('/^LionCore-builtins-([A-Za-z]\w*)-\d+-\d+$/', $id, $matched) !== 1) {
            return null;
        }

        return ['key' => self::BUILTINS . '-' . $matched[1], 'name' => $matched[1]];
    }

    /**
     * The builtin datatypes this language used, as primitive type entities.
     *
     * @return list<array<string, mixed>>
     */
    private function builtinRows(): array
    {
        $rows = [];

        ksort($this->builtins);

        foreach ($this->builtins as $key => $name) {
            $rows[] = [
                'name'                => $name,
                'key'                 => $key,
                'languageEntity_type' => 'DataType',
                'datatype'            => [
                    'dataType_type' => 'PrimitiveType',
                    'primitiveType' => ['LIonWeb_key' => self::MARKERS['primitive']],
                ],
                'LIonWeb_key'         => self::MARKERS['entity'],
            ];
        }

        return $rows;
    }

    /**
     * A LionWeb boolean as a Joomla form stores a checkbox.
     */
    private function bit(string $id, string $key): string
    {
        return $this->chunk->flag($id, $key) ? '1' : '0';
    }

    private function diag(string $severity, string $code, string $message): void
    {
        // One line per distinct message: a language with sixty properties typed
        // by a builtin should say so once per builtin, not once per property.
        foreach ($this->diagnostics as $existing) {
            if ($existing['code'] === $code && $existing['message'] === $message) {
                return;
            }
        }

        $this->diagnostics[] = [
            'severity' => $severity,
            'code'     => $code,
            'message'  => $message,
        ];
    }
}
