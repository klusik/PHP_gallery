/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: website/assets/site.js
 * Module Type: Project Website Enhancement
 * Purpose: Add presentation themes and authentic screenshot enlargement.
 * Responsibilities: Preserve native links, image pixels and browser modal focus.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
'use strict';

/**
 * Enhance the static website without changing its native navigation or captures.
 * @returns {void} Installs page-local palette controls and supported modal links.
 */
function enhanceWebsite() {
    const themeButton = document.querySelector('.theme-toggle');
    themeButton.hidden = false;
    themeButton.addEventListener('click', () => {
        const dark = document.documentElement.dataset.theme !== 'dark';
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        const label = dark ? themeButton.dataset.lightLabel
            : themeButton.dataset.darkLabel;
        themeButton.setAttribute('aria-label', label);
        themeButton.setAttribute('title', label);
        themeButton.querySelector('use').setAttribute('href',
            dark ? '#i-sun' : '#i-moon');
    });

    const dialog = document.querySelector('.screenshot-dialog');
    // Permanent progressive enhancement: browsers without native dialogs keep
    // ordinary full-image links. Website navigation and radio tabs need no JS.
    // Owner: website/assets/site.js; retain until that support contract changes.
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const captures = Array.from(document.querySelectorAll('[data-screenshot]'));
    let selected = 0;

    /**
     * Select an original screenshot and synchronize its source and accessible copy.
     * @param {number} index Requested screenshot index, wrapping at either end.
     * @returns {void} Updates the fitted image, heading, source link and counter.
     */
    function selectCapture(index) {
        selected = ((index % captures.length) + captures.length) % captures.length;
        const capture = captures[selected];
        const image = dialog.querySelector('.dialog-image');
        image.src = capture.href;
        image.alt = capture.querySelector('img').alt;
        dialog.querySelector('#capture-title').textContent = capture.dataset.title;
        const source = dialog.querySelector('[data-capture-source]');
        source.href = capture.dataset.source;
        source.textContent = capture.dataset.sourceLabel + ' ↗';
        dialog.querySelector('[data-capture-counter]').textContent =
            `${selected + 1} / ${captures.length}`;
    }

    for (const [index, capture] of captures.entries()) {
        capture.addEventListener('click', event => {
            // Modified clicks retain ordinary browser open/save behavior.
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            selectCapture(index);
            dialog.showModal();
        });
    }
    for (const link of document.querySelectorAll('[data-open-capture]')) {
        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            selectCapture(captures.findIndex(capture =>
                capture.dataset.captureId === link.dataset.openCapture));
            dialog.showModal();
        });
    }
    dialog.querySelector('[data-close-capture]').addEventListener('click', () => {
        dialog.close();
    });
    dialog.querySelector('[data-previous-capture]').addEventListener('click', () => {
        selectCapture(selected - 1);
    });
    dialog.querySelector('[data-next-capture]').addEventListener('click', () => {
        selectCapture(selected + 1);
    });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            selectCapture(selected + (event.key === 'ArrowRight' ? 1 : -1));
        }
    });
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            dialog.close();
        }
    });
}

enhanceWebsite();
