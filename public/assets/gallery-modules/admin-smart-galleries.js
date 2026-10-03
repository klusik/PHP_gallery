/**
 * Project: PHP Gallery
 * Module Type: Browser Module
 * Purpose: Edit nested Smart Gallery rule documents through server-provided choices.
 * Responsibilities:
 *   - Synchronize the visual rule tree with the canonical hidden JSON form field.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-smart-galleries.js
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Visual nested rule editor for Smart Galleries.
 *
 * The hidden JSON field remains the canonical form payload. All field and
 * operator choices originate in the server-provided allowlist.
 */

/**
 * Canonical groups and conditions keep their stored machine values behind readable controls.
 * @typedef {{type:'condition',field:string,operator:string,value?:string|number|Array<string|number>}} SmartRuleCondition
 * @typedef {{type:'group',operator:string,children:Array<SmartRuleNode>}} SmartRuleGroup
 * @typedef {SmartRuleCondition|SmartRuleGroup} SmartRuleNode
 * @typedef {{form:HTMLFormElement,hidden:HTMLInputElement,root:HTMLElement,rules:SmartRuleNode,catalog:Record<string,{label:string,kind:string,operators:string[],operator_labels?:Record<string,string>}>,tags:Array<{id:number|string,name:string}>,galleries:Array<{id:number|string,title:string,folder_path?:string}>,labels:Record<string,string>}} SmartRuleEditor
 */

