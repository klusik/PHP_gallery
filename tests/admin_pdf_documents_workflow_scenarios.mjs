/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_pdf_documents_workflow_scenarios.mjs
 * Module Type: Regression Test
 * Purpose: Verify attachment-only uploads, delegated conversion admission, lifecycle requests and cancellation.
 * Responsibilities: Exercise the Admin workflow with deterministic DOM, lifecycle and stream adapters.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

/**
 * Describe one recorded request sent by the PDF workflow.
 * @typedef {Object} PdfWorkflowRequest
 * @property {string} url Form action URL passed to fetch.
 * @property {Map<string, string|{name: string, type: string}>} body Request fields, including the original file adapter, fresh CSRF and lifecycle action.
 * @property {AbortSignal|null|undefined} signal Optional cancellation signal sent with the request.
 */

/**
 * Describe one set of deterministic PDF workflow adapters and observations.
 * @typedef {Object} PdfWorkflowHarness
 * @property {PdfWorkflowSubmitHandler} submit Dispatch the delegated submit listener for a dynamically supplied form and return its status text.
 * @property {PdfWorkflowCancelHandler} cancel Dispatch the delegated cancel click for a form.
 * @property {(operation: {running: boolean, unresolved?: boolean}, file: {name: string, type: string}, convertPages?: boolean) => Promise<Record<string, unknown>>} attachOnly Submit an original PDF adapter under an active operation with an optional explicit conversion choice.
 * @property {PdfWorkflowRequest[]} requests Requests received by the mocked server.
 * @property {Array<{url: string, options: Record<string, unknown>}>} fetchCalls Full request call details.
 * @property {Array<{url: string, options: Record<string, unknown>}>} streamCalls Protected PDF source and renderer options.
 * @property {Array<{form: PdfWorkflowForm, stream: PdfWorkflowStream}>} streams Created conversion streams.
 * @property {Array<{form: PdfWorkflowForm, progress: PdfWorkflowProgress, operation: PdfWorkflowOperation, stream: PdfWorkflowStream, options: Record<string, unknown>}>} uploads Existing uploader calls.
 * @property {PdfWorkflowOperation[]} operations Form-scoped operation intent records.
 * @property {string} initialUrl Browser URL before the test actions.
 * @property {{href: string, origin: string}} location Observable mock browser location.
 * @property {number} submitListenerCount Number of document-level submit handlers installed.
 * @property {() => Promise<void>} uploadStarted Resolves when the mocked uploader begins waiting for cancellation.
 */

/**
 * Describe one deterministic mocked server response.
 * @typedef {Object} PdfWorkflowResponse
 * @property {boolean} ok HTTP response success flag.
 * @property {Record<string, unknown>} payload JSON response fields.
 */

/**
 * Describe the callback selecting a deterministic mocked lifecycle response.
 * @typedef {(request: PdfWorkflowRequest, index: number) => PdfWorkflowResponse} PdfWorkflowResponseSelector
 */

/** @typedef {(form: PdfWorkflowForm) => Promise<string>} PdfWorkflowSubmitHandler */
/** @typedef {(form: PdfWorkflowForm) => void} PdfWorkflowCancelHandler */
/** @typedef {(selector: string) => boolean} PdfWorkflowMatcher */
/** @typedef {(selector: string) => {value: string}|PdfWorkflowButton|PdfWorkflowStatus|PdfWorkflowProgress|null} PdfWorkflowQueryHandler */
/** @typedef {(selector: string) => PdfWorkflowButton[]} PdfWorkflowButtonQueryHandler */
/** @typedef {(settings: {baseName: string, startPage: number, pageDigits: number}) => void} PdfWorkflowConfigureHandler */
/** @typedef {(selector: string) => ({value: number}|null)} PdfWorkflowProgressQueryHandler */
/** @typedef {() => PdfWorkflowForm|null} PdfWorkflowFormOwnerHandler */
/** @typedef {(...values: unknown[]) => void} PdfWorkflowAppendHandler */

