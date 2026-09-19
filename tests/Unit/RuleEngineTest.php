<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Rule\Registry;
use Yepr\Gen\Core\Rule\Rule;
use Yepr\Gen\Core\Rule\RuleEngine;
use Yepr\Gen\Core\Rule\RuleException;
use Yepr\Gen\Core\Rule\RuleSet;
use Yepr\Gen\Core\Rule\RuleSetValidator;
use Yepr\Gen\Core\Template\RendererInterface;

/**
 * Running a rule set.
 *
 * The renderer here reports what it was asked to render rather than rendering
 * it, because what is under test is the walk: which nodes, in which order, with
 * which variables. What Twig does with those variables is TwigRendererTest's
 * problem.
 */
final class RuleEngineTest extends TestCase
{
    private function model(): object
    {
        return json_decode(
            '{"name":"Planning","datamodel":['
            . '{"entity_name":"flight","related":[{"to":"balloon"},{"to":"pilot"}]},'
            . '{"entity_name":"colour","isvalueobject":null}]}',
            false,
            512,
            \JSON_THROW_ON_ERROR
        );
    }

    /**
     * A renderer that writes down its arguments instead of rendering them.
     */
    private function renderer(): RendererInterface
    {
        return new class () implements RendererInterface {
            public function render(string $template, array $variables = []): string
            {
                ksort($variables);

                $pairs = [];

                foreach ($variables as $name => $value) {
                    $pairs[] = $name . '=' . (\is_scalar($value) ? (string) $value : json_encode($value));
                }

                return $template . '(' . implode(',', $pairs) . ')';
            }
        };
    }

    private function selectors(): Registry
    {
        return (new Registry('selector'))
            ->register('root', static fn (object $model): array => [$model])
            ->register('entities', static fn (object $model): array => $model->datamodel);
    }

    private function derivations(): Registry
    {
        return (new Registry('derivation'))
            ->register('entityName', static fn (object $node): string => ucfirst($node->entity_name))
            ->register('projectName', static fn (mixed $node, object $model): string => $model->name)
            ->register(
                'related',
                static fn (object $node): array => array_map(
                    static fn (object $r): array => ['relatedName' => $r->to],
                    $node->related ?? []
                )
            );
    }

    private function engine(): RuleEngine
    {
        return new RuleEngine($this->renderer(), $this->selectors(), $this->derivations());
    }

    /**
     * @return array<string, string>  Path => contents.
     */
    private function filesFrom(RuleSet $rules): array
    {
        $written = [];

        $this->engine()->run(
            $rules,
            $this->model(),
            static function (string $path, string $contents) use (&$written): void {
                $written[$path] = $contents;
            }
        );

        return $written;
    }

    public function testARuleProducesOneFilePerSelectedNode(): void
    {
        $written = $this->filesFrom(RuleSet::fromArray([[
            'id'       => 'table',
            'for'      => 'entities',
            'template' => 'Table.php.twig',
            'target'   => 'src/Table/{entityName}Table.php',
            'bind'     => ['entityName' => ['derive' => 'entityName']],
        ]]));

        $this->assertSame(
            ['src/Table/FlightTable.php', 'src/Table/ColourTable.php'],
            array_keys($written)
        );
    }

    public function testAConditionKeepsARuleFromFiring(): void
    {
        $written = $this->filesFrom(RuleSet::fromArray([[
            'id'       => 'table',
            'for'      => 'entities',
            'when'     => [['operator' => 'missing', 'path' => 'isvalueobject']],
            'template' => 'Table.php.twig',
            'target'   => 'src/Table/{entityName}Table.php',
            'bind'     => ['entityName' => ['derive' => 'entityName']],
        ]]));

        $this->assertSame(['src/Table/FlightTable.php'], array_keys($written));
    }

    public function testBindingsReachTheTemplate(): void
    {
        $written = $this->filesFrom(RuleSet::fromArray([[
            'id'       => 'table',
            'for'      => 'entities',
            'when'     => [['operator' => 'equals', 'path' => 'entity_name', 'value' => 'flight']],
            'template' => 'Table.php.twig',
            'target'   => '{entityName}.php',
            'bind'     => [
                'entityName'  => ['derive' => 'entityName'],
                'projectName' => ['derive' => 'projectName'],
                'raw'         => ['node' => 'entity_name'],
                'top'         => ['path' => 'name'],
                'getFK'       => ['literal' => ''],
            ],
        ]]));

        $this->assertSame(
            'Table.php.twig(entityName=Flight,getFK=,projectName=Planning,raw=flight,top=Planning)',
            $written['Flight.php']
        );
    }

