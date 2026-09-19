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
 * Turns values into PHP source safely.
 *
 * Templates must never concatenate a model value into PHP source directly. A
 * plugin name containing a quote is the same bug class as SQL injection, one
 * target language over, so every value passes through here.
 */
final class PhpEmitter
{
    /**
     * Render a value as a PHP string literal, correctly quoted and escaped.
     *
     * @param   mixed  $value  The value to render.
     *
     * @return  string  A literal that evaluates back to the original value.
     *
     * @since   0.2.0
     */
    public static function string(mixed $value): string
    {
        return var_export((string) $value, true);
    }

    /**
     * Render a value as a PHP boolean literal.
     *
     * @param   mixed  $value  The value to render.
     *
     * @return  string  Either "true" or "false".
     *
     * @since   0.2.0
     */
    public static function bool(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Render a value as a PHP integer literal.
     *
     * @param   mixed  $value  The value to render.
     *
     * @return  string  The integer literal.
     *
     * @since   0.2.0
     */
    public static function int(mixed $value): string
    {
        return (string) (int) $value;
    }

    /**
     * Render a short array literal, recursively escaped.
     *
     * @param   array<array-key, mixed>  $value   The array to render.
     * @param   integer  $indent  Indentation level of the closing bracket.
     *
     * @return  string  The array literal.
     *
     * @since   0.2.0
     */
    public static function arrayLiteral(array $value, int $indent = 0): string
    {
        if ($value === []) {
            return '[]';
        }

        $pad    = str_repeat(' ', ($indent + 1) * 4);
        $close  = str_repeat(' ', $indent * 4);
        $isList = array_is_list($value);
        $parts  = [];

        foreach ($value as $key => $item) {
            $rendered = match (true) {
                \is_array($item) => self::arrayLiteral($item, $indent + 1),
                \is_bool($item)  => self::bool($item),
                \is_int($item)   => self::int($item),
                default          => self::string($item),
            };

            $parts[] = $isList ? $pad . $rendered : $pad . self::string($key) . ' => ' . $rendered;
        }

        return "[\n" . implode(",\n", $parts) . ",\n" . $close . ']';
    }

    /**
     * Check an identifier that will be used verbatim in source.
     *
     * Rejects anything that is not a plain PHP identifier rather than escaping
     * it, because there is no safe escaping for this position: a class or method
     * name is syntax, not data.
     *
     * @param   string  $value  The proposed identifier.
     *
     * @return  string  The identifier, unchanged.
     *
     * @throws  \InvalidArgumentException  When the value is not a PHP identifier.
     *
     * @since   0.2.0
     */
    public static function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid PHP identifier.', $value));
        }

        return $value;
    }

    /**
     * Check a namespace, part by part.
     *
     * @param   string  $value  The namespace, with or without leading backslash.
     *
     * @return  string  The namespace without its leading backslash.
     *
     * @throws  \InvalidArgumentException  When any part is not a PHP identifier.
     *
     * @since   0.2.0
     */
    public static function namespaceName(string $value): string
    {
        $value = trim($value, '\\');

        foreach (explode('\\', $value) as $part) {
            self::identifier($part);
        }

        return $value;
    }

    /**
     * Indent a block of user-supplied code to sit correctly inside a method body.
     *
     * @param   string   $code    The code block.
     * @param   integer  $levels  Indentation levels of four spaces.
     *
     * @return  string  The indented block, or an empty string when there is no code.
     *
     * @since   0.2.0
     */
    public static function indentBlock(string $code, int $levels = 2): string
    {
        $code = trim($code);

        if ($code === '') {
            return '';
        }

        $pad   = str_repeat(' ', $levels * 4);
        $lines = preg_split('/\R/', $code);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $pad . $line,
            $lines
        ));
    }

    /**
     * Wrap text as a PHP comment block, neutralising any comment terminator in it.
     *
     * @param   string   $text    The comment text.
     * @param   integer  $levels  Indentation levels of four spaces.
     *
     * @return  string  The comment body lines, without the opening and closing markers.
     *
     * @since   0.2.0
     */
    public static function comment(string $text, int $levels = 1): string
    {
        $pad  = str_repeat(' ', $levels * 4);
        $text = str_replace('*/', '*\\/', $text);

        return implode("\n", array_map(
            static fn (string $line): string => rtrim($pad . ' * ' . $line),
            preg_split('/\R/', $text)
        ));
    }
}
