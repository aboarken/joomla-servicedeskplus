/**
 * Attachments that a browser can show (images and PDF: links marked data-sdp-preview) open in a
 * popup over the page, with "Open in new tab", "Download" and "Close". Every other attachment,
 * and every attachment without JavaScript, is downloaded as before.
 *
 * The popup only ever loads the link's own address on this site, with "inline=1" added; the server
 * decides again whether that file may be shown (see ApprovalController::attachment()).
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  let dialog = null;

  const close = () => {
    if (dialog) {
      dialog.close();
    }
  };

  const build = () => {
    dialog = document.createElement('dialog');
    dialog.className = 'sdp-preview';
    dialog.setAttribute('aria-labelledby', 'sdp-preview-title');
    dialog.innerHTML = '<div class="sdp-preview-head"><strong class="sdp-preview-title" id="sdp-preview-title"></strong>'
      + '<span class="sdp-preview-actions"><a class="sdp-back" data-sdp-preview-open target="_blank" rel="noopener"></a>'
      + '<a class="sdp-back" data-sdp-preview-download download></a>'
      + '<button type="button" class="sdp-back" data-sdp-preview-close></button></span></div>'
      + '<div class="sdp-preview-body"></div>';
    dialog.querySelector('[data-sdp-preview-close]').addEventListener('click', close);
    // A click on the dimmed area around the popup closes it.
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        close();
      }
    });
    // The file is unloaded when the popup closes.
    dialog.addEventListener('close', () => {
      dialog.querySelector('.sdp-preview-body').replaceChildren();
    });
    document.body.append(dialog);
  };

  const open = (link) => {
    let inline;

    try {
      inline = new URL(link.href, window.location.href);
    } catch (e) {
      return false;
    }

    // Only this site.
    if (inline.origin !== window.location.origin || typeof HTMLDialogElement === 'undefined') {
      return false;
    }

    inline.searchParams.set('inline', '1');

    if (!dialog) {
      build();
    }

    const labels = link.dataset;
    const name = link.dataset.sdpPreviewName || link.textContent.trim();
    const body = dialog.querySelector('.sdp-preview-body');
    let view;

    if (link.dataset.sdpPreview === 'image') {
      view = document.createElement('img');
      view.alt = name;
    } else {
      view = document.createElement('iframe');
      view.title = name;
    }

    view.src = inline.toString();
    body.replaceChildren(view);

    dialog.querySelector('.sdp-preview-title').textContent = name;

    const openLink = dialog.querySelector('[data-sdp-preview-open]');
    openLink.href = inline.toString();
    openLink.textContent = labels.sdpPreviewOpen || 'Open in new tab';

    const download = dialog.querySelector('[data-sdp-preview-download]');
    download.href = link.href;
    download.textContent = labels.sdpPreviewDownload || 'Download';

    dialog.querySelector('[data-sdp-preview-close]').textContent = labels.sdpPreviewClose || 'Close';
    dialog.showModal();

    return true;
  };

  document.addEventListener('click', (event) => {
    const link = event.target.closest ? event.target.closest('a[data-sdp-preview]') : null;

    // Modified clicks (new tab, save) keep their normal meaning.
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    if (open(link)) {
      event.preventDefault();
    }
  });
})();
