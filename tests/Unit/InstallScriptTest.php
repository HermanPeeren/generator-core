<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The library refuses to be uninstalled while a component needs it.
 *
 * Joomla tracks no dependencies between extensions, so before this the library
 * went from Manage like anything else, and each component that carries it then
 * failed on its first page with "Class Yepr\Gen\... not found". What is checked
 * here is the wiring and the decision; the database lookup and the refusal
 * itself were tried on a real site, where they are the installer's business.
 */
final class InstallScriptTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function script(): object
    {
        static $script;

        if ($script === null) {
            \defined('_JEXEC') || \define('_JEXEC', 1);

            require_once self::root() . '/tests/Support/Joomla/InstallerScriptInterface.php';

            $script = require self::root() . '/script.php';
        }

        return $script;
    }

    public function testTheManifestNamesTheScriptAndTheScriptExists(): void
    {
        $manifest = simplexml_load_file(self::root() . '/yepr_gen.xml');

        $this->assertNotFalse($manifest);

        $script = trim((string) $manifest->scriptfile);

        $this->assertSame('script.php', $script);
        $this->assertFileExists(self::root() . '/' . $script);
    }

    public function testEveryConsumerBlocksTheUninstall(): void
    {
        $script = self::script();

        $this->assertSame(
            ['Extension Generator', 'Metalanguage Generator', 'Generator Generator', 'Plug-gen'],
            $script->blockers(['com_pluggen', 'com_gengen', 'com_metagen', 'com_extengen'])
        );
    }

    public function testOtherComponentsDoNot(): void
    {
        $script = self::script();

        $this->assertSame([], $script->blockers([]));
        $this->assertSame([], $script->blockers(['com_content', 'com_users']));
        $this->assertSame(['Generator Generator'], $script->blockers(['com_content', 'com_gengen']));
    }

    /**
     * Installing over a library run through 0.19.1's script leaves the old row
     * beside the new one; the newest, the row just written, is kept.
     */
    public function testOnlyTheNewestRowIsKept(): void
    {
        $script = self::script();

        $this->assertSame([], $script->staleRows([281]));
        $this->assertSame([], $script->staleRows([]));
        $this->assertSame([281, 282], $script->staleRows([283, 281, 282]));
    }
}
