<?php

declare(strict_types=1);

namespace Yepr\GeneratorCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\GeneratorCore\Emitter\IniEmitter;
use Yepr\GeneratorCore\Emitter\PhpEmitter;
use Yepr\GeneratorCore\Emitter\XmlEmitter;

/**
 * The emitters are the project's answer to injection, one target language over.
 * A model value reaching generated source unescaped is the same bug class as SQL
 * injection, so the hostile inputs get asserted explicitly.
 */
final class EmitterTest extends TestCase
{
    public function testAQuoteInAValueCannotBreakOutOfAPhpString(): void
    {
        $literal = PhpEmitter::string("it's; system('rm -rf /'); //");

        $this->assertSame("it's; system('rm -rf /'); //", eval('return ' . $literal . ';'));
    }

    public function testAnArrayLiteralEscapesRecursively(): void
    {
        $value = ['a' => ["it's", 2, true], 'b' => []];

        $this->assertSame($value, eval('return ' . PhpEmitter::arrayLiteral($value) . ';'));
    }

    public function testAnIdentifierIsCheckedNotEscaped(): void
    {
        $this->assertSame('FlightTable', PhpEmitter::identifier('FlightTable'));

        $this->expectException(\InvalidArgumentException::class);
        PhpEmitter::identifier('Flight Table');
    }

    public function testACommentCannotTerminateItself(): void
    {
        $this->assertStringNotContainsString('*/', PhpEmitter::comment('ends here */ and then code()'));
    }

    public function testNamespaceIsCheckedPartByPart(): void
    {
        $withDelimiters = '\Yepr\Component\Extengen\\';

        $this->assertSame('Yepr\Component\Extengen', PhpEmitter::namespaceName($withDelimiters));

        $this->expectException(\InvalidArgumentException::class);
        PhpEmitter::namespaceName('Yepr\Component\9Bad');
    }

    public function testXmlTextAndAttributesAreEscaped(): void
    {
        $this->assertSame('a &amp; b &lt;c&gt;', XmlEmitter::text('a & b <c>'));
        $this->assertSame('say &quot;hi&quot;', XmlEmitter::attr('say "hi"'));
    }

    public function testAnXmlElementSkipsEmptyAttributes(): void
    {
        $this->assertSame(
            "\t<field name=\"title\" type=\"text\"/>",
            XmlEmitter::element('field', ['name' => 'title', 'type' => 'text', 'label' => ''], null, 1)
        );
    }

    public function testGeneratedXmlActuallyParses(): void
    {
        $xml = '<root>' . XmlEmitter::element('f', ['v' => 'a "quoted" & <hostile> value'], 'text & more') . '</root>';

        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($xml));
    }

    public function testAnIniValueCannotBreakOutOfItsQuotes(): void
    {
        $line   = IniEmitter::line('COM_X_LABEL', 'He said "stop"' . "\n" . 'then left');
        $parsed = parse_ini_string($line, false, INI_SCANNER_RAW);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('COM_X_LABEL', $parsed);
        $this->assertStringNotContainsString("\n", $parsed['COM_X_LABEL']);
    }

    public function testTheIniQuoteDialectIsConfigurable(): void
    {
        $this->assertSame('A="say "_QQ_"hi"_QQ_""', IniEmitter::line('A', 'say "hi"'));
        $this->assertSame('A="say \\"hi\\""', IniEmitter::line('A', 'say "hi"', IniEmitter::QUOTE_BACKSLASH));
    }

    public function testALanguageKeyIsCheckedAndUpperCased(): void
    {
        $this->assertSame('COM_X_LABEL', IniEmitter::key('com_x_label'));

        $this->expectException(\InvalidArgumentException::class);
        IniEmitter::key('com x label');
    }
}
