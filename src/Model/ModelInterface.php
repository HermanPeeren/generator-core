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
 * The root of a source model.
 *
 * Deliberately empty. The core never inspects a model - it hands it to
 * generators, which know their own model's shape. What the interface buys is a
 * named boundary: a generator declares that it consumes a model rather than an
 * arbitrary object, and a consuming project can be checked statically against
 * its own model type.
 *
 * @since  0.2.0
 */
interface ModelInterface
{
}
