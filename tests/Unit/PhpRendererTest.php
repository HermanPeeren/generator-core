<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Emitter\PhpEmitter;
use Yepr\Gen\Core\Template\PhpRenderer;

final class PhpRendererTest extends TestCase
{
    private function template(): string
    {
        return \dirname(__DIR__) . '/Fixtures/table.php.tpl';
    }

    public function testItRendersATemplateThatGeneratesPhp(): void
    {
        $out = (new PhpRenderer())->render($this->template(), [
            'namespace' => 'Yepr\Component\Extengen\Administrator\Table',
            'class'     => 'FlightTable',
            'label'     => PhpEmitter::string("Herman's flights"),
        ]);

        $this->assertStringStartsWith("<?php\n", $out);
        $this->assertStringContainsString('class FlightTable extends Table', $out);
        $this->assertStringContainsString("'Herman\'s flights'", $out);
    }

    public function testTheRenderedOutputIsValidPhp(): void
    {
        $out = (new PhpRenderer())->render($this->template(), [
            'namespace' => 'A\B',
            'class'     => 'C',
            'label'     => PhpEmitter::string('x'),
        ]);

        $file = tempnam(sys_get_temp_dir(), 'gc') . '.php';
        file_put_contents($file, $out);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $status);
        unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function testOutputIsNormalisedToLfWithOneTrailingNewline(): void
    {
        $out = (new PhpRenderer())->render($this->template(), [
            'namespace' => 'A\B',
            'class'     => 'C',
            'label'     => "'x'",
        ]);

        $this->assertStringNotContainsString("\r", $out);
        $this->assertSame(rtrim($out, "\n") . "\n", $out);
    }

    public function testAMissingTemplateIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PhpRenderer())->render(__DIR__ . '/nope.tpl');
    }

    public function testTheTemplateCannotReachTheRenderer(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'gc') . '.tpl';
        file_put_contents($file, '<?php echo isset($this) ? "leaked" : "isolated"; ?>');

        $out = (new PhpRenderer())->render($file, []);
        unlink($file);

        $this->assertSame("isolated\n", $out);
    }
}
