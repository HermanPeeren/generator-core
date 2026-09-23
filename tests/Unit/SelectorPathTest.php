<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Reference\ReferenceIndex;
use Yepr\Gen\Core\Rule\DataSelectors;
use Yepr\Gen\Core\Rule\RuleException;
use Yepr\Gen\Core\Rule\SelectorPath;

/**
 * Selectors as data: step 3.6.
 *
 * A selector used to be a PHP closure a target registered, reaching into one
 * language's shape by name. A path is the same answer written down, so a
 * generator modelled for some other metalanguage can name a selector that means
 * something in *that* language.
 *
 * **The case that decides the design is the join.** ER1's back-end pages are
 * not inside the component: `Sections.backendsection` holds references, and the
 * pages themselves live in a flat list beside them. A dotted string cannot
 * express that, which is why a step is an object and one kind of step follows a
 * reference. If a path could not do this, targets would keep a PHP closure for
 * the interesting selectors and data for the easy ones, which is worse than
 * either.
 *
 * @since  0.8.0
 */
final class SelectorPathTest extends TestCase
{
    /**
     * A project shaped like ER1: entities, pages, and sections pointing at them.
     */
    private function project(): object
    {
        return (object) [
            'datamodel' => (object) [
                'datamodel0' => (object) ['entity_name' => 'Balloon', 'entity_id' => 'e1'],
                'datamodel1' => (object) ['entity_name' => 'Flight', 'entity_id' => 'e2'],
            ],
            'pages' => (object) [
                'pages0' => (object) ['page_name' => 'Balloons', 'page_id' => 'p1'],
                'pages1' => (object) ['page_name' => 'One balloon', 'page_id' => 'p2'],
                'pages2' => (object) ['page_name' => 'Public list', 'page_id' => 'p3'],
            ],
            'extensions' => (object) [
                'component' => (object) [
                    'Sections' => (object) [
                        'backendsection' => (object) [
                            // Deliberately not in page order: what a selector
                            // yields is the order the references are in, and
                            // that order reaches the output.
                            'backendsection0' => (object) ['page_reference' => 'p2'],
                            'backendsection1' => (object) ['page_reference' => 'p1'],
                            // A reference to a page somebody deleted.
                            'backendsection2' => (object) ['page_reference' => 'gone'],
                        ],
                        'frontendsection' => (object) [
                            'frontendsection0' => (object) ['page_reference' => 'p3'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * ER1's reference table, as its package carries it.
     */
    private function references(): ReferenceIndex
    {
        return ReferenceIndex::fromTable([
            'Page'   => ['path' => ['pages'], 'idKey' => 'page_id', 'nameKey' => 'page_name'],
            'Entity' => ['path' => ['datamodel'], 'idKey' => 'entity_id', 'nameKey' => 'entity_name'],
        ]);
    }

    /**
     * No steps is the model itself, which is what `root` means.
     */
    public function testNoStepsIsTheModelItself(): void
    {
        $model = $this->project();

        $this->assertSame([$model], SelectorPath::fromArray([])->walk($model));
    }

    /**
     * One step into a containment yields its rows, in model order.
     */
    public function testAContainmentYieldsItsRowsInOrder(): void
    {
        $nodes = SelectorPath::fromArray([['contain' => 'datamodel']])->walk($this->project());

        $this->assertSame(
            ['Balloon', 'Flight'],
            array_map(static fn (object $n): string => $n->entity_name, $nodes)
        );
    }

    /**
     * A containment nobody filled in yields nothing, and is not an error.
     *
     * A project with no entities is a project somebody has just started.
     */
    public function testAnEmptyContainmentYieldsNothing(): void
    {
        $path = SelectorPath::fromArray([['contain' => 'datamodel']]);

        $this->assertSame([], $path->walk((object) []));
        $this->assertSame([], $path->walk((object) ['datamodel' => (object) []]));
    }

    /**
     * The join: following a reference reaches the thing pointed at.
     *
     * This is the selector the imperative generator built inline, twice, with a
     * page map rebuilt at the top of each - which is why "the back-end pages"
     * was something you re-derived rather than something you could name.
     */
    public function testFollowingAReferenceReachesWhatItPointsAt(): void
    {
        $nodes = SelectorPath::fromArray([
            ['contain' => 'extensions'],
            ['contain' => 'component'],
            ['contain' => 'Sections'],
            ['contain' => 'backendsection'],
            ['follow' => 'page_reference', 'to' => 'Page'],
        ])->walk($this->project(), $this->references());

        // In the order the references are in, not the order the pages are in -
        // and the dangling one is skipped rather than fatal, because the forms
        // can produce one by deleting a page a section still names.
        $this->assertSame(
            ['One balloon', 'Balloons'],
            array_map(static fn (object $n): string => $n->page_name, $nodes)
        );
    }

    /**
     * Following a type the language does not have says so.
     */
    public function testFollowingATypeTheLanguageDoesNotHaveSaysSo(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessageMatches('/does not have/');

        SelectorPath::fromArray([['follow' => 'thing_reference', 'to' => 'Thing']])
            ->walk($this->project(), $this->references());
    }

    /**
     * And following one with no table at all.
     */
    public function testFollowingWithNoTableSaysSo(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessageMatches('/no reference table/');

        SelectorPath::fromArray([['follow' => 'page_reference', 'to' => 'Page']])
            ->walk($this->project());
    }

    /**
     * A step that is neither kind is refused when the path is read.
     *
     * Refused rather than skipped, because a selector that quietly ignored a
     * step would return the wrong nodes and look like it worked - and it would
     * do it during a generation run rather than while somebody was looking at
     * the language.
     */
    public function testAStepThatIsNeitherKindIsRefused(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessageMatches('/neither/');

        SelectorPath::fromArray([['descend' => 'datamodel']], 'entities');
    }

    /**
     * The registry the engine calls is built from the paths.
     *
     * `RuleEngine` asks a `Registry` for nodes and does not care where the
     * answer comes from, which is the seam that lets a selector stop being PHP
     * without the engine changing at all.
     */
    public function testTheRegistryTheEngineCallsIsBuiltFromPaths(): void
    {
        $registry = DataSelectors::registry(
            [
                'root'         => [],
                'entities'     => [['contain' => 'datamodel']],
                'backendPages' => [
                    ['contain' => 'extensions'],
                    ['contain' => 'component'],
                    ['contain' => 'Sections'],
                    ['contain' => 'backendsection'],
                    ['follow' => 'page_reference', 'to' => 'Page'],
                ],
            ],
            $this->references()
        );

        $this->assertTrue($registry->has('entities'));
        $this->assertTrue($registry->has('backendPages'));

        $model = $this->project();

        $this->assertCount(1, $registry->call('root', [$model], 'r1'));
        $this->assertCount(2, $registry->call('entities', [$model], 'r1'));
        $this->assertCount(2, $registry->call('backendPages', [$model], 'r1'));
    }

    /**
     * A path that is not one is reported when the registry is built.
     *
     * While somebody is looking at the language, rather than half way through
     * generating from it.
     */
    public function testABadPathIsReportedWhenTheRegistryIsBuilt(): void
    {
        $this->expectException(RuleException::class);

        DataSelectors::registry(['entities' => [['descend' => 'datamodel']]]);
    }
}
