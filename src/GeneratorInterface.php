<?php

/**
 * @package     GeneratorCore
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\GeneratorCore;

use Yepr\GeneratorCore\Model\ModelInterface;
use Yepr\GeneratorCore\Output\FileCollection;

/**
 * A generator contributes files for one concern.
 *
 * It must be pure: same model in, same bytes out. No filesystem, no clock, no
 * randomness, no network. That is what allows a generation run to be pinned by
 * golden files, and it is the property every other guarantee here rests on.
 *
 * @since  0.2.0
 */
interface GeneratorInterface
{
    /**
     * Whether this generator has anything to contribute for the given model.
     *
     * @param   ModelInterface  $model  The source model.
     *
     * @return  boolean  True when generate() should be called.
     *
     * @since   0.2.0
     */
    public function supports(ModelInterface $model): bool;

    /**
     * Add this generator's files to the collection.
     *
     * @param   ModelInterface  $model  The source model.
     * @param   FileCollection  $files  The collection to add to.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function generate(ModelInterface $model, FileCollection $files): void;
}
