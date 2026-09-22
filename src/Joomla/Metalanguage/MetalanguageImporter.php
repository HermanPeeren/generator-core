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
        $installer = new MetalanguageInstaller($this->siteRoot);
        $entry     = $installer->install($archive);

        $this->record($entry, $installer->manifestJson());

        return $entry;
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
