<?php

/**
 * @package     GeneratorCore
 * @subpackage  Template
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\GeneratorCore\Template;

/**
 * Turns a template and a set of variables into text.
 *
 * The interface exists so that no generator is coupled to a template engine.
 * Which engine is the default - plain PHP, Twig, something else - is a decision
 * a consuming project makes, and changing it must not touch a single generator.
 *
 * Whatever the engine, values interpolated into generated source still need the
 * emitters: no template engine escapes for PHP, XML or ini, and the one engine
 * that escapes by default escapes for HTML, which is wrong here.
 *
 * @since  0.2.0
 */
interface RendererInterface
{
    /**
     * Render a template.
     *
     * Output is normalised to LF with exactly one trailing newline, so that
     * regenerating on another platform does not produce a diff.
     *
     * @param   string                $template   Identifier of the template; for a file-based
     *                                            renderer, its absolute path.
     * @param   array<string, mixed>  $variables  Variables made available to the template.
     *
     * @return  string  The rendered text.
     *
     * @throws  \RuntimeException  When the template cannot be found or rendered.
     *
     * @since   0.2.0
     */
    public function render(string $template, array $variables = []): string;
}