/**
 * Describe the original form's semantic controls and delegated event methods.
 * @typedef {Object} PdfWorkflowForm
 * @property {string} action Same-origin Admin endpoint.
 * @property {boolean} isConnected Whether the original form remains in the document.
 * @property {{pdfUrl: string, pdfRunning?: string}} dataset Protected source URL and running state.
 * @property {Array<[string, string]>} formDataEntries Fresh CSRF and semantic request fields.
 * @property {Map<string, {value: string}>} inputs Queryable semantic form controls.
 * @property {PdfWorkflowMatcher} matches Match one PDF conversion form selector.
 * @property {PdfWorkflowQueryHandler} querySelector Return the named form or progress control.
 * @property {PdfWorkflowButtonQueryHandler} querySelectorAll Find current submit buttons.
 */

/**
 * Describe the conversion operation owner used in this workflow fixture.
 * @typedef {Object} PdfWorkflowOperation
 * @property {boolean} unresolved Whether the server outcome may still be pending.
 * @property {boolean} running Whether this form currently owns a submission.
 * @property {unknown|null} prepared Retained prepared upload retry state, or null before upload preparation.
 * @property {{documentId: string, dpi: number, targetMode: string, convertPages?: boolean}|undefined} pdfSource Frozen source identity and semantic conversion settings.
 */

/**
 * Describe the page stream returned by the mocked PDF renderer.
 * @typedef {Object} PdfWorkflowStream
 * @property {number} pageCount Discovered PDF page count.
 * @property {number} dpi Output resolution.
 * @property {PdfWorkflowConfigureHandler} configure Apply lifecycle naming and resume settings.
 * @property {() => Promise<void>} dispose Release mock renderer resources.
 */

/**
 * Describe the progress elements accessed by the conversion workflow.
 * @typedef {Object} PdfWorkflowProgress
 * @property {boolean} hidden Whether the progress region is visible.
 * @property {string} innerHTML Markup installed for the progress indicators.
 * @property {PdfWorkflowProgressQueryHandler} querySelector Return the percentage progress control.
 */

/**
 * Describe the cancel control and its form owner.
 * @typedef {Object} PdfWorkflowButton
 * @property {boolean} hidden Whether the cancel action is visible.
 * @property {PdfWorkflowFormOwnerHandler} closest Resolve the owning form.
 */

/**
 * Describe the status element shown during conversion and finalization.
 * @typedef {Object} PdfWorkflowStatus
 * @property {string} textContent Current user-visible status.
 * @property {PdfWorkflowAppendHandler} append Append a generated result link.
 */

/**
 * Create a VM harness that intercepts the real document-delegated PDF form owner.
 * @param {PdfWorkflowResponseSelector} responseFor Select a deterministic response for each endpoint action.
 * @param {boolean} [waitForCancel=false] Keep the uploader pending until the delegated cancel control fires.
 * @param {boolean} [failUpload=false] Reject the uploader after lifecycle admission to retain uncertain intent.
 * @returns {Promise<PdfWorkflowHarness>} Form workflow adapters and observable requests.
 */
