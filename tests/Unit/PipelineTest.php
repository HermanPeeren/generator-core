<?php

declare(strict_types=1);

namespace Yepr\GeneratorCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\GeneratorCore\GeneratorInterface;
use Yepr\GeneratorCore\Model\ModelInterface;
use Yepr\GeneratorCore\Model\ValidationException;
use Yepr\GeneratorCore\Model\ValidatorInterface;
use Yepr\GeneratorCore\Output\FileCollection;
use Yepr\GeneratorCore\Pipeline;

final class PipelineTest extends TestCase
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

    public function testEveryApplicableGeneratorContributes(): void
    {
        $files = (new Pipeline($this->generator('a.php'), $this->generator('b.php')))->run($this->model());

        $this->assertSame(['a.php', 'b.php'], $files->paths());
    }

    public function testAGeneratorThatDoesNotSupportTheModelIsSkipped(): void
    {
        $files = (new Pipeline(
            $this->generator('a.php'),
            $this->generator('skipped.php', false),
        ))->run($this->model());

        $this->assertSame(['a.php'], $files->paths());
    }

    public function testGeneratorsRunInTheOrderGiven(): void
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
                    $files->add($this->name . '.php', 'x');
                }
            };
        };

        (new Pipeline($recorder('first'), $recorder('second')))->run($this->model());

        $this->assertSame(['first', 'second'], $order->getArrayCopy());
    }

    public function testAnInvalidModelStopsEverythingBeforeAnyFileIsMade(): void
    {
        $validator = new class () implements ValidatorInterface {
            public function assertValid(ModelInterface $model): void
            {
                throw new ValidationException(['the name is missing', 'the target is unknown']);
            }
        };

        $pipeline = (new Pipeline($this->generator('a.php')))->withValidator($validator);

        try {
            $pipeline->run($this->model());
            $this->fail('An invalid model should not generate.');
        } catch (ValidationException $e) {
            $this->assertSame(['the name is missing', 'the target is unknown'], $e->getErrors());
        }
    }

    public function testThePipelineIsImmutable(): void
    {
        $original = new Pipeline($this->generator('a.php'));
        $extended = $original->with($this->generator('b.php'));

        $this->assertCount(1, $original->generators());
        $this->assertCount(2, $extended->generators());
        $this->assertSame(['a.php'], $original->run($this->model())->paths());
    }
}
