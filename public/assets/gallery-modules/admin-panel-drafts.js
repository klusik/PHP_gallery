/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Module
 * Purpose: Recover explicitly allowlisted gallery text within the document lifetime.
 * Responsibilities:
 *   - Keep transient drafts out of credentials, file inputs, access settings and browser storage.
 * File: public/assets/gallery-modules/admin-panel-drafts.js
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Document-lifetime recovery of explicitly allowlisted gallery text. Drafts never
 * serialize forms, credentials, file inputs, access settings or browser storage.
 */
import {i18n} from './admin-core.js?v=20260614-upload-order-v2';
import {allowAdminOperationTransition} from './admin-operation-keys.js?v=20260920-operation-keys-v1';
import {ADMIN_PANEL_DRAFT_FIELDS, ADMIN_PANEL_DRAFT_LIMIT, ADMIN_PANEL_DRAFT_TEXT_LIMIT, ADMIN_PANEL_REVISION_LENGTH_LIMIT, ADMIN_PANEL_SAVED_REVISION_PATTERN} from './admin-panel-policy.js?v=20260920-panel-lifecycle-v1';

/**
 * @typedef {Object} GalleryTextDraft
 * @property {string} key Workflow plus validated gallery/parent identity.
 * @property {Object<string, string>} text Only title and description, in UTF-16 strings.
 * @property {string} revision Bounded non-credential edit precondition, or empty for legacy forms.
 */
/** @type {Map<string, GalleryTextDraft>} Bounded drafts, owned solely by this document. */
const drafts = new Map();
/**
 * @typedef {Object} MountedGalleryText
 * @property {HTMLFormElement} form Current ordinary gallery form, never a serialized snapshot.
 * @property {string} key Opening workflow/entity context.
 * @property {Object<string, string>} baseline Last server/submitted title and description.
 * @property {string} revision Validated revision of that form.
 * @property {boolean} dirty Whether current allowlisted text differs from the baseline.
 * @property {boolean} retained Whether the whole current edit fits the memory budget.
 * @property {boolean} ownedDraft Whether this mounted form produced the retained edit.
 * @property {function(): void|null} pending Explicit close/open awaiting an inline choice.
 */
/** @type {WeakMap<HTMLElement, MountedGalleryText|null>} Active baseline, guard and retention status. */
const mounted = new WeakMap();

/**
 * @typedef {Object} AdminPanelSave
 * @property {HTMLFormElement} form Exact form that supplied this POST, not a replacement with the same gallery ID.
 * @property {HTMLElement} panel Original drawer shell.
 * @property {MountedGalleryText} state Mounted baseline identity at submission time.
 * @property {HTMLInputElement|null} field Exact hidden revision control supplied by this form.
 * @property {string} revision Submitted revision before any response/refresh.
 * @property {{isCurrent: function(): boolean}} owner Original open-intent guard; does not cancel the POST.
 * @property {GalleryTextDraft|null} draft Bounded submitted text snapshot, absent when recovery capacity is exceeded.
 */
/** @type {WeakMap<HTMLFormElement, AdminPanelSave>} Latest submission identity for each form; superseded responses cannot advance its revision. */
const latestSaves = new WeakMap();

/**
 * Return only a positive decimal object identity from server-rendered form context.
 * @param {string|null} value Candidate scalar.
 * @return {string} Bounded decimal identity, or empty when absent/invalid.
 */
function identity(value) {
    return /^[0-9]{1,20}$/.test(String(value || '')) ? String(value) : '';
}

/**
 * Select an allowlisted ordinary text control, excluding inputs of secret/file type.
 * @param {HTMLFormElement} form Ordinary gallery form.
 * @param {string} name Allowlisted field name.
 * @return {HTMLInputElement|HTMLTextAreaElement|null} Safe text control.
 */
function textControl(form, name) {
    const field = form.elements.namedItem(name);
    return field instanceof HTMLTextAreaElement || (field instanceof HTMLInputElement && field.type === 'text') ? field : null;
}

/**
 * Read only the explicit text allowlist; never iterate/serialize the complete form.
 * @param {HTMLFormElement} form Ordinary gallery form.
 * @return {Object<string, string>} Current editable text values.
 */