async function makeWorkflowHarness(responseFor, waitForCancel = false, failUpload = false) {
    const source = await readFile(new URL('../public/assets/gallery-modules/admin-pdf-documents.js', import.meta.url), 'utf8');
    const requests = [];
    const fetchCalls = [];
    const streamCalls = [];
    const streams = [];
    const uploads = [];
    const operations = [];
    const listeners = new Map();
    const intents = new WeakMap();
    let currentUrl = 'https://gallery.example/admin.php?tab=edit&id=17';
    const initialUrl = currentUrl;
    let uploadStartedResolve;
    const uploadStarted = new Promise(resolve => { uploadStartedResolve = resolve; });

    const location = new Proxy({href: currentUrl, origin: 'https://gallery.example'}, {
        set: (target, property, value) => {
            if (property === 'href') currentUrl = String(value);
            target[property] = value;
            return true;
        },
    });
    const document = {
        body: {dataset: {}},
        documentElement: {lang: 'en'},
        matches: () => false,
        querySelectorAll: () => [],
        addEventListener: (type, callback, capture) => {
            const existing = listeners.get(type) || [];
            existing.push({callback, capture});
            listeners.set(type, existing);
        },
        createElement: tag => ({tagName: tag, href: '', textContent: '', dataset: {}, append: () => {}}),
    };
    const fetch = async (url, options) => {
        const body = options.body;
        const request = {url: String(url), body, signal: options.signal};
        requests.push(request);
        fetchCalls.push({url: String(url), options});
        const response = responseFor(request, requests.length - 1);
        return {ok: response.ok, json: async () => response.payload};
    };
    const createPdfPngUploadStream = async (url, options) => {
        streamCalls.push({url, options});
        const stream = {
            pageCount: 3,
            dpi: options.dpi,
            configured: null,
            disposed: false,
            configure: settings => { stream.configured = settings; },
            dispose: async () => { stream.disposed = true; },
        };
        streams.push({form: activeForm, stream});
        return stream;
    };
    let activeForm = null;
    const uploadBrowserFileStream = async (form, progress, operation, stream, options) => {
        uploads.push({form, progress, operation, stream, options});
        const fill = progress.querySelector('[data-thumbnail-progress-fill]');
        if (fill) fill.value = 92;
        if (waitForCancel) {
            uploadStartedResolve();
            await new Promise((resolve, reject) => {
                options.signal.addEventListener('abort', () => reject(new Error('upload cancelled')), {once: true});
            });
        }
        if (failUpload) throw new Error('prepared page upload outcome is uncertain');
        return {ok: true, uploaded: 3};
    };
    const beginAdminOperation = (form) => {
        let operation = intents.get(form);
        if (!operation) {
            operation = {form, unresolved: false, running: false, prepared: null, pdfSource: undefined};
            intents.set(form, operation);
            operations.push(operation);
        }
        operation.running = true;
        return operation;
    };
    const finishAdminOperation = (operation, completed) => {
        if (!operation) return;
        operation.running = false;
        if (completed) intents.delete(operation.form);
    };
    const assertAdminOperationCurrent = operation => {
        if (!operation?.running) throw new Error('The parent upload operation is no longer current.');
    };
    const completeAdminMutation = async () => {};
    const i18nForElement = (_element, _key, fallback) => fallback;
    const context = vm.createContext({
        AbortController,
        Element: Object,
        HTMLFormElement: Object,
        HTMLButtonElement: function () {},
        MutationObserver: function () { this.observe = () => {}; },
        FormData: function (form) { return new Map(form?.formDataEntries || []); },
        URL,
        window: {location},
        document,
        fetch,
        // Shared PDF metadata has its own production-closure scenarios below.
        scanPdfPageCountCells: () => {},
        createPdfPngUploadStream,
        uploadBrowserFileStream,
        beginAdminOperation,
        finishAdminOperation,
        assertAdminOperationCurrent,
        completeAdminMutation,
        i18nForElement,
        appendUploadProgressLog: () => {},
        console,
    });
    const transformed = source
        .replace(/^import .*;\s*$/gm, '')
        .replace(/^export (?=(?:async )?function )/gm, '')
        .replaceAll('globalThis.setupGalleryPdfDocuments = setupGalleryPdfDocuments;', '');
    vm.runInContext(`${transformed}\nglobalThis.__setupGalleryPdfDocuments = setupGalleryPdfDocuments; globalThis.__uploadGalleryPdfFiles = uploadGalleryPdfFiles;`, context);
    context.__setupGalleryPdfDocuments();

    /**
     * Dispatch one submit event through the installed document-level owner.
     * @param {PdfWorkflowForm} form Newly rendered PDF conversion form.
     * @returns {Promise<void>} Resolves or rejects with the workflow result.
     */
    const submit = async form => {
        activeForm = form;
        const event = {target: form, submitter: null, prevented: false, stopped: false,
            preventDefault: () => { event.prevented = true; },
            stopImmediatePropagation: () => { event.stopped = true; }};
        const listener = (listeners.get('submit') || []).find(item => item.capture)?.callback;
        if (!listener) throw new Error('PDF workflow did not install its delegated submit listener.');
        await listener(event);
        assert.equal(event.prevented, true, 'Delegated PDF action must prevent navigation.');
        assert.equal(event.stopped, true, 'PDF workflow must own its form before generic handlers.');
        return form.querySelector('[data-pdf-status]').textContent;
    };

    /**
     * Dispatch one cancel click against the form currently owned by the PDF workflow.
     * @param {PdfWorkflowForm} form Form whose active conversion should be cancelled.
     * @returns {void} Run the delegated cancel click listener.
     */
    const cancel = form => {
        const button = {closest: selector => selector === '[data-pdf-cancel]' ? button : selector === 'form' ? form : null};
        const event = {target: button, preventDefault: () => {}};
        const listener = (listeners.get('click') || [])[0]?.callback;
        if (!listener) throw new Error('PDF workflow did not install its delegated cancel listener.');
        listener(event);
    };

    const attachOnly = async (operation, file, convertPages = false) => {
        const form = {dataset: {pdfAttachmentUrl: '/admin.php?tab=edit'}, action: '/admin.php?tab=edit',
            closest: () => null,
            querySelector: selector => selector === '[name="csrf_token"]' ? {value: 'csrf-fresh'} : null,
            insertAdjacentElement: () => {}};
        const progress = {querySelector: () => null};
        return context.__uploadGalleryPdfFiles(form, progress, [file], {galleryId: 17, targetMode: 'child', dpi: 150,
            convertPages, operation, baseResult: null});
    };
    return {submit, cancel, attachOnly, requests, fetchCalls, streamCalls, streams, uploads, operations,
        initialUrl, location,
        submitListenerCount: (listeners.get('submit') || []).length, uploadStarted};
}

