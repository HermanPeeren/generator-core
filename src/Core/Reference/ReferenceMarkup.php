<?php

/**
 * @package     Yepr Gen
 * @subpackage  Core
 *
 * @copyright   Copyright (C) Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Core\Reference;

/**
 * The markup for one reference dropdown, and the whole contract with the client.
 *
 * Separate from `ReferenceField` because that class is the Joomla adapter -
 * it knows how to get a name, an id and a value out of a form - while this is
 * the agreement between the server and `reference.js`. Keeping it here means
 * the agreement can be tested, which it could not be inside a `FormField`:
 * the core has no framework in it, on purpose.
 *
 * The `<select>` is a real form control and posts on its own. The element only
 * fills in its choices. A reference is a uuid nobody can retype from memory,
 * so a page whose script failed to load must still submit the value it was
 * given rather than an empty one - which is why the held value is rendered
 * here as a selected option rather than left to JavaScript.
 *
 * @since  0.4.0
 */
final class ReferenceMarkup
{
    /**
     * @param  string   $objectType  What may be chosen, for instance `Entity`.
     * @param  string   $name        The form control's name.
     * @param  string   $id          Its element id.
     * @param  string   $value       The reference it already holds.
     * @param  ?string  $scopeId     Element id of the input holding the parent
     *                               this dropdown is scoped to, if any.
     * @param  string   $class       Presentation classes from the form XML.
     *
     * @since  0.4.0
     */
    public function render(
        string $objectType,
        string $name,
        string $id,
        string $value,
        ?string $scopeId = null,
        string $class = ''
    ): string {
        if ($objectType === '') {
            // A reference to nothing in particular cannot be filled in, and an
            // empty dropdown would hide that from whoever wrote the form.
            throw new \UnexpectedValueException(
                'A Reference field needs an objecttype attribute naming what it points at; ' . $name . ' has none.'
            );
        }

        $attributes = ' type="' . $this->escape($objectType) . '"';

        if ($scopeId !== null && $scopeId !== '') {
            $attributes .= ' scope="' . $this->escape($scopeId) . '"';
        }

        return '<yepr-reference' . $attributes . '>'
            . '<select name="' . $this->escape($name) . '"'
            . ' id="' . $this->escape($id) . '"'
            . ' class="' . $this->escape(trim('form-select ' . $class)) . '">'
            . $this->options($value)
            . '</select>'
            . '</yepr-reference>';
    }

    /**
     * The element id of a sibling field in the same repeating row.
     *
     * Joomla builds these ids by putting the field's own name on the end, so
     * swapping that name reaches another field beside it. The client side pairs
     * a name input with its hidden id in exactly the same way, which is what
     * makes the two halves able to agree without talking.
     *
     * @since  0.4.0
     */
    public function siblingId(string $id, string $ownName, string $siblingName): string
    {
        return str_ends_with($id, $ownName)
            ? substr($id, 0, -\strlen($ownName)) . $siblingName
            : $id . '_' . $siblingName;
    }

    /**
     * @since  0.4.0
     */
    private function options(string $value): string
    {
        $empty = '<option value=""> </option>';

        if ($value === '') {
            return $empty;
        }

        // The name is not known here - resolving a uuid to a name is the
        // index's job, and the index is in the page, not in this request's
        // markup. The client replaces this option as soon as it runs; until
        // then the value is what matters, not how it reads.
        return $empty . '<option value="' . $this->escape($value) . '" selected>' . $this->escape($value) . '</option>';
    }

    /**
     * @since  0.4.0
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
