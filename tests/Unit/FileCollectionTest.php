<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Output\FileCollection;

/**
 * The collection is the only thing standing between a malformed model and the
 * filesystem, so its path rules get tested directly rather than through a
 * generator.
 */
final class FileCollectionTest extends TestCase
{
    public function testFilesComeBackInPathOrderWhateverOrderTheyWereAdded(): void
    {
        $files = new FileCollection();
        $files->add('src/Zebra.php', 'z');
        $files->add('manifest.xml', 'm');
        $files->add('src/Apple.php', 'a');

        $this->assertSame(['manifest.xml', 'src/Apple.php', 'src/Zebra.php'], $files->paths());
    }

    public function testTwoGeneratorsClaimingOnePathIsAnError(): void
    {
        $files = new FileCollection();
        $files->add('a.php', 'first');

        $this->expectException(\LogicException::class);
        $files->add('a.php', 'second');
    }

    public function testReplaceIsAllowedWhereAddIsNot(): void
    {
        $files = new FileCollection();
        $files->add('a.php', 'first');
        $files->replace('a.php', 'second');

        $this->assertSame('second', $files->get('a.php'));
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileCollection())->add($path, 'x');
    }

    /** @return array<string, string[]> */
    public static function unsafePaths(): array
    {
        return [
            'empty'            => [''],
            'absolute posix'   => ['/etc/passwd'],
            'windows drive'    => ['C:/Windows/system.ini'],
            'traversal'        => ['../outside.php'],
            'nested traversal' => ['src/../../outside.php'],
            'control char'     => ["src/a\x00.php"],
            'only separators'  => ['///'],
        ];
    }

    public function testBackslashesAreNormalisedToForwardSlashes(): void
    {
        $files = new FileCollection();
        $files->add('src\Table\Flight.php', 'x');

        $this->assertSame(['src/Table/Flight.php'], $files->paths());
        $this->assertTrue($files->has('src/Table/Flight.php'));
    }

    public function testCountingAndIteration(): void
    {
        $files = new FileCollection();
        $files->add('b.php', '2');
        $files->add('a.php', '1');

        $this->assertCount(2, $files);
        $this->assertSame(['a.php' => '1', 'b.php' => '2'], iterator_to_array($files));
    }

    public function testAskingForAFileThatWasNeverGenerated(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new FileCollection())->get('nope.php');
    }
}