function textValues(form) {
    return Object.fromEntries(ADMIN_PANEL_DRAFT_FIELDS.map(/** Copy one permitted text value, never a secret or file control. @param {string} name Allowlisted text-control name. @return {[string, string]} Field name and current text. */ name => [name, textControl(form, name)?.value || '']));
}

/**
 * Compare the two allowlisted text fields without considering transport metadata.
 * @param {{title: string, description: string}} left Earlier allowlisted text.
 * @param {{title: string, description: string}} right Later allowlisted text.
 * @return {boolean} Whether both field values agree.
 */
function sameText(left, right) {
    return ADMIN_PANEL_DRAFT_FIELDS.every(/** Compare one allowlisted text field exactly. @param {string} name Field name. @return {boolean} Whether both snapshots agree. */ name => left[name] === right[name]);
}

/**
 * Select only the explicit enabled hidden editor precondition, excluding other hidden fields.
 * @param {HTMLFormElement} form Ordinary gallery editor.
 * @return {HTMLInputElement|null} Server-prepared revision control.
 */
function formRevisionControl(form) {
    return form.querySelector('input[type="hidden"][name="expected_edit_revision"]:enabled, input[type="hidden"][name="edit_revision"]:enabled');
}

/**
 * Read only an explicitly named non-credential editor revision, including opaque snapshots.
 * Decimal and hexadecimal revisions compare as exact strings. No other hidden field
 * is inspected; rejected/oversized values become the unavailable revision marker.
 * @param {HTMLFormElement} form Ordinary gallery form.
 * @return {string} Validated revision or empty for legacy forms.
 */
function formRevision(form) {
    const field = formRevisionControl(form);
    const revision = String(field?.value || '');
    return revision.length > 0 && revision.length <= ADMIN_PANEL_REVISION_LENGTH_LIMIT
        && /^[A-Za-z0-9._:-]+$/.test(revision) ? revision : '';
}

/**
 * Derive a draft key from workflow and object identity, never a credential-bearing URL.
 * @param {HTMLElement} panel Shell containing the prepared form.
 * @param {HTMLFormElement} form Ordinary gallery form.
 * @return {string} Scoped key, or empty when identity is unavailable.
 */
function formKey(panel, form) {
    const workflow = panel.dataset.adminSidePanelWorkflow;
    if (workflow === 'create') {
        const parent = identity(form.elements.namedItem('parent_id')?.value || '0');
        return parent ? `create:${parent}` : '';
    }
    if (workflow !== 'gallery-edit') return '';
    let id = identity(form.elements.namedItem('gallery_id')?.value || form.elements.namedItem('id')?.value);
    if (!id) {
        try { id = identity(new URL(panel.dataset.adminSidePanelSourceUrl, location.href).searchParams.get('id')); }
        catch { return ''; }
    }
    return id && id !== '0' ? `gallery-edit:${id}` : '';
}

/**
 * Return/create the persistent inline draft region outside the replaceable body.
 * @param {HTMLElement} panel Shell.
 * @return {HTMLElement} Status/action container, safe to fill using textContent.
 */
function draftRegion(panel) {
    let region = panel.querySelector('[data-admin-panel-draft]');
    if (!region) {
        region = document.createElement('div');
        region.dataset.adminPanelDraft = 'true';
        region.className = 'notice admin-panel-draft';
        region.setAttribute('aria-live', 'polite');
        panel.querySelector('[data-admin-side-panel-body]').before(region);
    }
    return region;
}

/**
 * Add a non-submitting localized inline action with its own lifecycle callback.
 * @param {HTMLElement} region Inline notice container.
 * @param {string} action Stable fixture/style hook.
 * @param {string} label Localized button text.
 * @param {function(): void} callback Explicit user action.
 * @return {HTMLButtonElement} Newly added action.
 */
function addAction(region, action, label, callback) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button secondary';
    button.dataset.adminPanelDraftAction = action;
    button.textContent = label;
    button.addEventListener('click', callback);
    region.append(button);
    return button;
}

/**
 * Capture changed text within the document budget; refusal leaves all text in the DOM.
 * @param {MountedGalleryText} state Mounted ordinary form state.
 * @return {boolean} Whether the current edit has a complete retained copy.
 */
