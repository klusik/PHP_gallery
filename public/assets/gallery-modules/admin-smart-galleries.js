/**
 * Visual nested rule editor for Smart Galleries.
 *
 * The hidden JSON field remains the canonical form payload. All field and
 * operator choices originate in the server-provided allowlist.
 */

/** Return a human-readable label for one stable identifier. */
function label(value) {
    return String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

/** Create an option list and select the supplied value. */
function selectHtml(values, selected, labels = {}) {
    return values.map((value) => `<option value="${value}"${value === selected ? ' selected' : ''}>${labels[value] || label(value)}</option>`).join('');
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

/** Render a condition or recursive boolean group. */
function renderNode(editor, node, container, depth = 0, parentRule = null) {
    const element = document.createElement('div');
    element.className = node.type === 'group' ? 'smart-rule-group' : 'smart-rule-condition';
    if (node.type === 'group') {
        element.innerHTML = `<div class="smart-rule-group-head"><label>${label('match')} <select data-rule-group-operator>${selectHtml(['AND', 'OR', 'NOT'], node.operator)}</select></label><button type="button" class="button secondary" data-rule-add-condition>+ ${label('condition')}</button><button type="button" class="button secondary" data-rule-add-group>+ ${label('group')}</button>${depth ? `<button type="button" class="button secondary" data-rule-remove>${label('remove')}</button>` : ''}</div><div class="smart-rule-children"></div>`;
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
        node.children.forEach((child) => renderNode(editor, child, element.querySelector('.smart-rule-children'), depth + 1, node));
    } else {
        const fields = Object.keys(editor.catalog);
        const operators = editor.catalog[node.field]?.operators || [];
        const fieldLabels = Object.fromEntries(fields.map((field) => [field, editor.catalog[field].label]));
        element.innerHTML = `<select data-rule-field aria-label="${label('field')}">${selectHtml(fields, node.field, fieldLabels)}</select><select data-rule-operator aria-label="${label('operator')}">${selectHtml(operators, node.operator, editor.catalog[node.field]?.operator_labels)}</select><span data-rule-value></span><button type="button" class="button secondary" data-rule-remove>${label('remove')}</button>`;
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

/** Render the value input appropriate for a condition. */
function renderValue(editor, node, container) {
    const noValue = ['exists','missing','is_empty','not_empty','untagged','unrated','unresolved','resolved','none','landscape','portrait','square'].includes(node.operator);
    if (noValue) return;
    const source = node.field === 'tag' ? editor.tags : (node.field === 'gallery' ? editor.galleries : null);
    if (source) {
        const multiple = ['has_any_tags','has_all_tags'].includes(node.operator);
        const select = document.createElement('select'); select.multiple = multiple; select.setAttribute('aria-label', label('value'));
        const selected = Array.isArray(node.value) ? node.value.map(Number) : [Number(node.value)];
        if (!multiple) {
            const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = label('select value'); placeholder.selected = !selected.some((id) => id > 0); placeholder.disabled = true; select.appendChild(placeholder);
        }
        source.forEach((item) => {
            const option = document.createElement('option'); option.value = item.id;
            option.textContent = node.field === 'gallery' && item.folder_path ? `${item.title || item.folder_path} — ${item.folder_path}` : (item.name || item.title || item.folder_path);
            option.selected = selected.includes(Number(item.id)); select.appendChild(option);
        });
        selected.filter((id) => id > 0 && !source.some((item) => Number(item.id) === id)).forEach((id) => {
            const option = document.createElement('option'); option.value = String(id); option.textContent = `[${label('missing reference')} #${id}]`; option.selected = true; option.dataset.missingReference = '1'; select.appendChild(option);
        });
        select.addEventListener('change', () => { node.value = multiple ? Array.from(select.selectedOptions).map((option) => Number(option.value)) : Number(select.value); synchronize(editor); });
        container.appendChild(select); return;
    }
    if (node.operator === 'between') {
        const values = Array.isArray(node.value) ? node.value : ['', ''];
        values.forEach((value, index) => { const input = document.createElement('input'); input.value = value; input.type = editor.catalog[node.field].kind === 'date' ? 'date' : 'number'; input.setAttribute('aria-label', index ? label('to') : label('from')); input.addEventListener('input', () => { values[index] = input.value; node.value = values; synchronize(editor); }); container.appendChild(input); });
        return;
    }
    const input = document.createElement('input'); input.type = editor.catalog[node.field].kind === 'number' || ['year', 'month'].includes(node.operator) ? 'number' : (editor.catalog[node.field].kind === 'date' ? 'date' : 'text');
    if (node.operator === 'year') { input.min = '1000'; input.max = '9999'; }
    if (node.operator === 'month') { input.min = '1'; input.max = '12'; }
    input.value = node.value ?? ''; input.setAttribute('aria-label', label('value')); input.addEventListener('input', () => { node.value = input.value; synchronize(editor); }); container.appendChild(input);
}

/** Re-render one editor after a structural change. */
function renderEditor(editor) {
    editor.root.replaceChildren();
    renderNode(editor, editor.rules, editor.root);
    synchronize(editor);
}

/** Initialize all currently rendered Smart Gallery editors. */
export function setupAdminSmartGalleries() {
    document.querySelectorAll('[data-smart-gallery-editor]').forEach((form) => {
        if (form.dataset.smartGalleryReady === '1') return;
        form.dataset.smartGalleryReady = '1';
        const hidden = form.querySelector('[data-smart-gallery-rules]');
        let documentRules;
        try { documentRules = JSON.parse(hidden.value); } catch { documentRules = {version: 1, root: {type: 'group', operator: 'AND', children: []}}; }
        const editor = {form, hidden, root: form.querySelector('[data-smart-rule-builder]'), rules: documentRules.root, catalog: JSON.parse(form.dataset.smartGalleryCatalog), tags: JSON.parse(form.dataset.smartGalleryTags), galleries: JSON.parse(form.dataset.smartGalleryGalleries)};
        renderEditor(editor);
    });
}
