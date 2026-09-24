<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Package\AncestryCheck;
use Yepr\Gen\Joomla\Metalanguage\Ancestry;
use Yepr\Gen\Joomla\Metalanguage\MetalanguageEntry;
use Yepr\Gen\Core\Package\PackageManifest;

/**
 * A language that derives from another: step 4.5.
 *
 * The relation is not versioning. ER1 2.0 says *this replaces that*; deriving
 * says *this is also that*, which is what lets several languages come off one
 * parent - each with its own purpose and its own name - and all of them still
 * be generable by the parent's generators.
 *
 * What makes that cheap was already true before any of this: a generator reads
 * named paths and a validator checks named things, so a model carrying nodes
 * the parent has never heard of generates the parent's output unchanged. What
 * makes it *safe* is the guard, and the guard is most of this file.
 *
 * @since  0.11.0
 */
final class AncestryTest extends TestCase
{
    /**
     * @param  array<int, array{key: string, version: string}>  $dependsOn
     */
    private function manifest(array $dependsOn = []): PackageManifest
    {
        return new PackageManifest(
            'Derived',
            'Derived',
            '1.0',
            'Project',
            'media/yepr_metalanguages/Derived/1.0/',
            'language/en-GB/derived.ini',
            'en-GB',
            [['key' => 'c-entity', 'name' => 'Entity']],
            [],
            2,
            '',
            $dependsOn
        );
    }

    /**
     * A language says what it derives from, and it round-trips.
     */
    public function testAManifestCarriesWhatItDerivesFrom(): void
    {
        $manifest = $this->manifest([['key' => 'ER1', 'version' => '1.1']]);

        $read = PackageManifest::fromJson($manifest->toJson());

        $this->assertSame([['key' => 'ER1', 'version' => '1.1']], $read->dependsOn);
    }

    /**
     * A language that derives from nothing writes no field at all.
     *
     * Left out rather than written empty, so a package built by a Meta-gen that
     * has never heard of 4.5 and one built after it are the same bytes - which
     * matters, because a package's files are hashed and its manifest is what
     * says so.
     */
    public function testALanguageThatDerivesFromNothingSaysNothing(): void
    {
        $this->assertArrayNotHasKey('dependsOn', $this->manifest()->toArray());
        $this->assertStringNotContainsString('dependsOn', $this->manifest()->toJson());

        $this->assertSame([], PackageManifest::fromJson($this->manifest()->toJson())->dependsOn);
    }

