/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/tag-suggestions.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides reusable admin tag entry, selected tag pills, and context-ranked suggestions.
 *
 * Responsibilities:
 *   - Preserve the existing comma-separated form payload expected by PHP
 *   - Render selected tags as removable pills immediately after selection
 *   - Render context-ranked tag suggestions without waiting for input focus
 *   - Keep suggestion matching local, predictable, and independent of network calls
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-28
 */

/**
 * Attach enhanced tag editors to all server-rendered tag inputs in a DOM scope.
 *
 * The original input remains the visible typing control. JavaScript moves the
 * form field name onto a hidden input so the submitted value is still the same
 * comma-separated text that the PHP save routes already expect.
 *
 * @param {ParentNode} root DOM scope that may contain tag inputs.
 * @returns {void}
 */
export function setupTagSuggestions(root = document) {
    const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
    scope.querySelectorAll('[data-tag-input]').forEach((input) => {
        if (!(input instanceof HTMLInputElement) || input.dataset.tagSuggestionsBound === '1') {
            return;
        }

        input.dataset.tagSuggestionsBound = '1';
        createTagEditor(input);
    });
}

/**
 * Build one enhanced tag editor around a normal text input.
 *
 * @param {HTMLInputElement} input Original server-rendered tag input.
 * @returns {void}
 */
function createTagEditor(input) {
    const originalName = input.getAttribute('name') || '';
    const initialTags = parseTagList(input.value);
    const hiddenInput = createHiddenPayloadInput(input, originalName, initialTags);
    const allNames = readDatalistNames(input);
    const weightedSuggestions = readWeightedSuggestions(input);
    const weightedNames = weightedSuggestions.map((entry) => entry.name).filter(Boolean);
    const knownNames = Array.from(new Set([...weightedNames, ...allNames]));

    const selected = new Set(initialTags);
    const editor = document.createElement('div');
    const selectedPills = document.createElement('div');
    const suggestions = document.createElement('div');

    editor.className = 'tag-input-editor';
    selectedPills.className = 'tag-selected-pills';
    selectedPills.setAttribute('aria-live', 'polite');
    suggestions.className = 'tag-suggestions';
    suggestions.setAttribute('role', 'listbox');

    input.insertAdjacentElement('beforebegin', editor);
    editor.append(selectedPills, input, suggestions);

    input.removeAttribute('name');
    input.removeAttribute('list');
    input.value = '';
    input.autocomplete = 'off';
    input.placeholder = input.dataset.tagPlaceholder || 'Type a tag, then press comma or Enter';

    /**
     * Sync the hidden comma-separated payload and redraw the visible controls.
     *
     * @returns {void}
     */
    function syncEditor() {
        hiddenInput.value = Array.from(selected).join(', ');
        renderSelectedPills(selectedPills, selected, removeTag);
        renderSuggestions(suggestions, input, selected, knownNames, weightedSuggestions, chooseTag);
    }

    /**
     * Add a tag from the current text field, a separator commit, or a suggestion click.
     *
     * @param {string} rawName Raw tag entered or selected by the admin.
     * @returns {boolean} True when a tag was added.
     */
    function chooseTag(rawName) {
        const name = normalizeTagName(rawName);
        if (name === '' || selected.has(name)) {
            input.value = '';
            renderSuggestions(suggestions, input, selected, knownNames, weightedSuggestions, chooseTag);
            return false;
        }
        selected.add(name);
        input.value = '';
        syncEditor();
        input.dispatchEvent(new Event('change', {bubbles: true}));
        hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
        input.focus();
        return true;
    }

    /**
     * Remove one selected tag pill.
     *
     * @param {string} rawName Raw tag name from the clicked pill.
     * @returns {void}
     */
    function removeTag(rawName) {
        selected.delete(normalizeTagName(rawName));
        syncEditor();
        input.dispatchEvent(new Event('change', {bubbles: true}));
        hiddenInput.dispatchEvent(new Event('change', {bubbles: true}));
        input.focus();
    }

    /**
     * Commit all complete tags typed into the input and keep an unfinished tail.
     *
     * @returns {void}
     */
    function commitSeparatedInput() {
        const text = String(input.value || '');
        const hasTrailingSeparator = /[,;\n]\s*$/.test(text);
        const parts = text.split(/[,;\n]/);
        const tail = hasTrailingSeparator ? '' : parts.pop() || '';
        parts.forEach((part) => {
            const name = normalizeTagName(part);
            if (name !== '') {
                selected.add(name);
            }
        });
        if (hasTrailingSeparator) {
            const finalName = normalizeTagName(tail);
            if (finalName !== '') {
                selected.add(finalName);
            }
        }
        input.value = normalizeTagName(tail);
        syncEditor();
    }

    input.addEventListener('input', () => {
        const before = input.value;
        if (/[,;\n]/.test(before)) {
            commitSeparatedInput();
            return;
        }
        const normalized = normalizeTagName(before);
        if (before !== normalized) {
            input.value = normalized;
        }
        renderSuggestions(suggestions, input, selected, knownNames, weightedSuggestions, chooseTag);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ',') {
            event.preventDefault();
            chooseTag(input.value);
            return;
        }
        if (event.key === 'Backspace' && input.value === '' && selected.size > 0) {
            const lastName = Array.from(selected).pop();
            if (lastName) {
                selected.delete(lastName);
                syncEditor();
            }
        }
    });

    input.addEventListener('blur', () => {
        if (input.value.trim() !== '') {
            chooseTag(input.value);
        }
    });

    input.addEventListener('focus', () => {
        renderSuggestions(suggestions, input, selected, knownNames, weightedSuggestions, chooseTag);
    });

    syncEditor();
}

