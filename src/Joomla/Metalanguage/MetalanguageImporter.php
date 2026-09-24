<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Metalanguage
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Joomla\Metalanguage;

use Joomla\Database\DatabaseInterface;
use Yepr\Gen\Core\Package\AncestryCheck;
use Yepr\Gen\Core\Package\PackageManifest;
use Yepr\Gen\Core\Package\PackageReader;

/**
 * Importing a metalanguage: its files, and then its row.
 *
 * The files are `MetalanguageInstaller`'s job and the row is this one's. They
 * are separate because everything that can go wrong with a package goes wrong
 * in the first half, and a class that needs a database is a class no unit test
 * here can reach - the suite runs with two constants standing in for Joomla
 * and boots nothing.
 *
 * @since  0.6.0
 */
final class MetalanguageImporter
{
    /**
     * @param  DatabaseInterface  $database  The site's database.
     * @param  string             $table     Where this component keeps its imported languages.
     * @param  string             $siteRoot  Absolute path of the site.
     *
     * @since  0.6.0
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly string $table,
        private readonly string $siteRoot
    ) {
    }

    /**
     * Install one package and record it, replacing that language and version.
     *
     * Replacing rather than refusing: re-importing is how somebody picks up a
     * language they have just changed in Meta-gen, and a version still being
     * worked on gets re-exported a dozen times before it is finished. A
     * version that is finished gets a new number, which is what the number is
     * for.
     *
     * @param  string  $archive  Absolute path of the zip to read.
     *
     * @throws \RuntimeException  When the package is refused, with every reason in the message.
     *
     * @since  0.6.0
     */
    public function import(string $archive): MetalanguageEntry
    {
        // Read before anything is written, so a package this site will not
        // accept leaves nothing behind. `PackageReader` is what says whether it
        // is a package at all; `install()` says it again, because an importer
        // that trusted a caller to have checked is one nobody checks for.
        $this->assertAncestryHolds(PackageReader::fromZip($archive)->manifest());

        $installer = new MetalanguageInstaller($this->siteRoot);
        $entry     = $installer->install($archive);

        $this->record($entry, $installer->manifestJson());

        return $entry;
    }

    /**
     * Refuse a language that breaks the one it derives from: step 4.5.
     *
     * A derived language may **add** and may not **remove or rename**. That is
     * what makes the parent's generators run over the child's models, and the
     * failure it prevents is silent: a parent's rule selects `Entity`, the
     * child renamed it, the selector returns nothing, every rule fires zero
     * times, and out comes a package with a manifest and a third of its files,
     * looking exactly like one that worked.
     *
     * At import rather than at export, because this is the site where the
     * damage would show and the site that has the parent. A parent this site
     * has not imported cannot be checked and is not refused - the child is
     * usable for everything except that parent's generators, which
     * `Ancestry::missing()` is how a screen says.
     *
     * @throws \RuntimeException  With every reason in the message.
     *
     * @since  0.12.0
     */
    private function assertAncestryHolds(PackageManifest $manifest): void
    {
        if ($manifest->dependsOn === []) {
            return;
        }

        $catalogue = new MetalanguageCatalogue($this->database, $this->table);
        $problems  = [];

        foreach ($manifest->dependsOn as $parent) {
            $entry = $catalogue->forRecord((string) $parent['key'], (string) $parent['version']);

            if ($entry === null) {
                continue;
            }

            $problems = array_merge($problems, AncestryCheck::problems(
                $entry->concepts,
                $manifest->concepts,
                $entry->label()
            ));
        }

        if ($problems !== []) {
            throw new \RuntimeException(
                $manifest->label() . ' cannot be imported: ' . implode(' ', $problems)
            );
        }
    }

    /**
     * Put this language in the table, replacing the row for it if there is one.
     *
     * @since  0.6.0
     */
    private function record(MetalanguageEntry $entry, string $manifest): void
    {
        // Bound through locals: bind() takes its value by reference, and a
        // readonly property cannot be passed that way.
        $key     = $entry->key;
        $version = $entry->version;

        $delete = $this->database->getQuery(true)
            ->delete($this->database->quoteName($this->table))
            ->where($this->database->quoteName('lang_key') . ' = :key')
            ->where($this->database->quoteName('version') . ' = :version')
            ->bind(':key', $key)
            ->bind(':version', $version);

        $this->database->setQuery($delete)->execute();

        $row = (object) [
            'lang_key'      => $entry->key,
            'version'       => $entry->version,
            'name'          => $entry->name,
            'root'          => $entry->root,
            'form_root'     => $entry->formRoot,
            'language_file' => $entry->languageFile,
            'manifest'      => $manifest,
            'imported'      => gmdate('Y-m-d H:i:s'),
            'published'     => 1,
        ];

        $this->database->insertObject($this->table, $row, 'id');
    }
}
