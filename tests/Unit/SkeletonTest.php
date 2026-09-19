<?php

declare(strict_types=1);

namespace Yepr\GeneratorCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The suite has to assert something before there is anything to assert about.
 *
 * What it pins is the one thing that must hold from the first commit: this
 * package's namespace resolves to src/, and nothing in it has pulled in a
 * framework. The second assertion is the placeholder for NoJoomlaDependencyTest,
 * which replaces this file at step 0.2.
 */
final class SkeletonTest extends TestCase
{
    public function testTheAutoloaderKnowsThePackageNamespace(): void
    {
        $loader = require __DIR__ . '/../../vendor/autoload.php';

        $this->assertArrayHasKey(
            'Yepr\\GeneratorCore\\',
            $loader->getPrefixesPsr4(),
            'The package namespace is not registered; check composer.json autoload.'
        );
    }

    public function testTheSourceTreeHasNoFrameworkDependency(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/^\s*use\s+(Joomla|Symfony|Illuminate|Drupal)\\\\/m', $contents)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'The generator core must not import a framework.');
    }

    /** @return string[] */
    private function sourceFiles(): array
    {
        $src = \dirname(__DIR__, 2) . '/src';

        if (!is_dir($src)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
