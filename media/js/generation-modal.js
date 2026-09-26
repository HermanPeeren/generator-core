/**
 * @copyright  Copyright (C) Yepr, Herman Peeren. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE.txt
 */

/**
 * The Generate button fills the modal's iframe before Bootstrap opens it.
 *
 * Each row of the metalanguages list carries its own generate URL in
 * `data-href`, and there is one modal for the whole page. So the click has to
 * say *which* language before the dialog appears, and that is all this does.
 *
 * It was twelve lines inline in a template in Exten-gen and twelve identical
 * lines in Meta-gen, each with a note asking to be moved out. They were, into
 * a file per component - and then there were two copies of one rule, which is
 * the mistake this family keeps paying for. `field_type`, the subtype payload
 * key and `reference_id` were each a name written down twice that drifted.
 *
 * So it lives here, beside `reference.js`, and both components ask for
 * `lib_yepr_gen.generation-modal`. A fix to it is a fix to both.
 *
 * The two exported functions take what they work on rather than reaching for
 * it, so Node can call them with plain objects and no DOM. What is left below
 * them is wiring, and that is the Cypress spec's half.
 *
 * @since  1.5.0
 */

/**
 * Which document a click wants shown, or null when it names none.
 *
 * Via `closest` rather than reading the clicked element directly. The old
 * version took `event.target.getAttribute('data-href')`, which is the same
 * thing only while the button contains nothing: put an icon in it - which is
 * what every other button on these screens has - and the click lands on the
 * span, `data-href` comes back null, and the modal opens on whatever it was
 * showing last. Nothing would have reported that either.
 *
 * @param   {Element|null}  target  The element the click landed on.
 *
 * @return  {string|null}  The URL, or null.
 */
export function modalSource(target) {
  const button = target && typeof target.closest === 'function' ? target.closest('.dynbutton') : null;

  if (button === null) {
    return null;
  }

  const source = button.getAttribute('data-href');

  return source === null || source === '' ? null : source;
}

/**
 * Point the modal's iframe at what this click asked for.
 *
 * Returns whether it did, so that a caller - and a test - can tell the
 * difference between "shown" and "there was nothing to show". A click that
 * names nothing leaves the iframe alone rather than blanking it, because a
 * modal showing the previous language is wrong and a modal showing an empty
 * frame is wrong in a way that looks like the page is broken.
 *
 * @param   {Element|null}   target  The element the click landed on.
 * @param   {Document|null}  root    Where to look for the modal.
 *
 * @return  {boolean}  Whether an iframe was pointed anywhere.
 */
export function showInModal(target, root) {
  const source = modalSource(target);

  if (source === null || !root || typeof root.querySelector !== 'function') {
    return false;
  }

  const frame = root.querySelector('#generationModal iframe');

  if (!frame) {
    return false;
  }

  frame.setAttribute('src', source);

  return true;
}

/**
 * Listen on every generate button in the page.
 *
 * @param   {Document}  root  The document to wire up.
 *
 * @return  {void}
 */
function wire(root) {
  for (const button of root.querySelectorAll('.dynbutton')) {
    button.addEventListener('click', (event) => {
      // The button is an <a href="#generationModal">, so without this the
      // page jumps. Bootstrap's own modal toggle is a separate delegated
      // listener and still runs.
      event.preventDefault();

      showInModal(event.target, root);
    });
  }
}

// Guarded because this file is also imported by `node --test`, which has no
// document and wants the two functions above rather than the behaviour.
//
// Inside a browser: loaded as a module, so it runs deferred and the document
// may already be complete - in which case DOMContentLoaded is never coming.
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => wire(document));
  } else {
    wire(document);
  }
}
