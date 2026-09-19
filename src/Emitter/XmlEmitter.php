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
 * Escaping for generated XML (manifests and form definitions).
 */
final class XmlEmitter
{
    /**
     * Escape text content of an element.
     *
     * @param   mixed  $value  The raw text.
     *
     * @return  string  The escaped text.
     *
     * @since   0.2.0
     */
    public static function text(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Escape an attribute value.
     *
     * @param   mixed  $value  The raw value.
     *
     * @return  string  The escaped value, without the surrounding quotes.
     *
     * @since   0.2.0
     */
    public static function attr(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * Check an element or attribute name.
     *
     * @param   string  $value  The proposed name.
     *
     * @return  string  The name, unchanged.
     *
     * @throws  \InvalidArgumentException  When the value is not a valid XML name.
     *
     * @since   0.2.0
     */
    public static function name(string $value): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid XML name.', $value));
        }

        return $value;
    }

    /**
     * Render an element with attributes, skipping attributes with an empty value.
     *
     * @param   string                       $name        The element name.
     * @param   array<string, scalar|null>   $attributes  Attribute name/value pairs.
     * @param   ?string                      $content     Text content, or null for a self-closing element.
     * @param   integer                      $indent      Indentation level in tabs.
     *
     * @return  string  The rendered element.
     *
     * @since   0.2.0
     */
    public static function element(string $name, array $attributes = [], ?string $content = null, int $indent = 0): string
    {
        $pad  = str_repeat("\t", $indent);
        $tag  = self::name($name);
        $attr = '';

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $attr .= ' ' . self::name((string) $key) . '="' . self::attr($value) . '"';
        }

        if ($content === null || $content === '') {
            return $pad . '<' . $tag . $attr . '/>';
        }

        return $pad . '<' . $tag . $attr . '>' . self::text($content) . '</' . $tag . '>';
    }
}
