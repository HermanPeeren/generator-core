<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Output\FileCollection;
use Yepr\Gen\Core\Output\ZipWriter;

/**
 * The writer is the one place the core touches a filesystem, so it is also the
 * one place a bad path could do damage. Both halves get asserted: that the
 * archive is readable, and that entry names use forward slashes - a backslash
 * in a zip entry becomes a literal backslash in a filename on Linux, and the
 * extension then simply fails to load.
 */
final class ZipWriterTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('The zip extension is not loaded.');
        }

        $this->dir = sys_get_temp_dir() . '/gc-zip-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
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

    private function files(): FileCollection
    {
        $files = new FileCollection();
        $files->add('manifest.xml', '<extension/>');
        $files->add('src\Table\Flight.php', '<?php class FlightTable {}');

        return $files;
    }

    public function testTheArchiveHoldsEveryFileWithForwardSlashedNames(): void
    {
        $path = (new ZipWriter())->write($this->files(), $this->dir . '/out/pkg.zip');

        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        sort($names, SORT_STRING);
        $zip->close();

        $this->assertSame(['manifest.xml', 'src/Table/Flight.php'], $names);
    }

    public function testWritingIntoADirectoryLandsEveryFile(): void
    {
        $written = (new ZipWriter())->writeToDirectory($this->files(), $this->dir);

        sort($written, SORT_STRING);

        $this->assertSame(['manifest.xml', 'src/Table/Flight.php'], $written);
        $this->assertFileExists($this->dir . '/src/Table/Flight.php');
        $this->assertSame('<extension/>', file_get_contents($this->dir . '/manifest.xml'));
    }

    public function testWritingToADirectoryThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ZipWriter())->writeToDirectory($this->files(), $this->dir . '/missing');
    }
}
