<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Rule\Binding;
use Yepr\Gen\Core\Rule\Condition;
use Yepr\Gen\Core\Rule\Interpolator;
use Yepr\Gen\Core\Rule\PathReader;
use Yepr\Gen\Core\Rule\Rule;
use Yepr\Gen\Core\Rule\RuleException;
use Yepr\Gen\Core\Rule\RuleSet;

/**
 * The pieces a rule is made of.
 *
 * Each of these is small enough to look obvious, and each of them replaces a
 * line of imperative generator code that was not obvious at all - a
 * `property_exists($entity, 'isvalueobject')` buried in a nested loop, a
 * `strtolower($componentName)` concatenated into a path four statements away
 * from the path's other half.
 */
final class RuleTest extends TestCase
{
    /**
     * A model as it arrives: decoded JSON, so objects all the way down.
     */
    private function model(): object
    {
        return json_decode(
            '{"name":"BalloonPlanning","extensions":{"component":{"manifest":{"version":"1.2.3","copyright":"Yepr"}}},'
            . '"datamodel":[{"entity_name":"flight"},{"entity_name":"colour","isvalueobject":null}]}',
            false,
            512,
            \JSON_THROW_ON_ERROR
        );
    }

    public function testItReadsADottedPath(): void
    {
        $this->assertSame('1.2.3', PathReader::read($this->model(), 'extensions.component.manifest.version'));
    }

    public function testAMissingStepReadsAsNullRatherThanFailing(): void
    {
        $this->assertNull(PathReader::read($this->model(), 'extensions.module.manifest.version'));
    }

    public function testItWalksArraysAndObjectsAlike(): void
    {
        $this->assertSame('flight', PathReader::read($this->model(), 'datamodel.0.entity_name'));
        $this->assertSame('b', PathReader::read(['a' => ['b' => 'b']], 'a.b'));
    }

    /**
     * Presence and truth are different questions.
     *
     * The model marks an entity as a value object by *having* `isvalueobject`,
     * whose value is null. Anything that asked "is it truthy" would get this
     * backwards for every embeddable in every project.
     */
    public function testPresenceIsNotTheSameAsTruth(): void
    {
        $colour = $this->model()->datamodel[1];

        $this->assertTrue(PathReader::has($colour, 'isvalueobject'));
        $this->assertNull(PathReader::read($colour, 'isvalueobject'));
    }

    public function testConditionsTestPresenceAndValue(): void
    {
        $flight = $this->model()->datamodel[0];
        $colour = $this->model()->datamodel[1];

        $isEntity = Condition::fromArray(['operator' => 'missing', 'path' => 'isvalueobject'], 'r');

        $this->assertTrue($isEntity->matches($flight));
        $this->assertFalse($isEntity->matches($colour));

        $named = Condition::fromArray(['operator' => 'equals', 'path' => 'entity_name', 'value' => 'flight'], 'r');

        $this->assertTrue($named->matches($flight));
        $this->assertFalse($named->matches($colour));
    }

    public function testAnUnknownOperatorIsRefusedWithTheRuleNamed(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('rule admin.table: unknown condition operator "roughly"');

        Condition::fromArray(['operator' => 'roughly', 'path' => 'x'], 'admin.table');
    }

    public function testAPathIsBuiltFromBoundVariables(): void
    {
        $this->assertSame(
            'administrator/components/com_balloonplanning/src/Table/FlightTable.php',
            Interpolator::expand(
                'administrator/components/com_{componentName|lower}/src/Table/{entityName|ucfirst}Table.php',
                ['componentName' => 'BalloonPlanning', 'entityName' => 'flight'],
                'admin.table'
            )
        );
    }

    /**
     * A placeholder nothing binds is a mistake, not an empty string.
     *
     * Expanding it to nothing puts the file somewhere else, where it installs
     * and does nothing, which is the hardest kind of wrong to notice.
     */
    public function testAnUnboundPlaceholderThrows(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('uses {entityName}, which the rule does not bind');

        Interpolator::expand('src/{entityName}Table.php', ['componentName' => 'x'], 'admin.table');
    }

