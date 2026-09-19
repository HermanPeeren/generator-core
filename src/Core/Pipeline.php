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
use Yepr\Gen\Core\Target\TargetInterface;

/**
 * Runs a model through a target and returns the files that result.
 *
 * The pipeline knows nothing about what is being generated. It asks the target
 * which generators to run and in what order, which is what makes adding a target
 * a matter of registering one rather than of changing this class.
 *
 * The result is held in memory; persisting it is somebody else's job. Nothing
 * here writes a file, which is what lets a whole generation run be asserted in a
 * unit test without a temp directory.
 *
 * @since  0.4.0
 */
final class Pipeline
{
    /**
     * A validator that runs for every target, before the target's own.
     *
     * For what must be true of a model wherever it is going. Rules that depend
     * on the output belong to the target, which knows what it can express.
     *
     * @var    ?ValidatorInterface
     * @since  0.4.0
     */
    private ?ValidatorInterface $validator;

    /**
     * Constructor.
     *
     * @param   ?ValidatorInterface  $validator  Checked before any target's own validator.
     *
     * @since   0.4.0
     */
    public function __construct(?ValidatorInterface $validator = null)
    {
        $this->validator = $validator;
    }

    /**
     * Validate a model and run a target's generators over it.
     *
     * Both validators run before any generator does: generation either produces
     * the whole file set or produces nothing. A half-written package is worse
     * than a refusal, because it looks like it worked.
     *
     * @param   ModelInterface   $model   The source model.
     * @param   TargetInterface  $target  What to generate it into.
     *
     * @return  FileCollection  The generated files, in memory.
     *
     * @throws  Model\ValidationException  When the model cannot be generated from.
     *
     * @since   0.4.0
     */
    public function run(ModelInterface $model, TargetInterface $target): FileCollection
    {
        $this->validator?->assertValid($model);
        $target->validator()?->assertValid($model);

        $files = new FileCollection();

        foreach ($target->generators() as $generator) {
            if ($generator->supports($model)) {
                $generator->generate($model, $files);
            }
        }

        return $files;
    }
}
