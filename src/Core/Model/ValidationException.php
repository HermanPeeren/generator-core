<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Model
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Model;

/**
 * A model that cannot be generated from.
 *
 * Carries every problem found rather than only the first, because a user fixing
 * a model one error per run is a user who gives up.
 *
 * @since  0.2.0
 */
final class ValidationException extends \RuntimeException
{
    /**
     * Every problem found with the model.
     *
     * @var    string[]
     * @since  0.2.0
     */
    private array $errors;

    /**
     * Constructor.
     *
     * @param   string[]  $errors  The problems found.
     *
     * @since   0.2.0
     */
    public function __construct(array $errors)
    {
        $this->errors = array_values($errors);

        parent::__construct(
            $this->errors === []
                ? 'The model is not valid.'
                : 'The model is not valid: ' . implode('; ', $this->errors)
        );
    }

    /**
     * Every problem found with the model.
     *
     * @return  string[]  The problems.
     *
     * @since   0.2.0
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
