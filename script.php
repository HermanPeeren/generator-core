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
        if ($type !== 'uninstall') {
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
        return true;
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