/**
 * Build a dynamically rendered conversion form with fresh CSRF and semantic inputs.
 * @param {'child'|'append'} targetMode Requested output destination.
 * @param {number} dpi Output resolution selected by the administrator.
 * @returns {PdfWorkflowForm} Fake form with the same controls read by the production module.
 */
function makeConversionForm(targetMode, dpi = 150) {
    const inputs = new Map([
        ['[name="document_id"]', {value: 'doc-abc'}],
        ['[name="dpi"]', {value: String(dpi)}],
        ['[name="target_mode"]', {value: targetMode}],
        ['[name="id"]', {value: '17'}],
    ]);
    const progressFill = {value: 0};
    const resourceWarning = {hidden: true, textContent: ''};
    const status = {textContent: '', append: () => {}};
    const progress = {
        hidden: true,
        innerHTML: '',
        querySelector: selector => selector === '[data-thumbnail-progress-fill]' ? progressFill : null,
    };
    const cancelButton = {hidden: true, closest: () => form};
    const form = {
        action: '/admin.php?tab=edit&id=17',
        isConnected: true,
        dataset: {pdfUrl: '/pdf/doc-abc/view?access=private'},
        formDataEntries: [['csrf_token', 'csrf-fresh'], ['document_id', 'doc-abc'], ['dpi', String(dpi)],
            ['target_mode', targetMode], ['id', '17'], ['operation_key', 'operation-key-1']],
        inputs,
        matches: selector => selector.includes('[data-pdf-convert-form]'),
        querySelector: selector => inputs.get(selector)
            || (selector === '[data-pdf-cancel]' ? cancelButton : null)
            || (selector === '[data-pdf-status]' ? status : null)
            || (selector === '[data-pdf-resource-warning]' ? resourceWarning : null)
            || (selector === '[data-upload-progress]' ? progress : null),
        querySelectorAll: () => [],
    };
    return form;
}

const successfulResponses = (request, index) => {
    const action = request.body.get('pdf_action');
    if (action === 'begin') return {ok: true, payload: {ok: true, status: 'pending', session_token: 'token-' + index,
        gallery_id: request.body.get('target_mode') === 'append' ? 17 : 99, prefix: 'pdf-doc-page-', page_digits: 4,
        start_page: 1, next_batch_index: 0}};
    if (action === 'finalize') return {ok: true, payload: {ok: true, target_url: ''}};
    if (action === 'cancel') return {ok: true, payload: {ok: true}};
    return {ok: false, payload: {ok: false, message: 'Unexpected PDF action.'}};
};

