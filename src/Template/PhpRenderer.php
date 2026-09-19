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
 * Renders a plain PHP template file.
 *
 * Templates are plain PHP, which keeps the core free of a vendored template
 * engine. The template file itself is trusted code shipped with the generator;
 * the *variables* are not, which is why templates use the emitters for every
 * value they interpolate.
 *
 * A template is included in an isolated scope with no access to $this.
 *
 * Two consequences of plain PHP that a template author has to know:
 *
 * - A template that generates PHP must escape its own opening tag, because the
 *   text it wants to emit is also its own syntax: write `<?php echo "<?php\n"; ?>`
 *   rather than a literal one.
 * - An undefined variable produces a PHP warning and an empty string, not an
 *   error. Anything that must not be missing belongs in a validator, not in a
 *   hope that the warning is noticed.
 *
 * @since  0.2.0
 */
final class PhpRenderer implements RendererInterface
{
    /**
     * Render a template file.
     *
     * @param   string                $template   Absolute path of the template.
     * @param   array<string, mixed>  $variables  Variables made available to the template.
     *
     * @return  string  The rendered file contents.
     *
     * @throws  \RuntimeException  When the template does not exist.
     *
     * @since   0.2.0
     */
    public function render(string $template, array $variables = []): string
    {
        if (!is_file($template)) {
            throw new \RuntimeException(\sprintf('Template "%s" does not exist.', $template));
        }

        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();

            try {
                include $__file;

                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();

                throw $e;
            }
        };

        $output = $render($template, $variables);

        // Generated text files use LF and end with exactly one newline, so that
        // regenerating on another platform does not produce a diff.
        $output = str_replace(["\r\n", "\r"], "\n", $output);

        return rtrim($output, "\n") . "\n";
    }
}
