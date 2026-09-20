/**
 * <yepr-reference>: a dropdown that knows what the form currently holds.
 *
 * @copyright  Copyright (C) 2023+, Yepr, Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE.txt
 */

import { referenceOptions, liveEntries } from './reference-options.js';

/**
 * What the page carries: the stored model's references, and a description of
 * each object type. Both come from `ReferenceIndex` on the server, so there is
 * one statement of what an Entity or a Concept is rather than two.
 *
 * The key is `yepr.references` rather than any one component's, because the
 * three components that edit models all put the same payload in the page and a
 * page only ever edits one model at a time.
 */
function pageData() {
  const options = window.Joomla && typeof window.Joomla.getOptions === 'function'
    ? window.Joomla.getOptions('yepr.references')
    : null;

  return { index: {}, types: {}, ...(options || {}) };
}

/**
 * A v4 identifier, from the browser's own generator where there is one.
 *
 * `crypto.randomUUID` needs a secure context, which an administrator session
 * over plain http is not, so the fallback is not theoretical.
 */
function newId() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }

  return ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, (c) => (
    c ^ (window.crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))
  ).toString(16));
}

/**
 * A reference dropdown.
 *
 * It renders nothing itself: the `<select>` inside it is a real form control
 * that submits with or without JavaScript, and the element fills in its
 * choices. That is deliberate - a form whose values depend on a script having
 * run is a form that silently loses data when the script does not.
 *
 * Two attributes:
 *   type   which kind of object may be chosen, for instance `Entity`
 *   scope  for a child type, the element id of the input holding the parent
 *          this dropdown is scoped to. Absent means "all of them".
 */
class YeprReference extends HTMLElement {
  connectedCallback() {
    // Joomla clones a subform row to make a new one, so this can run again for
    // an element that has already been set up.
    if (this.wired) {
      this.refresh();

      return;
    }

    this.wired = true;

    const select = this.select();

    if (select) {
      // The hidden field beside the select is what the model stores, and it is
      // the only copy that survives a page where the options never arrived.
      select.addEventListener('change', () => {
        const backup = this.backup();

        if (backup) {
          backup.value = select.value;
        }
      });
    }

    this.refresh();
  }

  /**
   * Fill the dropdown, keeping whatever it holds.
   */
  refresh() {
    const select = this.select();
    const backup = this.backup();

    if (!select) {
      return;
    }

    const type = this.getAttribute('type');
    const { index, types } = pageData();
    const descriptor = types[type];

    if (!descriptor) {
      // An unknown type is a form and a server that disagree. Leaving the
      // select alone keeps whatever the server rendered, which is the least
      // destructive thing to do.
      return;
    }

    const selected = (backup && backup.value) || select.value || '';

    const options = referenceOptions({
      stored: index[type] || [],
      live: liveEntries({ root: document, ...descriptor, newId }),
      parent: this.scope(),
      selected,
    });

    const empty = new Option(' ', '');

    select.replaceChildren(
      empty,
      ...options.map((option) => new Option(option.name, option.id)),
    );

    select.value = selected;

    // A value the select would not take is one the backup must keep anyway.
    if (select.value !== selected && backup) {
      backup.value = selected;
    }
  }

  /** The parent this dropdown is scoped to, or null when it is not scoped. */
  scope() {
    const id = this.getAttribute('scope');

    if (!id) {
      return null;
    }

    const input = document.getElementById(id);

    return input ? input.value : null;
  }

  select() {
    return this.querySelector('select');
  }

  backup() {
    const select = this.select();

    return select ? document.getElementById(`${select.id}_id`) : null;
  }
}

if (!window.customElements.get('yepr-reference')) {
  window.customElements.define('yepr-reference', YeprReference);
}

/**
 * Renaming or adding an object updates every dropdown that could point at it.
 *
 * One listener on the document rather than an `onchange` attribute on each
 * field: a subform row added after load carries no handler anybody attached,
 * and the form XML stops having to name JavaScript functions. Two of the names
 * it did carry - `editChildConceptList` and `editConceptFieldsList` - had no
 * definition anywhere, so every keystroke in a field name threw.
 */
document.addEventListener('change', (event) => {
  const target = event.target;

  if (!target || !target.classList) {
    return;
  }

  const { types } = pageData();
  const watched = Object.values(types).some(
    (descriptor) => target.classList.contains(descriptor.selector),
  );

  if (!watched) {
    return;
  }

  for (const element of document.querySelectorAll('yepr-reference')) {
    element.refresh();
  }
});

export { YeprReference };
