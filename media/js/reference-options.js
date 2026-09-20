/**
 * What a reference dropdown should offer, given what is stored and what is on screen.
 *
 * @copyright  Copyright (C) 2023+, Yepr, Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE.txt
 */

/**
 * The whole decision, as a function of data.
 *
 * It is separate from the element that uses it because this is the part with
 * rules in it - what beats what, what is in scope, what order things come in -
 * and a function over plain objects can be tested without a browser. The
 * element around it only reads the DOM and writes the DOM.
 *
 * `stored` is the project as the server last saw it. `live` is what the form
 * holds right now, including entities somebody added a minute ago and has not
 * saved. Live wins on both counts: a renamed object shows its new name, and a
 * newly added one is offered although the server has never heard of it. That
 * is the whole of "you should not have to save before you can refer to
 * something".
 *
 * @param {object}   args
 * @param {Array}    args.stored    Entries `{id, name, parent?}` from the page's index.
 * @param {Array}    args.live      Entries `{id, name, parent?}` read from the form.
 * @param {?string}  args.parent    When set, only entries belonging to this parent.
 * @param {string}   args.selected  The id currently held, which is kept even if
 *                                  nothing else knows about it.
 * @returns {Array} `{id, name}` in the order they should appear.
 */
export function referenceOptions({ stored = [], live = [], parent = null, selected = '' } = {}) {
  const byId = new Map();

  for (const entry of stored) {
    if (entry && entry.id) {
      byId.set(entry.id, { ...entry });
    }
  }

  // Second, so that it overwrites: the form is more current than the database.
  for (const entry of live) {
    if (entry && entry.id) {
      byId.set(entry.id, { ...byId.get(entry.id), ...entry });
    }
  }

  let entries = [...byId.values()];

  if (parent !== null && parent !== '') {
    // A dropdown scoped to a parent - the fields of one entity - shows only
    // that parent's children. An entry with no parent at all is not in scope
    // of any parent, so it is not offered here either.
    entries = entries.filter((entry) => entry.parent === parent);
  }

  // An object that exists but has not been named yet would be a blank line in
  // the list, indistinguishable from the empty choice.
  entries = entries.filter((entry) => typeof entry.name === 'string' && entry.name !== '');

  entries.sort((a, b) => a.name.localeCompare(b.name));

  // The held value survives even when it is out of scope or gone: dropping it
  // would silently rewrite a stored reference the moment somebody opened the
  // form. Better an entry that reads as missing than a reference that changes
  // itself while nobody is looking.
  if (selected && !entries.some((entry) => entry.id === selected)) {
    const known = byId.get(selected);

    entries = [
      ...entries,
      { id: selected, name: known && known.name ? known.name : `(missing: ${selected})` },
    ];
  }

  return entries.map((entry) => ({ id: entry.id, name: entry.name }));
}

/**
 * The live state of one object type, read out of the form.
 *
 * Every repeating row carries a name input and a hidden id beside it, and the
 * two are related by their element ids: the name input's id contains
 * `entity_name`, and the id input's has `entity_id` in the same place. That
 * substitution is the contract between the form XML and this file, and it is
 * why `idToken` and `nameToken` are arguments rather than assumptions - a
 * second meta-language names its objects differently.
 *
 * A row whose id is still empty gets one here. An object has to have an
 * identity before anything can point at it, and the first moment that matters
 * is when somebody names it.
 *
 * Some object types share a row and differ only by what the row says it is. A
 * LionCore language entity is a Classifier or a DataType, and a Classifier is a
 * Concept, a ConceptInterface or an Annotation - all one repeating group with
 * one name input. `when` is how a dropdown for Concepts skips the rows that are
 * something else, read live, so changing the radio changes what the dropdowns
 * next to it offer. The server applies the same conditions to the stored model;
 * both lists come from one table.
 *
 * @param {object}    args
 * @param {Document}  args.root        Where to look.
 * @param {string}    args.selector    Class on the name inputs, for instance `entityName`.
 * @param {string}    args.nameToken   The part of the id that marks a name input.
 * @param {string}    args.idToken     What that part becomes on the id input.
 * @param {?string}   args.parentToken What it becomes on the parent's id input, for a child type.
 * @param {?string}   args.parentCut   Where to cut a child's id to reach its parent's row, when
 *                                     the child's own parent field is still empty.
 * @param {Array}     args.when        `{token, value}` conditions the row must satisfy.
 * @param {function}  args.newId       Makes an identifier for a row that has none.
 * @returns {Array} `{id, name, parent?}` for each row on screen.
 */
export function liveEntries({
  root,
  selector,
  nameToken,
  idToken,
  parentToken = null,
  parentCut = null,
  when = [],
  newId,
}) {
  const rows = [...root.querySelectorAll(`.${selector}`)];

  return rows.map((nameInput) => {
    for (const condition of when) {
      if (valueInRow(root, nameInput, nameToken, condition.token) !== condition.value) {
        return null;
      }
    }

    const idInput = root.getElementById(nameInput.id.replace(nameToken, idToken));

    // A name with no id beside it is a form that does not match this contract.
    // Skipping is right: throwing would take down every other dropdown too.
    if (!idInput) {
      return null;
    }

    if (!idInput.value) {
      idInput.value = newId();
    }

    const entry = { id: idInput.value, name: nameInput.value };

    if (parentToken) {
      entry.parent = parentOf(root, nameInput, nameToken, parentToken, parentCut);
    }

    return entry;
  }).filter(Boolean);
}

/**
 * What another input in the same row currently holds.
 *
 * The same id substitution the name and id inputs use, because it is the only
 * relation between two fields of one row that Joomla guarantees. A radio group
 * is the awkward case: Joomla puts the id on the fieldset and numbers the
 * inputs inside it, so the fieldset is what the substitution finds and the
 * checked input is what the answer is.
 */
function valueInRow(root, nameInput, nameToken, token) {
  const element = root.getElementById(nameInput.id.replace(nameToken, token));

  if (!element) {
    return '';
  }

  if (typeof element.value === 'string') {
    return element.value;
  }

  const checked = element.querySelector('input:checked');

  return checked ? checked.value : '';
}

/**
 * Which parent a child row belongs to.
 *
 * Usually the child carries it in a hidden field. A row somebody has just
 * added does not - nothing has filled it in yet - so the parent is found by
 * position instead: a child's element id is its parent's id with the child's
 * own repeating group appended, so cutting there and asking for the parent's
 * id field gets there. That is the same walk the old `getParentIdForChild()`
 * did, with the two names it hard-coded passed in.
 */
function parentOf(root, nameInput, nameToken, parentToken, parentCut) {
  const parentInput = root.getElementById(nameInput.id.replace(nameToken, parentToken));

  if (!parentInput) {
    return '';
  }

  if (parentInput.value) {
    return parentInput.value;
  }

  if (parentCut && nameInput.id.includes(parentCut)) {
    const prefix = nameInput.id.slice(0, nameInput.id.indexOf(parentCut));
    const owner = root.getElementById(`${prefix}_${parentToken}`);

    if (owner && owner.value) {
      // Remember it, so the next read and the submitted form agree.
      parentInput.value = owner.value;
    }
  }

  return parentInput.value;
}

export function lowerFirst(text) {
  return text.charAt(0).toLowerCase() + text.slice(1);
}
