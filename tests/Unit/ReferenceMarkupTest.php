<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Reference\ReferenceMarkup;

/**
 * The markup the server renders is the markup the element expects.
 *
 * This is the seam: PHP writes `<yepr-reference type="Entity">` around a
 * `<select>`, and `admin-yepr-reference.js` reads those attributes to
 * decide what to offer. Neither side can check the other at runtime, so the
 * agreement is checked here and stated in words in both files.
 *
 * `ReferenceField` itself is not tested: it is four lines of getting a name, an
 * id and a value out of a Joomla form, and the core deliberately has no
 * framework in it. Everything that could be wrong about the markup is here.
 */
final class ReferenceMarkupTest extends TestCase
{
    public function testItRendersTheCustomElementAroundARealSelect(): void
    {
        $html = $this->render('Entity', 'abc-123');

        $this->assertStringContainsString('<yepr-reference type="Entity">', $html);
        $this->assertStringContainsString('<select name="jform[reference][reference0][reference]"', $html);
        $this->assertStringContainsString('</yepr-reference>', $html);
    }

    /**
     * The value it already holds is rendered as a selected option.
     *
     * Without it the select posts empty on a page whose script never arrived,
     * and a stored reference - a uuid nobody can retype - is gone. With it, the
     * worst case is a dropdown offering one choice: the one already correct.
     */
    public function testTheStoredValueIsThereBeforeAnyScriptRuns(): void
    {
        $this->assertStringContainsString(
            '<option value="abc-123" selected>',
            $this->render('Entity', 'abc-123')
        );
    }

    public function testAnEmptyFieldRendersOnlyTheEmptyChoice(): void
    {
        $html = $this->render('Entity', '');

        $this->assertSame(1, substr_count($html, '<option'));
        $this->assertStringNotContainsString('selected', $html);
    }

    /**
     * A scoped dropdown names the input holding the parent it belongs to.
     *
     * The sibling's name comes from the form XML; the rest of the element id is
     * built by Joomla from where the field sits in a repeating group, so only
     * the server can put the two together.
     */
    public function testAScopedFieldPointsAtItsParentInTheSameRow(): void
    {
        $markup = new ReferenceMarkup();

        $this->assertSame(
            'jform_reference__reference0_entity_reference_id',
            $markup->siblingId('jform_reference__reference0_field_reference', 'field_reference', 'entity_reference_id')
        );

        $this->assertStringContainsString(
            'scope="jform_reference__reference0_entity_reference_id"',
            $markup->render('Field', 'n', 'i', '', 'jform_reference__reference0_entity_reference_id')
        );
    }

    /**
     * A sibling lookup that does not recognise the id says so by appending
     * rather than by cutting a string at a place that was not there.
     */
    public function testASiblingOfAnUnexpectedIdIsBuiltRatherThanGuessedAt(): void
    {
        $this->assertSame(
            'something_else_entity_id',
            (new ReferenceMarkup())->siblingId('something_else', 'field_reference', 'entity_id')
        );
    }

    public function testAnUnscopedFieldSaysNothingAboutScope(): void
    {
        $this->assertStringNotContainsString('scope=', $this->render('Entity', ''));
    }

    /**
     * A field that does not say what it points at is a mistake in the form, and
     * an empty dropdown would hide it from whoever wrote that form.
     */
    public function testAFieldWithNoObjectTypeRefusesToRender(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/objecttype/');

        $this->render('', '');
    }

    /**
     * A stored value is model data, so it is escaped before it lands in an
     * attribute.
     */
    public function testAValueCannotBreakOutOfTheMarkup(): void
    {
        $html = $this->render('Entity', '"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testTheObjectTypeCannotEither(): void
    {
        $this->assertStringNotContainsString(
            '"><script>',
            $this->render('"><script>alert(1)</script>', '')
        );
    }

    private function render(string $objectType, string $value): string
    {
        return (new ReferenceMarkup())->render(
            $objectType,
            'jform[reference][reference0][reference]',
            'jform_reference__reference0_reference',
            $value
        );
    }
}