/**
 * Create the hidden input that carries the real form value after enhancement.
 *
 * @param {HTMLInputElement} input Original visible input.
 * @param {string} originalName Original form field name.
 * @param {string[]} initialTags Initial normalized selected tags.
 * @returns {HTMLInputElement} Hidden payload input.
 */
function createHiddenPayloadInput(input, originalName, initialTags) {
    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.value = initialTags.join(', ');
    if (originalName !== '') {
        hiddenInput.name = originalName;
    }
    input.insertAdjacentElement('afterend', hiddenInput);
    return hiddenInput;
}

/**
 * Convert arbitrary tag text into the canonical lowercase safe form used by PHP.
 *
 * @param {string} value Raw tag name.
 * @returns {string} Safe lowercase tag name.
 */
function normalizeTagName(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase()
        .slice(0, 100);
}

/**
 * Parse comma-separated tag text into unique normalized names.
 *
 * @param {string} value Raw comma-separated input value.
 * @returns {string[]} Unique normalized tag names in original order.
 */
function parseTagList(value) {
    const tags = [];
    String(value || '').split(/[,;\n]/).forEach((part) => {
        const name = normalizeTagName(part);
        if (name !== '' && !tags.includes(name)) {
            tags.push(name);
        }
    });
    return tags;
}

/**
 * Read all known tag names from the input datalist.
 *
 * @param {HTMLInputElement} input Tag input with an optional list attribute.
 * @returns {string[]} Known tag names.
 */
function readDatalistNames(input) {
    const listId = input.getAttribute('list') || '';
    const list = listId !== '' ? document.getElementById(listId) : null;
    if (!(list instanceof HTMLDataListElement)) {
        return [];
    }
    return Array.from(list.options)
        .map((option) => normalizeTagName(option.value))
        .filter(Boolean);
}

/**
 * Read server-ranked contextual suggestions from the input element.
 *
 * @param {HTMLInputElement} input Tag input with JSON suggestion data.
 * @returns {Array<{name: string, score: number, sources: string[]}>} Ranked suggestion entries.
 */
function readWeightedSuggestions(input) {
    const raw = input.dataset.tagWeightedSuggestions || '';
    if (raw === '') {
        return [];
    }
    try {
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return [];
        }
        return parsed
            .map((entry) => ({
                name: normalizeTagName(entry?.name || ''),
                score: Number(entry?.score || 0),
                sources: Array.isArray(entry?.sources) ? entry.sources.map(String) : [],
            }))
            .filter((entry) => entry.name !== '');
    } catch (error) {
        return [];
    }
}

