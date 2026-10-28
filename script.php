<?php

/**
 * @package     Yepr\Gen
 *
 * @copyright   Copyright (C) Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Install script for the library: one job, refusing an uninstall that would
 * break the extensions using it.
 *
 * Joomla does not track dependencies between extensions, so without this the
 * library can be uninstalled from Manage like anything else, and every
 * component that carries it fails with "Class Yepr\Gen\... not found" the next
 * time it is opened.
 *
 * The check is in preflight because that is the only point where a refusal
 * counts: `InstallerAdapter::uninstall()` stops when preflight returns false,
 * before a file is removed, and ignores what `uninstall()` returns.
 *
 * Not every uninstall is somebody removing the library. Installing a library
 * over an existing one starts with `LibraryAdapter::checkExtensionInFilesystem()`
 * uninstalling the old one, with this script, before the new one's preflight;
 * and uninstalling a package uninstalls its children. Both run on an Installer
 * marked with `setPackageUninstall(true)`, and Manage never sets it, so that is
 * what tells them apart. 0.19.1 refused those too: the update went on regardless,
 * because nothing reads what that inner uninstall returns, and left the old
 * row beside the new one. `postflight` clears that up.
 *
 * The components leave the library in place when they are uninstalled
 * themselves, so the last one going does not take it along. Removing it after
 * that is a deliberate act, and nothing stops it.
 */
return new class () implements InstallerScriptInterface {
    /**
     * The extensions that cannot work without this library, by element, with
     * the name their administrator menu shows.
     *
     * @var array<string, string>
     */
    public const CONSUMERS = [
        'com_extengen' => 'Extension Generator',
        'com_metagen'  => 'Metalanguage Generator',
        'com_gengen'   => 'Generator Generator',
        'com_pluggen'  => 'Plug-gen',
    ];

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall' || $adapter->getParent()->isPackageUninstall()) {
            return true;
        }

        $blockers = $this->blockers($this->installedComponents());

        if ($blockers === []) {
            return true;
        }

        // The library has no language files of its own, and a message about
        // its own removal is the one place it would need them.
        Factory::getApplication()->enqueueMessage(
            'The Yepr Gen Library is still used by: ' . implode(', ', $blockers)
            . '. Uninstall those first, then the library.',
            'error'
        );

        return false;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall') {
            $this->removeStaleRows();
        }

        return true;
    }

    /**
     * Of several rows for this library, the one to keep: the newest.
     *
     * An install inserts its row, so the one just written has the highest id.
     *
     * @param   int[]  $ids  Extension ids of every row for this library.
     *
     * @return  int[]  The ids to remove.
     */
    public function staleRows(array $ids): array
    {
        sort($ids);
        array_pop($ids);

        return $ids;
    }

    /**
     * Remove the rows an update under 0.19.1 left behind.
     *
     * The files are the new library's either way; only the old record stays,
     * still at the old version, so Joomla keeps offering it an update and
     * Manage lists the library twice.
     */
    private function removeStaleRows(): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('library'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('yepr_gen'));

        $stale = $this->staleRows(array_map('intval', $db->setQuery($query)->loadColumn()));

        if ($stale === []) {
            return;
        }

        foreach (['#__update_sites_extensions', '#__updates', '#__extensions'] as $table) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($table))
                    ->whereIn($db->quoteName('extension_id'), $stale)
            )->execute();
        }
    }

    /**
     * The names of the consumers among the installed components.
     *
     * Disabled ones count: their files are still there, and enabling one again
     * would meet the missing library.
     *
     * @param   string[]  $installed  Elements of the installed components.
     *
     * @return  string[]
     */
    public function blockers(array $installed): array
    {
        return array_values(array_intersect_key(self::CONSUMERS, array_flip($installed)));
    }

    /**
     * @return  string[]  Elements of the installed consumers.
     */
    private function installedComponents(): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->whereIn($db->quoteName('element'), array_keys(self::CONSUMERS), ParameterType::STRING);

        return $db->setQuery($query)->loadColumn();
    }
};
