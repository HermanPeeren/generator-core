<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Output\FileCollection;
use Yepr\Gen\Core\Testing\GoldenFiles;

/**
 * The harness itself. A golden suite that cannot fail is worse than none, so the
 * parts that decide whether it fails get tested directly.
 */
final class GoldenFilesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gc-golden-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/models', 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->dir);
    }

    private function files(string ...$paths): FileCollection
    {
        $files = new FileCollection();

        foreach ($paths as $path) {
            $files->add($path, 'contents of ' . $path . "\n");
        }

        return $files;
    }

    public function testFixturesAreDiscoveredAndSorted(): void
    {
        foreach (['zebra', 'apple', 'mango'] as $name) {
            file_put_contents($this->dir . '/models/' . $name . '.json', '{}');
        }

        $this->assertSame(['apple', 'mango', 'zebra'], (new GoldenFiles($this->dir))->names());
    }

    public function testWritingThenReadingRoundTripsExactly(): void
    {
        $golden = new GoldenFiles($this->dir);
        file_put_contents($this->dir . '/models/a.json', '{}');

        $golden->write('a', $this->files('src/A.php', 'language/en-GB/a.ini'));

        $this->assertSame(
            ['language/en-GB/a.ini', 'src/A.php'],
            array_keys($golden->expected('a'))
        );
        $this->assertSame("contents of src/A.php\n", $golden->expected('a')['src/A.php']);
    }

    /**
     * The failure this practice exists to catch, and the one a naive comparison
     * misses: a file that quietly stops being produced.
     */
    public function testAFileThatStopsBeingGeneratedDisappearsOnlyWhenApproved(): void
    {
        $golden = new GoldenFiles($this->dir);
        file_put_contents($this->dir . '/models/a.json', '{}');

        $golden->write('a', $this->files('kept.php', 'dropped.php'));
        $this->assertArrayHasKey('dropped.php', $golden->expected('a'));

        // Until write() is called again, the approved set still demands it - so a
        // suite comparing both directions goes red rather than silently shrinking.
        $stillApproved = $golden->expected('a');
        $this->assertFalse($this->files('kept.php')->has('dropped.php'));
        $this->assertArrayHasKey('dropped.php', $stillApproved);

        // Approving the change is deliberate, and then it is gone for good.
        $golden->write('a', $this->files('kept.php'));
        $this->assertSame(['kept.php'], array_keys($golden->expected('a')));
    }

    public function testCrlfInACheckoutDoesNotFailAgainstLfOutput(): void
    {
        $golden = new GoldenFiles($this->dir);
        file_put_contents($this->dir . '/models/a.json', '{}');
        mkdir($this->dir . '/expected/a', 0755, true);
        file_put_contents($this->dir . '/expected/a/x.php', "line one\r\nline two\r\n");

        $this->assertSame("line one\nline two\n", $golden->expected('a')['x.php']);
    }

    public function testNormalisationGivesExactlyOneTrailingNewline(): void
    {
        $this->assertSame("a\n", GoldenFiles::normalise("a\n\n\n"));
        $this->assertSame("a\n", GoldenFiles::normalise('a'));
        $this->assertSame("a\nb\n", GoldenFiles::normalise("a\r\nb"));
    }

    public function testAnUnknownFixtureIsAnError(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new GoldenFiles($this->dir))->model('absent');
    }

    /**
     * write() deletes a directory built from the name, so the name is checked.
     */
    public function testAFixtureNameCannotReachOutsideTheFixtureDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GoldenFiles($this->dir))->expectedDirectory('../../etc');
    }

    public function testAFixtureWithNoApprovedOutputYetReadsAsEmpty(): void
    {
        file_put_contents($this->dir . '/models/fresh.json', '{}');

        $this->assertSame([], (new GoldenFiles($this->dir))->expected('fresh'));
    }
}
