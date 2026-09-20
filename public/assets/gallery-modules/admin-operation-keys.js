/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Module
 * Purpose: Own document-local create/upload intent keys without transport secrets.
 * Responsibilities:
 *   - Preserve exact retry identities and refuse replacement of unresolved work.
 * File: public/assets/gallery-modules/admin-operation-keys.js
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {i18n} from './admin-core.js?v=20260614-upload-order-v2';
import {OPERATION_KEY_BYTES, OPERATION_KEY_PATTERN, OPERATION_FIELDS, OPERATION_SCALAR_LIMIT} from './admin-panel-policy.js?v=20260920-operation-keys-v1';
/** @type {WeakMap<HTMLFormElement, AdminOperationIntent>} Original form owns uncertain work; replacement forms never inherit it. */
const intents = new WeakMap();

/**
 * @typedef {Object} AdminOperationIntent
 * @property {HTMLFormElement} form Original form, including its fresh transport controls.
 * @property {HTMLInputElement} field Exact server-rendered non-credential operation control.
 * @property {string} key Root intent key; first classic request or prepared create bootstrap only.
 * @property {string} kind Explicit create/classic/prepared workflow identity.
 * @property {string} signature Allowlisted scalar comparison only; never restored into controls.
 * @property {File[]} files Original immutable File references; no file bytes copied or persisted here.
 * @property {Map<number, string>} steps Stable key per intentional classic multipart request.
 * @property {boolean} running Whether an explicit submission is active.
 * @property {boolean} unresolved Whether a request may have reached the server.
 * @property {import('./admin-browser-upload.js').PreparedUploadAttempt|null} prepared Existing prepared uploader's session/batches, never new ledger keys; null before preparation.
 */

/**
 * Compare only allowlisted non-credential values, excluding all transport fields.
 * @param {HTMLFormElement} form Original create/upload form.
 * @return {string} Bounded scalar comparison representation; not a server fingerprint.
 */
function operationSignature(form) {
    const body = new FormData(form);
    const signature = JSON.stringify(OPERATION_FIELDS.map(/** Capture one allowlisted field's scalar values only. @param {string} name Safe field name. @return {[string, string[]]} Comparison entry, excluding files. */ name => [name, body.getAll(name).filter(/** Exclude file parts from scalar comparisons. @param {FormDataEntryValue} value Current multipart entry. @return {boolean} Whether the entry is plain text. */ value => typeof value === 'string')]));
    if (signature.length > OPERATION_SCALAR_LIMIT) throw new Error(i18n('admin.operation.client_capacity', 'This form is too large for safe in-page retry tracking. Keep the original form and review its input.'));
    return signature;
}

/**
 * Generate a distinct operation identity using browser cryptographic randomness.
 * @return {string} Exactly 64 lowercase hexadecimal characters.
 */