/** Return a human-readable label for one stable identifier. */
function label(value) {
    return String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

/** Escape server-supplied labels before inserting markup.
 * @param {unknown} value Text to encode. @return {string} Safe HTML text or attribute value.
 */
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, /** Encode one markup delimiter. @param {string} character Matched delimiter. @return {string} HTML entity. */ (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
}

/** Resolve one localized rule-editor label, with a readable standalone fallback.
 * @param {SmartRuleEditor} editor Rule editor with translated labels. @param {string} key Label identifier.
 * @param {string} fallback Standalone English text. @return {string} Display label.
 */
function ruleText(editor, key, fallback) {
    return editor.labels[key] || fallback;
}

/** Create an option list and select the supplied value.
 * @param {string[]} values Stable option values. @param {string} selected Current value.
 * @param {Record<string,string>} labels Labels keyed by value. @return {string} Escaped option markup.
 */
function selectHtml(values, selected, labels = {}) {
    return values.map(/** Format one catalog option. @param {string} value Stable option ID. @return {string} Escaped markup. */ (value) => `<option value="${escapeHtml(value)}"${value === selected ? ' selected' : ''}>${escapeHtml(labels[value] || label(value))}</option>`).join('');
}

/** Return a new condition using the first supported catalog entry. */
function newCondition(catalog) {
    const field = Object.keys(catalog)[0];
    return {type: 'condition', field, operator: catalog[field].operators[0], value: ''};
}

/** Write the current rule tree into the form payload. */
function synchronize(editor) {
    editor.hidden.value = JSON.stringify({version: 1, root: editor.rules});
}

/** Render a condition or recursive boolean group.
 * @param {SmartRuleEditor} editor Shared rule document, catalog and form state. @param {SmartRuleNode} node Group or condition.
 * @param {HTMLElement} container Owned render target. @param {number} depth Current nesting level.
 * @param {SmartRuleGroup|null} parentRule Owning rule group for removal. @param {number} position Index within that group.
 * @return {void} Appends accessible controls and binds their existing rule mutations.
 */
function renderNode(editor, node, container, depth = 0, parentRule = null, position = 0) {
    const element = document.createElement('div');
    element.className = node.type === 'group' ? 'smart-rule-group' : 'smart-rule-condition';
    if (node.type === 'group') {
        const groupLabels = {AND: ruleText(editor, 'all', 'All conditions'), OR: ruleText(editor, 'any', 'Any condition'), NOT: ruleText(editor, 'exclude', 'Exclude the following')};
        const help = node.operator === 'OR' ? ruleText(editor, 'any_help', 'A photo only needs to match one of the conditions below.') : (node.operator === 'NOT' ? ruleText(editor, 'exclude_help', 'Photos matching the condition or group below will be excluded.') : ruleText(editor, 'all_help', 'A photo must match every condition below.'));
        element.innerHTML = `<div class="smart-rule-group-head"><label>${escapeHtml(ruleText(editor, 'match', 'Photo selection'))}<select data-rule-group-operator>${selectHtml(['AND', 'OR', 'NOT'], node.operator, groupLabels)}</select></label><div class="smart-rule-group-actions"><button type="button" class="button secondary" data-rule-add-condition>+ ${escapeHtml(ruleText(editor, 'add_condition', 'Add condition'))}</button><button type="button" class="button secondary smart-rule-add-group" title="${escapeHtml(ruleText(editor, 'group_help', 'Combine several conditions into a separate set.'))}" data-rule-add-group>+ ${escapeHtml(ruleText(editor, 'add_group', 'Condition group'))}</button>${depth ? `<button type="button" class="button secondary smart-rule-remove" data-rule-remove aria-label="${escapeHtml(ruleText(editor, 'remove_group', 'Remove group'))}" title="${escapeHtml(ruleText(editor, 'remove_group', 'Remove group'))}">&#215;</button>` : ''}</div></div><p class="smart-rule-group-help">${escapeHtml(help)}</p><div class="smart-rule-children"></div>`;
        element.querySelector('[data-rule-group-operator]').addEventListener('change', (event) => {
            node.operator = event.target.value;
            if (node.operator === 'NOT' && node.children.length > 1) node.children = [node.children[0]];
            renderEditor(editor);
        });
        element.querySelector('[data-rule-add-condition]').addEventListener('click', () => {
            if (node.operator === 'NOT') node.children = [];
            node.children.push(newCondition(editor.catalog)); renderEditor(editor);
        });
        element.querySelector('[data-rule-add-group]').addEventListener('click', () => {
            if (node.operator === 'NOT') node.children = [];
            node.children.push({type: 'group', operator: 'AND', children: [newCondition(editor.catalog)]}); renderEditor(editor);
        });
        node.children.forEach(/** Render one owned child. @param {SmartRuleNode} child Rule node. @param {number} index Position in the group. @return {void} Appends controls. */ (child, index) => renderNode(editor, child, element.querySelector('.smart-rule-children'), depth + 1, node, index));
        if (!node.children.length) {
            const empty = document.createElement('p'); empty.className = 'smart-rule-empty';
            empty.textContent = ruleText(editor, 'empty', 'Add a condition to choose which photos belong here.');
            element.querySelector('.smart-rule-children').appendChild(empty);
        }
    } else {
        const fields = Object.keys(editor.catalog);
        const operators = editor.catalog[node.field]?.operators || [];
        const fieldLabels = Object.fromEntries(fields.map((field) => [field, editor.catalog[field].label]));
        element.innerHTML = `<span class="smart-rule-number" aria-hidden="true">${position + 1}</span><label class="smart-rule-control"><span>${escapeHtml(ruleText(editor, 'field', 'Photo property'))}</span><select data-rule-field>${selectHtml(fields, node.field, fieldLabels)}</select></label><label class="smart-rule-control"><span>${escapeHtml(ruleText(editor, 'comparison', 'Comparison'))}</span><select data-rule-operator>${selectHtml(operators, node.operator, editor.catalog[node.field]?.operator_labels)}</select></label><div class="smart-rule-control smart-rule-value-control"><span>${escapeHtml(ruleText(editor, 'value', 'Value'))}</span><span data-rule-value></span></div><button type="button" class="button secondary smart-rule-remove" data-rule-remove aria-label="${escapeHtml(ruleText(editor, 'remove_condition', 'Remove condition'))}" title="${escapeHtml(ruleText(editor, 'remove_condition', 'Remove condition'))}">&#215;</button>`;
        element.querySelector('[data-rule-field]').addEventListener('change', (event) => {
            node.field = event.target.value; node.operator = editor.catalog[node.field].operators[0]; node.value = ''; renderEditor(editor);
        });
        element.querySelector('[data-rule-operator]').addEventListener('change', (event) => { node.operator = event.target.value; renderEditor(editor); });
        renderValue(editor, node, element.querySelector('[data-rule-value]'));
    }
    element.querySelector('[data-rule-remove]')?.addEventListener('click', () => {
        if (!parentRule || !Array.isArray(parentRule.children)) return;
        const index = parentRule.children.indexOf(node);
        if (index >= 0) parentRule.children.splice(index, 1);
        renderEditor(editor);
    });
    container.appendChild(element);
}

/** Render the value input appropriate for a condition.
 * @param {SmartRuleEditor} editor Shared catalog and translated labels. @param {SmartRuleCondition} node Current condition.
 * @param {HTMLElement} container Value cell. @return {void} Appends the matching successful input or a no-value explanation.
 */
function renderValue(editor, node, container) {
    const noValue = ['exists','missing','is_empty','not_empty','untagged','unrated','unresolved','resolved','none','landscape','portrait','square'].includes(node.operator);
    if (noValue) {
        const note = document.createElement('span'); note.className = 'smart-rule-value-unused';
        note.textContent = ruleText(editor, 'no_value', 'No value needed'); container.appendChild(note); return;
    }
    const source = node.field === 'tag' ? editor.tags : (node.field === 'gallery' ? editor.galleries : null);
    if (source) {
        const multiple = ['has_any_tags','has_all_tags'].includes(node.operator);
        const select = document.createElement('select'); select.multiple = multiple; select.setAttribute('aria-label', ruleText(editor, 'value', 'Value'));
        const selected = Array.isArray(node.value) ? node.value.map(Number) : [Number(node.value)];
        if (!multiple) {
            const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = ruleText(editor, 'select_value', 'Choose a value'); placeholder.selected = !selected.some((id) => id > 0); placeholder.disabled = true; select.appendChild(placeholder);
        }
        source.forEach((item) => {
            const option = document.createElement('option'); option.value = item.id;
            option.textContent = node.field === 'gallery' && item.folder_path ? `${item.title || item.folder_path} — ${item.folder_path}` : (item.name || item.title || item.folder_path);
            option.selected = selected.includes(Number(item.id)); select.appendChild(option);
        });
        selected.filter((id) => id > 0 && !source.some((item) => Number(item.id) === id)).forEach(/** Preserve an unavailable saved reference. @param {number} id Missing source identifier. @return {void} Appends a selected fallback option. */ (id) => {
            const option = document.createElement('option'); option.value = String(id); option.textContent = `[${ruleText(editor, 'missing_reference', 'Missing reference')} #${id}]`; option.selected = true; option.dataset.missingReference = '1'; select.appendChild(option);
        });
        select.addEventListener('change', () => { node.value = multiple ? Array.from(select.selectedOptions).map((option) => Number(option.value)) : Number(select.value); synchronize(editor); });
        container.appendChild(select); return;
    }
    if (node.operator === 'between') {
        const values = Array.isArray(node.value) ? node.value : ['', ''];
        values.forEach(/** Render one range endpoint. @param {string|number} value Saved bound. @param {number} index Lower or upper position. @return {void} Appends a synchronized input. */ (value, index) => { const input = document.createElement('input'); input.value = value; input.type = editor.catalog[node.field].kind === 'date' ? 'date' : 'number'; input.setAttribute('aria-label', index ? ruleText(editor, 'to', 'To') : ruleText(editor, 'from', 'From')); input.addEventListener('input', () => { values[index] = input.value; node.value = values; synchronize(editor); }); container.appendChild(input); });
        return;
    }
    const input = document.createElement('input'); input.type = editor.catalog[node.field].kind === 'number' || ['year', 'month'].includes(node.operator) ? 'number' : (editor.catalog[node.field].kind === 'date' ? 'date' : 'text');
    if (node.operator === 'year') { input.min = '1000'; input.max = '9999'; }
    if (node.operator === 'month') { input.min = '1'; input.max = '12'; }
    input.value = node.value ?? ''; input.setAttribute('aria-label', ruleText(editor, 'value', 'Value')); input.addEventListener('input', () => { node.value = input.value; synchronize(editor); }); container.appendChild(input);
}

/** Re-render one editor after a structural change. */
function renderEditor(editor) {
    editor.root.replaceChildren();
    renderNode(editor, editor.rules, editor.root);
    synchronize(editor);
}

/** Initialize Smart Gallery presentation controls using the same range semantics as physical galleries. */
function setupSmartGalleryPresentation(form) {
    const presentationToggle = form.querySelector('[data-smart-gallery-presentation-toggle]');
    const presentationFields = form.querySelector('[data-smart-gallery-presentation-fields]');
    const columns = form.querySelector('[data-smart-gallery-grid-columns]');
    const rows = form.querySelector('[data-smart-gallery-grid-rows]');
    const columnsDisplay = form.querySelector('[data-gallery-grid-columns-display]');
    const rowsDisplay = form.querySelector('[data-gallery-grid-rows-display]');
    const paginationToggle = form.querySelector('[data-smart-gallery-pagination-toggle]');
    const rowsControl = form.querySelector('[data-smart-gallery-rows-control]');
    const itemsPerPage = form.querySelector('[data-smart-gallery-items-per-page]');
    const activeStatus = form.querySelector('[data-smart-gallery-pagination-active-status]');
    const inactiveStatus = form.querySelector('[data-smart-gallery-pagination-inactive-status]');

    /** Synchronize the optional Smart Gallery presentation fieldset with its override toggle. */
    const synchronizePresentationVisibility = () => {
        if (presentationToggle instanceof HTMLInputElement && presentationFields instanceof HTMLElement) {
            presentationFields.hidden = !presentationToggle.checked;
        }
    };

    /** Synchronize range readouts and the derived page-size preview. */
    const synchronizeGrid = () => {
        if (columns instanceof HTMLInputElement && columnsDisplay instanceof HTMLElement) {
            columnsDisplay.textContent = columns.value;
        }
        if (rows instanceof HTMLInputElement && rowsDisplay instanceof HTMLElement) {
            rowsDisplay.textContent = rows.value;
        }
        if (columns instanceof HTMLInputElement && rows instanceof HTMLInputElement && itemsPerPage instanceof HTMLElement) {
            const columnCount = Math.max(1, parseInt(columns.value, 10) || 1);
            const rowCount = Math.max(1, parseInt(rows.value, 10) || 1);
            itemsPerPage.textContent = String(columnCount * rowCount);
        }
    };

    /** Make the Rows per page dependency explicit without disabling its submitted value. */
    const synchronizePaginationDependency = () => {
        const paginationEnabled = paginationToggle instanceof HTMLInputElement && paginationToggle.checked;
        if (rowsControl instanceof HTMLElement) {
            rowsControl.classList.toggle('is-inactive', !paginationEnabled);
            rowsControl.setAttribute('aria-disabled', paginationEnabled ? 'false' : 'true');
        }
        if (activeStatus instanceof HTMLElement) activeStatus.hidden = !paginationEnabled;
        if (inactiveStatus instanceof HTMLElement) inactiveStatus.hidden = paginationEnabled;
        synchronizeGrid();
    };

    presentationToggle?.addEventListener('change', synchronizePresentationVisibility);
    columns?.addEventListener('input', synchronizeGrid);
    columns?.addEventListener('change', synchronizeGrid);
    rows?.addEventListener('input', synchronizeGrid);
    rows?.addEventListener('change', synchronizeGrid);
    paginationToggle?.addEventListener('change', synchronizePaginationDependency);
    synchronizePresentationVisibility();
    synchronizePaginationDependency();
}

/** Initialize all currently rendered Smart Gallery editors.
 * @param {ParentNode} root Document or newly replaced side-panel fragment. @return {void} Binds each uninitialized editor once.
 */
export function setupAdminSmartGalleries(root = document) {
    root.querySelectorAll('[data-smart-gallery-editor]').forEach(/** Bind one dynamically rendered editor. @param {HTMLFormElement} form Owned Smart Gallery form. @return {void} Initializes presentation and canonical rule controls. */ (form) => {
        if (form.dataset.smartGalleryReady === '1') return;
        form.dataset.smartGalleryReady = '1';
        setupSmartGalleryPresentation(form);
        const hidden = form.querySelector('[data-smart-gallery-rules]');
        const builder = form.querySelector('[data-smart-rule-builder]');
        if (!(hidden instanceof HTMLInputElement) || !(builder instanceof HTMLElement)) return;
        let documentRules;
        try { documentRules = JSON.parse(hidden.value); } catch { documentRules = {version: 1, root: {type: 'group', operator: 'AND', children: []}}; }
        const editor = {form, hidden, root: builder, rules: documentRules.root, catalog: JSON.parse(form.dataset.smartGalleryCatalog), tags: JSON.parse(form.dataset.smartGalleryTags), galleries: JSON.parse(form.dataset.smartGalleryGalleries), labels: JSON.parse(form.dataset.smartGalleryRuleLabels || '{}')};
        renderEditor(editor);
    });
}
