<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Rule
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Rule;

use Yepr\Gen\Core\Reference\ReferenceIndex;

/**
 * Selectors a language describes, as a registry the engine can call: step 3.6.
 *
 * `RuleEngine` asks a `Registry` for the nodes a selector names and does not
 * care where the answer comes from - it calls a callable and expects an array.
 * That seam is what lets a selector stop being PHP: these are closures over a
 * `SelectorPath`, and the path is data the language carries.
 *
 * So the engine is untouched by 3.6, which is the point. A target that still
 * registers its selectors in PHP keeps working beside a language that writes
 * them down, and the two are the same thing by the time the engine sees them.
 *
 * @since  0.8.0
 */
final class DataSelectors
{
    /**
     * Build a registry from selector paths.
     *
     * @param  array<string, array<int, mixed>>  $definitions  Selector name => its steps.
     * @param  ?ReferenceIndex                   $references   The language's table, for follow steps.
     *
     * @throws RuleException  When a path is not one.
     *
     * @since  0.8.0
     */
    public static function registry(array $definitions, ?ReferenceIndex $references = null): Registry
    {
        $registry = new Registry('selector');

        foreach ($definitions as $name => $steps) {
            // Read now rather than when the selector is called, so a path that
            // is not one is reported while somebody is looking at the language
            // rather than in the middle of a generation run.
            $path = SelectorPath::fromArray($steps, (string) $name);

            $registry->register(
                (string) $name,
                static fn (object $model): array => $path->walk($model, $references)
            );
        }

        return $registry;
    }
}
