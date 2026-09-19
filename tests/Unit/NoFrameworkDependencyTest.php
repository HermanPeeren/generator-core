<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The core must stay framework-agnostic.
 *
 * Not a style rule. The moment a generator reaches for a CMS singleton it stops
 * being a pure model-to-text transformation, the unit suite needs a bootstrap,
 * and the whole thing stops being usable from another platform. Guard the
 * boundary mechanically rather than by intention.
 */
final class NoFrameworkDependencyTest extends TestCase
{
    private const FRAMEWORKS = ['Joomla', 'Symfony', 'Illuminate', 'Drupal', 'Twig'];

    public function testTheCoreImportsNoFramework(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::FRAMEWORKS as $framework) {
                // The namespace separator is a single backslash; preg_quote keeps
                // this readable instead of counting escapes across two layers.
                $prefix = preg_quote($framework . '\\', '~');

                if (preg_match('~^\s*use\s+' . $prefix . '~mi', $source)) {
                    $offenders[] = basename($file) . ' imports ' . $framework;
                }
            }
        }

        $this->assertSame([], $offenders, implode(', ', $offenders));
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
