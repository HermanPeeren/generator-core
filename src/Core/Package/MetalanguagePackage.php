<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Package
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Package;

/**
 * What a metalanguage package is: step 3.3.
 *
 * A zip holding everything needed to edit a model written in one language -
 * the concept model it came from, the generated forms, the reference table
 * those forms' dropdowns read, a language file for the text on them, and a
 * manifest naming the language, its version and its root classifier.
 *
 * **Nothing in a package names a component**, and that is the whole shape of
 * it. The plan settled the same question about language strings first: a
 * package is consumed by com_extengen *and* com_gengen, so a key naming one of
 * them is wrong by construction. A *path* naming one of them is wrong for
 * exactly the same reason, and 3.2 left every generated file under
 * `administrator/components/com_metagen/forms/generated/` - the producer's own
 * directory, inside a file set the producer never reads.
 *
 * **Which forces a decision this step could not avoid.** A subform's
 * `formsource` is resolved by Joomla as `JPATH_ROOT . '/' . $formsource`
 * (`SubformField::__set()`), so the site-relative directory a package will be
 * unpacked into is baked into the XML at generation time. It cannot be
 * package-relative and it cannot be left out. So the package declares where it
 * expects to live:
 *
 *     media/yepr_metalanguages/<language>/<version>/
 *
 * `media/` because it is the one shared site directory no single extension's
 * uninstall owns; `<language>/<version>/` because 3.4 says a project records
 * the language it is written in *and* the version, which is only meaningful if
 * two versions can sit beside each other. Installing is then a plain unpack,
 * with no XML rewritten on the way in - which is what makes the round-trip
 * worth proving: the forms that run are the bytes that were generated.
 *
 * **And the root is recorded rather than assumed.** `formRoot` is in the
 * manifest, so an importer that must put a language somewhere else can see
 * that the paths do not match and regenerate, instead of unpacking a set of
 * forms whose subforms all point at a directory that is not there - which is
 * the silent kind of failure this repository has now met three times.
 *
 * @since  0.5.0
 */
final class MetalanguagePackage
{
    /**
     * The manifest, at the root of the package.
     *
     * @since  0.5.0
     */
    public const MANIFEST = 'manifest.json';

    /**
     * The concept model the package was generated from.
     *
     * It travels with its own output because a language that arrives somewhere
     * can then be regenerated there - the difference between importing forms
     * and importing a language.
     *
     * @since  0.5.0
     */
    public const MODEL = 'model.json';

    /**
     * Where the generated forms sit inside the package.
     *
     * @since  0.5.0
     */
    public const FORMS = 'forms/';

    /**
     * The reference table, beside the forms whose dropdowns read it.
     *
     * @since  0.5.0
     */
    public const REFERENCES = self::FORMS . 'references.json';

    /**
     * Where language files sit, in the layout Joomla's own loader expects.
     *
     * `Language::load($extension, $basePath)` looks for
     * `$basePath/language/<tag>/<extension>.ini`, so a consumer loads the
     * package's strings by pointing that at the unpacked root and nothing else
     * has to know the shape of this.
     *
     * @since  0.5.0
     */
    public const LANGUAGE = 'language/';

    /**
     * The one language tag generated so far.
     *
     * A modelled language holds one label per feature and no notion of a
     * translation, so there is one file and it is the site default's fallback.
     * Translating a metalanguage is a model gap rather than a format gap.
     *
     * @since  0.5.0
     */
    public const TAG = 'en-GB';

    /**
     * Where a package expects to be unpacked, relative to the site root.
     *
     * @since  0.5.0
     */
    public const INSTALL_ROOT = 'media/yepr_metalanguages/';

    /**
     * The version of this format, carried in every manifest and written by this
     * version of the library.
     *
     * A reader refuses a number it does not know rather than guessing at a
     * layout, because a package that half-loads is a language with holes in it.
     *
     * @since  0.5.0
     */
    public const FORMAT = 3;

    /**
     * The oldest format this library still reads.
     *
     * Refusing a *newer* format is the point: a package from a later Meta-gen
     * may use a layout this reader cannot parse, and guessing at one is how a
     * language arrives with holes in it. Refusing an older one is the opposite
     * of the point, and 4.5 nearly did it - bumping the number to 2 for
     * `dependsOn` would have made this reader reject every package ever built,
     * including the ER1 that Exten-gen ships.
     *
     * 1, 2 and 3 differ by two fields - `dependsOn` on the language, `features`
     * on each concept - and an absent one is meaningful in both cases: "derives
     * from nothing", and "this package does not say". So all three are
     * readable, and this constant is what the next format change has to argue
     * with: raising it is a deliberate statement that the difference is no
     * longer additive.
     *
     * @since  0.11.0
     */
    public const OLDEST_READABLE_FORMAT = 1;

    /**
     * What a version becomes when the language does not give one.
     *
     * @since  0.5.0
     */
    public const UNVERSIONED = '0.0.0';

    /**
     * A name as it can appear in a path: letters, digits, underscore, dash.
     *
     * A language may be called anything at all, and its name becomes a
     * directory, a file name and part of a language constant.
     *
     * @since  0.5.0
     */
    public static function slug(string $name): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9_-]+/', '', $name);

        return $slug === '' ? 'unnamed' : $slug;
    }

    /**
     * A version as it can appear in a path.
     *
     * A dot is kept - `1.2.0` is the point of a version - and everything else
     * a path cannot hold is not.
     *
     * @since  0.5.0
     */
    public static function versionSlug(string $version): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9._-]+/', '', $version);

        return $slug === '' ? self::UNVERSIONED : $slug;
    }

    /**
     * The site-relative directory a package of this language expects.
     *
     * With a trailing slash, because everything that uses it concatenates.
     *
     * @since  0.5.0
     */
    public static function installRoot(string $name, string $version): string
    {
        return self::INSTALL_ROOT . self::slug($name) . '/' . self::versionSlug($version) . '/';
    }

    /**
     * What Joomla's language loader calls this language's file.
     *
     * Lowercase, because it becomes a file name: `LIonCore_M3` and
     * `lioncore_m3` are one file on the machine this is written on and two on
     * the server it will run on, which is the defect this repository has now
     * found four times.
     *
     * @since  0.5.0
     */
    public static function languageExtension(string $name): string
    {
        return strtolower(self::slug($name));
    }

    /**
     * The package-relative path of the language file.
     *
     * @since  0.5.0
     */
    public static function languagePath(string $name, string $tag = self::TAG): string
    {
        return self::LANGUAGE . $tag . '/' . self::languageExtension($name) . '.ini';
    }

    /**
     * The package-relative path of one classifier's form.
     *
     * @since  0.5.0
     */
    public static function formPath(string $classifierName): string
    {
        return self::FORMS . lcfirst($classifierName) . '.xml';
    }
}
