/**
 * Filter bars of the portal lists (forms marked data-sdp-filters) have no Search button:
 *  - choosing an option in a list (status, type, show, sort) applies it at once;
 *  - the search text is applied with Enter, or when the field is cleared with its "x".
 * A filter change always starts again at page 1 (the form does not send "page").
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const submit = (form) => {
    if (form.dataset.sdpSubmitting === '1') {
      return;
    }

    form.dataset.sdpSubmitting = '1';
    form.setAttribute('aria-busy', 'true');

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  };

  const init = (form) => {
    // A page restored by Back from the browser's cache keeps the "submitting" mark of the
    // filter it left with; without this its filters would no longer react.
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        delete form.dataset.sdpSubmitting;
        form.removeAttribute('aria-busy');
      }
    });

    form.querySelectorAll('select').forEach((select) => {
      select.addEventListener('change', () => submit(form));
    });

    form.querySelectorAll('input[type="search"], input[type="text"]').forEach((input) => {
      const initial = input.value;

      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) {
          event.preventDefault();
          submit(form);
        }
      });

      // The clear button ("x") of a search field: show everything again.
      input.addEventListener('search', () => {
        if (input.value === '' && initial !== '') {
          submit(form);
        }
      });
    });
  };

  const start = () => document.querySelectorAll('form[data-sdp-filters]').forEach(init);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  // The page content was replaced by its refreshed version (refresh.js).
  document.addEventListener('sdp:refreshed', start);
})();