    /**
     * A parent named without a version is a parent named badly.
     *
     * Two versions of one language are two different parents: a language
     * deriving from ER1 1.0 does not inherit what 1.1 added. So an entry
     * missing either half is not a looser reference, it is an unusable one.
     */
    public function testAParentMissingHalfItsIdentityIsIgnored(): void
    {
        $read = PackageManifest::fromJson(json_encode([
            'format'    => 2,
            'name'      => 'Derived',
            'key'       => 'Derived',
            'version'   => '1.0',
            'dependsOn' => [
                ['key' => 'ER1'],
                ['version' => '1.1'],
                ['key' => '', 'version' => '1.1'],
                ['key' => 'ER1', 'version' => '1.1'],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([['key' => 'ER1', 'version' => '1.1']], $read->dependsOn);
    }

    /**
     * A child that only adds is a child the parent's generators still run over.
     */
    public function testAChildThatOnlyAddsIsFine(): void
    {
        $parent = [['key' => 'c-entity', 'name' => 'Entity'], ['key' => 'c-page', 'name' => 'Page']];
        $child  = [...$parent, ['key' => 'c-layout', 'name' => 'Layout']];

        $this->assertSame([], AncestryCheck::problems($parent, $child));
    }

    /**
     * A child that drops a concept is refused, because models stop resolving.
     */
    public function testAChildThatDropsAConceptIsRefused(): void
    {
        $problems = AncestryCheck::problems(
            [['key' => 'c-entity', 'name' => 'Entity'], ['key' => 'c-page', 'name' => 'Page']],
            [['key' => 'c-entity', 'name' => 'Entity']]
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Page', $problems[0]);
        $this->assertStringContainsString('stop resolving', $problems[0]);
    }

    /**
     * And one that renames it, which is the failure a key-only check would miss.
     *
     * The data still resolves - a stored model points at `c-entity` and finds
     * it. The rules do not: a selector says `Entity`, the language now calls it
     * `Thing`, and every rule written for the parent fires zero times.
     */
    public function testAChildThatRenamesAConceptIsRefusedToo(): void
    {
        $problems = AncestryCheck::problems(
            [['key' => 'c-entity', 'name' => 'Entity']],
            [['key' => 'c-entity', 'name' => 'Thing']]
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('"Entity"', $problems[0]);
        $this->assertStringContainsString('"Thing"', $problems[0]);
        $this->assertStringContainsString('select nothing', $problems[0]);
    }

    /**
     * Every problem, not the first.
     *
     * Somebody fixing a language wants the list. The same reason
     * `PackageReader::problems()` is a list rather than an exception.
     */
    public function testItReportsEveryProblemAtOnce(): void
    {
        $problems = AncestryCheck::problems(
            [
                ['key' => 'c-entity', 'name' => 'Entity'],
                ['key' => 'c-page', 'name' => 'Page'],
                ['key' => 'c-field', 'name' => 'Field'],
            ],
            [['key' => 'c-entity', 'name' => 'Thing']]
        );

        $this->assertCount(3, $problems);
    }

    /**
     * A parent whose concepts carry no keys cannot be checked, and is not the
     * child's fault.
     *
     * Packages built before the manifest carried concepts have none at all, and
     * refusing a child for what its parent does not say would make deriving
     * from an older language impossible for a reason that has nothing to do
     * with the child.
     */
    public function testAParentWithNoKeysIsNotTheChildsProblem(): void
    {
        $this->assertSame(
            [],
            AncestryCheck::problems([['key' => '', 'name' => 'Entity']], [])
        );
    }

    /**
     * A concept that keeps its features is fine, and may add more.
     */
    public function testAChildMayAddFeatures(): void
    {
        $parent = [[
            'key'      => 'c-entity',
            'name'     => 'Entity',
            'features' => [['key' => 'f-name', 'name' => 'entity_name']],
        ]];

        $child = [[
            'key'      => 'c-entity',
            'name'     => 'Entity',
            'features' => [
                ['key' => 'f-name', 'name' => 'entity_name'],
                ['key' => 'f-colour', 'name' => 'colour'],
            ],
        ]];

        $this->assertSame([], AncestryCheck::problems($parent, $child));
    }

    /**
     * A feature the child dropped is refused: bindings walk through it.
     *
     * The same silence as a dropped concept, one level down. A rule binds a
     * variable by a path through the model, and a path that resolves to nothing
     * renders an empty string into a generated file - no error, no warning, a
     * file that looks finished.
     */
    public function testAChildThatDropsAFeatureIsRefused(): void
    {
        $problems = AncestryCheck::problems(
            [[
                'key'      => 'c-entity',
                'name'     => 'Entity',
                'features' => [
                    ['key' => 'f-name', 'name' => 'entity_name'],
                    ['key' => 'f-id', 'name' => 'entity_id'],
                ],
            ]],
            [[
                'key'      => 'c-entity',
                'name'     => 'Entity',
                'features' => [['key' => 'f-name', 'name' => 'entity_name']],
            ]]
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Entity.entity_id', $problems[0]);
        $this->assertStringContainsString('resolve to nothing', $problems[0]);
    }

    /**
     * And one that renames it, which is again the confusing half.
     *
     * The key is still there, so nothing about the stored data moved. The path
     * moved, because a path walks by name.
     */
    public function testAChildThatRenamesAFeatureIsRefusedToo(): void
    {
        $problems = AncestryCheck::problems(
            [[
                'key'      => 'c-entity',
                'name'     => 'Entity',
                'features' => [['key' => 'f-name', 'name' => 'entity_name']],
            ]],
            [[
                'key'      => 'c-entity',
                'name'     => 'Entity',
                'features' => [['key' => 'f-name', 'name' => 'title']],
            ]]
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Entity.entity_name', $problems[0]);
        $this->assertStringContainsString('"title"', $problems[0]);
        $this->assertStringContainsString('walk into nothing', $problems[0]);
    }

    /**
     * A parent that says nothing about features is not checked for them.
     *
     * That is every package built before this, and refusing on it would mean
     * nothing could derive from a language imported before today. An absent
     * list and an empty one are different things, which is the whole reason the
     * manifest writes `features: []` for a concept that genuinely has none.
     */
    public function testAParentThatListsNoFeaturesIsNotCheckedForThem(): void
    {
        $this->assertSame(
            [],
            AncestryCheck::problems(
                [['key' => 'c-entity', 'name' => 'Entity']],
                [['key' => 'c-entity', 'name' => 'Entity']]
            )
        );

        // But one that says it has none, and a child that agrees, is checked
        // and passes - which is what makes the distinction observable.
        $this->assertSame(
            [],
            AncestryCheck::problems(
                [['key' => 'c-entity', 'name' => 'Entity', 'features' => []]],
                [['key' => 'c-entity', 'name' => 'Entity', 'features' => []]]
            )
        );
    }

    /**
     * A child that drops the whole concept is reported once, not twice.
     *
     * The concept is gone, so its features are gone with it, and listing each
     * of them would bury the one sentence that matters under ten that follow
     * from it.
     */
    public function testAMissingConceptIsNotAlsoReportedFeatureByFeature(): void
    {
        $problems = AncestryCheck::problems(
            [[
                'key'      => 'c-entity',
                'name'     => 'Entity',
                'features' => [
                    ['key' => 'f-name', 'name' => 'entity_name'],
                    ['key' => 'f-id', 'name' => 'entity_id'],
                ],
            ]],
            []
        );

        $this->assertCount(1, $problems);
    }

    /**
     * A feature that moved to a supertype has not been removed.
     *
     * The manifest carries *effective* features - inherited included - because
     * the hierarchy is not in there and a child that tidied its inheritance has
     * broken nothing. Meta-gen resolves that before writing; this is the shape
     * that arrives here when it has.
     */
    public function testAFeatureInheritedRatherThanDeclaredStillCounts(): void
    {
        $parent = [[
            'key'      => 'c-property',
            'name'     => 'Property',
            'features' => [['key' => 'f-name', 'name' => 'field_name']],
        ]];

        // The child declares it on a supertype; what reaches here is the
        // effective list, which still has it.
        $child = [
            ['key' => 'c-field', 'name' => 'Field', 'features' => [['key' => 'f-name', 'name' => 'field_name']]],
            ['key' => 'c-property', 'name' => 'Property', 'features' => [['key' => 'f-name', 'name' => 'field_name']]],
        ];

        $this->assertSame([], AncestryCheck::problems($parent, $child));
    }

    /**
     * A little family of languages, resolvable the way a catalogue resolves one.
     *
     * @param  array<string, array<int, array{key: string, version: string}>>  $graph
     */
    private function ancestryOver(array $graph): Ancestry
    {
        $entries = [];

        foreach ($graph as $id => $dependsOn) {
            [$key, $version] = explode('|', $id);

            $entries[$id] = new MetalanguageEntry(
                $key,
                $version,
                $key,
                'Project',
                'media/yepr_metalanguages/' . $key . '/' . $version . '/',
                'language/en-GB/' . strtolower($key) . '.ini',
                false,
                0,
                [],
                '',
                $dependsOn
            );
        }

        return new Ancestry(
            static fn (string $key, string $version): ?MetalanguageEntry
                => $entries[$key . '|' . $version] ?? null
        );
    }

    /**
     * One language out of a graph, by the id the graph names it with.
     *
     * @param  array<string, array<int, array{key: string, version: string}>>  $graph
     */
    private function entry(array $graph, string $id): MetalanguageEntry
    {
        [$key, $version] = explode('|', $id);

        return new MetalanguageEntry($key, $version, $key, 'Project', '', '', false, 0, [], '', $graph[$id]);
    }

    /**
     * Ancestors come back nearest first, and the language itself is not one.
     */
    public function testAncestorsComeBackNearestFirst(): void
    {
        $graph = [
            'Child|1.0'  => [['key' => 'Middle', 'version' => '1.0']],
            'Middle|1.0' => [['key' => 'ER1', 'version' => '1.1']],
            'ER1|1.1'    => [],
        ];

        $ancestry = $this->ancestryOver($graph);
        $child    = $this->entry($graph, 'Child|1.0');

        $this->assertSame(
            ['Middle', 'ER1'],
            array_map(static fn (MetalanguageEntry $e): string => $e->key, $ancestry->of($child))
        );

        // And with itself, which is what "this language or one it derives from"
        // means everywhere it is asked.
        $this->assertSame(
            ['Child', 'Middle', 'ER1'],
            array_map(static fn (MetalanguageEntry $e): string => $e->key, $ancestry->withSelf($child))
        );
    }

    /**
     * A language reached twice appears once.
     *
     * Two parents that share a grandparent is the ordinary shape of this, not a
     * pathological one, and a list with the grandparent twice would offer its
     * concepts twice in every dropdown.
     */
    public function testALanguageReachedTwiceAppearsOnce(): void
    {
        $graph = [
            'Child|1.0' => [
                ['key' => 'Left', 'version' => '1.0'],
                ['key' => 'Right', 'version' => '1.0'],
            ],
            'Left|1.0'  => [['key' => 'ER1', 'version' => '1.1']],
            'Right|1.0' => [['key' => 'ER1', 'version' => '1.1']],
            'ER1|1.1'   => [],
        ];

        $ancestry = $this->ancestryOver($graph);

        $this->assertSame(
            ['Left', 'ER1', 'Right'],
            array_map(
                static fn (MetalanguageEntry $e): string => $e->key,
                $ancestry->of($this->entry($graph, 'Child|1.0'))
            )
        );
    }

    /**
     * Two versions of one language are two different parents.
     *
     * A language deriving from ER1 1.0 does not inherit what 1.1 added, and
     * treating the key as the identity would have made the guard on renames
     * meaningless - a child could satisfy it against whichever version happened
     * to be installed.
     */
    public function testTwoVersionsOfOneLanguageAreTwoParents(): void
    {
        $graph = [
            'Child|1.0' => [
                ['key' => 'ER1', 'version' => '1.0'],
                ['key' => 'ER1', 'version' => '1.1'],
            ],
            'ER1|1.0'   => [],
            'ER1|1.1'   => [],
        ];

        $ancestry = $this->ancestryOver($graph);

        $this->assertCount(2, $ancestry->of($this->entry($graph, 'Child|1.0')));
    }

    /**
     * A cycle is survived rather than thrown, and reported separately.
     *
     * A screen that cannot fill a dropdown until somebody fixes a language is a
     * screen nobody can fix a language on. So the walk returns the acyclic part
     * and `isCyclic()` is what says the rest.
     */
    public function testACycleIsSurvivedAndReported(): void
    {
        $graph = [
            'A|1.0' => [['key' => 'B', 'version' => '1.0']],
            'B|1.0' => [['key' => 'A', 'version' => '1.0']],
        ];

        $ancestry = $this->ancestryOver($graph);
        $a        = $this->entry($graph, 'A|1.0');

        $this->assertSame(
            ['B'],
            array_map(static fn (MetalanguageEntry $e): string => $e->key, $ancestry->of($a))
        );

        $this->assertTrue($ancestry->isCyclic($a));
    }

    /**
     * A language that derives from itself is the same case, one step shorter.
     */
    public function testALanguageThatDerivesFromItselfIsCyclic(): void
    {
        $graph    = ['A|1.0' => [['key' => 'A', 'version' => '1.0']]];
        $ancestry = $this->ancestryOver($graph);
        $a        = $this->entry($graph, 'A|1.0');

        $this->assertSame([], $ancestry->of($a));
        $this->assertTrue($ancestry->isCyclic($a));
    }

    /**
     * An honest ancestry is not cyclic, which is the guard under the two above.
     */
    public function testAnHonestAncestryIsNotCyclic(): void
    {
        $graph = [
            'Child|1.0' => [['key' => 'ER1', 'version' => '1.1']],
            'ER1|1.1'   => [],
        ];

        $ancestry = $this->ancestryOver($graph);

        $this->assertFalse($ancestry->isCyclic($this->entry($graph, 'Child|1.0')));
    }

    /**
     * A parent this site has not got is skipped, and named.
     *
     * Usable for everything except that parent's generators beats refusing to
     * open the language at all.
     */
    public function testAParentThatIsNotInstalledIsSkippedAndNamed(): void
    {
        $graph = [
            'Child|1.0' => [
                ['key' => 'ER1', 'version' => '1.1'],
                ['key' => 'Gone', 'version' => '2.0'],
            ],
            'ER1|1.1'   => [],
        ];

        $ancestry = $this->ancestryOver($graph);
        $child    = $this->entry($graph, 'Child|1.0');

        $this->assertSame(
            ['ER1'],
            array_map(static fn (MetalanguageEntry $e): string => $e->key, $ancestry->of($child))
        );

        $this->assertSame([['key' => 'Gone', 'version' => '2.0']], $ancestry->missing($child));
    }
}
