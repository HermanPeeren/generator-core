<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Target
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Target;

/**
 * The targets an application can generate for.
 *
 * What makes adding a target cheap: a consumer registers one here and the
 * pipeline never changes, because the pipeline asks the target what to run
 * rather than knowing itself.
 *
 * Immutable, so one assembled registry can be shared without a caller's addition
 * appearing in someone else's list.
 *
 * @implements \IteratorAggregate<string, TargetInterface>
 *
 * @since  0.4.0
 */
final class TargetRegistry implements \IteratorAggregate, \Countable
{
    /**
     * The registered targets, id => target.
     *
     * @var    array<string, TargetInterface>
     * @since  0.4.0
     */
    private array $targets = [];

    /**
     * Constructor.
     *
     * @param   TargetInterface  ...$targets  The targets to register.
     *
     * @throws  \LogicException  When two targets claim the same id.
     *
     * @since   0.4.0
     */
    public function __construct(TargetInterface ...$targets)
    {
        foreach ($targets as $target) {
            $id = $target->id();

            if (isset($this->targets[$id])) {
                throw new \LogicException(\sprintf('Two targets claim the id "%s".', $id));
            }

            $this->targets[$id] = $target;
        }
    }

    /**
     * Whether a target is registered under this id.
     *
     * @param   string  $id  The target id.
     *
     * @return  boolean  True when it is registered.
     *
     * @since   0.4.0
     */
    public function has(string $id): bool
    {
        return isset($this->targets[$id]);
    }

    /**
     * The target registered under this id.
     *
     * @param   string  $id  The target id.
     *
     * @return  TargetInterface  The target.
     *
     * @throws  \OutOfBoundsException  When no target is registered under that id.
     *
     * @since   0.4.0
     */
    public function get(string $id): TargetInterface
    {
        return $this->targets[$id] ?? throw new \OutOfBoundsException(\sprintf(
            'No target "%s". Registered: %s.',
            $id,
            $this->ids() === [] ? 'none' : implode(', ', $this->ids())
        ));
    }

    /**
     * The registered ids, sorted, so a list offered to a person is stable.
     *
     * @return  string[]  The ids.
     *
     * @since   0.4.0
     */
    public function ids(): array
    {
        $ids = array_keys($this->targets);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * A copy of this registry with one more target.
     *
     * @param   TargetInterface  $target  The target to register.
     *
     * @return  self  A new registry.
     *
     * @throws  \LogicException  When the id is already registered.
     *
     * @since   0.4.0
     */
    public function with(TargetInterface $target): self
    {
        return new self(...[...array_values($this->targets), $target]);
    }

    /**
     * Iterate the targets in id order.
     *
     * @return  \ArrayIterator<string, TargetInterface>  An iterator over id => target.
     *
     * @since   0.4.0
     */
    public function getIterator(): \ArrayIterator
    {
        $targets = [];

        foreach ($this->ids() as $id) {
            $targets[$id] = $this->targets[$id];
        }

        return new \ArrayIterator($targets);
    }

    /**
     * How many targets are registered.
     *
     * @return  integer  The number of targets.
     *
     * @since   0.4.0
     */
    public function count(): int
    {
        return \count($this->targets);
    }
}
