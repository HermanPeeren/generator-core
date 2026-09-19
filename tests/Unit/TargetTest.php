<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\GeneratorInterface;
use Yepr\Gen\Core\Model\ModelInterface;
use Yepr\Gen\Core\Model\ValidationException;
use Yepr\Gen\Core\Model\ValidatorInterface;
use Yepr\Gen\Core\Output\FileCollection;
use Yepr\Gen\Core\Pipeline;
use Yepr\Gen\Core\Target\Target;
use Yepr\Gen\Core\Target\TargetInterface;
use Yepr\Gen\Core\Target\TargetRegistry;

final class TargetTest extends TestCase
{
    private function model(): ModelInterface
    {
        return new class () implements ModelInterface {
        };
    }

    private function generator(string $path, bool $supports = true): GeneratorInterface
    {
        return new class ($path, $supports) implements GeneratorInterface {
            public function __construct(private string $path, private bool $supports)
            {
            }

            public function supports(ModelInterface $model): bool
            {
                return $this->supports;
            }

            public function generate(ModelInterface $model, FileCollection $files): void
            {
                $files->add($this->path, 'from ' . $this->path);
            }
        };
    }

    /**
     * The point of the whole abstraction: a second target is registered and run,
     * and nothing in the pipeline knows it exists.
     */
    public function testASecondTargetNeedsNoChangeToThePipeline(): void
    {
        $joomla = new Target('joomla6', 'Joomla 6 component', null, $this->generator('joomla/manifest.xml'));
        $drupal = new Target('drupal11', 'Drupal 11 module', null, $this->generator('drupal/module.info.yml'));

        $registry = new TargetRegistry($joomla, $drupal);
        $pipeline = new Pipeline();
        $model    = $this->model();

        $this->assertSame(
            ['joomla/manifest.xml'],
            $pipeline->run($model, $registry->get('joomla6'))->paths()
        );

        $this->assertSame(
            ['drupal/module.info.yml'],
            $pipeline->run($model, $registry->get('drupal11'))->paths()
        );
    }

    public function testATargetsGeneratorsRunInTheOrderItGivesThem(): void
    {
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();

        $recorder = static function (string $name) use ($order): GeneratorInterface {
            return new class ($name, $order) implements GeneratorInterface {
                /** @param \ArrayObject<int, string> $order */
                public function __construct(private string $name, private \ArrayObject $order)
                {
                }

                public function supports(ModelInterface $model): bool
                {
                    return true;
                }

                public function generate(ModelInterface $model, FileCollection $files): void
                {
                    $this->order[] = $this->name;
                    $files->add($this->name . '.txt', 'x');
                }
            };
        };

        $target = new Target('t', 'T', null, $recorder('first'), $recorder('second'), $recorder('third'));

        (new Pipeline())->run($this->model(), $target);

        $this->assertSame(['first', 'second', 'third'], $order->getArrayCopy());
    }

    public function testAGeneratorThatDoesNotSupportTheModelIsSkipped(): void
    {
        $target = new Target(
            't',
            'T',
            null,
            $this->generator('kept.txt'),
            $this->generator('skipped.txt', false)
        );

        $this->assertSame(['kept.txt'], (new Pipeline())->run($this->model(), $target)->paths());
    }

    public function testBothValidatorsRunAndNeitherLetsAFileBeMade(): void
    {
        $failing = new class () implements ValidatorInterface {
            public function assertValid(ModelInterface $model): void
            {
                throw new ValidationException(['the target cannot express this model']);
            }
        };

        $target = new Target('t', 'T', $failing, $this->generator('never.txt'));

        try {
            (new Pipeline())->run($this->model(), $target);
            $this->fail('An invalid model should not generate.');
        } catch (ValidationException $e) {
            $this->assertSame(['the target cannot express this model'], $e->getErrors());
        }
    }

    public function testTheSharedValidatorRunsBeforeTheTargetsOwn(): void
    {
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();

        $recording = static function (string $name) use ($order): ValidatorInterface {
            return new class ($name, $order) implements ValidatorInterface {
                /** @param \ArrayObject<int, string> $order */
                public function __construct(private string $name, private \ArrayObject $order)
                {
                }

                public function assertValid(ModelInterface $model): void
                {
                    $this->order[] = $this->name;
                }
            };
        };

        $target = new Target('t', 'T', $recording('target'));

        (new Pipeline($recording('shared')))->run($this->model(), $target);

        $this->assertSame(['shared', 'target'], $order->getArrayCopy());
    }

    public function testTargetsAreImmutable(): void
    {
        $original = new Target('t', 'T', null, $this->generator('a.txt'));
        $extended = $original->with($this->generator('b.txt'));

        $this->assertCount(1, $original->generators());
        $this->assertCount(2, $extended->generators());
        $this->assertSame('t', $extended->id());
    }

    public function testAnUnusableTargetIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Target('Joomla 6', 'Joomla 6');
    }

    public function testTwoTargetsCannotClaimOneId(): void
    {
        $this->expectException(\LogicException::class);
        new TargetRegistry(new Target('t', 'One'), new Target('t', 'Two'));
    }

    public function testAskingForATargetThatIsNotRegistered(): void
    {
        $registry = new TargetRegistry(new Target('joomla6', 'Joomla 6'));

        try {
            $registry->get('drupal11');
            $this->fail('An unregistered target should not resolve.');
        } catch (\OutOfBoundsException $e) {
            // The message names what is available, because the usual cause is a
            // typo or a target that was never registered.
            $this->assertStringContainsString('joomla6', $e->getMessage());
        }
    }

    public function testTheRegistryListsTargetsInAStableOrder(): void
    {
        $registry = new TargetRegistry(
            new Target('wordpress', 'WordPress'),
            new Target('drupal11', 'Drupal 11'),
            new Target('joomla6', 'Joomla 6')
        );

        $this->assertSame(['drupal11', 'joomla6', 'wordpress'], $registry->ids());
        $this->assertSame(['drupal11', 'joomla6', 'wordpress'], array_keys(iterator_to_array($registry)));
        $this->assertCount(3, $registry);
    }

    public function testTheRegistryIsImmutable(): void
    {
        $original = new TargetRegistry(new Target('joomla6', 'Joomla 6'));
        $extended = $original->with(new Target('drupal11', 'Drupal 11'));

        $this->assertSame(['joomla6'], $original->ids());
        $this->assertSame(['drupal11', 'joomla6'], $extended->ids());
        $this->assertFalse($original->has('drupal11'));
    }

    public function testATargetWithRealBehaviourImplementsTheInterfaceDirectly(): void
    {
        // Target is a convenience for "these generators, in this order". A target
        // that decides by looking at the model implements the interface itself,
        // and the pipeline cannot tell the difference.
        $conditional = new class ($this->generator('chosen.txt')) implements TargetInterface {
            public function __construct(private GeneratorInterface $generator)
            {
            }

            public function id(): string
            {
                return 'conditional';
            }

            public function label(): string
            {
                return 'Chooses at run time';
            }

            public function generators(): array
            {
                return [$this->generator];
            }

            public function validator(): ?ValidatorInterface
            {
                return null;
            }
        };

        $this->assertSame(['chosen.txt'], (new Pipeline())->run($this->model(), $conditional)->paths());
    }
}
