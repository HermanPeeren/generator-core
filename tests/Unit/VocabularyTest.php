<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Rule\Registry;
use Yepr\Gen\Core\Rule\RuleException;
use Yepr\Gen\Core\Rule\RuleSet;
use Yepr\Gen\Core\Rule\Vocabulary;

/**
 * What a target publishes so that something else can offer its language.
 *
 * The engine never needs this - it is handed the registries. An editor does,
 * and an editor must not have to load the target's code to find out what a rule
 * may say.
 */
final class VocabularyTest extends TestCase
{
    private function vocabulary(): Vocabulary
    {
        return Vocabulary::fromRegistries(
            'joomla6',
            (new Registry('selector'))
                ->register('entities', static fn (): array => [])
                ->register('root', static fn (): array => []),
            (new Registry('derivation'))
                ->register('entityName', static fn (): string => '')
                ->register('manyToMany', static fn (): array => []),
            ['b/Table.php.twig', 'a/fragments/m2m.php.twig']
        );
    }

    public function testItReadsTheRegistriesRatherThanBeingToldTwice(): void
    {
        $vocabulary = $this->vocabulary();

        $this->assertSame(['entities', 'root'], $vocabulary->selectors);
        $this->assertSame(['entityName', 'manyToMany'], $vocabulary->derivations);
    }

    /**
     * Sorted, so that registering a derivation does not reorder the committed
     * descriptor and produce a diff about nothing.
     */
    public function testEveryListIsSorted(): void
    {
        $vocabulary = $this->vocabulary();

        $this->assertSame(['a/fragments/m2m.php.twig', 'b/Table.php.twig'], $vocabulary->templates);
    }

    /**
     * The three fixed lists come from the library, and are written out so an
     * editor pinned to another version can see which it is being given.
     */
    public function testItCarriesTheLibrarysOwnVocabulariesToo(): void
    {
        $data = $this->vocabulary()->toArray();

        $this->assertSame(['has', 'missing', 'equals', 'notEquals'], $data['operators']);
        $this->assertSame(['literal', 'path', 'node', 'derive', 'fragments'], $data['bindingKinds']);
        $this->assertSame(['lower', 'upper', 'ucfirst', 'lcfirst'], $data['pathFilters']);
    }

    public function testItSurvivesJson(): void
    {
        $data = $this->vocabulary()->toArray();

        $this->assertSame($data, Vocabulary::fromArray(json_decode($this->vocabulary()->toJson(), true))->toArray());
    }

    public function testADescriptorWithoutATargetIsRefused(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('must name its target');

        Vocabulary::fromArray(['selectors' => [], 'derivations' => [], 'templates' => []]);
    }

    public function testADescriptorMissingAListIsRefused(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('has no "derivations" list');

        Vocabulary::fromArray(['target' => 'joomla6', 'selectors' => [], 'templates' => []]);
    }

    /**
     * A rule set can be checked against the descriptor alone.
     *
     * Which is what an editor does before saving: it has the vocabulary and the
     * rules, and no way to run either.
     */
    public function testItFindsWhatARuleSetNamesAndItDoesNotHave(): void
    {
        $problems = $this->vocabulary()->problems(RuleSet::fromArray([
            [
                'id'       => 'good',
                'for'      => 'entities',
                'template' => 'b/Table.php.twig',
                'target'   => 'src/{x}.php',
                'bind'     => [
                    'x'   => ['derive' => 'entityName'],
                    'm2m' => ['fragments' => 'manyToMany', 'template' => 'a/fragments/m2m.php.twig'],
                ],
            ],
            [
                'id'       => 'bad',
                'for'      => 'pages',
                'template' => 'Nothing.twig',
                'target'   => 'src/a.php',
                'bind'     => [
                    'y'   => ['derive' => 'noSuchDerivation'],
                    'm2m' => ['fragments' => 'manyToMany', 'template' => 'NoSuchFragment.twig'],
                ],
            ],
        ]));

        $this->assertCount(4, $problems, implode("\n", $problems));
        $this->assertStringContainsString('"pages" is not a selector of joomla6', $problems[0]);
        $this->assertStringContainsString('"Nothing.twig" is not a template of joomla6', $problems[1]);
        $this->assertStringContainsString('"noSuchDerivation", which is not a derivation', $problems[2]);
        $this->assertStringContainsString('"NoSuchFragment.twig", which is not a template', $problems[3]);
    }

