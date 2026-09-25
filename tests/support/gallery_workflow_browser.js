/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_workflow_browser.js
 * Module Type: Test Fixture
 * Purpose: Run the same-origin browser journey inside a disposable application.
 * Responsibilities:
 *   - Exercise real workflow controls and capture observable browser postconditions.
 * Author: Rudolf Klusal
 * Real same-origin application journey executed by a standalone headless Chromium fixture.
 */
(
/** Run the complete same-origin browser journey. @return {Promise<void>} Writes a bounded fixture result. */
async () => {
    let stage = 'browser login';
    /** Yield briefly while actual HTTP and rendering work completes. */
    const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    /** Wait for an observable application postcondition without assuming request duration. */
    async function until(predicate) {
        const deadline = Date.now() + 20000;
        while (Date.now() < deadline) {
            const value = predicate();
            if (value) return value;
            await delay(50);
        }
        throw new Error('Timed out');
    }
    /** Fail with a bounded label, never with HTML or response details. */
    function expect(value) { if (!value) throw new Error('Assertion failed'); }
    try {
        const credentials = JSON.parse(document.querySelector('#credentials').textContent);
        const login = new DOMParser().parseFromString(await (await fetch('/index.php?page=admin_login')).text(), 'text/html');
        const csrf = login.querySelector('[name="csrf_token"]').value;
        const result = await fetch('/index.php?page=admin_login', {method: 'POST', body: new URLSearchParams({
            identifier: credentials.username, password: credentials.password, csrf_token: csrf,
        })});
        expect(result.ok);
        const frame = document.querySelector('#application');
        frame.src = '/index.php?page=home';
        await until(() => frame.contentDocument?.body?.dataset.adminGallerySidePanelBound === '1');
        const win = frame.contentWindow;
        const doc = frame.contentDocument;
        const initialUrl = win.location.href;
        const initialDocument = doc;
        let posts = 0;
        let lastMutationStatus = 0;
        let holdNextEditorRefresh = false;
        let heldEditorRefresh = null;
        let heldEditorReturned = false;
        const originalFetch = win.fetch.bind(win);
        win.fetch = async (...args) => {
            const route = new URL(String(args[0]), win.location.href).searchParams.get('page') || '';
            const isMutation = String(args[1]?.method || '').toUpperCase() === 'POST'
                && ['admin_new_gallery', 'admin_edit_gallery'].includes(route);
            if (isMutation) posts++;
            const response = await originalFetch(...args);
            if (isMutation) lastMutationStatus = response.status;
            if (holdNextEditorRefresh && route === 'admin_edit_gallery'
                && new URL(String(args[0]), win.location.href).searchParams.has('_panel_refresh') && !isMutation) {
                holdNextEditorRefresh = false;
                // Freeze the actual earlier PHP render, then deliberately deliver it after a later save.
                const body = await response.text();
                const snapshot = new win.Response(body, {status: response.status, headers: response.headers});
                await new Promise((resolve) => { heldEditorRefresh = {release: resolve}; });
                heldEditorReturned = true;
                return snapshot;
            }
            // Hold successful mutation responses long enough for the second button click.
            if (isMutation) await delay(180);
            return response;
        };
        stage = 'open actual create panel';
        // Exercise the installed delegated handler with a dynamically inserted normal create link.
        const link = doc.createElement('a');
        link.href = '/index.php?page=admin_new_gallery';
        link.dataset.gallerySidePanelLink = '';
        link.dataset.adminSidePanelWorkflow = 'create';
        link.textContent = 'Create fixture gallery';
        doc.body.append(link);
        link.click();
        const form = await until(() => doc.querySelector('[data-gallery-panel-create-form]'));
        const panel = form.closest('[data-admin-side-panel]');
        expect(form.querySelector('[name="title"]').getAttribute('aria-label') === 'Gallery name');
        form.querySelector('[name="title"]').value = 'Browser workflow';
        expect(!form.querySelector('[name="folder_name"]') && !form.querySelector('[name="visibility"]'));
        stage = 'create double click and refresh';
        const submit = form.querySelector('[type="submit"]');
        submit.click();
        submit.click();
        await until(() => doc.querySelector('[data-admin-panel-edit-form]'));
        expect(posts === 1 && !panel.hidden && win.location.href === initialUrl && frame.contentDocument === initialDocument);
        expect(doc.querySelector('[data-admin-panel-edit-form] [name="title"]').value === 'Browser workflow');
        expect(doc.querySelector('[data-admin-panel-edit-form] [name="visibility"]').value === 'unpublished');
        for (let revision = 1; revision <= 2; revision++) {
            stage = 'edit replaced panel revision ' + revision;
            const edit = doc.querySelector('[data-admin-panel-edit-form]');
            edit.querySelector('[name="title"]').value = 'Browser edited ' + revision;
            const save = edit.querySelector('.admin-edit-gallery-savebar button[type="submit"]');
            expect(save);
            save.click();
            stage = 'wait for replaced editor revision ' + revision;
            await until(() => {
                const replacement = doc.querySelector('[data-admin-panel-edit-form]');
                return replacement && replacement !== edit && replacement.querySelector('[name="title"]').value === 'Browser edited ' + revision;
            });
            stage = 'check editor mutation count revision ' + revision + ' count ' + posts;
            expect(posts === 1 + revision);
            stage = 'check editor open panel revision ' + revision;
            expect(!panel.hidden);
            stage = 'check editor unchanged URL revision ' + revision;
            expect(win.location.href === initialUrl);
            stage = 'check editor unchanged document revision ' + revision;
            expect(frame.contentDocument === initialDocument);
        }
        stage = 'hold older server rendered editor refresh';
        const beforeReorder = doc.querySelector('[data-admin-panel-edit-form]');
        holdNextEditorRefresh = true;
        beforeReorder.querySelector('[name="title"]').value = 'Browser stale response';
        beforeReorder.querySelector('.admin-edit-gallery-savebar button[type="submit"]').click();
        await until(() => heldEditorRefresh && !beforeReorder.querySelector('.admin-edit-gallery-savebar button[type="submit"]').disabled);
        stage = 'newer save overtakes delayed editor refresh';
        beforeReorder.querySelector('[name="title"]').value = 'Browser edited 2';
        beforeReorder.querySelector('.admin-edit-gallery-savebar button[type="submit"]').click();
        await until(() => {
            const current = doc.querySelector('[data-admin-panel-edit-form]');
            return current && current !== beforeReorder && current.querySelector('[name="title"]').value === 'Browser edited 2';
        });
        const latestEditor = doc.querySelector('[data-admin-panel-edit-form]');
        heldEditorRefresh.release();
        await until(() => heldEditorReturned);
        await new Promise((resolve) => win.requestAnimationFrame(() => win.requestAnimationFrame(resolve)));
        stage = 'stale editor response cannot replace newer fragment';
        expect(doc.querySelector('[data-admin-panel-edit-form]') === latestEditor);
        expect(latestEditor.querySelector('[name="title"]').value === 'Browser edited 2');
        expect(!panel.hidden && win.location.href === initialUrl && frame.contentDocument === initialDocument);
        /** Open a real server fragment through the installed delegated side-panel link handler. */
        async function openPanel(route, workflow, selector) {
            const old = doc.querySelector(selector);
            const entry = doc.createElement('a');
            entry.href = route;
            entry.dataset.gallerySidePanelLink = '';
            entry.dataset.adminSidePanelWorkflow = workflow;
            entry.textContent = 'Fixture workflow';
            doc.body.append(entry);
            entry.click();
            return until(() => {
                const loaded = doc.querySelector(selector);
                return loaded && loaded !== old ? loaded : null;
            });
        }
        /** Require every completed panel action to retain both document identity and URL. */
        function inPlace() {
            expect(win.location.href === initialUrl && frame.contentDocument === initialDocument);
            expect(!panel.hidden && panel.isConnected);
        }
        const galleryId = Number(doc.querySelector('[data-admin-panel-edit-form] [name="id"]').value);
        stage = 'browser upload file selection';
        const upload = await openPanel('/index.php?page=admin_upload&gallery_id=' + galleryId, 'upload', '[data-gallery-upload-form]');
        const canvas = doc.createElement('canvas');
        canvas.width = 48;
        canvas.height = 32;
        const drawing = canvas.getContext('2d');
        drawing.fillStyle = '#338899';
        drawing.fillRect(0, 0, 48, 32);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg'));
        const bytes = await blob.arrayBuffer();
        const hashBytes = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
        const imageHash = Array.from(hashBytes, (value) => value.toString(16).padStart(2, '0')).join('');
        const transfer = new win.DataTransfer();
        transfer.items.add(new win.File([bytes], 'browser-upload.jpg', {type: 'image/jpeg'}));
        upload.querySelector('[type="file"]').files = transfer.files;
        upload.querySelector('[type="file"]').dispatchEvent(new win.Event('change', {bubbles: true}));
        // Exercise the standard multipart UI pipeline; prepared-batch retry is covered over HTTP.
        upload.querySelector('[data-browser-upload-toggle]').checked = false;
        upload.querySelector('[name="create_thumbnails"]').checked = false;
        stage = 'browser multipart upload and refreshed editor';
        upload.querySelector('[type="submit"]').click();
        await until(() => doc.querySelector('[data-admin-panel-edit-form]') && doc.querySelector('[data-admin-image-order-row][data-image-id]'));
        inPlace();
        const imageId = Number(doc.querySelector('[data-admin-image-order-row][data-image-id]').dataset.imageId);
        expect(imageId > 0);
        for (const visibility of ['private', 'public']) {
            stage = 'browser visibility ' + visibility;
            const editor = doc.querySelector('[data-admin-panel-edit-form]');
            editor.querySelector('[name="visibility"]').value = visibility;
            editor.querySelector('.admin-edit-gallery-savebar button[type="submit"]').click();
            await until(() => {
                const replacement = doc.querySelector('[data-admin-panel-edit-form]');
                return replacement && replacement !== editor && replacement.querySelector('[name="visibility"]').value === visibility;
            });
            inPlace();
            const media = await fetch('/index.php?page=media&id=' + imageId, {credentials: 'omit', cache: 'no-store'});
            expect(media.status === (visibility === 'private' ? 404 : 200));
        }
        stage = 'browser recoverable gallery delete';
        const card = await until(() => doc.querySelector('article[data-gallery-id="' + galleryId + '"]'));
        const deletion = card.querySelector('[data-public-admin-delete-form][data-public-admin-delete-kind="gallery"]');
        expect(deletion?.dataset.publicAdminDeleteMode === 'trash');
        // Accept the product's existing confirmation for this synthetic gallery only.
        win.confirm = () => true;
        deletion.querySelector('[type="submit"]').click();
        await until(() => !doc.querySelector('article[data-gallery-id="' + galleryId + '"]'));
        inPlace();
        expect((await fetch('/index.php?page=media&id=' + imageId, {credentials: 'omit', cache: 'no-store'})).status === 404);
        stage = 'browser trash fragment restore';
        const trashPanel = await openPanel('/index.php?page=admin_trash', 'create', '[data-admin-trash-panel]');
        const restore = Array.from(trashPanel.querySelectorAll('[data-admin-trash-restore-form]'))
            .find((candidate) => candidate.closest('tr').textContent.includes('Browser edited 2'));
        expect(restore);
        restore.querySelector('[type="submit"]').click();
        await until(() => {
            const replacement = doc.querySelector('[data-admin-trash-panel]');
            return replacement && replacement !== trashPanel
                && !Array.from(replacement.querySelectorAll('[data-admin-trash-restore-form]'))
                    .some((candidate) => candidate.closest('tr').textContent.includes('Browser edited 2'));
        });
        inPlace();
        // Restore may assign new row identities. Resolve them from an actual authenticated page.
        const home = new DOMParser().parseFromString(await (await fetch('/index.php?page=home', {cache: 'no-store'})).text(), 'text/html');
        const restoredCard = Array.from(home.querySelectorAll('article[data-gallery-id]'))
            .find((candidate) => candidate.textContent.includes('Browser edited 2'));
        expect(restoredCard);
        const restoredId = Number(restoredCard.dataset.galleryId);
        stage = 'browser session expiry in open editor';
        const expiredEditor = await openPanel('/index.php?page=admin_edit_gallery&id=' + restoredId, 'gallery-edit', '[data-admin-panel-edit-form]');
        await fetch('/index.php?page=admin_logout');
        lastMutationStatus = 0;
        expiredEditor.querySelector('[name="title"]').value = 'Expired session must not persist';
        expiredEditor.querySelector('.admin-edit-gallery-savebar button[type="submit"]').click();
        await until(() => lastMutationStatus === 401 && !expiredEditor.querySelector('.admin-edit-gallery-savebar button[type="submit"]').disabled);
        expect(doc.querySelector('[data-admin-side-panel-status]').textContent.trim() !== '');
        inPlace();
        stage = 'browser create upload visibility trash restore expiry and response ordering';
        await fetch(location.pathname + '/result', {method: 'POST', body: JSON.stringify({ok: true, stage, imageHash})});
    } catch {
        await fetch(location.pathname + '/result', {method: 'POST', body: JSON.stringify({ok: false, stage})});
    }
})();
