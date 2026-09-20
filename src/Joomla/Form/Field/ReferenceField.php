<?php

/**
 * @package     Yepr Gen
 * @subpackage  Joomla
 *
 * @copyright   Copyright (C) Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Yepr\Gen\Joomla\Form\Field;

use Joomla\CMS\Form\FormField;
use Yepr\Gen\Core\Reference\ReferenceMarkup;

/**
 * A dropdown holding a reference to something else in the model.
 *
 *     <field name="extends" type="Reference" objecttype="Concept" />
 *
 * **Why this is in the library and not in a component.** Three components edit
 * models with reference dropdowns - Exten-gen a project, Meta-gen a language,
 * Gen-gen a generator - and this class is four lines of adapter around a
 * contract that is written down in two places at once, `ReferenceMarkup` and
 * `reference.js`. One copy per component would be three copies of that
 * contract, which is the arrangement 1.9 and 3.1 both existed to end.
 *
 * **Why it is not in `Core`.** `Core` imports no framework, ever: that rule is
 * what keeps the engine usable from Drupal, Symfony or a plain script, and a
 * class extending `FormField` would end it. `Yepr\Gen\Joomla` is the library's
 * other half - the Joomla-shaped code the generator extensions share - and the
 * plan said as much when it called the engine the library's *first* occupant.
 * Nothing under `Core` may reach into here, and a test says so.
 *
 * Nothing is read here. The choices are decided in the browser by
 * `<yepr-reference>`, from the index the page carries plus whatever the form
 * holds right now, which is what makes an object added a minute ago available
 * to point at without saving first. Options baked into `<option>` tags at
 * render time can only ever describe the database.
 *
 * @since  0.4.0
 */
class ReferenceField extends FormField
{
    /**
     * The field type, as the form XML names it.
     *
     * @var string
     *
     * @since  0.4.0
     */
    protected $type = 'Reference';

    /**
     * The markup for one reference dropdown.
     *
     * @since  0.4.0
     */
    protected function getInput(): string
    {
        $markup = new ReferenceMarkup();
        $scope  = (string) ($this->element['scope'] ?? '');

        return $markup->render(
            (string) ($this->element['objecttype'] ?? ''),
            (string) $this->name,
            (string) $this->id,
            (string) $this->value,
            // A scoped dropdown - the fields of one entity - names the sibling
            // field holding its parent. The form XML gives that field's name;
            // only PHP knows the rest of the element id, because Joomla builds
            // it from where the field sits in a repeating group.
            $scope === '' ? null : $markup->siblingId((string) $this->id, (string) $this->fieldname, $scope),
            (string) ($this->element['class'] ?? '')
        );
    }
}
