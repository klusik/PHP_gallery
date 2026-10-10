/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-public-widgets.js
 * Module Type: Browser Module
 * Purpose: Enhance widget Markdown authoring and viewport placement without client-side persistence.
 * Responsibilities: Keep native form actions functional without JavaScript; use safe server preview.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Replace only the selected Markdown text with an intentionally simple formatting token.
 *
 * @param {HTMLTextAreaElement} editor Bounded authored content field.
 * @param {string} command Whitelisted formatting command.
 * @returns {void} Inserts Markdown and restores keyboard focus.
 */
function formatWidgetMarkdown(editor, command) {
    const begin = editor.selectionStart;
    const end = editor.selectionEnd;
    const selected = editor.value.slice(begin, end);
    const fallback = selected || 'text';
    const replacements = {
        bold: () => '**' + fallback + '**',
        italic: () => '*' + fallback + '*',
        heading: () => '## ' + (selected || 'Heading'),
        list: () => (selected || 'List item').split('\n').map((line) => '- ' + line).join('\n'),
        ordered: () => (selected || 'List item').split('\n').map((line, index) => String(index + 1) + '. ' + line).join('\n'),
        link: () => '[' + (selected || 'Link text') + '](https://example.org)',
    };
    if (!Object.prototype.hasOwnProperty.call(replacements, command)) return;
    const value = replacements[command]();
    editor.setRangeText(value, begin, end, 'select');
    editor.dispatchEvent(new Event('input', { bubbles: true }));
    editor.focus();
}

/**
 * Synchronize supported page zones and flow/floating settings before form submission.
 *
 * @param {HTMLFormElement} form Active widget editor.
 * @returns {() => void} Safe refresh function shared by preview controls.
 */
function setupWidgetPlacement(form) {
    const mode = form.querySelector('[name="placement_mode"]');
    const scope = form.querySelector('[name="page_scope"]');
    const zone = form.querySelector('[name="flow_slot"]');
    const anchor = form.querySelector('[name="floating_anchor"]');
    const x = form.querySelector('[name="x_permille"]');
    const y = form.querySelector('[name="y_permille"]');
    const width = form.querySelector('[name="width_px"]');
    const flow = form.querySelector('[data-widget-flow-controls]');
    const floating = form.querySelector('[data-widget-floating-controls]');
    const preview = document.querySelector('[data-widget-preview]');
    const card = preview?.querySelector('.public-content-widget');
    const tools = document.createElement('section');
    tools.className = 'public-widgets-placement-tools';
    const toggle = document.createElement('div');
    toggle.className = 'public-widgets-device-toggle';
    const deviceNames = ['desktop', 'tablet', 'mobile'];
    const devices = deviceNames.map((name) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'secondary';
        button.textContent = name.slice(0, 1).toUpperCase() + name.slice(1);
        button.setAttribute('aria-pressed', name === 'desktop' ? 'true' : 'false');
        button.addEventListener('click', () => {
            stage.dataset.device = name;
            for (const other of devices) other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            refresh();
        });
        toggle.append(button);
        return button;
    });
    const stage = document.createElement('div');
    stage.className = 'public-widgets-placement-stage';
    stage.dataset.device = 'desktop';
    stage.tabIndex = 0;
    stage.setAttribute('role', 'button');
    stage.setAttribute('aria-label', 'Floating widget location preview. Click or use arrow keys to select custom coordinates.');
    const marker = document.createElement('span');
    marker.className = 'public-widgets-position-marker';
    marker.textContent = 'Widget';
    stage.append(marker);
    const caption = document.createElement('p');
    caption.className = 'public-widgets-placement-caption';
    caption.textContent = 'Select desktop, tablet or mobile. Click inside the dashed preview to set a custom floating location.';
    tools.append(toggle, stage, caption);
    floating?.insertAdjacentElement('afterend', tools);
    const clamp = (value) => Math.min(1000, Math.max(0, Math.round(Number(value) || 0)));
    const anchorCoords = {
        'top-left': [90, 100], 'top-center': [500, 100], 'top-right': [910, 100],
        'middle-left': [90, 500], 'middle-right': [910, 500],
        'bottom-left': [90, 900], 'bottom-center': [500, 900], 'bottom-right': [910, 900],
    };
    const refresh = () => {
        const isFloating = mode?.value === 'floating';
        if (flow) flow.hidden = isFloating;
        if (floating) floating.hidden = !isFloating;
        if (scope?.value === 'gallery' && ['home_before_grid', 'home_after_grid'].includes(zone?.value)) zone.value = 'content_top';
        const galleryOnly = scope?.value === 'gallery';
        for (const option of Array.from(zone?.options || [])) {
            option.disabled = galleryOnly && ['home_before_grid', 'home_after_grid'].includes(option.value);
        }
        stage.dataset.mode = isFloating ? 'floating' : 'flow';
        const xy = anchorCoords[anchor?.value] || [clamp(x?.value), clamp(y?.value)];
        marker.style.left = String(xy[0] / 10) + '%';
        marker.style.top = String(xy[1] / 10) + '%';
        if (card && width) card.style.maxWidth = String(Math.min(480, Math.max(180, Number(width.value) || 320))) + 'px';
        caption.textContent = stage.dataset.device === 'mobile'
            ? 'Mobile uses an accessible in-page fallback instead of a fixed overlay.'
            : isFloating ? 'Click or use arrow keys in the preview to customize the floating position.'
                : 'In-page widget follows normal page flow and the selected content zone.';
    };
    stage.addEventListener('click', (event) => {
        if (mode?.value !== 'floating') return;
        const rect = stage.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;
        x.value = String(clamp(1000 * (event.clientX - rect.left) / rect.width));
        y.value = String(clamp(1000 * (event.clientY - rect.top) / rect.height));
        anchor.value = 'custom';
        refresh();
    });
    stage.addEventListener('keydown', (event) => {
        const dx = event.key === 'ArrowLeft' ? -10 : event.key === 'ArrowRight' ? 10 : 0;
        const dy = event.key === 'ArrowUp' ? -10 : event.key === 'ArrowDown' ? 10 : 0;
        if ((!dx && !dy) || mode?.value !== 'floating') return;
        event.preventDefault();
        x.value = String(clamp((anchorCoords[anchor?.value]?.[0] ?? Number(x.value)) + dx));
        y.value = String(clamp((anchorCoords[anchor?.value]?.[1] ?? Number(y.value)) + dy));
        anchor.value = 'custom';
        refresh();
    });
    for (const input of [mode, scope, zone, anchor, x, y, width]) {
        input?.addEventListener('input', refresh);
        input?.addEventListener('change', refresh);
    }
    refresh();
    return refresh;
}