    /**
     * A block runs node-major: everything for one node, then the next.
     *
     * Emitting every controller and then every model would produce the same
     * files, so nothing about the file set would catch a change here. What it
     * would change is the order in which templates run, and templates register
     * language strings as they go.
     */
    public function testOneBlockRunsNodeMajor(): void
    {
        $written = $this->filesFrom(RuleSet::fromArray([
            [
                'id'       => 'a',
                'for'      => 'entities',
                'template' => 'A.twig',
                'target'   => '{entityName}A.php',
                'bind'     => ['entityName' => ['derive' => 'entityName']],
            ],
            [
                'id'       => 'b',
                'for'      => 'entities',
                'template' => 'B.twig',
                'target'   => '{entityName}B.php',
                'bind'     => ['entityName' => ['derive' => 'entityName']],
            ],
        ]));

        $this->assertSame(
            ['FlightA.php', 'FlightB.php', 'ColourA.php', 'ColourB.php'],
            array_keys($written)
        );
    }

    /**
     * Fragments repeat a smaller template inside the file and see its variables.
     */
    public function testFragmentsRepeatAndSeeTheRulesOtherBindings(): void
    {
        $written = $this->filesFrom(RuleSet::fromArray([[
            'id'       => 'table',
            'for'      => 'entities',
            'when'     => [['operator' => 'has', 'path' => 'related']],
            'template' => 'Table.twig',
            'target'   => '{entityName}.php',
            'bind'     => [
                'entityName' => ['derive' => 'entityName'],
                'bindings'   => ['fragments' => 'related', 'template' => 'bind.twig', 'glue' => '|'],
            ],
        ]]));

        $this->assertSame(
            'Table.twig(bindings=bind.twig(entityName=Flight,relatedName=balloon)'
            . '|bind.twig(entityName=Flight,relatedName=pilot),entityName=Flight)',
            $written['Flight.php']
        );
    }

    public function testAnUnknownSelectorNamesTheRule(): void
    {
        $this->expectException(RuleException::class);
        $this->expectExceptionMessage('rule table: unknown selector "entitys"');

        $this->filesFrom(RuleSet::fromArray([[
            'id'       => 'table',
            'for'      => 'entitys',
            'template' => 't',
            'target'   => 'a.php',
        ]]));
    }

    /**
     * The whole set is checked before anything runs, and every problem is
     * reported at once - a rule file is edited in bulk.
     */
    public function testTheValidatorFindsEveryProblemAtOnce(): void
    {
        $problems = (new RuleSetValidator($this->selectors(), $this->derivations()))->problems(
            RuleSet::fromArray([
                [
                    'id'       => 'one',
                    'for'      => 'pages',
                    'template' => 't',
                    'target'   => 'src/{entityName}.php',
                ],
                [
                    'id'       => 'two',
                    'for'      => 'entities',
                    'template' => 't',
                    'target'   => '../outside.php',
                    'bind'     => ['x' => ['derive' => 'nosuchthing']],
                ],
            ])
        );

        $this->assertCount(4, $problems);
        $this->assertStringContainsString('rule one: unknown selector "pages"', $problems[0]);
        $this->assertStringContainsString('target path uses {entityName}', $problems[1]);
        $this->assertStringContainsString('"nosuchthing", which is not registered', $problems[2]);
        $this->assertStringContainsString('leaves the package', $problems[3]);
    }

    public function testTheValidatorPassesASoundSet(): void
    {
        $rules = RuleSet::fromArray([[
            'id'       => 'table',
            'for'      => 'entities',
            'template' => 't',
            'target'   => 'src/{entityName}.php',
            'bind'     => ['entityName' => ['derive' => 'entityName']],
        ]]);

        (new RuleSetValidator($this->selectors(), $this->derivations()))->assertValid($rules);

        $this->assertCount(1, $rules);
        $this->assertInstanceOf(Rule::class, iterator_to_array($rules)[0]);
    }
}