function freshOperationKey() {
    return Array.from(crypto.getRandomValues(new Uint8Array(OPERATION_KEY_BYTES)), /** Encode one random byte without exposing it in diagnostics. @param {number} byte Unsigned byte value. @return {string} Two lowercase hexadecimal characters. */ byte => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Report an active explicit submission without enabling a parallel form run.
 * @param {HTMLFormElement} form Original form.
 * @return {boolean} Whether its create/upload handler must ignore another submission.
 */
export function adminOperationIsRunning(form) {
    return intents.get(form)?.running === true;
}

/**
 * Begin or explicitly retry the same form intent; never rotate on failure or changed input.
 * File identity is conservative: keep the original selection for an exact retry.
 * @param {HTMLFormElement} form Original server-rendered form.
 * @param {string} kind Create, classic upload or prepared upload workflow.
 * @param {File[]} files Selected immutable browser files in request order.
 * @return {AdminOperationIntent} Exclusive document-local ownership for this submission.
 */
export function beginAdminOperation(form, kind, files = []) {
    const field = form.querySelector('input[type="hidden"][name="operation_key"]:enabled');
    if (!(field instanceof HTMLInputElement) || !OPERATION_KEY_PATTERN.test(field.value)) {
        throw new Error(i18n('admin.operation.client_key_required', 'Reload a generated create/upload form before starting this operation.'));
    }
    const signature = operationSignature(form);
    let intent = intents.get(form);
    if (intent && (intent.running || intent.field !== field || intent.key !== field.value || intent.kind !== kind
        || intent.signature !== signature || intent.files.length !== files.length || intent.files.some(/** Detect replacement or reordering of original immutable files. @param {File} file Original selected file. @param {number} index Zero-based request order. @return {boolean} Whether this selection differs. */ (file, index) => file !== files[index]))) {
        throw new Error(i18n('admin.operation.client_unresolved', 'An earlier create/upload intent is unresolved. Keep this form and its original fields/files, retry it after reauthentication if needed, or resolve its outcome before starting different work.'));
    }
    if (!intent) {
        intent = {form, field, key: field.value, kind, signature, files: files.slice(), steps: new Map(), running: false, unresolved: false, prepared: null};
        intents.set(form, intent);
    }
    intent.running = true;
    return intent;
}

/**
 * Attach the stable key of one exact multipart request without retaining its body or CSRF.
 * @param {AdminOperationIntent} intent Original exclusive form intent.
 * @param {number} index Zero-based intentional classic request; zero also owns prepared bootstrap.
 * @param {FormData} body Fresh multipart body with current transport authentication.
 * @return {FormData} Same body with its explicit operation key.
 */
export function adminOperationBody(intent, index, body) {
    assertAdminOperationCurrent(intent);
    if (!Number.isSafeInteger(index) || index < 0) throw new Error('Invalid create/upload request index.');
    if (!intent.steps.has(index)) intent.steps.set(index, index === 0 ? intent.key : freshOperationKey());
    body.set('operation_key', intent.steps.get(index));
    intent.unresolved = true;
    return body;
}

/**
 * Refuse changed inputs before another classic request or prepared batch is sent.
 * @param {AdminOperationIntent} intent Original exclusive form intent.
 * @return {void} Throws a generic message without exposing field values or keys.
 */
export function assertAdminOperationCurrent(intent) {
    const selected = Array.from(intent?.form.querySelector('input[type="file"][name="images[]"]')?.files || []);
    if (!intent?.running || intents.get(intent.form) !== intent || intent.field.value !== intent.key
        || operationSignature(intent.form) !== intent.signature || selected.length !== intent.files.length
        || selected.some(/** Reject a newly selected file before another request starts. @param {File} file Current selected file. @return {boolean} Whether it is outside the original selection. */ file => !intent.files.includes(file))) {
        throw new Error(i18n('admin.operation.client_changed', 'The create/upload input changed. Restore the original fields/files before retrying unresolved work.'));
    }
}

/**
 * End one attempt, rotating only after the entire intent's canonical success is confirmed.
 * No failure, response parser, refresh callback or unrelated form may mint retry authority.
 * @param {AdminOperationIntent|null} intent Original form intent, if admission succeeded.
 * @param {boolean} completed Whether all intended mutations returned canonical success.
 * @return {void} Releases active submission while preserving uncertain keys/session state.
 */
export function finishAdminOperation(intent, completed = false) {
    if (!intent || intents.get(intent.form) !== intent) return;
    intent.running = false;
    if (!completed) {
        if (!intent.unresolved && !intent.prepared) intents.delete(intent.form);
        return;
    }
    // Never touch a replacement form, including a reopened editor for the same parent.
    if (intent.form.isConnected && intent.field.isConnected && intent.field.form === intent.form && intent.field.value === intent.key) {
        intent.field.value = freshOperationKey();
    }
    intents.delete(intent.form);
    intent.form.querySelector('[data-admin-operation-unresolved]')?.remove();
}

/**
 * Preserve the original unresolved form and key through drawer close/context requests.
 * This is an inline guard, not a browser dialog, auto-save or alternate retry pipeline.
 * @param {HTMLElement} panel Drawer whose content is about to be replaced.
 * @return {boolean} Whether the ordinary text-draft transition may proceed.
 */
export function allowAdminOperationTransition(panel) {
    for (const form of panel.querySelectorAll('form')) {
        const intent = intents.get(form);
        if (!intent || (!intent.running && !intent.unresolved)) continue;
        let notice = form.querySelector('[data-admin-operation-unresolved]');
        if (!notice) {
            notice = document.createElement('p');
            notice.dataset.adminOperationUnresolved = 'true';
            notice.className = 'notice';
            notice.setAttribute('role', 'status');
            notice.tabIndex = -1;
            form.prepend(notice);
        }
        notice.textContent = i18n('admin.operation.client_keep_form', 'Keep this create/upload form open: its result is still unresolved. Retry the original intent here. If authentication expired, sign in in another tab first. Do not start a replacement operation before reviewing the original outcome.');
        notice.focus({preventScroll: false});
        return false;
    }
    return true;
}