function retain(state) {
    const text = textValues(state.form);
    state.dirty = !sameText(text, state.baseline);
    if (!state.dirty) {
        if (state.ownedDraft) drafts.delete(state.key);
        state.ownedDraft = false;
        return true;
    }
    state.ownedDraft = true;
    let units = ADMIN_PANEL_DRAFT_FIELDS.reduce(/** Count this draft's UTF-16 text units. @param {number} sum Accumulated code units. @param {string} name Allowlisted field name. @return {number} Updated code-unit total. */ (sum, name) => sum + text[name].length, 0);
    for (const [key, draft] of drafts) {
        if (key !== state.key) units += ADMIN_PANEL_DRAFT_FIELDS.reduce(/** Count another retained draft toward the shared text budget. @param {number} sum Accumulated UTF-16 code units. @param {string} name Allowlisted field name. @return {number} Updated code-unit total. */ (sum, name) => sum + draft.text[name].length, 0);
    }
    state.retained = units <= ADMIN_PANEL_DRAFT_TEXT_LIMIT && (drafts.has(state.key) || drafts.size < ADMIN_PANEL_DRAFT_LIMIT);
    if (state.retained) drafts.set(state.key, {key: state.key, text, revision: state.revision});
    return state.retained;
}

/**
 * Render unsaved or recoverable text without inserting the private draft in the notice.
 * @param {HTMLElement} panel Shell.
 * @return {void}
 */
function renderDraft(panel) {
    const state = mounted.get(panel);
    const region = draftRegion(panel);
    const noticeMode = state?.dirty ? (state.retained ? 'unsaved' : 'capacity') : 'recovery';
    // Do not repeatedly replace identical live-region text on every keystroke.
    if (state?.dirty && !state.pending && region.childElementCount === 1
        && region.dataset.adminPanelDraftNotice === noticeMode && !region.hidden) return;
    region.dataset.adminPanelDraftNotice = noticeMode;
    region.replaceChildren();
    region.hidden = !state;
    if (!state) return;
    const saved = drafts.get(state.key);
    region.hidden = !state.dirty && !saved;
    if (region.hidden) return;
    const message = document.createElement('p');
    message.textContent = state.dirty
        ? (state.retained ? i18n('admin.side_panel.draft_unsaved', 'Unsaved title or description. Text is kept only while this page remains open.')
            : i18n('admin.side_panel.draft_capacity', 'Draft memory is full. Keep editing or explicitly discard before leaving this form.'))
        : (saved.revision !== state.revision || !sameText(saved.text, state.baseline) && saved.revision === ''
            ? i18n('admin.side_panel.draft_review', 'A text draft is available. Review the current server values before restoring; the server revision has changed or cannot be verified.')
            : i18n('admin.side_panel.draft_available', 'A text draft is available for this gallery. Restore it only when ready to review and save.'));
    region.append(message);
    if (!state.dirty && saved) {
        addAction(region, 'restore', i18n('admin.side_panel.draft_restore', 'Restore text for review'), /** Restore only text after an explicit user choice. @return {void} Preserves fresh revision/CSRF and focuses the title. */ () => {
            for (const name of ADMIN_PANEL_DRAFT_FIELDS) {
                const field = textControl(state.form, name);
                if (field) field.value = saved.text[name];
            }
            // The fresh CSRF and revision fields remain owned by the loaded form.
            retain(state);
            renderDraft(panel);
            textControl(state.form, 'title')?.focus({preventScroll: true});
        });
        addAction(region, 'discard', i18n('admin.side_panel.draft_discard', 'Discard draft'), /** Discard this retained text after an explicit user choice. @return {void} Clears its recovery notice without submitting. */ () => {
            drafts.delete(state.key);
            renderDraft(panel);
            textControl(state.form, 'title')?.focus({preventScroll: true});
        });
    }
}

/**
 * Observe a newly prepared ordinary gallery form, preserving other entity drafts.
 * @param {HTMLElement} panel Shell after accepted fragment replacement.
 * @return {void}
 */
