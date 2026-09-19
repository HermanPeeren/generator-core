<?php

/**
 * @package     GeneratorCore
 * @subpackage  Emitter
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\GeneratorCore\Emitter;

/**
 * Escaping for ini-style language files.
 *
 * An ini value is parsed as a single double quoted string, so a raw quote or a
 * newline in a translation breaks the whole file - and a broken language file
 * fails silently, showing raw keys in the interface, which is a miserable thing
 * to debug.
 *
 * How a literal quote is spelled inside that value is a dialect question, not an
 * ini one: Joomla writes "_QQ_", other consumers use a backslash escape. The
 * default follows Joomla because that is the first target, but a target that
 * spells it differently passes its own.
 *
 * @since  0.2.0
 */
final class IniEmitter
{
    /**
     * Joomla spells an embedded double quote as "_QQ_".
     *
     * @var    string
     * @since  0.2.0
     */
    public const QUOTE_JOOMLA = '"_QQ_"';

    /**
     * The common ini dialect spells it as a backslash escape.
     *
     * @var    string
     * @since  0.2.0
     */
    public const QUOTE_BACKSLASH = '\\"';

    /**
     * Render one KEY="value" line.
     *
     * @param   string  $key    The language key.
     * @param   string  $value  The translation.
     *
     * @return  string  The escaped line, without a trailing newline.
     *
     * @since   0.2.0
     */
    public static function line(string $key, string $value, string $quote = self::QUOTE_JOOMLA): string
    {
        return self::key($key) . '="' . self::value($value, $quote) . '"';
    }

    /**
     * Normalise and check a language key.
     *
     * @param   string  $key  The language key, in any case.
     *
     * @return  string  The upper case key.
     *
     * @throws  \InvalidArgumentException  When the key is not a valid language key.
     *
     * @since   0.2.0
     */
    public static function key(string $key): string
    {
        $key = strtoupper($key);

        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid language key.', $key));
        }

        return $key;
    }

    /**
     * Escape a translation so it cannot break out of its quoted value.
     *
     * @param   string  $value  The raw translation.
     *
     * @return  string  The escaped value, without the surrounding quotes.
     *
     * @since   0.2.0
     */
    public static function value(string $value, string $quote = self::QUOTE_JOOMLA): string
    {
        // Collapse newlines: an ini value is a single line.
        $value = preg_replace('/\R+/', ' ', $value) ?? '';

        // A double quote has to be spelled the way this dialect spells it.
        $value = str_replace('"', $quote, $value);

        // Strip control characters that would corrupt the file.
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    }

    /**
     * Render a comment line.
     *
     * @param   string  $text  The comment text.
     *
     * @return  string  The comment, collapsed onto one line.
     *
     * @since   0.2.0
     */
    public static function comment(string $text): string
    {
        return '; ' . (preg_replace('/\R+/', ' ', $text) ?? '');
    }
}