const workflow = await makeWorkflowHarness(successfulResponses);
const childForm = makeConversionForm('child', 200);
assert.equal(await workflow.submit(childForm), 'Completed');
assert.equal(workflow.submitListenerCount, 1, 'One document-level handler must cover dynamically rendered forms.');
assert.equal(workflow.requests[0].url, childForm.action, 'Lifecycle calls must use the original form action.');
assert.equal(workflow.requests[0].body.get('pdf_action'), 'begin', 'The lifecycle request must explicitly begin conversion.');
assert.equal(workflow.requests[0].body.get('csrf_token'), 'csrf-fresh', 'The lifecycle request must retain fresh form CSRF.');
assert.equal(workflow.requests[0].body.get('target_mode'), 'child', 'The child destination must be sent to lifecycle admission.');
assert.equal(workflow.requests[0].body.get('dpi'), '200', 'The selected DPI must be bound into lifecycle admission.');
assert.equal(workflow.requests[0].body.get('page_count'), '3', 'The discovered PDF page count must be declared before upload.');
assert.equal(workflow.streams[0].stream.configured.baseName, 'pdf-doc-page-');
assert.equal(workflow.streams[0].stream.configured.pageDigits, 4);
assert.equal(workflow.uploads[0].options.galleryId, 99, 'Private-child lifecycle response selects its returned gallery.');
assert.equal(workflow.requests[1].body.get('pdf_action'), 'finalize');
assert.equal(workflow.requests[1].body.get('pdf_conversion_token'), 'token-0');
assert.equal(workflow.requests[1].body.get('target_gallery_id'), '99');
assert.equal(workflow.requests.every(request => request.url === childForm.action), true);
assert.equal(workflow.location.href, workflow.initialUrl, 'PDF conversion must preserve the browser URL.');
assert.equal(workflow.operations[0].pdfSource.documentId, 'doc-abc');
assert.equal(Object.isFrozen(workflow.operations[0].pdfSource), true, 'Unresolved source identity and settings must be immutable.');

const appendForm = makeConversionForm('append', 96);
await workflow.submit(appendForm);
assert.equal(workflow.requests[2].body.get('target_mode'), 'append', 'The dynamically rerendered append destination must reach lifecycle admission.');
assert.equal(workflow.uploads[1].options.galleryId, 17, 'Append uses the existing gallery returned by lifecycle admission.');
assert.equal(workflow.requests[3].body.get('target_gallery_id'), '17', 'Finalization is bound to the existing destination gallery.');
assert.equal(workflow.submitListenerCount, 1, 'The replacement form is handled without adding a second delegated listener.');
assert.equal(workflow.location.href, workflow.initialUrl, 'Dynamically injected append forms remain in place.');

const unresolvedHarness = await makeWorkflowHarness(successfulResponses, false, true);
const unresolvedForm = makeConversionForm('child', 150);
assert.match(await unresolvedHarness.submit(unresolvedForm), /prepared page upload outcome is uncertain/);
assert.equal(unresolvedHarness.operations[0].unresolved, true, 'A failed upload retains its lifecycle intent for exact retry.');
assert.equal(Object.isFrozen(unresolvedHarness.operations[0].pdfSource), true, 'Retry semantics must be immutable once admitted.');
unresolvedForm.querySelector('[name="dpi"]').value = '200';
unresolvedForm.formDataEntries = unresolvedForm.formDataEntries.map(([name, value]) => [name, name === 'dpi' ? '200' : value]);
assert.match(await unresolvedHarness.submit(unresolvedForm), /unresolved/i, 'Changed conversion inputs must be refused before a new begin.');
assert.equal(unresolvedHarness.requests.length, 1, 'The changed retry must not make another lifecycle request.');
assert.equal(unresolvedHarness.streamCalls.length, 1, 'The changed retry must be refused before reopening the PDF.');

const attachmentOnlyHarness = await makeWorkflowHarness(() => ({ok: true, payload: {
    ok: true, message: 'PDF attached', document_id: 'doc-attachment', contexts: [], image_ids: [],
    mutation: {entity: 'pdf_document', entity_ids: ['doc-attachment']}, panel: {},
}}));
const attachmentOperation = {running: true};
const originalPdf = {name: 'flight.pdf', type: 'application/pdf'};
const attached = await attachmentOnlyHarness.attachOnly(attachmentOperation, originalPdf);
assert.equal(attachmentOnlyHarness.requests.length, 1, 'Default PDF upload sends only one original attachment request.');
assert.equal(attachmentOnlyHarness.requests[0].body.get('pdf_action'), 'upload');
assert.equal(attachmentOnlyHarness.requests[0].body.get('pdf_file'), originalPdf, 'Attachment keeps the original selected File identity.');
assert.equal(attachmentOnlyHarness.streamCalls.length, 0, 'Attachment-only upload never opens a PDF conversion stream.');
assert.equal(attachmentOnlyHarness.requests.some(request => request.body.get('pdf_action') === 'begin'), false,
    'Attachment-only upload never starts a page conversion lifecycle.');
