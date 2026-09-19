<?php

/**
 * @package     Yepr Gen Library
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core;

use Yepr\Gen\Core\Model\ModelInterface;
use Yepr\Gen\Core\Model\ValidatorInterface;
use Yepr\Gen\Core\Output\FileCollection;

/**
 * Validates a model and runs every applicable generator over it.
 *
 * The result is held in memory; persisting it is somebody else's job. Nothing
 * here writes a file, which is what lets a whole generation run be asserted in a
 * unit test without a temp directory.
 *
 * Generators run in the order given. Order is not an implementation detail: a
 * generator that inventories what others produced - a manifest listing the
 * folders that were generated - has to run after them.
 *
 * Deliberately not here: any notion of *which* generators a given target or type
 * needs. That is the Target abstraction, and it arrives at step 0.4. Until then
 * a caller assembles the list itself, which is honest about what this class
 * currently does.
 *
 * @since  0.2.0
 */
final class Pipeline
{
    /**
     * The generators to run, in order.
     *
     * @var    GeneratorInterface[]
     * @since  0.2.0
     */
    private array $generators;

    /**
     * The validator to run before generating, if any.
     *
     * @var    ?ValidatorInterface
     * @since  0.2.0
     */
    private ?ValidatorInterface $validator;

    /**
     * Constructor.
     *
     * Variadic rather than an array parameter so that PHP itself rejects a list
     * holding something that is not a generator, at the boundary, with a clear
     * TypeError. A hand-written check would say the same thing later and add a
     * branch no test can reach through a typed call.
     *
     * A caller holding an array spreads it: new Pipeline(...$generators).
     *
     * @param   GeneratorInterface  ...$generators  The generators to run, in order.
     *
     * @since   0.2.0
     */
    public function __construct(GeneratorInterface ...$generators)
    {
        $this->generators = array_values($generators);
        $this->validator  = null;
    }

    /**
     * A copy of this pipeline that validates before generating.
     *
     * @param   ?ValidatorInterface  $validator  Checked before generating; null to skip.
     *
     * @return  self  A new pipeline.
     *
     * @since   0.2.0
     */
    public function withValidator(?ValidatorInterface $validator): self
    {
        $clone            = new self(...$this->generators);
        $clone->validator = $validator;

        return $clone;
    }

    /**
     * A copy of this pipeline with a generator appended.
     *
     * The pipeline is immutable so that one assembled pipeline can be reused
     * across runs without a caller's addition leaking into someone else's.
     *
     * @param   GeneratorInterface  $generator  The generator to append.
     *
     * @return  self  A new pipeline.
     *
     * @since   0.2.0
     */
    public function with(GeneratorInterface $generator): self
    {
        $clone            = new self(...[...$this->generators, $generator]);
        $clone->validator = $this->validator;

        return $clone;
    }

    /**
     * The generators this pipeline will run, in order.
     *
     * @return  GeneratorInterface[]  The generators.
     *
     * @since   0.2.0
     */
    public function generators(): array
    {
        return $this->generators;
    }

    /**
     * Validate a model and run every applicable generator over it.
     *
     * @param   ModelInterface  $model  The source model.
     *
     * @return  FileCollection  The generated files, in memory.
     *
     * @throws  Model\ValidationException  When the model cannot be generated from.
     *
     * @since   0.2.0
     */
    public function run(ModelInterface $model): FileCollection
    {
        // Validation runs first and completely: generation either produces the
        // whole file set or produces nothing.
        $this->validator?->assertValid($model);

        $files = new FileCollection();

        foreach ($this->generators as $generator) {
            if ($generator->supports($model)) {
                $generator->generate($model, $files);
            }
        }

        return $files;
    }
}
