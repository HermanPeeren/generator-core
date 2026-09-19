<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Template
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Template;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;

/**
 * Renders a Twig template.
 *
 * Twig is the default engine for the Joomla targets, for two reasons that plain
 * PHP templates cannot match:
 *
 * - A template can come from anywhere a loader can reach, a database included.
 *   A plain PHP template comes from a file, and holding one in a database means
 *   eval() or writing a temp file.
 * - A template that someone other than the generator's author may edit is
 *   untrusted input. A plain PHP template is then arbitrary code execution;
 *   Twig has a sandbox.
 *
 * Twig is not faster. Measured against an equivalent plain PHP template
 * rendering the same class, it is consistently a little slower - it compiles to
 * PHP and then runs that. The difference is a fraction of a millisecond per
 * file, which no generation run will notice.
 *
 * Two settings here are not preferences:
 *
 * - `strict_variables` is off in Twig by default, so a mistyped variable renders
 *   as an empty string and produces a broken file that looks fine. On.
 * - `autoescape` escapes for HTML, which is wrong for every language this
 *   generates: it would turn an apostrophe in generated PHP into `&#039;`. Off -
 *   and values interpolated into generated source go through the emitters, which
 *   escape for the language actually being written.
 *
 * One Environment is built per renderer and reused. Building one per template is
 * both slower and a trap: Twig keys its compiled classes on template source and
 * name, *not* on environment options, so a second Environment with different
 * options silently reuses the first one's compilation.
 *
 * @since  0.3.0
 */
final class TwigRenderer implements RendererInterface
{
    /**
     * The Twig environment, built once and reused for every render.
     *
     * @var    Environment
     * @since  0.3.0
     */
    private Environment $twig;

    /**
     * Constructor.
     *
     * @param   LoaderInterface  $loader          Where templates are fetched from.
     * @param   ?string          $cacheDirectory  Where Twig may cache compiled templates,
     *                                            or null to compile in memory each run.
     *
     * @throws  \RuntimeException  When Twig is not available.
     *
     * @since   0.3.0
     */
    public function __construct(LoaderInterface $loader, ?string $cacheDirectory = null)
    {
        self::requireTwig();

        $this->twig = new Environment($loader, [
            'strict_variables' => true,
            'autoescape'       => false,
            'cache'            => $cacheDirectory ?? false,
        ]);
    }

    /**
     * A renderer reading templates from one or more directories.
     *
     * @param   string|string[]  $directories     Absolute path(s) holding templates.
     * @param   ?string          $cacheDirectory  Where Twig may cache compiled templates.
     *
     * @return  self  The renderer.
     *
     * @since   0.3.0
     */
    public static function forDirectories(string|array $directories, ?string $cacheDirectory = null): self
    {
        self::requireTwig();

        return new self(new FilesystemLoader((array) $directories), $cacheDirectory);
    }

    /**
     * A renderer for templates already held in memory.
     *
     * This is the case a plain PHP renderer cannot serve: templates stored in a
     * database, which is where modelled generators lead.
     *
     * @param   array<string, string>  $templates       Template name => source.
     * @param   ?string                $cacheDirectory  Where Twig may cache compiled templates.
     *
     * @return  self  The renderer.
     *
     * @since   0.3.0
     */
    public static function forTemplates(array $templates, ?string $cacheDirectory = null): self
    {
        self::requireTwig();

        return new self(new ArrayLoader($templates), $cacheDirectory);
    }

    /**
     * Render a template.
     *
     * @param   string                $template   Template name, as the loader knows it.
     * @param   array<string, mixed>  $variables  Variables made available to the template.
     *
     * @return  string  The rendered text.
     *
     * @throws  \RuntimeException  When the template is missing, will not compile, or uses
     *                             a variable that was not supplied.
     *
     * @since   0.3.0
     */
    public function render(string $template, array $variables = []): string
    {
        try {
            $output = $this->twig->render($template, $variables);
        } catch (TwigError $e) {
            // The interface promises a RuntimeException. Twig's errors do not
            // extend it, and their message carries the template and line, which
            // is the useful part, so it is kept and the original chained.
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        // Same contract as the plain PHP renderer: LF, and exactly one trailing
        // newline, so that regenerating on another platform produces no diff.
        $output = str_replace(["\r\n", "\r"], "\n", $output);

        return rtrim($output, "\n") . "\n";
    }

    /**
     * Add a Twig extension, for filters and functions a target needs.
     *
     * @param   ExtensionInterface  $extension  The extension to add.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function addExtension(ExtensionInterface $extension): void
    {
        $this->twig->addExtension($extension);
    }

    /**
     * Make sure Twig is loadable, and say so clearly when it is not.
     *
     * Under composer this is already true. Inside the Joomla library it is not
     * automatic: Joomla registers the one namespace a library manifest declares,
     * which is this package's own, so a bundled third-party tree has to register
     * itself. Doing that here, from the only class that needs Twig, means no
     * consuming extension ever writes a require_once - the same arrangement
     * Regular Labs uses for its own vendored packages.
     *
     * The path works unchanged in both layouts because the repository mirrors
     * the library: three levels up from src/Core/Template is the root either way.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When Twig cannot be loaded.
     *
     * @since   0.3.0
     */
    private static function requireTwig(): void
    {
        if (class_exists(Environment::class)) {
            return;
        }

        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';

        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (!class_exists(Environment::class)) {
            throw new \RuntimeException(
                'Twig is not available. Install it with composer, or use the library package,'
                . ' which ships it in vendor/.'
            );
        }
    }
}