assert.equal(attached.pdf_documents_uploaded, 1);
await attachmentOnlyHarness.attachOnly(attachmentOperation, originalPdf);
assert.equal(attachmentOnlyHarness.requests.length, 1, 'Retry after acknowledged attachment does not attach a duplicate document.');
await assert.rejects(attachmentOnlyHarness.attachOnly(attachmentOperation, originalPdf, true), /conversion choice/i,
    'Retry must preserve the originally frozen conversion choice.');
assert.equal(attachmentOnlyHarness.requests.length, 1, 'Changing conversion intent is rejected before another attachment.');

const failedFinalizeHarness = await makeWorkflowHarness((request, index) => {
    if (request.body.get('pdf_action') === 'begin') return successfulResponses(request, index);
    if (request.body.get('pdf_action') === 'finalize') return {ok: false, payload: {ok: false, message: 'finalize refused'}};
    return {ok: false, payload: {ok: false, message: 'Unexpected PDF action.'}};
});
const failedFinalizeForm = makeConversionForm('child', 150);
assert.match(await failedFinalizeHarness.submit(failedFinalizeForm), /finalize refused/);
assert.ok(failedFinalizeForm.querySelector('[data-upload-progress]').querySelector('[data-thumbnail-progress-fill]').value < 100,
    'A failed finalize response must never show 100 percent completion.');

const cancelHarness = await makeWorkflowHarness(successfulResponses, true);
const cancelForm = makeConversionForm('append', 150);
const cancelSubmission = cancelHarness.submit(cancelForm);
await cancelHarness.uploadStarted;
cancelHarness.cancel(cancelForm);
assert.match(await cancelSubmission, /Cancelled\./);
const cancelRequest = cancelHarness.requests.find(request => request.body.get('pdf_action') === 'cancel');
assert.ok(cancelRequest, 'The delegated cancel action must notify the conversion lifecycle.');
assert.equal(cancelRequest.body.get('pdf_conversion_token'), 'token-0');
assert.equal(cancelRequest.body.get('target_gallery_id'), '17');
assert.equal(cancelRequest.body.get('csrf_token'), 'csrf-fresh');
assert.equal(cancelRequest.url, cancelForm.action);
assert.equal(cancelHarness.location.href, cancelHarness.initialUrl, 'Cancellation must preserve the form URL.');

// Gallery-editor placement regression (#200): document actions are isolated
// from API configuration and the shared gallery settings form.
const editorSourcePaths = [
    'app/controllers/admin_galleries_edit_page/overview.php',
    'app/controllers/admin_galleries_edit_page/controller.php',
    'app/controllers/admin_galleries_edit_page/tab_documents.php',
    'app/controllers/admin_galleries_edit_page/tab_tools.php',
    'app/controllers/admin_galleries_edit_page.php',
    'app/controllers/admin_galleries_edit_actions.php',
    'app/controllers/admin_galleries_edit_page/pdf_documents.php',
    'app/controllers/admin_galleries_edit_page/post_actions.php',
];
const editorSources = await Promise.all(editorSourcePaths.map(path =>
    readFile(new URL('../' + path, import.meta.url), 'utf8')));
const [overviewSource, editorSource, documentsSource, apiSource, entrySource,
    editActionsSource, pdfPostSource, ofpPostSource] = editorSources;
