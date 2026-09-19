<?php

/**
 * @package     Yepr Gen Library
 * @subpackage  Emitter
 *
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Emitter;

/**
 * Escaping for ini-style language files.
 *
 * An ini value is parsed as a single double quoted string, so a raw quote or a
 * newline in a translation breaks the whole file - and a broken language file
 * fails silently, showing raw keys in the interface, which is a miserable thing
 * to debug.
 *
 * An embedded double quote is written as a backslash escape, which is both the
 * ordinary ini spelling and what Joomla has expected since 4.0: the file is read
 * with parse_ini_string() in RAW mode, and the single postprocessing step in
 * LanguageHelper::parseIniFile() is str_replace('\"', '"', $strings).
 *
 * The older Joomla spelling "_QQ_" is deliberately not supported. It is not
 * merely legacy: nothing replaces it any more, so a value written that way now
 * reaches the interface with a literal _QQ_ in it.
 *
 * @since  0.2.0
 */
final class IniEmitter
{
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
    public static function line(string $key, string $value): string
    {
        return self::key($key) . '="' . self::value($value) . '"';
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
    public static function value(string $value): string
    {
        // Collapse newlines: an ini value is a single line.
        $value = preg_replace('/\R+/', ' ', $value) ?? '';

        // A double quote is written as a backslash escape.
        //
        // Only the quote. Doubling backslashes would be wrong here: the file is
        // read in RAW mode, where no escape is processed, and the one thing the
        // reader undoes is \" - so a doubled backslash stays doubled and reaches
        // the interface that way. Verified by round-tripping a Windows path and
        // a trailing backslash through parse_ini_string(RAW) plus that replace.
        $value = str_replace('"', '\\"', $value);

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