/**
 * Attach the editor to its dedicated Admin DOM only, leaving all other pages inert.
 *
 * Native POST actions remain authoritative. AJAX is used solely for a preview,
 * which is returned by the authenticated server using the same safe renderer.
 *
 * @returns {void} Installs idempotent progressive enhancement on the editor.
 */
export function setupAdminPublicWidgets() {
    const form = document.querySelector('[data-public-widget-editor]');
    if (!(form instanceof HTMLFormElement) || form.dataset.widgetEnhanced === '1') return;
    form.dataset.widgetEnhanced = '1';
    const editor = form.querySelector('[data-widget-source]');
    if (!(editor instanceof HTMLTextAreaElement)) return;
    for (const button of form.querySelectorAll('[data-widget-format]')) {
        button.addEventListener('click', () => formatWidgetMarkdown(editor, button.dataset.widgetFormat || ''));
    }
    const refresh = setupWidgetPlacement(form);
    const preview = document.querySelector('[data-widget-preview]');
    const body = preview?.querySelector('[data-widget-preview-body]');
    const title = preview?.querySelector('.public-content-widget-title');
    const card = preview?.querySelector('.public-content-widget');
    const notice = document.createElement('p');
    notice.setAttribute('role', 'status');
    preview?.append(notice);
    form.addEventListener('submit', async (event) => {
        const submitter = event.submitter;
        if (!(submitter instanceof HTMLButtonElement) || submitter.value !== 'preview' || submitter.name !== 'widget_action') return;
        if (!body || !card || !window.fetch) return;
        event.preventDefault();
        const data = new FormData(form);
        data.set('widget_action', 'preview');
        data.set('widget_preview_json', '1');
        notice.textContent = 'Rendering preview…';
        submitter.disabled = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true || typeof result.html !== 'string') {
                throw new Error(result.message || 'Preview failed.');
            }
            body.innerHTML = result.html;
            const nextTitle = String(result.title || '');
            let heading = title || card.querySelector('.public-content-widget-title');
            if (!heading && nextTitle) {
                heading = document.createElement('h4');
                heading.className = 'public-content-widget-title';
                card.insertBefore(heading, card.firstChild);
            }
            if (heading) {
                heading.textContent = nextTitle;
                heading.hidden = nextTitle === '';
            }
            card.classList.toggle('public-content-widget--minimal', result.appearance === 'minimal');
            card.classList.toggle('public-content-widget--card', result.appearance !== 'minimal');
            refresh();
            notice.textContent = result.message || 'Preview updated. No changes saved.';
        } catch (error) {
            notice.textContent = error instanceof Error ? error.message : 'Preview unavailable.';
        } finally {
            submitter.disabled = false;
        }
    });
}
