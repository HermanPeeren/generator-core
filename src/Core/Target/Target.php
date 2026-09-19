<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Target
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Target;

use Yepr\Gen\Core\GeneratorInterface;
use Yepr\Gen\Core\Model\ValidatorInterface;

/**
 * A target assembled from a list of generators rather than written as a class.
 *
 * Enough for a target whose whole definition is "these generators, in this
 * order", and for tests. A target with real behaviour - one that chooses
 * generators by looking at the model, or builds them from a template set on disk
 * - implements the interface directly instead.
 *
 * @since  0.4.0
 */
final class Target implements TargetInterface
{
    /**
     * The generators, in the order they run.
     *
     * @var    GeneratorInterface[]
     * @since  0.4.0
     */
    private array $generators;

    /**
     * Constructor.
     *
     * Variadic, so PHP rejects a list holding something that is not a generator
     * at the boundary rather than a hand-written check doing it later.
     *
     * @param   string               $id            The stable identifier.
     * @param   string               $label         The readable label.
     * @param   ?ValidatorInterface  $validator     Checked before generating; null to skip.
     * @param   GeneratorInterface   ...$generators The generators, in order.
     *
     * @throws  \InvalidArgumentException  When the id is not usable.
     *
     * @since   0.4.0
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly ?ValidatorInterface $validator = null,
        GeneratorInterface ...$generators
    ) {
        // The id selects a target and may end up naming an output directory, so
        // it is checked rather than trusted.
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $id)) {
            throw new \InvalidArgumentException(
                \sprintf('"%s" is not a usable target id: lower case, starting with a letter.', $id)
            );
        }

        $this->generators = array_values($generators);
    }

    /**
     * The stable identifier.
     *
     * @return  string  The id.
     *
     * @since   0.4.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * The readable label.
     *
     * @return  string  The label.
     *
     * @since   0.4.0
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * The generators, in the order they run.
     *
     * @return  GeneratorInterface[]  The generators.
     *
     * @since   0.4.0
     */
    public function generators(): array
    {
        return $this->generators;
    }

    /**
     * The validator, if this target asks anything extra of a model.
     *
     * @return  ?ValidatorInterface  The validator, or null.
     *
     * @since   0.4.0
     */
    public function validator(): ?ValidatorInterface
    {
        return $this->validator;
    }

    /**
     * A copy of this target with a generator appended.
     *
     * @param   GeneratorInterface  $generator  The generator to append.
     *
     * @return  self  A new target.
     *
     * @since   0.4.0
     */
    public function with(GeneratorInterface $generator): self
    {
        return new self($this->id, $this->label, $this->validator, ...[...$this->generators, $generator]);
    }
}