    public function testAnUnknownFilterThrows(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('uses the filter |snake');

        Interpolator::expand('src/{name|snake}.php', ['name' => 'x'], 'r');
    }

    public function testABindingNamesExactlyOneKind(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('binding "componentName" must name exactly one of');

        Binding::fromArray(['path' => 'name', 'literal' => 'x'], 'componentName', 'r');
    }

    public function testFragmentsNeedATemplate(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('is fragments but names no template');

        Binding::fromArray(['fragments' => 'manyToMany'], 'm2m_bind', 'r');
    }

    /**
     * Rules round-trip, because Gen-gen will read them, edit them and write
     * them back. Anything lost here is something an edit deletes in silence.
     */
    public function testARuleSetSurvivesJson(): void
    {
        $data = [
            [
                'id'       => 'admin.table',
                'for'      => 'entities',
                'template' => 'src/Table/Table.php.twig',
                'target'   => 'src/Table/{entityName}Table.php',
                'when'     => [['operator' => 'missing', 'path' => 'isvalueobject']],
                'bind'     => [
                    'entityName' => ['derive' => 'entityName'],
                    'copyright'  => ['path' => 'extensions.component.manifest.copyright'],
                    'getFK'      => ['literal' => ''],
                    'm2m_bind'   => ['fragments' => 'manyToMany', 'template' => 'fragments/m2m_bind.php.twig'],
                ],
            ],
        ];

        $this->assertSame($data, RuleSet::fromArray($data)->toArray());
        $this->assertSame($data, RuleSet::fromJson(RuleSet::fromArray($data)->toJson())->toArray());
    }

    public function testTwoRulesCannotShareAnId(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('two rules share the id "a"');

        RuleSet::fromArray([
            ['id' => 'a', 'for' => 'root', 'template' => 't', 'target' => 'a'],
            ['id' => 'a', 'for' => 'root', 'template' => 't', 'target' => 'b'],
        ]);
    }

    /**
     * Consecutive rules over the same selector are one block.
     *
     * Which is what makes a page emit its controller, model and view together
     * rather than the run emitting every controller and then every model. The
     * difference is invisible in the file set and very visible in any file that
     * accumulates while templates render, such as a language file.
     */
    public function testConsecutiveRulesOverOneSelectorFormABlock(): void
    {
        $set = RuleSet::fromArray([
            ['id' => 'a', 'for' => 'pages', 'template' => 't', 'target' => 'a'],
            ['id' => 'b', 'for' => 'pages', 'template' => 't', 'target' => 'b'],
            ['id' => 'c', 'for' => 'root', 'template' => 't', 'target' => 'c'],
            ['id' => 'd', 'for' => 'pages', 'template' => 't', 'target' => 'd'],
        ]);

        $blocks = $set->blocks();

        $this->assertCount(3, $blocks);
        $this->assertSame('pages', $blocks[0][0]);
        $this->assertCount(2, $blocks[0][1]);
        $this->assertSame('root', $blocks[1][0]);
        $this->assertSame('pages', $blocks[2][0]);
    }

    public function testAPrefixSelectsOneGeneratorsShareInOrder(): void
    {
        $set = RuleSet::fromArray([
            ['id' => 'admin.mvc.controller', 'for' => 'pages', 'template' => 't', 'target' => 'a'],
            ['id' => 'site.mvc.controller', 'for' => 'pages', 'template' => 't', 'target' => 'b'],
            ['id' => 'admin.mvc.model', 'for' => 'pages', 'template' => 't', 'target' => 'c'],
        ]);

        $ids = array_map(static fn (Rule $r): string => $r->id, iterator_to_array($set->withPrefix('admin.mvc.')));

        $this->assertSame(['admin.mvc.controller', 'admin.mvc.model'], $ids);
    }
}