assert.match(overviewSource,
    /'id' => 'admin-edit-documents',\s*'label' => t\('presentation\.media_tab'/,
    'A dedicated localized PDF/Music/Presentation tab must exist.');
assert.match(entrySource, /require_once __DIR__ \. '\/admin_galleries_edit_page\/tab_documents\.php'/,
    'The gallery editor must load the Documents tab controller.');
assert.match(editActionsSource, /'admin-edit-documents'/,
    'The server must recognize Documents as a valid redirect/selected tab.');
assert.match(documentsSource, /'documents_html' => admin_gallery_pdf_documents_html\(\$gallery\)/,
    'The document manager must belong to the Documents tab.');
assert.match(documentsSource, /'simbrief_html' => admin_edit_gallery_simbrief_legacy_panel_html\(\$gallery\)/,
    'The historical SimBrief PDF attachment must also belong to Documents.');
assert.match(documentsSource, /view_render_admin_gallery_media_tab\(/,
    'The prepared media fragments must use the presentation-owned tab wrapper.');
const mediaView = await readFile(new URL(
    '../app/views/admin_gallery_edit_tabs.php', import.meta.url
), 'utf8');
assert.match(mediaView, /view_render_admin_tab_panel\('admin-edit-documents'/,
    'The media view must retain its existing native tab identity.');
assert.doesNotMatch(apiSource, /admin_gallery_pdf_documents_html|admin_edit_gallery_simbrief_legacy_panel_html/,
    'The API tab must not render PDF or SimBrief document forms.');
const closeSettingsFormAt = editorSource.indexOf('    view_render_admin_gallery_editor_form_close([');
const renderDocumentsAt = editorSource.indexOf('    admin_edit_gallery_render_documents_tab($gallery, $activeEditTab);');
assert.ok(closeSettingsFormAt >= 0 && renderDocumentsAt > closeSettingsFormAt,
    'Independent PDF upload/conversion forms must render after the shared settings form closes.');
assert.match(pdfPostSource, /'tab' => 'admin-edit-documents'/,
    'PDF mutation redirect/panel metadata must return to the Documents tab.');
assert.match(ofpPostSource, /redirect_to\(admin_edit_gallery_tab_url\(\$galleryId, 'admin-edit-documents'\)\)/,
    'Legacy OFP non-JavaScript upload fallback must return to Documents.');

// Exercise the actual shared metadata queue independently of upload adapters.
// Two equal sources share one read; a changed version must reopen the original.
const metadataSource = await readFile(new URL(
    '../public/assets/gallery-modules/gallery-pdf-metadata.js', import.meta.url
), 'utf8');
let metadataActive = 0;
let metadataPeak = 0;
const metadataReads = [];
const metadataDisposals = [];
const pageTotal = {
    textContent: '…', removeAttribute: () => {},
};
const metadataSection = {
    querySelector: () => pageTotal,
    querySelectorAll: () => metadataCells,
};
const metadataCells = ['/one', '/one', '/two'].map(pdfUrl => ({
    dataset: {pdfUrl, pdfVersion: 'v1'}, isConnected: true, textContent: '…',
    closest: () => metadataSection, removeAttribute: () => {},
}));
const metadataRoot = {
    matches: () => false, querySelectorAll: () => metadataCells,
};
const metadataContext = vm.createContext({
    Element: Object,
    createPdfPngUploadStream: async url => {
        metadataReads.push(url);
        metadataActive += 1;
        metadataPeak = Math.max(metadataPeak, metadataActive);
        return {
            pageCount: url === '/one' ? 3 : 5,
            dispose: async () => {
                metadataActive -= 1;
                metadataDisposals.push(url);
            },
        };
    },
});
vm.runInContext(metadataSource
    .replace(/^import[^]*?;\s*$/m, '')
    .replace(/^export /gm, '')
    .replace('void drainPdfPageCounts();',
        'globalThis.metadataWork = drainPdfPageCounts();'), metadataContext);
metadataContext.scanPdfPageCountCells(metadataRoot);
assert.equal(pageTotal.textContent, '…', 'Pending pages must not become zero.');
await metadataContext.metadataWork;
assert.equal(pageTotal.textContent, '11', 'Sum all three authorized PDF rows.');
assert.deepEqual(metadataReads, ['/one', '/two'], 'Reuse exact-version metadata.');
assert.equal(metadataPeak, 1, 'PDF streams must never overlap.');
assert.deepEqual(metadataDisposals, ['/one', '/two'], 'Release every stream.');

const changedCell = {
    ...metadataCells[0], dataset: {pdfUrl: '/one', pdfVersion: 'v2'},
};
metadataCells.splice(0, metadataCells.length, changedCell);
metadataContext.scanPdfPageCountCells(metadataRoot);
await metadataContext.metadataWork;
assert.equal(metadataReads.length, 3, 'Changed source version must be reread.');
assert.equal(pageTotal.textContent, '3');

metadataContext.createPdfPngUploadStream = async () => {
    throw new Error('Denied or malformed original');
};
metadataCells.push({
    ...changedCell, dataset: {pdfUrl: '/denied', pdfVersion: 'v1'},
});
metadataContext.scanPdfPageCountCells(metadataRoot);
await metadataContext.metadataWork;
assert.equal(pageTotal.textContent, '—', 'Unknown pages must not be omitted.');
assert.equal(metadataCells[1].textContent, '—');

console.log('Admin PDF document workflow tests passed.');
