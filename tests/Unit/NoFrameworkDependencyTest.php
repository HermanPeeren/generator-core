<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two boundaries the core has to keep, which are not the same boundary.
 *
 * **No framework.** The moment a generator reaches for a CMS singleton it stops
 * being a pure model-to-text transformation, the unit suite needs a bootstrap,
 * and the whole thing stops being usable from another platform. No exceptions,
 * ever.
 *
 * **No template engine past the renderer.** Twig is a dependency, deliberately,
 * and exactly one class may know that: the Twig renderer. If a generator or an
 * emitter imported Twig directly, RendererInterface would have stopped being an
 * abstraction and swapping engines would stop being a registration change.
 *
 * These were one list once, which read as though Twig were a framework and hid
 * the second rule entirely. Separated, the second rule survives Twig arriving -
 * a blanket ban would simply have been deleted, taking the real guarantee with
 * it.
 */
final class NoFrameworkDependencyTest extends TestCase
{
    private const FRAMEWORKS = ['Joomla', 'Symfony', 'Illuminate', 'Drupal'];

    /** Engine => the one source file allowed to import it, relative to src/. */
    private const ENGINES = ['Twig' => 'Core/Template/TwigRenderer.php'];

    public function testTheCoreImportsNoFramework(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::FRAMEWORKS as $framework) {
                if ($this->imports($source, $framework)) {
                    $offenders[] = basename($file) . ' imports ' . $framework;
                }
            }
        }

        $this->assertSame([], $offenders, implode(', ', $offenders));
    }

    public function testOnlyItsOwnRendererKnowsAboutATemplateEngine(): void
    {
        $offenders = [];
        $root      = str_replace('\\', '/', \dirname(__DIR__, 2) . '/src') . '/';

        foreach ($this->sourceFiles() as $file) {
            $relative = str_replace($root, '', str_replace('\\', '/', $file));
            $source   = (string) file_get_contents($file);

            foreach (self::ENGINES as $engine => $allowed) {
                if ($relative === $allowed) {
                    continue;
                }

                if ($this->imports($source, $engine)) {
                    $offenders[] = $relative . ' imports ' . $engine;
                }
            }
        }

        $this->assertSame([], $offenders, implode(', ', $offenders));
    }

    public function testTheRendererAllowedToImportTwigActuallyDoesExist(): void
    {
        // Otherwise the rule above passes by naming a file that is not there,
        // and would keep passing after the renderer was renamed away.
        foreach (self::ENGINES as $engine => $allowed) {
            $path = \dirname(__DIR__, 2) . '/src/' . $allowed;

            $this->assertFileExists($path, 'The file allowed to import ' . $engine . ' is missing.');
            $this->assertTrue(
                $this->imports((string) file_get_contents($path), $engine),
                $allowed . ' is the only file allowed to import ' . $engine . ', but it does not.'
            );
        }
    }

    /** Whether a source file imports anything from a namespace prefix. */
    private function imports(string $source, string $prefix): bool
    {
        // The namespace separator is a single backslash; preg_quote keeps this
        // readable instead of counting escapes across two layers.
        return (bool) preg_match('~^\s*use\s+' . preg_quote($prefix . '\\', '~') . '~mi', $source);
    }

    public function testTheCoreCallsNoGlobalCmsEntryPoint(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/\b(JPATH_[A-Z_]+|JFactory|Factory::get)\b/', $source)) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'These reach for a CMS global: ' . implode(', ', $offenders));
    }

    /**
     * php-cs-fixer classes declare_strict_types as a risky fixer, because adding
     * it can change how a file behaves. It is checked here instead of fixed
     * automatically, so that it is a thing someone decides rather than a thing a
     * tool does on the way past. Tests count too: a test running in weak mode
     * proves less than it appears to.
     */
    public function testEveryFileDeclaresStrictTypes(): void
    {
        $offenders = [];

        foreach ([...$this->sourceFiles(), ...$this->phpFilesIn(__DIR__)] as $file) {
            if (!str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'These do not declare strict types: ' . implode(', ', $offenders));
    }

    /** @return string[] */
    private function sourceFiles(): array
    {
        return $this->phpFilesIn(\dirname(__DIR__, 2) . '/src');
    }

    /** @return string[] */
    private function phpFilesIn(string $root): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
