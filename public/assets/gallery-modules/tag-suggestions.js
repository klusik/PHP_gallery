/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/tag-suggestions.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides reusable admin tag input sanitizing and suggestions.
 *
 * Responsibilities:
 *   - Attach behavior to existing server-rendered markup
 *   - Keep DOM interaction predictable and readable
 *   - Avoid unnecessary layout work in performance-sensitive paths
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
 *   2026-05-12
 */

export function setupTagSuggestions(root = document) {
    // Tag fields still store comma-separated text, but this helper makes reused
    // tags discoverable while the admin types each comma-separated value.
    const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
    scope.querySelectorAll('[data-tag-input]').forEach((input) => {
        if (!(input instanceof HTMLInputElement) || input.dataset.tagSuggestionsBound === '1') {
            return;
        }
        input.dataset.tagSuggestionsBound = '1';
        // Variable `list` stores this steps working value.
        const listId = input.getAttribute('list') || '';
        // Variable `list` stores this steps working value.
        const list = listId !== '' ? document.getElementById(listId) : null;
        // Variable `names` stores this steps working value.
        const names = list instanceof HTMLDataListElement
            ? Array.from(list.options).map((option) => normalizeTagName(option.value)).filter(Boolean)
            : [];
        // Variable `uniqueNames` stores this steps working value.
        const uniqueNames = Array.from(new Set(names));
        // Variable `suggestions` stores this steps working value.
        const suggestions = document.createElement('div');
        suggestions.className = 'tag-suggestions';
        suggestions.setAttribute('role', 'listbox');
        suggestions.hidden = true;
        input.insertAdjacentElement('afterend', suggestions);

        /**
         * Convert a tag to the same canonical lowercase safe form used on the server.
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
         * Normalize comma-separated tag text without losing separators while the admin types.
         *
         * @param {string} value Raw input value.
         * @returns {string} Sanitized input value.
         */
        function normalizeTagText(value) {
            return String(value || '')
                .split(/([,;\n])/)
                .map((part) => /^[,;\n]$/.test(part) ? part : normalizeTagName(part))
                .join('')
                .replace(/[;\n]+/g, ', ');
        }

        /**
         * Sanitize the current field value and keep the cursor as stable as possible.
         *
         * @returns {void}
         */
        function sanitizeInputValue() {
            const before = input.value;
            const cursor = input.selectionStart;
            const after = normalizeTagText(before);
            if (after === before) {
                return;
            }
            input.value = after;
            if (typeof cursor === 'number') {
                const nextCursor = Math.min(after.length, cursor);
                input.setSelectionRange(nextCursor, nextCursor);
            }
        }

        /**
         * Return the text fragment currently being edited after the last separator.
         *
         * @returns {string} Lower-cased partial tag name.
         */
        function currentFragment() {
            // Variable `parts` stores this steps working value.
            const parts = input.value.split(/[,;\n]/);
            return normalizeTagName(String(parts[parts.length - 1] || ''));
        }

        /**
         * Return the already selected tag names so suggestions do not repeat them.
         *
         * @returns {Set<string>} Lower-cased chosen tag names.
         */
        function selectedTagNames() {
            return new Set(input.value.split(/[,;\n]/).map((part) => normalizeTagName(part)).filter(Boolean));
        }

        /**
         * Score a suggestion against the current fragment.
         *
         * @param {string} name Existing tag name.
         * @param {string} fragment Current user-entered partial tag.
         * @returns {number} Lower score means stronger match. -1 means no match.
         */
        function suggestionScore(name, fragment) {
            const normalized = normalizeTagName(name);
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
         * Replace the edited fragment with the selected reused tag.
         *
         * @param {string} name Existing tag name selected by the admin.
         * @returns {void}
         */
        function choose(name) {
            // Variable `parts` stores this steps working value.
            const parts = input.value.split(/([,;\n])/);
            let valueIndex = parts.length - 1;
            while (valueIndex >= 0 && /^[,;\n]$/.test(parts[valueIndex])) {
                valueIndex -= 1;
            }
            if (valueIndex < 0) {
                parts.push(name);
            } else {
                parts[valueIndex] = name;
            }
            input.value = parts.join('').split(/[,;\n]/).map((part) => normalizeTagName(part)).filter(Boolean).join(', ');
            suggestions.innerHTML = '';
            suggestions.hidden = true;
            input.dispatchEvent(new Event('change', {bubbles: true}));
            input.focus();
        }

        /**
         * Redraw suggestion buttons for the current input value.
         *
         * @returns {void}
         */
        function renderSuggestions() {
            // Variable `fragment` stores this steps working value.
            const fragment = currentFragment();
            // Variable `selected` stores this steps working value.
            const selected = selectedTagNames();
            suggestions.innerHTML = '';
            suggestions.hidden = true;
            if (fragment === '') {
                return;
            }
            uniqueNames
                .map((name) => ({name, score: suggestionScore(name, fragment)}))
                .filter((entry) => entry.score >= 0 && !selected.has(normalizeTagName(entry.name)))
                .sort((left, right) => left.score - right.score || left.name.localeCompare(right.name))
                .slice(0, 8)
                .forEach((entry) => {
                    // Variable `button` stores this steps working value.
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = entry.name;
                    button.setAttribute('role', 'option');
                    button.addEventListener('mousedown', (event) => event.preventDefault());
                    button.addEventListener('click', () => choose(entry.name));
                    suggestions.append(button);
                });
            suggestions.hidden = suggestions.children.length === 0;
        }

        input.addEventListener('input', () => {
            sanitizeInputValue();
            renderSuggestions();
        });
        input.addEventListener('change', sanitizeInputValue);
        input.addEventListener('blur', sanitizeInputValue);
        input.addEventListener('focus', renderSuggestions);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                suggestions.innerHTML = '';
                suggestions.hidden = true;
            }
        });
        document.addEventListener('click', (event) => {
            if (event.target === input || suggestions.contains(event.target)) {
                return;
            }
            suggestions.hidden = true;
        });
    });
}