export function prepareAdminPanelDrafts(panel) {
    const form = panel.querySelector('.admin-edit-gallery-form, [data-gallery-panel-create-form]');
    const key = form instanceof HTMLFormElement ? formKey(panel, form) : '';
    const state = key ? {form, key, baseline: textValues(form), revision: formRevision(form), dirty: false, retained: true, ownedDraft: false, pending: null} : null;
    mounted.set(panel, state);
    renderDraft(panel);
    if (!state) return;
    /**
     * Observe the current mounted form's allowlisted text after a user edit or reset.
     * @return {void} Updates bounded recovery state and its inline notice only while this form owns the mount.
     */
    const observe = () => {
        if (mounted.get(panel) !== state) return;
        retain(state);
        if (!state.pending) renderDraft(panel);
    };
    form.addEventListener('input', observe);
    form.addEventListener('change', observe);
    form.addEventListener('reset', /** Observe values after native reset has applied. @return {void} Schedules only current-form draft observation. */ () => queueMicrotask(observe));
}

/**
 * Guard an explicit close or context switch with in-place non-submitting choices.
 * @param {HTMLElement} panel Active shell.
 * @param {function(): void} proceed Action to perform only after the choice.
 * @return {boolean} True when no draft guard is needed; caller may proceed immediately.
 */
export function allowAdminPanelTransition(panel, proceed) {
    if (!allowAdminOperationTransition(panel)) return false;
    const state = mounted.get(panel);
    if (!state) return true;
    retain(state);
    if (!state.dirty) return true;
    const returnFocus = document.activeElement;
    state.pending = proceed;
    renderDraft(panel);
    const region = draftRegion(panel);
    const keep = addAction(region, 'keep-editing', i18n('admin.side_panel.draft_keep_editing', 'Keep editing'), /** Cancel the pending context switch and restore editing focus. @return {void} Keeps all current form input in place. */ () => {
        state.pending = null;
        renderDraft(panel);
        if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus({preventScroll: true});
        else textControl(state.form, 'title')?.focus({preventScroll: true});
    });
    if (state.retained) {
        addAction(region, 'keep-continue', i18n('admin.side_panel.draft_keep_continue', 'Keep draft and continue'), /** Retain complete text before allowing the requested context switch. @return {void} Refuses replacement if current text exceeds capacity. */ () => {
            if (!retain(state)) { allowAdminPanelTransition(panel, proceed); return; }
            state.pending = null;
            mounted.set(panel, null);
            region.hidden = true;
            proceed();
        });
    }
    addAction(region, 'discard-continue', i18n('admin.side_panel.draft_discard_continue', 'Discard and continue'), /** Discard this text explicitly and perform the requested context switch. @return {void} Clears only the originating draft and pending choice. */ () => {
        drafts.delete(state.key);
        state.pending = null;
        mounted.set(panel, null);
        region.hidden = true;
        proceed();
    });
    keep.focus({preventScroll: true});
    return false;
}

/**
 * Preserve edits typed before/during a mutation GET; never defer an old refresh callback.
 * @param {HTMLElement} panel Active shell.
 * @return {boolean} Whether replacing the current form would lose unsaved text.
 */
export function adminPanelHasUnsavedText(panel) {
    // Unrelated completion must not replace an unresolved create/upload form either.
    if (!allowAdminOperationTransition(panel)) return true;
    const state = mounted.get(panel);
    if (!state) return false;
    retain(state);
    if (!state.pending) renderDraft(panel);
    return state.dirty;
}

/**
 * Snapshot only submitted text and revision before the POST starts.
 * @param {HTMLFormElement} form Submitted ordinary gallery form.
 * @return {GalleryTextDraft|null} Bounded acknowledgment token; no transport secrets.
 */
export function submittedAdminPanelDraft(form) {
    const panel = form.closest('[data-admin-side-panel]');
    const state = mounted.get(panel);
    if (!state || state.form !== form || !retain(state)) return null;
    return {key: state.key, text: textValues(form), revision: state.revision};
}

/**
 * Capture the exact editor and latest submission before its POST can yield.
 * Revision acknowledgment remains available even when its text exceeds the draft budget.
 * @param {HTMLFormElement} form Ordinary complete gallery editor being submitted.
 * @param {{isCurrent: function(): boolean}} owner Original drawer open-intent guard.
 * @return {AdminPanelSave|null} Non-serialized submission identity, or null for another workflow.
 */
