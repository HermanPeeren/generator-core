<?php

/**
 * @package     GeneratorCore
 * @subpackage  Model
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\GeneratorCore\Model;

/**
 * Checks a model before anything is generated from it.
 *
 * Validation runs first and all at once, so that generation either produces a
 * complete file set or produces nothing. A half-written package is worse than a
 * refusal, because it looks like it worked.
 *
 * @since  0.2.0
 */
interface ValidatorInterface
{
    /**
     * Throw unless the model can be generated from.
     *
     * @param   ModelInterface  $model  The model to check.
     *
     * @return  void
     *
     * @throws  ValidationException  When the model is not valid.
     *
     * @since   0.2.0
     */
    public function assertValid(ModelInterface $model): void;
}