    public function testASoundRuleSetHasNoProblems(): void
    {
        $problems = $this->vocabulary()->problems(RuleSet::fromArray([[
            'id'       => 'good',
            'for'      => 'root',
            'template' => 'b/Table.php.twig',
            'target'   => 'src/a.php',
            'when'     => [['operator' => 'missing', 'path' => 'isvalueobject']],
        ]]));

        $this->assertSame([], $problems);
    }

    /**
     * A vocabulary may say what a selector *is*, not only that it exists.
     *
     * The source half, added at 3.6. Before it, a vocabulary listed four names
     * and the meaning of each was a PHP closure in the target's own code,
     * written against one language's shape - so a generator modelled for
     * another language could name `entities` and get nothing.
     */
    public function testAVocabularyMaySayWhatASelectorIs(): void
    {
        $vocabulary = Vocabulary::fromArray([
            'target'        => 'joomla6',
            'selectors'     => ['root', 'entities'],
            'derivations'   => [],
            'templates'     => [],
            'selectorPaths' => ['entities' => [['contain' => 'datamodel']]],
        ]);

        $this->assertTrue($vocabulary->describes('entities'));
        $this->assertFalse($vocabulary->describes('root'), 'a selector may still be only a name');
        $this->assertSame([['contain' => 'datamodel']], $vocabulary->paths()['entities']);
    }

    /**
     * One written before 3.6 reads exactly as it did.
     *
     * A target generating from something other than a modelled language has
     * nowhere to put a path, so no paths is a real answer rather than a
     * migration left half done - and it round-trips to the descriptor it had
     * rather than to one carrying an empty object nobody wrote.
     */
    public function testAVocabularyWithNoPathsReadsAndWritesAsItDid(): void
    {
        $data = [
            'target'      => 'joomla6',
            'selectors'   => ['root'],
            'derivations' => [],
            'templates'   => [],
        ];

        $vocabulary = Vocabulary::fromArray($data);

        $this->assertSame([], $vocabulary->paths());
        $this->assertArrayNotHasKey('selectorPaths', $vocabulary->toArray());
    }

    /**
     * Describing a selector the vocabulary does not offer is refused.
     *
     * A path for a selector nothing may name is a path nothing will ever walk,
     * and the likeliest reason for one is a rename that changed the list and
     * not the paths beside it.
     */
    public function testDescribingASelectorItDoesNotOfferIsRefused(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessageMatches('/does not offer/');

        Vocabulary::fromArray([
            'target'        => 'joomla6',
            'selectors'     => ['root'],
            'derivations'   => [],
            'templates'     => [],
            'selectorPaths' => ['entities' => [['contain' => 'datamodel']]],
        ]);
    }

    /**
     * A language's concepts become selectors, on both sides at once.
     *
     * Gen-gen offers what a rule may select and Exten-gen validates what it
     * did, and `problems()` checks the name against this list. 3.4 had Gen-gen
     * offer a language's concepts without adding them here, so a rule written
     * that way was refused as naming a selector the target does not have - the
     * offering and the checking disagreeing about what a selector is.
     */
    public function testALanguagesConceptsBecomeSelectors(): void
    {
        $base = Vocabulary::fromArray([
            'target'      => 'joomla6',
            'selectors'   => ['root', 'entities'],
            'derivations' => [],
            'templates'   => [],
        ]);

        $seen = $base->withConcepts(['Entity', 'Page']);

        $this->assertSame(['Entity', 'Page', 'entities', 'root'], $seen->selectors);
        $this->assertTrue($seen->describes('Page'));
        $this->assertSame([['all' => 'Page']], $seen->paths()['Page']);

        // And the target keeps its own.
        $this->assertFalse($seen->describes('root'));
    }

    /**
     * A concept cannot quietly redefine a selector the target already has.
     */
    public function testAConceptCannotRedefineOneTheTargetAlreadyHas(): void
    {
        $base = Vocabulary::fromArray([
            'target'        => 'joomla6',
            'selectors'     => ['entities'],
            'derivations'   => [],
            'templates'     => [],
            'selectorPaths' => ['entities' => [['contain' => 'datamodel']]],
        ]);

        $seen = $base->withConcepts(['entities']);

        $this->assertSame([['contain' => 'datamodel']], $seen->paths()['entities']);
    }
}
