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
 * What a model is generated *into*.
 *
 * A target owns the whole answer to "what files should exist": which generators
 * run, in what order, and what has to be true of a model before any of it is
 * attempted. Joomla 6 is the first; nothing here assumes a CMS, a PHP output, or
 * even a web platform.
 *
 * Deliberately narrow. A target's generators wire up their own renderer and
 * reach for whichever emitters their output language needs, because those are
 * decisions per generator rather than per target - a Joomla target writes PHP,
 * XML and ini from the same run. Pushing them into this interface now would fix
 * a shape before there is a second target to check it against, which is how an
 * abstraction ends up being dismantled a method at a time.
 *
 * @since  0.4.0
 */
interface TargetInterface
{
    /**
     * The stable identifier, used to select this target and to name its output.
     *
     * @return  string  A short machine-readable id, for example "joomla6".
     *
     * @since   0.4.0
     */
    public function id(): string;

    /**
     * How this target is named to a person choosing one.
     *
     * @return  string  A readable label, for example "Joomla 6 component".
     *
     * @since   0.4.0
     */
    public function label(): string;

    /**
     * The generators that produce this target's files, in the order they run.
     *
     * Order is part of the target's definition, not an implementation detail: a
     * generator that inventories what the others produced - a manifest listing
     * the folders that were generated - has to run after them.
     *
     * @return  GeneratorInterface[]  The generators, in order.
     *
     * @since   0.4.0
     */
    public function generators(): array;

    /**
     * What has to be true of a model before generating for this target.
     *
     * Targets differ in what they can express, so a model that is complete for
     * one may be missing something another requires.
     *
     * @return  ?ValidatorInterface  The validator, or null when the target asks nothing extra.
     *
     * @since   0.4.0
     */
    public function validator(): ?ValidatorInterface;
}
