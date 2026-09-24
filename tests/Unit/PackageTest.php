<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yepr\Gen\Core\Output\FileCollection;
use Yepr\Gen\Core\Output\ZipWriter;
use Yepr\Gen\Core\Package\MetalanguagePackage;
use Yepr\Gen\Core\Package\PackageManifest;
use Yepr\Gen\Core\Package\PackageReader;

/**
 * The metalanguage package format, which the library now owns.
 *
 * Written in Meta-gen at 3.3 and moved here at 3.4, when Exten-gen and Gen-gen
 * became readers too. A format one component writes is that component's
 * business; a format three components agree on is a mechanism, and this is
 * where the mechanism for the reference dropdown went for the same reason.
 *
 * **What is checked here is the format, not anybody's generator.** The
 * packages below are built by hand, a few files at a time, so that a change to
 * Meta-gen's forms generator cannot make these pass or fail. Meta-gen has its
 * own suite for the other half - that the package it produces is the one its
 * generator meant to produce.
 *
 * @since  0.5.0
 */
final class PackageTest extends TestCase
{
    /**
     * Paths written during a test, removed afterwards.
     *
     * @var string[]
     */
    private array $rubbish = [];

    protected function tearDown(): void
    {
        foreach ($this->rubbish as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                self::removeTree($path);
            }
        }

        $this->rubbish = [];

