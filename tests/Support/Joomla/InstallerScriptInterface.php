<?php

/**
 * Stand-in for Joomla's InstallerScriptInterface, for the one test that loads
 * the library's install script.
 *
 * The suite runs without Joomla: CI fetches it only for the analyser, after
 * the tests. The script's class implements this interface and is never handed
 * to an installer here, so an empty one is enough to load it. A real Joomla's
 * interface, when one is already loaded, wins.
 */

declare(strict_types=1);

namespace Joomla\CMS\Installer;

if (!interface_exists(InstallerScriptInterface::class)) {
    interface InstallerScriptInterface
    {
    }
}
