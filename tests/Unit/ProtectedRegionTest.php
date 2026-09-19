<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Output\ProtectedRegionMerger;

/**
 * Regenerating must not throw away hand-written code.
 *
 * This is the feature people will trust most, so it gets the round-trip test:
 * generate, edit inside a region, regenerate, and check that the edit survived
 * while the generated code around it was updated.
 */
final class ProtectedRegionTest extends TestCase
{
    public function testUserCodeSurvivesRegeneration(): void
    {
        $edited = <<<'PHP'
            <?php
            class Recipes
            {
                protected $layout = 'old';

                protected function index($item)
                {
                    // <gen id="index.custom">
                    $item->addTaxonomy('Cuisine', $item->cuisine);
                    // </gen>
                }
            }
            PHP;

        $regenerated = <<<'PHP'
            <?php
            class Recipes
            {
                protected $layout = 'new';

                protected function index($item)
                {
                    // <gen id="index.custom">
                    // </gen>
                }
            }
            PHP;

        $merged = (new ProtectedRegionMerger())->merge($edited, $regenerated);

        $this->assertStringContainsString("\$item->addTaxonomy('Cuisine', \$item->cuisine);", $merged);
        $this->assertStringContainsString("protected \$layout = 'new';", $merged);
        $this->assertStringNotContainsString("protected \$layout = 'old';", $merged);
    }

    public function testRegionsThatDisappearAreReportedNotSilentlyDropped(): void
    {
        $edited = "// <gen id=\"gone\">\n\$keepMe = 1;\n// </gen>\n";
        $fresh  = "// <gen id=\"other\">\n// </gen>\n";

        $merger = new ProtectedRegionMerger();
        $merger->merge($edited, $fresh);

        $this->assertSame(['gone'], $merger->orphanedRegions());
    }

    public function testEmptyRegionsDoNotOverwriteGeneratedDefaults(): void
    {
        $existing = "// <gen id=\"a\">\n// </gen>\n";
        $fresh    = "// <gen id=\"a\">\n\$generated = 1;\n// </gen>\n";

        $this->assertSame($fresh, (new ProtectedRegionMerger())->merge($existing, $fresh));
    }

    public function testFirstGenerationNeedsNoExistingFile(): void
    {
        $fresh = "// <gen id=\"a\">\n// </gen>\n";

        $this->assertSame($fresh, (new ProtectedRegionMerger())->merge('', $fresh));
    }

    public function testTheMarkerTagIsConfigurable(): void
    {
        $merger   = new ProtectedRegionMerger('extengen');
        $existing = "// <extengen id=\"a\">\n\$mine = 1;\n// </extengen>\n";
        $fresh    = "// <extengen id=\"a\">\n// </extengen>\n";

        $this->assertStringContainsString('$mine = 1;', $merger->merge($existing, $fresh));
    }

    public function testAMergerIgnoresAnotherToolsMarkers(): void
    {
        $merger   = new ProtectedRegionMerger('extengen');
        $existing = "// <pluggen id=\"a\">\n\$theirs = 1;\n// </pluggen>\n";
        $fresh    = "// <extengen id=\"a\">\n// </extengen>\n";

        $this->assertSame($fresh, $merger->merge($existing, $fresh));
        $this->assertSame([], $merger->orphanedRegions());
    }

    public function testRegionRendersWithTheConfiguredTag(): void
    {
        $merger = new ProtectedRegionMerger('extengen');

        $this->assertSame(
            "        // <extengen id=\"a.b\">\n        // </extengen>",
            $merger->region('a.b')
        );
    }

    public function testAnUnusableTagIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProtectedRegionMerger('not a tag');
    }
}
