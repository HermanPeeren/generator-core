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
 * Every metalanguage a site has: step 3.4.
 *
 * The imported ones and any the component ships, in one list - which is the
 * shape the plan asked for: *ER1 is one entry in that list like any other*.
 * Everything above this asks the catalogue rather than asking whether a
 * language was imported, so turning a shipped language into a package is one
 * argument fewer here and no change anywhere else.
 *
 * @since  0.6.0
 */
final class MetalanguageCatalogue
{
    /**
     * Languages the component ships, offered before the imported ones.
     *
     * @var MetalanguageEntry[]
     *
     * @since  0.6.0
     */
    private array $builtIn;

    /**
     * @param  DatabaseInterface  $database  The site's database.
     * @param  string             $table     Where this component keeps its imported languages.
     * @param  MetalanguageEntry  ...$builtIn  Languages it ships, if any.
     *
     * @since  0.6.0
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly string $table,
        MetalanguageEntry ...$builtIn
    ) {
        $this->builtIn = $builtIn;
    }

    /**
     * Every language this component can use, the shipped ones first.
     *
     * First because a shipped language is what an unbound record is written
     * in, so it is the sensible default in a dropdown, and because it is the
     * only one every site has.
     *
     * @return MetalanguageEntry[]
     *
     * @since  0.6.0
     */
    public function all(): array
    {
        $entries = $this->builtIn;

        foreach ($this->rows() as $row) {
            $entries[] = MetalanguageEntry::fromRow($row);
        }

        return $entries;
    }

    /**
     * The language a stored record says it is written in.
     *
     * A binding naming a language that is not here any more falls back to the
     * first shipped one, because the alternative is an edit screen that cannot
     * open at all: somebody who removed a language out from under a record
     * needs to see the record, not a stack trace, and the forms they get are
     * at least forms. A component that ships none gets null, and has to say
     * something about it.
     *
     * @param  string  $key      What the record stored.
     * @param  string  $version  And which version of it.
     *
     * @since  0.6.0
     */
    public function forRecord(string $key, string $version): ?MetalanguageEntry
    {
        foreach ($this->all() as $entry) {
            if ($entry->answersTo($key, $version)) {
                return $entry;
            }
        }

        return $this->builtIn[0] ?? null;
    }

    /**
     * Whether a binding names something this site actually has.
     *
     * Separate from `forProject()` on purpose: the screen wants to say "the
     * language this project was written in is not installed" and still open,
     * and it cannot do that if the fallback is invisible.
     *
     * @since  0.6.0
     */
    public function knows(string $key, string $version): bool
    {
        foreach ($this->all() as $entry) {
            if ($entry->answersTo($key, $version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One imported language by key and version, or null.
     *
     * @since  0.6.0
     */
    public function imported(string $key, string $version): ?MetalanguageEntry
    {
        foreach ($this->rows() as $row) {
            $entry = MetalanguageEntry::fromRow($row);

            if ($entry->key === $key && $entry->version === $version) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The imported rows, newest name order.
     *
     * @return object[]
     *
     * @since  0.6.0
     */
    private function rows(): array
    {
        $query = $this->database->getQuery(true)
            ->select('*')
            ->from($this->database->quoteName($this->table))
            ->where($this->database->quoteName('published') . ' = 1')
            ->order($this->database->quoteName('name') . ' ASC')
            ->order($this->database->quoteName('version') . ' ASC');

        $this->database->setQuery($query);

        return $this->database->loadObjectList() ?: [];
    }
}