/**
 * Render selected tag pills with remove buttons.
 *
 * @param {HTMLElement} container Target pill container.
 * @param {Set<string>} selected Selected normalized tag names.
 * @param {(name: string) => void} removeTag Callback used by remove buttons.
 * @returns {void}
 */
function renderSelectedPills(container, selected, removeTag) {
    container.innerHTML = '';
    Array.from(selected).forEach((name) => {
        const pill = document.createElement('button');
        pill.type = 'button';
        pill.className = 'tag-selected-pill';
        pill.setAttribute('aria-label', `Remove tag ${name}`);
        pill.textContent = name;

        const remove = document.createElement('span');
        remove.className = 'tag-selected-pill-remove';
        remove.setAttribute('aria-hidden', 'true');
        remove.textContent = '×';

        pill.append(remove);
        pill.addEventListener('click', () => removeTag(name));
        container.append(pill);
    });
    container.hidden = selected.size === 0;
}

/**
 * Return the current partial tag being typed.
 *
 * @param {HTMLInputElement} input Visible tag input.
 * @returns {string} Normalized fragment.
 */
function currentFragment(input) {
    return normalizeTagName(input.value || '');
}

/**
 * Score one suggestion against the current input text.
 *
 * @param {string} name Existing tag name.
 * @param {string} fragment Current normalized fragment.
 * @returns {number} Lower score is better. -1 means not a match.
 */
function suggestionScore(name, fragment) {
    const normalized = normalizeTagName(name);
    if (fragment === '') {
        return 0;
    }
    if (normalized.startsWith(fragment)) {
        return 0;
    }
    if (normalized.includes(fragment)) {
        return 1;
    }

    const compactName = normalized.replace(/[\s_-]+/g, '');
    const compactFragment = fragment.replace(/[\s_-]+/g, '');
    if (compactFragment !== '' && compactName.includes(compactFragment)) {
        return 2;
    }

    let cursor = 0;
    for (const character of compactFragment) {
        cursor = compactName.indexOf(character, cursor);
        if (cursor === -1) {
            return -1;
        }
        cursor += 1;
    }
    return compactFragment.length >= 2 ? 3 : -1;
}

/**
 * Render visible tag advice as clickable suggestion pills.
 *
 * @param {HTMLElement} container Suggestion container.
 * @param {HTMLInputElement} input Visible tag input.
 * @param {Set<string>} selected Selected normalized tag names.
 * @param {string[]} knownNames All known tag names.
 * @param {Array<{name: string, score: number, sources: string[]}>} weightedSuggestions Context-ranked suggestions.
 * @param {(name: string) => void} chooseTag Callback used by suggestion buttons.
 * @returns {void}
 */
function renderSuggestions(container, input, selected, knownNames, weightedSuggestions, chooseTag) {
    const fragment = currentFragment(input);
    const weightedByName = new Map(weightedSuggestions.map((entry, index) => [
        normalizeTagName(entry.name),
        {score: entry.score, index},
    ]));

    container.innerHTML = '';

    knownNames
        .map((name) => {
            const normalized = normalizeTagName(name);
            const weighted = weightedByName.get(normalized) || {score: 0, index: Number.MAX_SAFE_INTEGER};
            return {
                name: normalized,
                matchScore: suggestionScore(normalized, fragment),
                contextScore: weighted.score,
                contextIndex: weighted.index,
            };
        })
        .filter((entry) => entry.name !== '' && entry.matchScore >= 0 && !selected.has(entry.name))
        .sort((left, right) => {
            if (fragment === '') {
                return right.contextScore - left.contextScore
                    || left.contextIndex - right.contextIndex
                    || left.name.localeCompare(right.name);
            }
            return left.matchScore - right.matchScore
                || right.contextScore - left.contextScore
                || left.name.localeCompare(right.name);
        })
        .slice(0, 18)
        .forEach((entry) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = entry.name;
            button.setAttribute('role', 'option');
            button.title = entry.contextScore > 0 ? `Context score: ${entry.contextScore}` : 'Known tag';
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => chooseTag(entry.name));
            container.append(button);
        });

    container.hidden = container.children.length === 0;
}
