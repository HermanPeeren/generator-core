<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Emitter\PhpEmitter;
use Yepr\Gen\Core\Template\TwigRenderer;

/**
 * What is asserted here is mostly the configuration, because the configuration
 * is where Twig is dangerous for code generation: its two defaults are both
 * wrong for this job, and both fail quietly.
 */
final class TwigRendererTest extends TestCase
{
    private const TABLE = <<<'TWIG'
        <?php
        namespace {{ namespace }};

        class {{ class }}Table extends Table
        {
            public const LABEL = {{ label }};
        }
        TWIG;

    public function testItRendersATemplateThatGeneratesPhp(): void
    {
        $out = TwigRenderer::forTemplates(['table' => self::TABLE])->render('table', [
            'namespace' => 'Yepr\Component\Extengen\Administrator\Table',
            'class'     => 'Flight',
            'label'     => PhpEmitter::string("Herman's flights"),
        ]);

        $this->assertStringStartsWith("<?php\n", $out);
        $this->assertStringContainsString('class FlightTable extends Table', $out);
        $this->assertStringContainsString("'Herman\\'s flights'", $out);
    }

    /**
     * Twig's default is to escape for HTML, which would turn an apostrophe in
     * generated PHP into &#039; and produce a file that does not parse.
     */
    public function testAutoescapingIsOffSoGeneratedSourceIsNotHtmlEscaped(): void
    {
        $out = TwigRenderer::forTemplates(['t' => '$x = {{ v }};'])
            ->render('t', ['v' => PhpEmitter::string('O\'Brien & Sons <tag>')]);

        $this->assertStringNotContainsString('&#039;', $out);
        $this->assertStringNotContainsString('&amp;', $out);
        $this->assertSame("\$x = 'O\\'Brien & Sons <tag>';\n", $out);
    }

    /**
     * Off by default, and the cause of the silent empty-variable failures in the
     * current Extengen generator: a mistyped name renders as nothing at all.
     */
    public function testAMissingVariableIsAnErrorRatherThanAnEmptyString(): void
    {
        $renderer = TwigRenderer::forTemplates(['t' => 'class {{ name }} extends {{ notSupplied }} {}']);

        $this->expectException(\RuntimeException::class);
        $renderer->render('t', ['name' => 'Flight']);
    }

    public function testTheErrorNamesTheTemplateAndLine(): void
    {
        $renderer = TwigRenderer::forTemplates(['table' => self::TABLE]);

        try {
            $renderer->render('table', ['namespace' => 'A', 'class' => 'B']);
            $this->fail('A missing variable should not render.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('label', $e->getMessage());
            $this->assertStringContainsString('table', $e->getMessage());
            $this->assertInstanceOf(\Twig\Error\Error::class, $e->getPrevious());
        }
    }

    /**
     * The case a plain PHP renderer cannot serve without eval(), and the reason
     * Twig is the default: templates that are data rather than files.
     */
    public function testATemplateCanComeFromMemoryRatherThanAFile(): void
    {
        $fromDatabase = 'class {{ name }} {}';

        $this->assertSame(
            "class Flight {}\n",
            TwigRenderer::forTemplates(['stored' => $fromDatabase])->render('stored', ['name' => 'Flight'])
        );
    }

    public function testOutputIsNormalisedToLfWithOneTrailingNewline(): void
    {
        $out = TwigRenderer::forTemplates(['t' => "a\r\nb\r\n\r\n\r\n"])->render('t');

        $this->assertSame("a\nb\n", $out);
    }

    public function testAMissingTemplateIsARuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        TwigRenderer::forTemplates(['t' => 'x'])->render('absent');
    }

    public function testItReadsTemplatesFromADirectoryToo(): void
    {
        $out = TwigRenderer::forDirectories(\dirname(__DIR__) . '/Fixtures')
            ->render('table.php.twig', ['namespace' => 'A\B', 'class' => 'C', 'label' => "'x'"]);

        $this->assertStringContainsString('class CTable extends Table', $out);
    }

    /**
     * Twig keys its compiled classes on template source and name, not on the
     * environment's options. Two renderers built over the same source and name
     * but different settings would therefore share one compilation - which is
     * why one Environment is built per renderer and reused, and why this asserts
     * that two renderers do not contaminate each other.
     */
    public function testTwoRenderersOverTheSameTemplateNameStayIndependent(): void
    {
        $a = TwigRenderer::forTemplates(['shared' => 'A:{{ v }}']);
        $b = TwigRenderer::forTemplates(['shared' => 'B:{{ v }}']);

        $this->assertSame("A:1\n", $a->render('shared', ['v' => 1]));
        $this->assertSame("B:1\n", $b->render('shared', ['v' => 1]));
    }

    /**
     * An edited template takes effect on the next request.
     *
     * Twig defaults `auto_reload` to the value of `debug`, which is false. With
     * a cache directory and nothing else said, the compiled class is used
     * forever: the source changes and the output does not. On a site that is
     * silent and permanent - a generator quietly producing last month's
     * output, with nothing anywhere to say why. It is how a manifest edit in
     * Exten-gen reached a Joomla install and did nothing at all.
     *
     * **In two processes, because one cannot see it.** Twig names a compiled
     * class after the template name, and reuses whatever is already declared in
     * the running process without consulting the cache. That is right for a
     * request, and it means a single-process test passes whatever `auto_reload`
     * says. What a site actually does is compile in one request and read in the
     * next, so that is what this does.
     */
    public function testAnEditedTemplateIsPickedUpOnTheNextRequest(): void
    {
        $templates = sys_get_temp_dir() . '/yepr-gen-templates-' . bin2hex(random_bytes(4));
        $cache     = sys_get_temp_dir() . '/yepr-gen-cache-' . bin2hex(random_bytes(4));

        mkdir($templates, 0777, true);
        mkdir($cache, 0777, true);

        $template = $templates . '/greeting.twig';

        file_put_contents($template, 'first');

        $this->assertSame('first', $this->renderInItsOwnProcess($templates, $cache));

        // A second apart, because Twig compares whole-second mtimes.
        sleep(1);

        file_put_contents($template, 'second');

        $this->assertSame(
            'second',
            $this->renderInItsOwnProcess($templates, $cache),
            'The cached compilation outlived the template it was compiled from.'
        );

        unlink($template);
        rmdir($templates);

        $this->deleteTree($cache);
    }

    /**
     * Render one template in a fresh PHP process, as a second request would.
     */
    private function renderInItsOwnProcess(string $templates, string $cache): string
    {
        $script = \sprintf(
            'require %s; echo trim(\Yepr\Gen\Core\Template\TwigRenderer::forDirectories(%s, %s)->render(%s));',
            var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($templates, true),
            var_export($cache, true),
            var_export('greeting.twig', true)
        );

        exec(\sprintf('php -r %s 2>&1', escapeshellarg($script)), $output, $status);

        $this->assertSame(0, $status, implode("
", $output));

        return trim(implode("
", $output));
    }

    private function deleteTree(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