export function beginAdminPanelSave(form, owner) {
    const panel = form.closest('[data-admin-side-panel]');
    const state = mounted.get(panel);
    if (!state || state.form !== form || !state.key.startsWith('gallery-edit:')) return null;
    const submission = {form, panel, state, field: formRevisionControl(form), revision: formRevision(form), owner, draft: submittedAdminPanelDraft(form)};
    latestSaves.set(form, submission);
    return submission;
}

/**
 * Require the original form, control, open intent and latest POST to still own acknowledgment.
 * @param {AdminPanelSave} submission Snapshot captured before the POST.
 * @return {boolean} Whether its response may alter this mounted form's baseline/revision.
 */
function currentAdminPanelSave(submission) {
    return latestSaves.get(submission.form) === submission && submission.owner.isCurrent()
        && submission.form.isConnected && submission.form.closest('[data-admin-side-panel]') === submission.panel
        && mounted.get(submission.panel) === submission.state && submission.state.form === submission.form
        && submission.field instanceof HTMLInputElement && formRevisionControl(submission.form) === submission.field
        && formRevision(submission.form) === submission.revision && submission.state.revision === submission.revision;
}

/**
 * Acknowledge the just-saved row before any asynchronous editor refresh starts.
 * Only the hidden precondition advances: no text, privacy control, CSRF value or
 * other server field is merged. A different writer advancing the row afterwards
 * still causes the next POST to fail its server-side revision comparison.
 * @param {AdminPanelSave|null} submission Exact form/open/submission identity.
 * @param {{ok: boolean, mutation: {entity: string, entity_ids: Array<number|string>}, edit_revision?: string}} result Read-only acknowledgment projection of the canonical response; revision comes from the just-saved row.
 * @return {void} Updates only matching draft metadata and the original form's precondition.
 */
export function acknowledgeAdminPanelSave(submission, result) {
    if (!submission || result?.ok !== true || result.mutation?.entity !== 'gallery'
        || !Array.isArray(result.mutation.entity_ids) || result.mutation.entity_ids.length !== 1
        || String(result.mutation.entity_ids[0]) !== submission.state.key.slice('gallery-edit:'.length)) return;
    const current = currentAdminPanelSave(submission);
    acknowledgeAdminPanelDraft(submission.panel, submission.draft, result, current);
    const nextRevision = result.edit_revision;
    if (!current || typeof nextRevision !== 'string' || !ADMIN_PANEL_SAVED_REVISION_PATTERN.test(nextRevision)
        || !ADMIN_PANEL_SAVED_REVISION_PATTERN.test(submission.revision)
        || BigInt(nextRevision) <= BigInt(submission.revision)) return;

    // This is the precondition of our successful POST, never a later GET's row.
    // Keep the same form usable while its older editor-refresh request is pending.
    submission.field.value = nextRevision;
    submission.state.revision = nextRevision;
    const draft = drafts.get(submission.state.key);
    if (draft?.revision === submission.revision) {
        drafts.set(submission.state.key, {...draft, revision: nextRevision});
    }
}

/**
 * Clear only text confirmed saved for the same submission/entity/revision.
 * @param {HTMLElement} panel Originating shell.
 * @param {GalleryTextDraft|null} submitted Text actually sent before awaiting.
 * @param {{ok: boolean, mutation: {entity: string, entity_ids: Array<number|string>}}} result Read-only text-acknowledgment projection of the canonical mutation response.
 * @param {boolean} updateActiveForm Whether the original form/submission still owns the mounted baseline.
 * @return {void}
 */
export function acknowledgeAdminPanelDraft(panel, submitted, result, updateActiveForm = true) {
    if (!submitted || result?.ok !== true || result.mutation?.entity !== 'gallery') return;
    if (submitted.key.startsWith('gallery-edit:')
        && !result.mutation.entity_ids?.map(String).includes(submitted.key.slice('gallery-edit:'.length))) return;
    const saved = drafts.get(submitted.key);
    if (saved && saved.revision === submitted.revision && sameText(saved.text, submitted.text)) drafts.delete(submitted.key);
    const state = mounted.get(panel);
    if (updateActiveForm && state?.key === submitted.key && state.revision === submitted.revision) {
        state.baseline = submitted.text;
        retain(state);
        if (!state.dirty) state.pending = null;
    }
    if (updateActiveForm && state?.key === submitted.key && !state.pending) renderDraft(panel);
}