        parent::tearDown();
    }

    /**
     * A small package, complete and consistent.
     *
     * Two forms, one pointing at the other, a reference table, a language
     * file, the model and a manifest hashing all of it - which is the shape a
     * real one has, four files smaller.
     */
    private function package(): FileCollection
    {
        $files = new FileCollection();
        $root  = MetalanguagePackage::installRoot('Small', '1.0');

        $files->add(MetalanguagePackage::MODEL, "{\"name\":\"Small\",\"version\":\"1.0\"}\n");
        $files->add(
            MetalanguagePackage::formPath('Thing'),
            '<?xml version="1.0"?><form><fieldset><field name="part" type="subform" formsource="'
            . $root . MetalanguagePackage::formPath('Part') . '"/></fieldset></form>'
        );
        $files->add(
            MetalanguagePackage::formPath('Part'),
            '<?xml version="1.0"?><form><fieldset><field name="name" type="text"'
            . ' label="YEPR_SMALL_PART_FIELD_NAME_LABEL"/></fieldset></form>'
        );
        $files->add(MetalanguagePackage::REFERENCES, "{}\n");
        $files->add(
            MetalanguagePackage::languagePath('Small'),
            "; Small 1.0\nYEPR_SMALL_PART_FIELD_NAME_LABEL=\"A \\\"part\\\" of it\"\n"
        );

        $hashes = [];

        foreach ($files as $path => $contents) {
            $hashes[$path] = hash('sha256', $contents);
        }

        ksort($hashes);

        $files->add(MetalanguagePackage::MANIFEST, (new PackageManifest(
            'Small',
            'Small',
            '1.0',
            'Thing',
            $root,
            MetalanguagePackage::languagePath('Small'),
            MetalanguagePackage::TAG,
            [['key' => 'c-thing', 'name' => 'Thing'], ['key' => 'c-part', 'name' => 'Part']],
            $hashes,
            MetalanguagePackage::FORMAT,
            '2026-09-22T00:00:00+00:00'
        ))->toJson());

        return $files;
    }

    /**
     * That package, written to a zip nobody has to clean up by hand.
     */
    private function zip(?FileCollection $files = null): string
    {
        $path = sys_get_temp_dir() . '/yepr-package-' . bin2hex(random_bytes(6)) . '.zip';

        $this->rubbish[] = $path;

        (new ZipWriter())->write($files ?? $this->package(), $path);

        return $path;
    }

    public function testAPackageRoundTripsThroughAZip(): void
    {
        $files = $this->package();
        $read  = PackageReader::fromZip($this->zip($files));

        $this->assertSame([], $read->problems());
        $this->assertSame($files->all(), $read->files());
    }

    public function testAPackageReadsTheSameFromAnUnpackedTree(): void
    {
        $files = $this->package();
        $root  = sys_get_temp_dir() . '/yepr-tree-' . bin2hex(random_bytes(6));

        $this->rubbish[] = $root;

        mkdir($root, 0755, true);

        (new ZipWriter())->writeToDirectory($files, $root);

        $read = PackageReader::fromDirectory($root);

        $this->assertSame([], $read->problems());
        $this->assertSame($files->all(), $read->files());
    }

    public function testTheManifestComesBackAsItWentIn(): void
    {
        $manifest = PackageReader::fromZip($this->zip())->manifest();

        $this->assertSame('Small', $manifest->name);
        $this->assertSame('1.0', $manifest->version);
        $this->assertSame('Thing', $manifest->root);
        $this->assertSame('media/yepr_metalanguages/Small/1.0/', $manifest->formRoot);
        $this->assertSame('Small 1.0', $manifest->label());
    }

    /**
     * The manifest names the language's concepts, by key and by name.
     *
     * Both, because they answer different questions: a name is what a person
     * picks out of a list, and a key is what a rule should store, so that
     * renaming a concept in Meta-gen changes what a rule reads as rather than
     * what it points at. The key is also the half a LionWeb metapointer needs.
     */
    public function testTheManifestNamesTheConceptsByKeyAndName(): void
    {
        $concepts = PackageReader::fromZip($this->zip())->manifest()->concepts;

        $this->assertSame(
            [['key' => 'c-thing', 'name' => 'Thing'], ['key' => 'c-part', 'name' => 'Part']],
            $concepts
        );
    }

    /**
     * A package built before the manifest named concepts still reads.
     *
     * An empty list is the right answer for one of those - it holds
     * classifiers, but nothing in it says which - and refusing a package that
     * is otherwise complete would be refusing every package made last week.
     */
    public function testAManifestWithNoConceptsInItStillReads(): void
    {
        $manifest = PackageManifest::fromJson('{"format":1,"name":"Old","version":"1.0"}');

        $this->assertSame([], $manifest->concepts);
        $this->assertSame('Old', $manifest->name);
    }

    /**
     * The model comes back decoded, and no further.
     *
     * A `stdClass` rather than a model type, which is the seam this move is
     * about: the library says what is in a package, and each component says
     * what a language means.
     */
    public function testTheModelComesBackDecodedAndNoFurther(): void
    {
        $model = PackageReader::fromZip($this->zip())->model();

        $this->assertInstanceOf(\stdClass::class, $model);
        $this->assertSame('Small', $model->name);
    }

    /**
     * The strings come back the way Joomla's own loader reads them.
     *
     * Which means the escaped quote is undone. Joomla 3 wrote one `"_QQ_"`;
     * Joomla 4 changed to `\\"` and undoes it in a single `str_replace` at the
     * end of `LanguageHelper::parseIniFile()`. Written the old way the marker
     * arrives in the string verbatim, and nothing anywhere reports it.
     */
    public function testTheStringsComeBackTheWayJoomlaReadsThem(): void
    {
        $strings = PackageReader::fromZip($this->zip())->strings();

        $this->assertSame('A "part" of it', $strings['YEPR_SMALL_PART_FIELD_NAME_LABEL'] ?? null);
    }

    /**
     * A file that arrives changed is reported rather than loaded.
     *
     * The failure this stands for is a truncated download: a form file missing
     * its last bytes still parses far enough for Joomla to render a fieldset
     * with nothing in it, and reports nothing anywhere.
     */
    public function testAChangedFileIsNoticed(): void
    {
        $files = $this->package();
        $path  = $this->zip($files);
        $form  = MetalanguagePackage::formPath('Part');

        $zip = new \ZipArchive();

        $this->assertTrue($zip->open($path) === true);
        $zip->addFromString($form, 'truncated');
        $zip->close();

        $this->assertSame(
            [$form . ' is not the file the manifest describes.'],
            PackageReader::fromZip($path)->problems()
        );
    }

    public function testAMissingFileIsNoticed(): void
    {
        $path = $this->zip();
        $form = MetalanguagePackage::formPath('Part');

        $zip = new \ZipArchive();

        $this->assertTrue($zip->open($path) === true);
        $zip->deleteName($form);
        $zip->close();

        $this->assertSame(
            ['The manifest lists ' . $form . ', which is not in the package.'],
            PackageReader::fromZip($path)->problems()
        );
    }

    /**
     * And a file nothing in the manifest accounts for.
     *
     * Which is the one a person would not think to look for: something added
     * to the archive after it was built is a file the package will unpack onto
     * a site with nothing vouching for it.
     */
    public function testAFileTheManifestDoesNotAccountForIsNoticed(): void
    {
        $path = $this->zip();

        $zip = new \ZipArchive();

        $this->assertTrue($zip->open($path) === true);
        $zip->addFromString('forms/extra.xml', '<form/>');
        $zip->close();

        $this->assertSame(
            ['forms/extra.xml is in the package and not in the manifest.'],
            PackageReader::fromZip($path)->problems()
        );
    }

    public function testAPackageInAFormatThisVersionCannotReadSaysSo(): void
    {
        $files    = $this->package();
        $manifest = PackageManifest::fromJson($files->get(MetalanguagePackage::MANIFEST));

        $files->replace(MetalanguagePackage::MANIFEST, (new PackageManifest(
            $manifest->name,
            $manifest->key,
            $manifest->version,
            $manifest->root,
            $manifest->formRoot,
            $manifest->language,
            $manifest->tag,
            $manifest->concepts,
            $manifest->files,
            MetalanguagePackage::FORMAT + 1,
            $manifest->generated
        ))->toJson());

        $this->assertSame(
            [
                'This package is in format ' . (MetalanguagePackage::FORMAT + 1)
                . ' and this component reads formats ' . MetalanguagePackage::OLDEST_READABLE_FORMAT
                . ' to ' . MetalanguagePackage::FORMAT . '.',
            ],
            PackageReader::fromZip($this->zip($files))->problems()
        );
    }

    /**
     * And one in an older format is read, because the difference is additive.
     *
     * 4.5 added `dependsOn` and bumped the format to 2. Refusing a newer format
     * is the point - a package from a later Meta-gen may use a layout this
     * reader cannot parse. Refusing an *older* one is the opposite of the
     * point, and strict equality would have done it: every package ever built
     * is format 1, including the ER1 Exten-gen ships.
     */
    public function testAPackageInAnOlderFormatIsStillRead(): void
    {
        $files    = $this->package();
        $manifest = PackageManifest::fromJson($files->get(MetalanguagePackage::MANIFEST));

        $files->replace(MetalanguagePackage::MANIFEST, (new PackageManifest(
            $manifest->name,
            $manifest->key,
            $manifest->version,
            $manifest->root,
            $manifest->formRoot,
            $manifest->language,
            $manifest->tag,
            $manifest->concepts,
            $manifest->files,
            MetalanguagePackage::OLDEST_READABLE_FORMAT,
            $manifest->generated
        ))->toJson());

        $reader = PackageReader::fromZip($this->zip($files));

        $this->assertSame([], $reader->problems());

        // And it derives from nothing, which is what the absent field means.
        $this->assertSame([], $reader->manifest()->dependsOn);
    }

    public function testSomethingThatIsNotAPackageSaysSo(): void
    {
        $path = sys_get_temp_dir() . '/yepr-notapackage-' . bin2hex(random_bytes(6)) . '.zip';

        $this->rubbish[] = $path;

        $zip = new \ZipArchive();

        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'holiday photos');
        $zip->close();

        $this->assertSame(
            ['This is not a metalanguage package: it has no manifest.'],
            PackageReader::fromZip($path)->problems()
        );
    }

    public function testAnArchiveThatWillNotOpenThrowsRatherThanReadingNothing(): void
    {
        $path = sys_get_temp_dir() . '/yepr-notazip-' . bin2hex(random_bytes(6)) . '.zip';

        $this->rubbish[] = $path;

        file_put_contents($path, 'this is not a zip at all');

        $this->expectException(\RuntimeException::class);

        PackageReader::fromZip($path);
    }

    /**
     * A language may be called anything; a path may not.
     */
    public function testANameBecomesSomethingAPathCanHold(): void
    {
        $this->assertSame('LIonCore_M3', MetalanguagePackage::slug('LIonCore_M3'));
        $this->assertSame('MyLanguage', MetalanguagePackage::slug('My Language!'));
        $this->assertSame('unnamed', MetalanguagePackage::slug('***'));

        // A dot survives, because that is the point of a version.
        $this->assertSame('1.2.0', MetalanguagePackage::versionSlug('1.2.0'));
        $this->assertSame('0.0.0', MetalanguagePackage::versionSlug(''));

        $this->assertSame(
            'media/yepr_metalanguages/unnamed/0.0.0/',
            MetalanguagePackage::installRoot('', '')
        );
    }

    /**
     * A language file is named the way a file name has to be named.
     *
     * Lowercase: `LIonCore_M3.ini` and `lioncore_m3.ini` are one file on a Mac
     * or Windows and two on the server this runs on, and that family of defect
     * has now been found four times across these repositories.
     */
    public function testALanguageFileIsNamedForAFilesystemThatCares(): void
    {
        $this->assertSame(
            'language/en-GB/lioncore_m3.ini',
            MetalanguagePackage::languagePath('LIonCore_M3')
        );
        $this->assertSame('forms/languageEntity.xml', MetalanguagePackage::formPath('LanguageEntity'));
    }

    /**
     * A directory and everything under it.
     */
    private static function removeTree(string $root): void
    {
        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($tree as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }

        rmdir($root);
    }
}
