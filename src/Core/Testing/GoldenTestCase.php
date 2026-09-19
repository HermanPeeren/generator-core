<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Testing
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Testing;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Output\FileCollection;

/**
 * Pins a generator's whole output against the approved copy in the repository.
 *
 * A consuming project gets golden tests by extending this and answering two
 * questions - where the fixtures are, and how to turn one into files:
 *
 *     final class GoldenOutputTest extends GoldenTestCase
 *     {
 *         protected function fixtures(): GoldenFiles
 *         {
 *             return new GoldenFiles(__DIR__ . '/../Fixtures');
 *         }
 *
 *         protected function generate(string $name): FileCollection
 *         {
 *             $model = ProjectModel::fromJson($this->fixtures()->model($name));
 *
 *             return (new Pipeline())->run($model, $this->target());
 *         }
 *     }
 *
 * Comparison runs in both directions. Checking only that each generated file
 * matches its counterpart would miss a file that stopped being generated at all,
 * which is the change most worth catching and the one least likely to be
 * noticed.
 *
 * Only loadable where PHPUnit is: it lives in src/ because consuming projects
 * need it, not because the library uses it at run time. The library build leaves
 * this directory out.
 *
 * @since  0.5.0
 */
abstract class GoldenTestCase extends TestCase
{
    /**
     * Where the fixtures live.
     *
     * @return  GoldenFiles  The fixture directory.
     *
     * @since   0.5.0
     */
    abstract protected function fixtures(): GoldenFiles;

    /**
     * Generate one fixture's output.
     *
     * @param   string  $name  The fixture name.
     *
     * @return  FileCollection  What the generator produced.
     *
     * @since   0.5.0
     */
    abstract protected function generate(string $name): FileCollection;

    /**
     * Every fixture must produce exactly the output committed for it.
     *
     * @return  void
     *
     * @since   0.5.0
     */
    public function testEveryFixtureMatchesItsGoldenOutput(): void
    {
        $fixtures = $this->fixtures();
        $names    = $fixtures->names();

        $this->assertNotEmpty($names, 'No fixture models found. A golden suite with no fixtures proves nothing.');

        foreach ($names as $name) {
            $generated = $this->generate($name);
            $expected  = $fixtures->expected($name);

            $this->assertNotEmpty(
                $generated->paths(),
                \sprintf('The fixture "%s" generated nothing.', $name)
            );

            $this->assertNotEmpty(
                $expected,
                \sprintf('The fixture "%s" has no approved output. Run the fixture writer and read the diff.', $name)
            );

            // Contents, one file at a time, so a failure shows PHPUnit's diff of
            // the generated code rather than a summary of how many files moved.
            foreach ($generated as $path => $contents) {
                $this->assertArrayHasKey(
                    $path,
                    $expected,
                    \sprintf('%s/%s was generated but has no approved counterpart.', $name, $path)
                );

                $this->assertSame(
                    $expected[$path],
                    GoldenFiles::normalise($contents),
                    \sprintf('%s/%s differs from its approved output.', $name, $path)
                );
            }

            // And the other direction: nothing approved has stopped being made.
            foreach (array_keys($expected) as $path) {
                $this->assertTrue(
                    $generated->has($path),
                    \sprintf('%s/%s is approved output but is no longer generated.', $name, $path)
                );
            }
        }
    }

    /**
     * The set of files itself, stated rather than snapshotted.
     *
     * A golden file pins contents; this pins the shape. A fixture that starts
     * producing a file nobody meant it to fails here with a readable list, which
     * a content comparison reports as a stray path and nothing more.
     *
     * @return  void
     *
     * @since   0.5.0
     */
    public function testEveryFixtureGeneratesTheFileSetItsGoldenDirectoryHolds(): void
    {
        $fixtures = $this->fixtures();

        foreach ($fixtures->names() as $name) {
            $this->assertSame(
                array_keys($fixtures->expected($name)),
                $this->generate($name)->paths(),
                \sprintf('The set of files generated for "%s" has changed.', $name)
            );
        }
    }
}
