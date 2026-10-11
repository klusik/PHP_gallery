/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_workflow_browser.js
 * Module Type: Test Fixture
 * Purpose: Run the same-origin browser journey inside a disposable application.
 * Responsibilities:
 *   - Exercise real workflow controls and capture observable browser postconditions.
 *   - Deliver an unsaved authenticated widget draft into protected Home and Gallery iframes.
 *   - Check live page, placement and device changes while guarding persisted widget state.
 * Author: Rudolf Klusal
 * Real same-origin application journey executed by a standalone headless Chromium fixture.
 */
(
/** Run the complete same-origin browser journey. @return {Promise<void>} Writes a bounded fixture result. */
async () => {
    let stage = 'browser login';
    Object.defineProperty(window, '__galleryWorkflowStage', {
        configurable: false,
        enumerable: false,
        get: () => stage,
    });
    // Purpose: Bound one browser request and response body in the disposable workflow.
    // Type: integer.
    // Units: milliseconds.
    // Scope: HTTP requests awaited by the outer page and its same-origin application frames.
    // Consumers: fixture fetch wrappers used by the live Gallery browser journey.
    // Rationale: surface a stalled local request at its current workflow step instead of consuming the full fixture result window.
    const workflowFetchTimeoutMs = 30000;
    const nativeFetch = window.fetch.bind(window);
    const fetchWithDeadline = async (fetchFunction, ...args) => {
        const [request, init] = args;
        const requestOptions = init && typeof init === 'object' ? init : {};
        const existingSignal = requestOptions.signal ?? request?.signal;
        const timeoutSignal = AbortSignal.timeout(workflowFetchTimeoutMs);
        const signal = existingSignal ? AbortSignal.any([existingSignal, timeoutSignal]) : timeoutSignal;
        try {
            return await fetchFunction(request, {...requestOptions, signal});
        } catch (error) {
            if (timeoutSignal.aborted) {
                const safeStage = stage.replace(/[^a-zA-Z0-9 ._-]/g, ' ').replace(/\s+/g, ' ').trim();
                stage = ('fetch timeout ' + safeStage).slice(0, 100);
            }
            throw error;
        }
    };
    const fetch = (...args) => fetchWithDeadline(nativeFetch, ...args);
    // Purpose: Bound native canvas encoding used to create the synthetic upload image.
    // Type: integer.
    // Units: milliseconds.
    // Scope: one JPEG callback in the disposable Gallery browser journey.
    // Consumers: the browser-upload fixture's canvas.toBlob() call.
    // Rationale: a missing native encoding callback must not hold the browser result poll indefinitely.
    const workflowCanvasEncodingTimeoutMs = 10000;
    // Purpose: Bound the pair of animation frames used to settle a replaced Admin panel.
    // Type: integer.
    // Units: milliseconds.
    // Scope: one stale-fragment ordering check in the disposable Gallery browser journey.
    // Consumers: the two-frame Admin panel settle point.
    // Rationale: a suspended iframe must report its active stage instead of holding the full browser result poll.
    const workflowFrameSettleTimeoutMs = 2000;
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
    let savedPublicFrame = null;
    try {
        const safeRoute = value => {
            try {
                const route = new URL(value, location.href).searchParams.get('page') || 'home';
                return ['admin', 'admin_login', 'admin_logout', 'admin_theme', 'admin_new_gallery', 'home'].includes(route) ? route : 'other';
            } catch {
                return 'unknown';
            }
        };
        const safePublicRoute = value => {
            try {
                const url = new URL(value, location.href);
                const route = url.searchParams.get('page') || '';
                if (['home', 'gallery'].includes(route)) return route;
                if (url.pathname === '/') return 'root';
                if (/\/gallery\/(?:[^/]+\/)*thumb-[1-9][0-9]*\.(?:jpg|webp)$/i.test(url.pathname)) return 'clean_thumb';
                if (/\/gallery\/(?:[^/]+\/)*media$/i.test(url.pathname)) return 'clean_media';
                return 'other';
            } catch {
                return 'unknown';
            }
        };
        const credentials = JSON.parse(document.querySelector('#credentials').textContent);
        const frame = document.querySelector('#application');
        expect(frame instanceof HTMLIFrameElement);
        // Use the real login form in the application's own browsing context.
        // Fetch follows redirects and can report HTTP 200 for a failed login form;
        // only navigation to the authenticated Admin route proves sign-in.
        stage = 'open native admin login form';
        frame.src = '/index.php?page=admin_login';
        const loginDocument = await until(() => {
            const current = frame.contentDocument;
            if (current?.readyState !== 'complete') return null;
            const form = current.querySelector('form[method="post"]');
            return form?.querySelector('[name="csrf_token"]')?.value ? current : null;
        });
        const loginForm = loginDocument.querySelector('form[method="post"]');
        const loginIdentifier = loginForm.querySelector('input[name="identifier"]');
        const loginPassword = loginForm.querySelector('input[name="password"]');
        expect(loginIdentifier instanceof frame.contentWindow.HTMLInputElement
            && loginPassword instanceof frame.contentWindow.HTMLInputElement);
        loginIdentifier.value = credentials.username;
        loginPassword.value = credentials.password;
        stage = 'submit native admin login form';
        loginForm.requestSubmit();
        const afterLoginDocument = await until(() => {
            const current = frame.contentDocument;
            return current && current !== loginDocument && current.readyState === 'complete' ? current : null;
        });
        stage = 'native login destination ' + safeRoute(frame.contentWindow.location.href)
            + ' login-form ' + Boolean(afterLoginDocument.querySelector('input[name="password"]'));
        expect(new URL(frame.contentWindow.location.href).searchParams.get('page') === 'admin'
            && !afterLoginDocument.querySelector('input[name="password"]'));
        stage = 'open authenticated admin home';
        frame.src = '/index.php?page=home';
        let actualCreateLink;
        try {
            actualCreateLink = await until(() => {
                const current = frame.contentDocument;
                if (current?.readyState !== 'complete'
                    || current.body?.dataset.adminGallerySidePanelBound !== '1') return null;
                return current.querySelector('[data-gallery-side-panel-link][data-admin-side-panel-workflow="create"]');
            });
        } catch {
            const current = frame.contentDocument;
            stage = 'admin home route ' + safeRoute(frame.contentWindow?.location.href || '')
                + ' panel-bound ' + Boolean(current?.body?.dataset.adminGallerySidePanelBound === '1')
                + ' admin-create ' + Boolean(current?.querySelector('[data-admin-side-panel-workflow="create"]'))
                + ' login-form ' + Boolean(current?.querySelector('input[name="password"]'));
            throw new Error('Authenticated Home did not expose its real create-gallery entry point.');
        }
        const win = frame.contentWindow;
        const doc = frame.contentDocument;
        const initialUrl = win.location.href;
        const initialDocument = doc;
        let posts = 0;
        let createPanelResponseStatus = 0;
        let createPanelResponseRoute = 'none';
        let createPanelRedirected = false;
        let lastMutationStatus = 0;
        const imageMutationResponses = [];
        let holdNextEditorRefresh = false;
        let heldEditorRefresh = null;
        let heldEditorReturned = false;
        const originalFetch = win.fetch.bind(win);
        /** Observe fixture workflow requests and retain image-action envelopes. @param {Parameters<typeof win.fetch>} args Original browser fetch arguments. @return {Promise<Response>} Original or deliberately gated network response. */
        win.fetch = async (...args) => {
            const route = new URL(String(args[0]), win.location.href).searchParams.get('page') || '';
            const isMutation = String(args[1]?.method || '').toUpperCase() === 'POST'
                && ['admin_new_gallery', 'admin_edit_gallery'].includes(route);
            const isImageMutation = String(args[1]?.method || '').toUpperCase() === 'POST' && route === 'admin_bulk_images';
            if (isMutation) posts++;
            const response = await fetchWithDeadline(originalFetch, ...args);
            if (isMutation) lastMutationStatus = response.status;
            if (route === 'admin_new_gallery' && !isMutation) {
                createPanelResponseStatus = response.status;
                createPanelResponseRoute = safeRoute(response.url);
                createPanelRedirected = response.redirected;
            }
            if (isImageMutation) {
                const payload = await response.clone().json();
                imageMutationResponses.push(payload);
            }
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
        // Keep the dynamic-link delegation test, but use a real authorized
        // production link including its server-generated panel destination.
        const link = actualCreateLink.cloneNode(true);
        expect(link instanceof win.HTMLAnchorElement);
        doc.body.append(link);
        link.click();
        let form;
        try {
            form = await until(() => doc.querySelector('[data-gallery-panel-create-form]'));
        } catch {
            const panelElement = doc.querySelector('[data-admin-side-panel]');
            const panelBody = panelElement?.querySelector('[data-admin-side-panel-body]');
            stage = 'create panel status ' + createPanelResponseStatus
                + ' route ' + createPanelResponseRoute
                + ' redirected ' + Number(createPanelRedirected)
                + ' panel ' + Number(Boolean(panelElement))
                + ' loading ' + Number(Boolean(panelBody?.querySelector('.admin-side-panel-loading')));
            throw new Error('Real create-gallery panel did not contain the expected form.');
        }
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
        await new Promise((resolve, reject) => {
            const settleTimeout = setTimeout(() => {
                stage = 'gallery panel animation frame timeout';
                reject(new Error('Admin panel replacement did not settle in two animation frames.'));
            }, workflowFrameSettleTimeoutMs);
            win.requestAnimationFrame(() => win.requestAnimationFrame(() => {
                clearTimeout(settleTimeout);
                resolve();
            }));
        });
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
        stage = 'breadcrumb radio selection saves in the open gallery panel';
        const breadcrumbEditor = doc.querySelector('[data-admin-panel-edit-form]');
        const displayTab = panel.querySelector('[data-admin-tab-target="admin-edit-display"]');
        expect(displayTab);
        displayTab.click();
        await until(() => doc.querySelector('#admin-edit-display')?.classList.contains('is-active'));
        const advancedDisplay = doc.querySelector('#admin-edit-display details.admin-display-advanced');
        expect(advancedDisplay);
        if (!advancedDisplay.open) advancedDisplay.querySelector('summary').click();
        expect(advancedDisplay.open);
        const breadcrumbRadio = breadcrumbEditor?.querySelector('input[type="radio"][name="gallery_breadcrumb_style"][value="gradient"]');
        const breadcrumbCard = breadcrumbRadio?.closest('.breadcrumb-style-picker__option');
        expect(breadcrumbRadio && breadcrumbCard);
        breadcrumbCard.scrollIntoView({block: 'center', inline: 'nearest'});
        const breadcrumbCardRect = breadcrumbCard.getBoundingClientRect();
        expect(breadcrumbCardRect.width > 0 && breadcrumbCardRect.height > 0
            && win.getComputedStyle(breadcrumbCard).display !== 'none'
            && win.getComputedStyle(breadcrumbCard).visibility === 'visible');
        breadcrumbCard.click();
        expect(breadcrumbRadio.checked);
        const postsBeforeBreadcrumbSave = posts;
        breadcrumbEditor.querySelector('.admin-edit-gallery-savebar button[type="submit"]').click();
        const breadcrumbEditorAfterSave = await until(() => {
            const replacement = doc.querySelector('[data-admin-panel-edit-form]');
            const selected = replacement?.querySelector('input[type="radio"][name="gallery_breadcrumb_style"][value="gradient"]');
            return replacement && replacement !== breadcrumbEditor && selected?.checked ? replacement : null;
        });
        inPlace();
        expect(posts === postsBeforeBreadcrumbSave + 1 && breadcrumbEditorAfterSave.querySelectorAll('input[name="gallery_breadcrumb_style"]').length === 10);
        const galleryId = Number(doc.querySelector('[data-admin-panel-edit-form] [name="id"]').value);
        stage = 'browser upload file selection';
        const upload = await openPanel('/index.php?page=admin_upload&gallery_id=' + galleryId, 'upload', '[data-gallery-upload-form]');
        const canvas = doc.createElement('canvas');
        canvas.width = 48;
        canvas.height = 32;
        const drawing = canvas.getContext('2d');
        drawing.fillStyle = '#338899';
        drawing.fillRect(0, 0, 48, 32);
        const blob = await new Promise((resolve, reject) => {
            const encodingTimeout = setTimeout(() => {
                stage = 'browser upload canvas encoding timeout';
                reject(new Error('Canvas JPEG encoding did not complete before its workflow deadline.'));
            }, workflowCanvasEncodingTimeoutMs);
            canvas.toBlob((encoded) => {
                clearTimeout(encodingTimeout);
                if (encoded) resolve(encoded);
                else reject(new Error('Canvas JPEG encoding returned no image.'));
            }, 'image/jpeg');
        });
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
        /** Submit one row-owned action and require its replacement control to remain usable in the open drawer.
         * @param {string} action Direct row action value without its stable image ID.
         * @return {Promise<HTMLFormElement>} Dynamically replaced Images form after the canonical completion.
         */
        async function submitImageRowAction(action) {
            // Fragment insertion precedes scoped translations and delegated form preparation.
            const oldForm = await until(() => {
                const current = doc.querySelector('[data-admin-image-bulk-form]');
                const button = current?.querySelector('[data-admin-image-row-action][name="action"][value="' + action + ':' + imageId + '"]');
                return current?.dataset.adminPanelBulkForm === 'true' && button && !button.disabled ? current : null;
            });
            const oldButton = oldForm?.querySelector('[data-admin-image-row-action][name="action"][value="' + action + ':' + imageId + '"]');
            expect(oldButton && oldForm.querySelectorAll('input[name="image_ids[]"]:checked').length === 0);
            oldButton.click();
            // Require the replacement to have completed the same panel-owned initialization.
            const replacement = await until(() => {
                const current = doc.querySelector('[data-admin-image-bulk-form]');
                return current && current !== oldForm && current.dataset.adminPanelBulkForm === 'true' ? current : null;
            });
            inPlace();
            const payload = imageMutationResponses.at(-1);
            const expectedEntityId = action === 'cover' ? galleryId : imageId;
            expect(payload?.ok === true
                && Object.keys(payload).slice(0, 6).join(',') === 'ok,message,mutation,panel,contexts,fallback'
                && payload.mutation?.entity_ids?.length === 1
                && Number(payload.mutation.entity_ids[0]) === expectedEntityId
                && payload.panel?.keep_open === true
                && Array.isArray(payload.contexts) && payload.fallback?.redirect_url);
            return replacement;
        }
        stage = 'browser single-image cover action stays in the drawer';
        let imageForm = await submitImageRowAction('cover');
        expect(imageForm.querySelector('[data-admin-image-cover-cell]')?.textContent.trim() !== '');
        stage = 'browser dynamically replaced row visibility action';
        imageForm = await submitImageRowAction('draft');
        expect(imageForm.querySelector('[data-admin-image-row-action][value="draft:' + imageId + '"]')?.getAttribute('aria-pressed') === 'true');
        stage = 'browser dynamically replaced row visibility restore';
        imageForm = await submitImageRowAction('public');
        expect(imageForm.querySelector('[data-admin-image-row-action][value="public:' + imageId + '"]')?.getAttribute('aria-pressed') === 'true');
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
        stage = 'load real admin login form after session expiry';
        const reloginResponse = await fetch('/index.php?page=admin_login', {credentials: 'same-origin', cache: 'no-store'});
        expect(reloginResponse.status === 200);
        const relogin = new DOMParser().parseFromString(await reloginResponse.text(), 'text/html');
        const reloginToken = relogin.querySelector('[name="csrf_token"]')?.value;
        expect(typeof reloginToken === 'string' && reloginToken.length > 0);
        stage = 'submit real admin credentials after session expiry';
        const reloginResult = await fetch('/index.php?page=admin_login', {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: new URLSearchParams({
            identifier: credentials.username, password: credentials.password, csrf_token: reloginToken,
        })});
        const reloginFailureBody = reloginResult.status === 400 ? await reloginResult.text() : '';
        const reloginInvalidCsrf = reloginFailureBody.trim() === 'Invalid CSRF token.';
        stage = 'relogin POST status ' + reloginResult.status + ' route ' + safeRoute(reloginResult.url)
            + ' csrf-posted ' + Boolean(reloginToken) + ' invalid-csrf ' + reloginInvalidCsrf + ' ok ' + reloginResult.ok;
        expect(reloginResult.ok);
        stage = 'verify admin session probe';
        const visualEditorUrl = '/index.php?page=admin_theme&css_editor=1#admin-theme-tab-custom-css';
        const adminProbe = await fetch(visualEditorUrl, {cache: 'no-store'});
        const adminProbeMarkup = await adminProbe.text();
        const adminEditorMarker = adminProbeMarkup.includes('data-css-override-editor');
        const adminLoginForm = Boolean(new DOMParser().parseFromString(adminProbeMarkup, 'text/html')
            .querySelector('input[type="password"], [name="password"]'));
        stage = 'admin probe status ' + adminProbe.status + ' route ' + safeRoute(adminProbe.url)
            + ' editor ' + adminEditorMarker + ' login-form ' + adminLoginForm;
        expect(adminProbe.status === 200 && adminEditorMarker);
        stage = 'read disposable widget persistence baseline';
        const workflowTokenForWidget = location.pathname.match(/^\/__workflow_([a-f0-9]{24})$/)?.[1] || '';
        expect(workflowTokenForWidget !== '');
        const widgetStateUrl = location.pathname + '/preview-state';
        const readWidgetStateHash = async () => {
            const response = await fetch(widgetStateUrl, {cache: 'no-store'});
            if (!response.ok) return '';
            const state = await response.json();
            return typeof state.public_widgets_sha256 === 'string' ? state.public_widgets_sha256 : '';
        };
        const widgetStateBefore = await readWidgetStateHash();
        expect(/^[a-f0-9]{64}$/.test(widgetStateBefore));
        const widgetEditorRoute = '/index.php?page=admin_theme&widgets=1&id=new';
        stage = 'navigate authenticated app to real widget editor';
        frame.src = widgetEditorRoute;
        const widgetDocument = await until(() => {
            const currentUrl = new URL(frame.contentWindow.location.href);
            if (currentUrl.origin !== win.location.origin || currentUrl.pathname !== '/index.php'
                || currentUrl.searchParams.get('page') !== 'admin_theme'
                || currentUrl.searchParams.get('widgets') !== '1') return null;
            const editor = frame.contentDocument?.querySelector('[data-public-widget-editor]');
            return editor?.dataset.widgetEnhanced === '1' ? frame.contentDocument : null;
        });
        const widgetWindow = frame.contentWindow;
        const widgetForm = widgetDocument.querySelector('[data-public-widget-editor]');
        const widgetTitleInput = widgetForm.querySelector('[name="title"]');
        const widgetContentInput = widgetForm.querySelector('[name="content_md"]');
        const widgetScopeInput = widgetForm.querySelector('[name="page_scope"]');
        const widgetFlowSlotInput = widgetForm.querySelector('[name="flow_slot"]');
        const widgetPlacementModeInput = widgetForm.querySelector('[name="placement_mode"]');
        const widgetPageSelect = widgetDocument.querySelector('[data-widget-preview-page-selector]');
        const widgetDeviceButtons = Array.from(widgetDocument.querySelectorAll('.public-widgets-device-toggle button'));
        const widgetPreviewButton = widgetForm.querySelector('button[type="submit"][name="widget_action"][value="preview"]');
        const widgetThemeHost = widgetDocument.querySelector('[data-widget-theme-preview]');
        const widgetPublicFrame = widgetDocument.querySelector('iframe.public-widgets-theme-frame');
        const widgetTitleMarker = 'Workflow browser widget ' + workflowTokenForWidget;
        const widgetBoldMarker = 'Delivered through the authenticated editor';
        const widgetContentMarker = 'Unsaved widget payload ' + workflowTokenForWidget
            + ' **' + widgetBoldMarker + '** [Safe destination](https://example.test/widget-' + workflowTokenForWidget + ')';
        expect(widgetTitleInput && widgetContentInput && widgetScopeInput && widgetFlowSlotInput
            && widgetPageSelect && widgetPreviewButton && widgetThemeHost && widgetPublicFrame);
        widgetTitleInput.value = widgetTitleMarker;
        widgetTitleInput.dispatchEvent(new widgetWindow.Event('input', {bubbles: true}));
        widgetContentInput.value = widgetContentMarker;
        widgetContentInput.dispatchEvent(new widgetWindow.Event('input', {bubbles: true}));
        widgetScopeInput.value = 'all';
        widgetScopeInput.dispatchEvent(new widgetWindow.Event('change', {bubbles: true}));
        widgetFlowSlotInput.value = 'home_before_grid';
        widgetFlowSlotInput.dispatchEvent(new widgetWindow.Event('change', {bubbles: true}));
        widgetPageSelect.value = 'home';
        widgetPageSelect.dispatchEvent(new widgetWindow.Event('change', {bubbles: true}));
        const originalWidgetFetch = widgetWindow.fetch.bind(widgetWindow);
        let widgetPreviewRequests = 0;
        let widgetPreviewResponse = null;
        widgetWindow.fetch = async (...args) => {
            const requestUrl = new URL(String(args[0]), widgetWindow.location.href);
            const isWidgetEditorPost = String(args[1]?.method || '').toUpperCase() === 'POST'
                && requestUrl.pathname === '/index.php'
                && requestUrl.searchParams.get('page') === 'admin_theme'
                && requestUrl.searchParams.get('widgets') === '1';
            const response = await fetchWithDeadline(originalWidgetFetch, ...args);
            if (isWidgetEditorPost) {
                widgetPreviewRequests++;
                const payload = await response.clone().json().catch(() => null);
                widgetPreviewResponse = {
                    status: response.status,
                    noStore: (response.headers.get('Cache-Control') || '').toLowerCase().includes('no-store'),
                    ok: payload?.ok === true,
                    title: payload?.title,
                    html: typeof payload?.html === 'string' ? payload.html : '',
                };
            }
            return response;
        };
        stage = 'request real authenticated unsaved widget preview';
        widgetPreviewButton.click();
        await until(() => widgetPreviewResponse !== null && !widgetPreviewButton.disabled);
        expect(widgetPreviewRequests === 1 && widgetPreviewResponse.status === 200
            && widgetPreviewResponse.noStore && widgetPreviewResponse.ok
            && widgetPreviewResponse.title === widgetTitleMarker
            && widgetPreviewResponse.html.includes(widgetBoldMarker)
            && widgetPreviewResponse.html.includes('https://example.test/widget-' + workflowTokenForWidget));
        expect(widgetDeviceButtons.length === 3 && widgetPlacementModeInput && widgetFlowSlotInput.value === 'home_before_grid');
        const widgetPreviewViewportReady = (width, height) => {
            const publicDocument = widgetPublicFrame.contentDocument;
            const publicWindow = widgetPublicFrame.contentWindow;
            return Boolean(publicDocument?.documentElement && publicWindow
                && widgetPublicFrame.style.width === width + 'px'
                && widgetPublicFrame.style.height === height + 'px'
                && publicWindow.innerWidth === width
                && publicWindow.innerHeight === height);
        };
        const widgetPreviewRouteReady = (page, viewport) => {
            const publicDocument = widgetPublicFrame.contentDocument;
            const publicUrl = new URL(widgetPublicFrame.contentWindow.location.href);
            const routePage = publicUrl.searchParams.get('page');
            const correctRoute = page === 'home'
                ? routePage === 'home' || publicUrl.pathname === '/'
                : routePage === 'gallery' || publicUrl.pathname.startsWith('/gallery/');
            const correctPageContent = page === 'home'
                ? Boolean(publicDocument?.querySelector('[data-public-gallery-index-grid], .gallery-list-frame'))
                : Boolean(publicDocument?.querySelector('.hero[data-public-gallery-id]'));
            const sameOriginProtectedRoute = publicUrl.origin === widgetWindow.location.origin
                && publicUrl.searchParams.getAll('preview').length === 1
                && publicUrl.searchParams.get('preview') === 'visual'
                && publicUrl.searchParams.getAll('view_as').length === 1
                && publicUrl.searchParams.get('view_as') === 'anonymous';
            const safeFrame = widgetPublicFrame.getAttribute('sandbox') === 'allow-same-origin'
                && widgetPublicFrame.getAttribute('referrerpolicy') === 'same-origin';
            return Boolean(publicDocument?.body?.classList.contains('public-page')
                && publicDocument.querySelector('main.site-main') && publicDocument.querySelector('.site-header')
                && publicDocument.querySelector('.site-footer') && correctRoute && correctPageContent
                && sameOriginProtectedRoute && safeFrame
                && widgetPreviewViewportReady(viewport.width, viewport.height));
        };
        const widgetPreviewPageReady = (page, slot, viewport, floatingMode, floatingPositioned) => {
            const publicDocument = widgetPublicFrame.contentDocument;
            const draft = publicDocument?.querySelector('[data-widget-theme-draft="1"]');
            const draftZone = draft?.closest('[data-public-widget-zone]');
            return Boolean(widgetPreviewRouteReady(page, viewport) && draft
                && draft.querySelector('.public-content-widget-title')?.textContent === widgetTitleMarker
                && draft.querySelector('strong')?.textContent === widgetBoldMarker
                && draft.querySelector('a')?.href === 'https://example.test/widget-' + workflowTokenForWidget
                && draftZone?.dataset.publicWidgetZone === slot
                && draft.hasAttribute('data-public-widget-floating') === floatingMode
                && draft.classList.contains('is-public-widget-floating') === floatingPositioned);
        };
        const widgetPreviewReadinessDiagnostic = () => {
            try {
                const publicDocument = widgetPublicFrame.contentDocument;
                const publicWindow = widgetPublicFrame.contentWindow;
                const publicUrl = publicWindow?.location.href || '';
                const draft = publicDocument?.querySelector('[data-widget-theme-draft="1"]');
                const zone = draft?.closest('[data-public-widget-zone]')?.dataset.publicWidgetZone || 'none';
                const widgetShell = widgetThemeHost.querySelector('[data-widget-theme-frame-wrap]');
                const widgetTitle = draft?.querySelector('.public-content-widget-title')?.textContent;
                const widgetBold = draft?.querySelector('strong')?.textContent;
                const widgetLink = draft?.querySelector('a')?.href;
                return 'p' + (widgetPageSelect.value === 'gallery' ? 'g' : 'h')
                    + 'r' + safePublicRoute(publicUrl)
                    + 'd' + Number(Boolean(draft))
                    + 'z' + zone
                    + 't' + Number(widgetTitle === widgetTitleMarker)
                    + 'b' + Number(widgetBold === widgetBoldMarker)
                    + 'l' + Number(widgetLink === 'https://example.test/widget-' + workflowTokenForWidget)
                    + 'v' + (publicDocument?.documentElement.clientWidth || 0)
                    + '/' + (publicWindow?.innerWidth || 0)
                    + 's' + Number(widgetPublicFrame.getAttribute('sandbox') === 'allow-same-origin')
                    + 'w' + Number(Boolean(widgetShell?.hidden));
            } catch {
                return 'preview-document-unreadable';
            }
        };
        const widgetPreviewFailureStage = label =>
            (label + ' ' + widgetPreviewReadinessDiagnostic()).slice(0, 100);
        const verifyWidgetPersistenceUnchanged = async () => {
            const currentHash = await readWidgetStateHash();
            expect(/^[a-f0-9]{64}$/.test(currentHash) && currentHash === widgetStateBefore);
        };
        // Purpose: Keep the Admin preview's configured device viewports explicit in this live browser journey.
        // Type: Record<'desktop'|'tablet'|'mobile',{width:number,height:number}>.
        // Units: CSS pixels.
        // Scope: Authenticated widget-preview browser fixture.
        // Consumers: Protected iframe size and public floating/fallback assertions below.
        // Rationale: Lock the editor's supported viewport presets without claiming real-device fidelity.
        const WIDGET_PREVIEW_VIEWPORTS = Object.freeze({
            desktop: Object.freeze({width: 1280, height: 900}),
            tablet: Object.freeze({width: 768, height: 1024}),
            mobile: Object.freeze({width: 390, height: 740}),
        });
        const desktopViewport = WIDGET_PREVIEW_VIEWPORTS.desktop;
        stage = 'real protected Home iframe places the unsaved draft before the gallery grid';
        try {
            await until(() => widgetPreviewPageReady('home', 'home_before_grid', desktopViewport, false, false));
        } catch {
            stage = widgetPreviewFailureStage('Home placement');
            throw new Error('Protected Home draft did not reach its expected placement.');
        }
        expect(widgetPreviewPageReady('home', 'home_before_grid', desktopViewport, false, false));
        const homePreviewDocument = widgetPublicFrame.contentDocument;
        const homePreviewDraft = homePreviewDocument.querySelector('[data-widget-theme-draft="1"]');
        const homePreviewGrid = homePreviewDocument.querySelector('[data-public-gallery-index-grid]');
        const homePreviewRegion = homePreviewDraft.closest('[data-public-widget-zone]');
        expect(homePreviewGrid && homePreviewRegion
            && Boolean(homePreviewRegion.compareDocumentPosition(homePreviewGrid)
                & widgetPublicFrame.contentWindow.Node.DOCUMENT_POSITION_FOLLOWING));
        await verifyWidgetPersistenceUnchanged();
        stage = 'switch the live draft preview to Gallery content-bottom fallback';
        widgetPageSelect.value = 'gallery';
        widgetPageSelect.dispatchEvent(new widgetWindow.Event('change', {bubbles: true}));
        try {
            stage = 'load real protected Gallery page in the Admin preview';
            await until(() => widgetPreviewRouteReady('gallery', desktopViewport));
        } catch {
            stage = widgetPreviewFailureStage('Gallery route');
            throw new Error('Protected Gallery route did not pass its preview checks.');
        }
        try {
            stage = 'insert the draft into the Gallery content-bottom fallback';
            await until(() => widgetPreviewPageReady('gallery', 'content_bottom', desktopViewport, false, false));
        } catch {
            stage = widgetPreviewFailureStage('Gallery fallback');
            throw new Error('Protected Gallery draft did not reach its expected placement.');
        }
        expect(widgetPreviewPageReady('gallery', 'content_bottom', desktopViewport, false, false));
        const galleryPreviewDocument = widgetPublicFrame.contentDocument;
        const galleryPreviewDraft = galleryPreviewDocument.querySelector('[data-widget-theme-draft="1"]');
        const galleryPreviewRegion = galleryPreviewDraft.closest('[data-public-widget-zone]');
        const galleryPreviewFooter = galleryPreviewDocument.querySelector('.site-footer');
        expect(galleryPreviewDraft.closest('main.site-main') && galleryPreviewRegion && galleryPreviewFooter
            && Boolean(galleryPreviewRegion.compareDocumentPosition(galleryPreviewFooter)
                & widgetPublicFrame.contentWindow.Node.DOCUMENT_POSITION_FOLLOWING));
        await verifyWidgetPersistenceUnchanged();
        stage = 'request desktop floating preview and check rendered Gallery control clearance';
        widgetPlacementModeInput.value = 'floating';
        widgetPlacementModeInput.dispatchEvent(new widgetWindow.Event('change', {bubbles: true}));
        await until(() => widgetPreviewPageReady('gallery', 'floating', desktopViewport, true, true));
        expect(widgetPreviewPageReady('gallery', 'floating', desktopViewport, true, true));
        const widgetPreviewFloatingClearance = page => {
            const publicDocument = widgetPublicFrame.contentDocument;
            const publicWindow = widgetPublicFrame.contentWindow;
            const draft = publicDocument?.querySelector('[data-widget-theme-draft="1"]');
            const bounds = draft?.getBoundingClientRect();
            const viewportWidth = publicDocument?.documentElement.clientWidth || 0;
            const viewportHeight = publicWindow?.innerHeight || 0;
            const protectedControls = page === 'home'
                ? '.site-header, .public-home-actions, .back-to-top-button:not([hidden]), .picture-manager-toolbar, .nav'
                : '.site-header, .hero[data-public-gallery-id] .hero-actions, .back-to-top-button:not([hidden]), .picture-manager-toolbar, .nav';
            const clearOfControls = draft && Array.from(publicDocument.querySelectorAll(protectedControls)).every(control => {
                const rect = control.getBoundingClientRect();
                const style = publicWindow.getComputedStyle(control);
                if (style.display === 'none' || style.visibility === 'hidden' || rect.width <= 0 || rect.height <= 0) return true;
                return bounds.right <= rect.left || bounds.left >= rect.right
                    || bounds.bottom <= rect.top || bounds.top >= rect.bottom;
            });
            const close = draft?.querySelector('.public-widget-dismiss');
            const closeBounds = close?.getBoundingClientRect();
            return Boolean(draft?.classList.contains('is-public-widget-floating')
                && bounds && bounds.width > 0 && bounds.height > 0
                && bounds.left >= 15 && bounds.top >= 15
                && bounds.right <= viewportWidth - 15 && bounds.bottom <= viewportHeight - 15
                && clearOfControls && closeBounds && closeBounds.width > 0 && closeBounds.height > 0
                && closeBounds.left >= bounds.left && closeBounds.right <= bounds.right
                && closeBounds.top >= bounds.top && closeBounds.bottom <= bounds.bottom);
        };
        expect(widgetPreviewFloatingClearance('gallery'));
        await verifyWidgetPersistenceUnchanged();
        stage = 'switch desktop floating draft to Home and clear its rendered controls';
        widgetPageSelect.value = 'home';
        widgetPageSelect.dispatchEvent(new widgetWindow.Event('change', {bubbles: true}));
        await until(() => widgetPreviewPageReady('home', 'floating', desktopViewport, true, true));
        expect(widgetPreviewPageReady('home', 'floating', desktopViewport, true, true)
            && widgetPreviewFloatingClearance('home'));
        await verifyWidgetPersistenceUnchanged();
        stage = 'return desktop floating draft to Gallery before tablet fallback';
        widgetPageSelect.value = 'gallery';
        widgetPageSelect.dispatchEvent(new widgetWindow.Event('change', {bubbles: true}));
        await until(() => widgetPreviewPageReady('gallery', 'floating', desktopViewport, true, true));
        expect(widgetPreviewPageReady('gallery', 'floating', desktopViewport, true, true)
            && widgetPreviewFloatingClearance('gallery'));
        await verifyWidgetPersistenceUnchanged();
        stage = 'tablet preview uses the configured viewport and in-flow floating fallback';
        widgetDeviceButtons[1].click();
        const tabletViewport = WIDGET_PREVIEW_VIEWPORTS.tablet;
        await until(() => widgetPreviewPageReady('gallery', 'floating', tabletViewport, true, false));
        const tabletDraft = widgetPublicFrame.contentDocument.querySelector('[data-widget-theme-draft="1"]');
        expect(widgetPreviewPageReady('gallery', 'floating', tabletViewport, true, false)
            && widgetPublicFrame.contentWindow.getComputedStyle(tabletDraft).position !== 'fixed'
            && tabletDraft.style.left === '' && tabletDraft.style.top === '' && tabletDraft.style.width === '');
        await verifyWidgetPersistenceUnchanged();
        stage = 'mobile preview uses the configured viewport and in-flow floating fallback';
        widgetDeviceButtons[2].click();
        const mobileViewport = WIDGET_PREVIEW_VIEWPORTS.mobile;
        await until(() => widgetPreviewPageReady('gallery', 'floating', mobileViewport, true, false));
        const mobileDraft = widgetPublicFrame.contentDocument.querySelector('[data-widget-theme-draft="1"]');
        expect(widgetPreviewPageReady('gallery', 'floating', mobileViewport, true, false)
            && widgetPublicFrame.contentWindow.getComputedStyle(mobileDraft).position !== 'fixed'
            && mobileDraft.style.left === '' && mobileDraft.style.top === '' && mobileDraft.style.width === '');
        await verifyWidgetPersistenceUnchanged();
        widgetWindow.fetch = originalWidgetFetch;
        stage = 'leave widget editor and reload the ordinary public Home';
        frame.src = '/index.php?page=home';
        const ordinaryHomeDocument = await until(() => {
            const currentUrl = new URL(frame.contentWindow.location.href);
            const publicDocument = frame.contentDocument;
            return currentUrl.origin === win.location.origin && currentUrl.searchParams.get('page') === 'home'
                && publicDocument?.readyState === 'complete'
                && publicDocument.body?.classList.contains('public-page') ? publicDocument : null;
        });
        expect(!ordinaryHomeDocument.querySelector('[data-widget-theme-draft]')
            && !ordinaryHomeDocument.body.textContent.includes(widgetTitleMarker)
            && !ordinaryHomeDocument.body.textContent.includes(workflowTokenForWidget));
        frame.contentWindow.location.reload();
        const reloadedOrdinaryHome = await until(() => {
            const publicDocument = frame.contentDocument;
            const currentUrl = new URL(frame.contentWindow.location.href);
            return publicDocument !== ordinaryHomeDocument && publicDocument?.readyState === 'complete'
                && currentUrl.searchParams.get('page') === 'home'
                && publicDocument.body?.classList.contains('public-page') ? publicDocument : null;
        });
        expect(!reloadedOrdinaryHome.querySelector('[data-widget-theme-draft]')
            && !reloadedOrdinaryHome.body.textContent.includes(widgetTitleMarker)
            && !reloadedOrdinaryHome.body.textContent.includes(workflowTokenForWidget));
        stage = 'reload the authenticated widget editor without retaining the draft';
        frame.src = widgetEditorRoute;
        const reloadedWidgetDocument = await until(() => {
            const currentUrl = new URL(frame.contentWindow.location.href);
            if (currentUrl.origin !== win.location.origin || currentUrl.pathname !== '/index.php'
                || currentUrl.searchParams.get('page') !== 'admin_theme'
                || currentUrl.searchParams.get('widgets') !== '1') return null;
            const editor = frame.contentDocument?.querySelector('[data-public-widget-editor]');
            return editor?.dataset.widgetEnhanced === '1' ? frame.contentDocument : null;
        });
        const reloadedWidgetForm = reloadedWidgetDocument.querySelector('[data-public-widget-editor]');
        const reloadedWidgetList = Array.from(reloadedWidgetDocument.querySelectorAll('.public-widgets-items strong'));
        expect(reloadedWidgetForm.querySelector('[name="title"]')?.value === ''
            && reloadedWidgetForm.querySelector('[name="content_md"]')?.value === ''
            && !reloadedWidgetList.some(item => item.textContent === widgetTitleMarker)
            && !reloadedWidgetDocument.body.textContent.includes(widgetTitleMarker));
        stage = 'authenticated iframe draft preview leaves disposable widget state unchanged';
        await verifyWidgetPersistenceUnchanged();
        const requestedEditorUrl = new URL(visualEditorUrl, win.location.href);
        stage = 'navigate authenticated application iframe to Custom CSS editor';
        frame.src = visualEditorUrl;
        stage = 'wait for committed admin_theme document and CSS editor root';
        const cssEditor = await until(() => {
            const currentUrl = new URL(frame.contentWindow.location.href);
            if (currentUrl.origin !== requestedEditorUrl.origin || currentUrl.pathname !== requestedEditorUrl.pathname
                || currentUrl.search !== requestedEditorUrl.search) return null;
            return frame.contentDocument?.querySelector('[data-css-override-editor]') || null;
        });
        const editorDocument = frame.contentDocument;
        const editorWindow = frame.contentWindow;
        const originalEditorWindowFetch = editorWindow.fetch.bind(editorWindow);
        editorWindow.fetch = (...args) => fetchWithDeadline(originalEditorWindowFetch, ...args);
        stage = 'wait for production visual-editor module to bind and reveal its launch button';
        let visualLaunch;
        try {
            visualLaunch = await until(() => {
                const button = editorDocument.querySelector('[data-visual-editor-launch]');
                return button && !button.hidden && button.dataset.visualEditorReady === '1' ? button : null;
            });
        } catch {
            const launch = editorDocument.querySelector('[data-visual-editor-launch]');
            stage = 'visual-editor launch readiness timeout route ' + safeRoute(editorWindow.location.href)
                + ' editor-root ' + Boolean(editorDocument.querySelector('[data-css-override-editor]'))
                + ' theme-form ' + Boolean(editorDocument.querySelector('[data-theme-form]'))
                + ' css-form-ready ' + (editorDocument.querySelector('[data-css-override-form]')?.dataset.cssOverrideReady === '1')
                + ' preview-url ' + Boolean(cssEditor.dataset.visualEditorPreviewUrl)
                + ' launch-present ' + Boolean(launch) + ' launch-ready ' + (launch?.dataset.visualEditorReady === '1')
                + ' launch-visible ' + Boolean(launch && !launch.hidden);
            throw new Error('Visual editor launch control did not become ready.');
        }
        stage = 'verify editor textarea belongs to the authenticated editor document';
        const editorRoot = cssEditor;
        const draftText = editorRoot.querySelector('[data-css-override-text]');
        expect(draftText instanceof editorWindow.HTMLTextAreaElement);
        draftText.value = 'body { outline: 7px solid rgb(12, 34, 56); }';
        draftText.dispatchEvent(new editorWindow.Event('input', {bubbles: true}));
        const entryCssBytes = draftText.value;
        /** Bound each visual-preview iframe navigation wait to 20 seconds.
         * Type: number.
         * Units: milliseconds.
         * Scope: one pending iframe transition in the disposable browser fixture.
         * Consumers: waitForVisualFrameLoad() timeout callback.
         * Rationale: reject stalled transitions before the outer workflow deadline and release listeners.
         */
        const VISUAL_PREVIEW_FRAME_LOAD_TIMEOUT_MS = 20000;
        const waitForVisualFrameLoad = (targetFrame, transition) => new Promise((resolve, reject) => {
            let timer = 0;
            const cleanup = () => {
                clearTimeout(timer);
                targetFrame.removeEventListener('load', loaded);
                targetFrame.removeEventListener('error', failed);
            };
            const loaded = () => { cleanup(); resolve(); };
            const failed = () => { cleanup(); reject(new Error('Preview frame load failed after ' + transition + '.')); };
            timer = setTimeout(() => { cleanup(); reject(new Error('Preview frame load timed out after ' + transition + '.')); }, VISUAL_PREVIEW_FRAME_LOAD_TIMEOUT_MS);
            targetFrame.addEventListener('load', loaded, {once: true});
            targetFrame.addEventListener('error', failed, {once: true});
        });
        const visualFrameDocumentCommitted = (targetFrame, expectedPreviewCss) => {
            const previewDocument = targetFrame.contentDocument;
            const pageTitle = previewDocument?.title || previewDocument?.querySelector('h1')?.textContent?.trim() || '';
            const hudTitle = editorDocument.querySelector('.theme-visual-editor-page-title')?.textContent || '';
            const draftStyle = previewDocument?.querySelector('style[data-theme-visual-editor-draft]');
            const draftMatches = expectedPreviewCss === '' ? !draftStyle : draftStyle?.textContent === expectedPreviewCss;
            return previewDocument?.readyState === 'complete'
                && previewDocument.body?.classList.contains('public-page')
                && pageTitle !== '' && hudTitle === pageTitle && draftMatches;
        };
        const previewStateUrl = location.pathname + '/preview-state';
        stage = 'read disposable public-preview state baseline';
        const previewStateBefore = await (await fetch(previewStateUrl, {cache: 'no-store'})).json();
        const preparedPreview = new URL(editorRoot.dataset.visualEditorPreviewUrl, editorWindow.location.href);
        stage = 'verify same-origin canonical homepage URL with one visual-preview marker';
        const editorPageUrl = new URL(editorWindow.location.href);
        const appMountPath = editorPageUrl.pathname.endsWith('/index.php')
            ? editorPageUrl.pathname.slice(0, -'index.php'.length)
            : '';
        const pageValues = preparedPreview.searchParams.getAll('page');
        const previewValues = preparedPreview.searchParams.getAll('preview');
        const queryNames = Array.from(preparedPreview.searchParams.keys());
        const frontControllerHome = preparedPreview.pathname === appMountPath + 'index.php'
            && pageValues.length === 1 && pageValues[0] === 'home'
            && queryNames.length === 2 && queryNames.includes('page') && queryNames.includes('preview');
        const cleanMountHome = appMountPath !== '' && preparedPreview.pathname === appMountPath
            && pageValues.length === 0 && queryNames.length === 1 && queryNames[0] === 'preview';
        expect(['http:', 'https:'].includes(preparedPreview.protocol)
            && preparedPreview.origin === editorPageUrl.origin && preparedPreview.username === ''
            && preparedPreview.password === '' && preparedPreview.hash === ''
            && (frontControllerHome || cleanMountHome)
            && previewValues.length === 1 && previewValues[0] === 'visual');
        stage = 'open genuine PHP homepage with isolated unsaved CSS';
        visualLaunch.click();
        stage = 'wait for production workspace dialog and preview iframe';
        let visualFrame;
        try {
            visualFrame = await until(() => editorDocument.querySelector('.theme-visual-editor-frame'));
        } catch {
            const workspace = editorDocument.querySelector('.theme-visual-editor-workspace');
            stage = 'workspace readiness timeout editor-root ' + Boolean(editorDocument.querySelector('[data-css-override-editor]'))
                + ' launch-ready ' + (visualLaunch.dataset.visualEditorReady === '1')
                + ' dialog-present ' + Boolean(workspace) + ' dialog-open ' + Boolean(workspace?.open)
                + ' preview-frame ' + Boolean(workspace?.querySelector('.theme-visual-editor-frame'));
            throw new Error('Visual editor workspace did not publish its preview frame.');
        }
        stage = 'wait for public homepage load handler to apply isolated CSS draft';
        try {
            await until(() => {
                const previewDocument = visualFrame.contentDocument;
                const pageTitle = previewDocument?.title || previewDocument?.querySelector('h1')?.textContent?.trim() || '';
                const hudTitle = editorDocument.querySelector('.theme-visual-editor-page-title')?.textContent || '';
                return previewDocument?.readyState === 'complete'
                    && previewDocument.body?.classList.contains('public-page')
                    && pageTitle !== '' && hudTitle === pageTitle
                    && previewDocument.querySelector('style[data-theme-visual-editor-draft]')?.textContent === draftText.value;
            });
        } catch {
            stage = 'public preview readiness timeout route ' + safeRoute(visualFrame.contentWindow.location.href)
                + ' public-page ' + Boolean(visualFrame.contentDocument?.body?.classList.contains('public-page'))
                + ' draft-style ' + Boolean(visualFrame.contentDocument?.querySelector('style[data-theme-visual-editor-draft]'))
                + ' sandbox ' + (visualFrame.getAttribute('sandbox') || 'missing');
            throw new Error('Public preview did not finish applying the isolated CSS draft.');
        }
        stage = 'verify preview sandbox and real public content';
        expect(visualFrame.getAttribute('sandbox') === 'allow-same-origin');
        const previewDocument = visualFrame.contentDocument;
        const draftStyle = previewDocument.querySelector('style[data-theme-visual-editor-draft]');
        expect(draftStyle?.textContent === draftText.value);
        expect(previewDocument.querySelector('main, article, .gallery-card, [data-gallery-id]'));
        const anonymousSeedCard = Array.from(previewDocument.querySelectorAll('.gallery-card-title-link[href]'))
            .find(link => link.textContent.trim() === 'Workflow seed');
        stage = 'real Home exposes the accessible seed gallery ' + Boolean(anonymousSeedCard);
        expect(anonymousSeedCard);
        const anonymousSeedRouteUrl = new URL(anonymousSeedCard.href);
        const signedInUrl = new URL(visualFrame.contentWindow.location.href);
        expect(signedInUrl.searchParams.get('preview') === 'visual' && signedInUrl.searchParams.get('view_as') !== 'anonymous');
        const signedInResponse = await editorWindow.fetch(signedInUrl.href, {cache: 'no-store'});
        expect(signedInResponse.status === 200 && /private/i.test(signedInResponse.headers.get('Cache-Control') || '')
            && /no-store/i.test(signedInResponse.headers.get('Cache-Control') || ''));
        const anonymousButton = Array.from(editorDocument.querySelectorAll('.theme-visual-editor-hud button'))
            .find(button => button.textContent.trim() === 'Anonymous view');
        expect(anonymousButton);
        const anonymousPreviewLoad = waitForVisualFrameLoad(visualFrame, 'anonymous audience transition');
        anonymousButton.click();
        stage = 'wait for anonymous audience load and editor commit';
        await anonymousPreviewLoad;
        await until(() => new URL(visualFrame.contentWindow.location.href).searchParams.get('view_as') === 'anonymous'
            && new URL(visualFrame.contentDocument?.URL || 'about:blank').searchParams.get('view_as') === 'anonymous'
            && visualFrameDocumentCommitted(visualFrame, entryCssBytes));
        stage = 'anonymous real gallery and media preserve public visitor policy';
        const publicGalleryUrl = new URL(preparedPreview.href);
        publicGalleryUrl.searchParams.set('page', 'gallery');
        publicGalleryUrl.searchParams.delete('slug');
        publicGalleryUrl.searchParams.set('public_path', 'seed');
        publicGalleryUrl.searchParams.set('view_as', 'anonymous');
        stage = 'request anonymous gallery through its canonical public path';
        const publicGalleryResponse = await editorWindow.fetch(publicGalleryUrl.href, {credentials: 'same-origin', cache: 'no-store'});
        const publicGalleryHtml = await publicGalleryResponse.text();
        const publicGalleryHasSeed = /Workflow seed/.test(publicGalleryHtml);
        const publicGalleryCacheControl = publicGalleryResponse.headers.get('Cache-Control') || '';
        const publicGalleryPrivateNoStore = /private/i.test(publicGalleryCacheControl) && /no-store/i.test(publicGalleryCacheControl);
        stage = 'anonymous gallery response status ' + publicGalleryResponse.status + ' seed-content ' + publicGalleryHasSeed
            + ' private-no-store ' + publicGalleryPrivateNoStore;
        expect(publicGalleryResponse.status === 200 && publicGalleryHasSeed && publicGalleryPrivateNoStore);
        const publicGalleryDocument = new DOMParser().parseFromString(publicGalleryHtml, 'text/html');
        const publicImage = publicGalleryDocument.querySelector('article[data-public-photo-order-item] a.image-preview-link picture img[src]');
        const publicImageUrl = publicImage?.getAttribute('src') || '';
        const publicImageIsCardImage = Boolean(publicImage?.closest('article[data-public-photo-order-item]'));
        stage = 'anonymous gallery contains a semantic photo-card image URL ' + Boolean(publicImageUrl && publicImageIsCardImage);
        expect(publicImageUrl && publicImageIsCardImage);
        const markedImageUrl = new URL(publicImageUrl, publicGalleryUrl.href);
        const publicImageSameOrigin = markedImageUrl.origin === publicGalleryUrl.origin;
        const publicImagePreviewMarked = markedImageUrl.searchParams.get('preview') === 'visual'
            && markedImageUrl.searchParams.get('view_as') === 'anonymous';
        stage = 'anonymous gallery image keeps same-origin preview markers ' + (publicImageSameOrigin && publicImagePreviewMarked);
        expect(publicImageSameOrigin && publicImagePreviewMarked);
        const thumbnailRoutePage = ['thumb', 'public_thumb'].includes(markedImageUrl.searchParams.get('page') || '')
            ? markedImageUrl.searchParams.get('page') : 'other';
        const thumbnailCleanSuffix = /\/thumb-[1-9][0-9]*\.(?:jpg|webp)$/i.test(markedImageUrl.pathname);
        const thumbnailFrontControllerPath = /(?:^|\/)index\.php$/i.test(markedImageUrl.pathname);
        const thumbnailRouteQueryValid = thumbnailRoutePage === 'thumb'
            ? markedImageUrl.searchParams.has('id') && markedImageUrl.searchParams.has('size') && markedImageUrl.searchParams.has('format')
            : (thumbnailRoutePage === 'public_thumb'
                ? markedImageUrl.searchParams.has('public_path') && markedImageUrl.searchParams.has('size') && markedImageUrl.searchParams.has('format')
                : false);
        const publicImageResponse = await editorWindow.fetch(markedImageUrl.href, {credentials: 'same-origin', cache: 'no-store'});
        const publicImageBytes = (await publicImageResponse.arrayBuffer()).byteLength;
        const publicImageContentType = (publicImageResponse.headers.get('Content-Type') || '').split(';', 1)[0].trim().toLowerCase();
        const publicImageIsImage = /^image\/[a-z0-9.+-]+$/.test(publicImageContentType);
        const publicImageHasBytes = publicImageBytes > 0;
        const publicImageCacheControl = publicImageResponse.headers.get('Cache-Control') || '';
        const publicImagePrivateNoStore = /private/i.test(publicImageCacheControl) && /no-store/i.test(publicImageCacheControl);
        const returnedThumbnailUrl = new URL(publicImageResponse.url);
        const returnedThumbnailPage = ['thumb', 'public_thumb', 'home', 'gallery'].includes(returnedThumbnailUrl.searchParams.get('page') || '')
            ? (returnedThumbnailUrl.searchParams.get('page') || 'none') : 'other';
        const returnedThumbnailPath = returnedThumbnailUrl.pathname === '/' ? 'root'
            : (/(?:^|\/)index\.php$/i.test(returnedThumbnailUrl.pathname) ? 'front_controller'
                : (/\/gallery\//i.test(returnedThumbnailUrl.pathname) ? 'gallery' : 'other'));
        const returnedThumbnailSameOrigin = returnedThumbnailUrl.origin === markedImageUrl.origin;
        const returnedThumbnailCleanSuffix = /\/thumb-[1-9][0-9]*\.(?:jpg|webp)$/i.test(returnedThumbnailUrl.pathname);
        const returnedThumbnailFrontControllerPath = /(?:^|\/)index\.php$/i.test(returnedThumbnailUrl.pathname);
        const thumbnailQueryPreserved = Array.from(markedImageUrl.searchParams.entries())
            .every(([name, value]) => returnedThumbnailUrl.searchParams.getAll(name).includes(value));
        stage = 'thumb s' + publicImageResponse.status + ' i' + Number(publicImageIsImage) + ' b' + Number(publicImageHasBytes)
            + ' p' + Number(publicImagePrivateNoStore) + ' rq' + thumbnailRoutePage + ' q' + Number(thumbnailRouteQueryValid)
            + ' f' + Number(thumbnailFrontControllerPath) + ' c' + Number(thumbnailCleanSuffix)
            + ' pv' + Number(markedImageUrl.searchParams.get('preview') === 'visual')
            + ' av' + Number(markedImageUrl.searchParams.get('view_as') === 'anonymous')
            + ' qp' + Number(thumbnailQueryPreserved) + ' rs' + returnedThumbnailPage + ' rf' + Number(returnedThumbnailFrontControllerPath)
            + ' rc' + Number(returnedThumbnailCleanSuffix) + ' ro' + returnedThumbnailPath
            + ' so' + Number(returnedThumbnailSameOrigin);
        expect(publicImageResponse.status === 200 && publicImageIsImage && publicImageHasBytes && publicImagePrivateNoStore);
        const publicOriginalSource = publicImage?.closest('article[data-public-photo-order-item]')?.getAttribute('data-full-src') || '';
        let publicOriginalUrl = null;
        try {
            if (publicOriginalSource !== '') publicOriginalUrl = new URL(publicOriginalSource, publicGalleryUrl.href);
        } catch {
            publicOriginalUrl = null;
        }
        const publicOriginalSameOrigin = publicOriginalUrl?.origin === publicGalleryUrl.origin;
        const publicOriginalPreviewMarked = publicOriginalUrl?.searchParams.get('preview') === 'visual'
            && publicOriginalUrl?.searchParams.get('view_as') === 'anonymous';
        stage = 'anonymous original URL uses the card source and preview markers '
            + Boolean(publicOriginalSameOrigin && publicOriginalPreviewMarked);
        expect(publicOriginalSameOrigin && publicOriginalPreviewMarked);
        const publicOriginalResponse = await editorWindow.fetch(publicOriginalUrl.href, {credentials: 'same-origin', cache: 'no-store'});
        let publicOriginalErrorDetail = '';
        let publicOriginalReferenceFound = false;
        let publicOriginalSummaryAvailable = false;
        let publicOriginalErrorLookupStatus = 0;
        if (publicOriginalResponse.status === 500) {
            try {
                const errorBody = await publicOriginalResponse.clone().text();
                const referenceMatch = errorBody.match(/\bReference:\s*([A-F0-9]{16})\b/);
                publicOriginalReferenceFound = Boolean(referenceMatch);
                if (referenceMatch) {
                    const previewErrorUrl = new URL(previewStateUrl, editorWindow.location.href);
                    previewErrorUrl.pathname = previewErrorUrl.pathname.replace(/\/preview-state$/, '/preview-error');
                    previewErrorUrl.searchParams.set('reference', referenceMatch[1]);
                    const errorResponse = await editorWindow.fetch(previewErrorUrl.href, {credentials: 'same-origin', cache: 'no-store'});
                    publicOriginalErrorLookupStatus = Number.isInteger(errorResponse.status) && errorResponse.status >= 100 && errorResponse.status <= 599
                        ? errorResponse.status
                        : 0;
                    if (errorResponse.status === 200) {
                        const errorSummary = await errorResponse.json();
                        const safeType = typeof errorSummary?.type === 'string'
                            && errorSummary.type.length <= 128
                            && /^(?:php-error-[0-9]+|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)$/.test(errorSummary.type);
                        const safeSource = typeof errorSummary?.source === 'string'
                            && /^[A-Za-z0-9_.-]{1,100}$/.test(errorSummary.source);
                        const safeLine = Number.isSafeInteger(errorSummary?.line) && errorSummary.line > 0;
                        if (errorSummary?.available === true && safeType && safeSource && safeLine) {
                            publicOriginalSummaryAvailable = true;
                            publicOriginalErrorDetail = `${errorSummary.type}:${errorSummary.source}:${errorSummary.line}`;
                        }
                    }
                }
            } catch {
                publicOriginalErrorDetail = '';
            }
        }
        const publicOriginalBytes = (await publicOriginalResponse.arrayBuffer()).byteLength;
        const publicOriginalContentType = (publicOriginalResponse.headers.get('Content-Type') || '').split(';', 1)[0].trim().toLowerCase();
        const publicOriginalIsJpeg = publicOriginalContentType === 'image/jpeg';
        const publicOriginalCacheControl = publicOriginalResponse.headers.get('Cache-Control') || '';
        const publicOriginalPrivateNoStore = /private/i.test(publicOriginalCacheControl) && /no-store/i.test(publicOriginalCacheControl);
        const originalMediaStage = 'original ' + publicOriginalResponse.status + ' jpeg ' + publicOriginalIsJpeg
            + ' bytes ' + (publicOriginalBytes > 0) + ' private ' + publicOriginalPrivateNoStore;
        const originalMediaDiagnostic = publicOriginalResponse.status === 500
            ? ` ref-${Number(publicOriginalReferenceFound)} summary-${Number(publicOriginalSummaryAvailable)} lookup-${publicOriginalErrorLookupStatus}`
            : '';
        const originalMediaStageWithDiagnostic = originalMediaStage + originalMediaDiagnostic;
        const originalMediaErrorSuffix = publicOriginalErrorDetail === '' ? '' : ' error ' + publicOriginalErrorDetail.replaceAll(':', '.');
        stage = originalMediaStageWithDiagnostic.length + originalMediaErrorSuffix.length <= 100
            ? originalMediaStageWithDiagnostic + originalMediaErrorSuffix
            : originalMediaStageWithDiagnostic;
        expect(publicOriginalResponse.status === 200 && publicOriginalIsJpeg && publicOriginalBytes > 0
            && publicOriginalPrivateNoStore);
        stage = 'signed-in preview loads the actual protected fixture gallery';
        const protectedGalleryUrl = new URL(signedInUrl.href);
        protectedGalleryUrl.searchParams.set('page', 'gallery');
        protectedGalleryUrl.searchParams.delete('slug');
        protectedGalleryUrl.searchParams.set('public_path', 'seed/protected');
        protectedGalleryUrl.searchParams.delete('view_as');
        const protectedGalleryResponse = await editorWindow.fetch(protectedGalleryUrl.href, {credentials: 'same-origin', cache: 'no-store'});
        const protectedGalleryHtml = await protectedGalleryResponse.text();
        const protectedGalleryDocument = new DOMParser().parseFromString(protectedGalleryHtml, 'text/html');
        const protectedCard = protectedGalleryDocument.querySelector('article[data-public-photo-order-item][data-full-src]');
        const protectedOriginalSource = protectedCard?.getAttribute('data-full-src') || '';
        const protectedGalleryCacheControl = protectedGalleryResponse.headers.get('Cache-Control') || '';
        const protectedGalleryPrivateNoStore = /private/i.test(protectedGalleryCacheControl) && /no-store/i.test(protectedGalleryCacheControl);
        stage = 'signed-in protected gallery status ' + protectedGalleryResponse.status + ' photo-card ' + Boolean(protectedOriginalSource)
            + ' private-no-store ' + protectedGalleryPrivateNoStore;
        expect(protectedGalleryResponse.status === 200 && protectedOriginalSource !== '' && protectedGalleryPrivateNoStore);
        let protectedMediaUrl = null;
        try {
            protectedMediaUrl = new URL(protectedOriginalSource, protectedGalleryUrl.href);
        } catch {
            protectedMediaUrl = null;
        }
        const protectedMediaSameOrigin = protectedMediaUrl?.origin === protectedGalleryUrl.origin;
        const protectedMediaPreviewMarked = protectedMediaUrl?.searchParams.get('preview') === 'visual';
        stage = 'protected card original URL keeps same-origin preview marker ' + Boolean(protectedMediaSameOrigin && protectedMediaPreviewMarked);
        expect(protectedMediaSameOrigin && protectedMediaPreviewMarked);
        protectedMediaUrl.searchParams.set('view_as', 'anonymous');
        const protectedMediaResponse = await editorWindow.fetch(protectedMediaUrl.href, {credentials: 'same-origin', cache: 'no-store'});
        const protectedMediaCacheControl = protectedMediaResponse.headers.get('Cache-Control') || '';
        const protectedMediaPrivateNoStore = /private/i.test(protectedMediaCacheControl) && /no-store/i.test(protectedMediaCacheControl);
        const protectedMediaBody = await protectedMediaResponse.clone().text();
        const protectedMediaIncludesEditorContext = /data-theme-background-visual-editor-target/.test(protectedMediaBody);
        let protectedMediaReferenceFound = false;
        let protectedMediaSummaryAvailable = false;
        let protectedMediaErrorLookupStatus = 0;
        let protectedMediaErrorDetail = '';
        if (protectedMediaResponse.status === 500) {
            try {
                const referenceMatch = protectedMediaBody.match(/\bReference:\s*([A-F0-9]{16})\b/);
                protectedMediaReferenceFound = Boolean(referenceMatch);
                if (referenceMatch) {
                    const previewErrorUrl = new URL(previewStateUrl, editorWindow.location.href);
                    previewErrorUrl.pathname = previewErrorUrl.pathname.replace(/\/preview-state$/, '/preview-error');
                    previewErrorUrl.searchParams.set('reference', referenceMatch[1]);
                    const errorResponse = await editorWindow.fetch(previewErrorUrl.href, {credentials: 'same-origin', cache: 'no-store'});
                    protectedMediaErrorLookupStatus = Number.isInteger(errorResponse.status)
                        && errorResponse.status >= 100 && errorResponse.status <= 599 ? errorResponse.status : 0;
                    if (errorResponse.status === 200) {
                        const errorSummary = await errorResponse.json();
                        const safeType = typeof errorSummary?.type === 'string'
                            && errorSummary.type.length <= 128
                            && /^(?:php-error-[0-9]+|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)$/.test(errorSummary.type);
                        const safeSource = typeof errorSummary?.source === 'string'
                            && /^[A-Za-z0-9_.-]{1,100}$/.test(errorSummary.source);
                        const safeLine = Number.isSafeInteger(errorSummary?.line) && errorSummary.line > 0;
                        if (errorSummary?.available === true && safeType && safeSource && safeLine) {
                            protectedMediaSummaryAvailable = true;
                            protectedMediaErrorDetail = `${errorSummary.type}:${errorSummary.source}:${errorSummary.line}`;
                        }
                    }
                }
            } catch {
                protectedMediaErrorDetail = '';
            }
        }
        const protectedMediaStage = 'protected media ' + protectedMediaResponse.status
            + ' private ' + Number(protectedMediaPrivateNoStore)
            + ' editor-context ' + Number(protectedMediaIncludesEditorContext);
        const protectedMediaDiagnostic = protectedMediaResponse.status === 500
            ? ` ref-${Number(protectedMediaReferenceFound)} summary-${Number(protectedMediaSummaryAvailable)} lookup-${protectedMediaErrorLookupStatus}`
            : '';
        const protectedMediaErrorSuffix = protectedMediaErrorDetail === '' ? '' : ' error ' + protectedMediaErrorDetail.replaceAll(':', '.');
        stage = protectedMediaStage.length + protectedMediaDiagnostic.length + protectedMediaErrorSuffix.length <= 100
            ? protectedMediaStage + protectedMediaDiagnostic + protectedMediaErrorSuffix
            : protectedMediaStage + protectedMediaDiagnostic;
        expect(protectedMediaResponse.status === 404 && protectedMediaPrivateNoStore && !protectedMediaIncludesEditorContext);
        stage = 'anonymous marked protected-gallery request follows safe ancestor fallback';
        const protectedGalleryPublicUrl = new URL(publicGalleryUrl.href);
        protectedGalleryPublicUrl.searchParams.set('public_path', 'seed/protected');
        const protectedGalleryPublicResponse = await editorWindow.fetch(protectedGalleryPublicUrl.href, {credentials: 'same-origin', cache: 'no-store'});
        const protectedGalleryPublicHtml = await protectedGalleryPublicResponse.text();
        const protectedGalleryPublicDocument = new DOMParser().parseFromString(protectedGalleryPublicHtml, 'text/html');
        const protectedGalleryPasswordGate = Boolean(protectedGalleryPublicDocument.querySelector('input[name="gallery_password"]'));
        const protectedFallbackResponseUrl = new URL(protectedGalleryPublicResponse.url);
        const protectedFallbackHasSeed = protectedGalleryPublicDocument.querySelector('h1')?.textContent.trim() === 'Workflow seed';
        const anonymousSeedRouteParameters = [...anonymousSeedRouteUrl.searchParams]
            .filter(([name]) => !['preview', 'view_as'].includes(name));
        const protectedFallbackIsSeedRoute = protectedFallbackResponseUrl.origin === anonymousSeedRouteUrl.origin
            && protectedFallbackResponseUrl.pathname === anonymousSeedRouteUrl.pathname
            && anonymousSeedRouteParameters.every(([name, value]) => protectedFallbackResponseUrl.searchParams.getAll(name).includes(value));
        const protectedFallbackMarkers = protectedFallbackResponseUrl.origin === publicGalleryUrl.origin
            && protectedFallbackResponseUrl.pathname.startsWith(appMountPath)
            && protectedFallbackResponseUrl.searchParams.get('preview') === 'visual'
            && protectedFallbackResponseUrl.searchParams.get('view_as') === 'anonymous'
            && protectedFallbackResponseUrl.searchParams.get('visual_notice') === 'anonymous_fallback';
        const protectedFallbackCacheControl = protectedGalleryPublicResponse.headers.get('Cache-Control') || '';
        const protectedFallbackPrivateNoStore = /private/i.test(protectedFallbackCacheControl)
            && /no-store/i.test(protectedFallbackCacheControl);
        stage = 'protected fallback status ' + protectedGalleryPublicResponse.status + ' flags '
            + [protectedGalleryPublicResponse.redirected, protectedFallbackHasSeed, protectedFallbackIsSeedRoute, protectedFallbackMarkers,
                !protectedGalleryPasswordGate, protectedFallbackPrivateNoStore].map(Number).join('');
        expect(protectedGalleryPublicResponse.status === 200 && protectedGalleryPublicResponse.redirected
            && protectedFallbackHasSeed && protectedFallbackIsSeedRoute && protectedFallbackMarkers
            && !protectedGalleryPasswordGate && protectedFallbackPrivateNoStore);
        const deniedAdminUrl = new URL(preparedPreview.href);
        deniedAdminUrl.searchParams.set('page', 'admin_dashboard');
        const deniedAdminResponse = await editorWindow.fetch(deniedAdminUrl.href, {credentials: 'same-origin', cache: 'no-store'});
        stage = 'anonymous preview denied Admin route status ' + deniedAdminResponse.status;
        expect(deniedAdminResponse.status === 404);
        const deniedPost = await editorWindow.fetch(preparedPreview.href, {method: 'POST', body: new URLSearchParams({action: 'noop'})});
        const deniedPostAllow = deniedPost.headers.get('Allow') || '';
        stage = 'anonymous preview denied POST status ' + deniedPost.status + ' allow-get-head '
            + (deniedPostAllow === 'GET, HEAD');
        expect(deniedPost.status === 405 && deniedPostAllow === 'GET, HEAD');
        const headResponse = await editorWindow.fetch(signedInUrl.href, {method: 'HEAD', cache: 'no-store'});
        const headBody = await headResponse.text();
        stage = 'signed-in visual preview HEAD status ' + headResponse.status + ' empty-body ' + (headBody === '');
        expect(headResponse.status === 200 && headBody === '');
        const previewStateAfter = await (await fetch(previewStateUrl, {cache: 'no-store'})).json();
        const previewStateUnchanged = JSON.stringify(previewStateAfter) === JSON.stringify(previewStateBefore);
        stage = 'anonymous visual preview leaves persistence snapshot unchanged ' + previewStateUnchanged;
        expect(previewStateUnchanged);
        stage = 'switch preview to signed-in audience';
        const signedInLabel = editorRoot.dataset.visualEditorSignedInLabel;
        const signedInButton = Array.from(editorDocument.querySelectorAll('.theme-visual-editor-hud button'))
            .find(button => button.textContent.trim() === signedInLabel);
        expect(signedInButton);
        const signedInPreviewLoad = waitForVisualFrameLoad(visualFrame, 'signed-in audience transition');
        signedInButton.click();
        stage = 'wait for signed-in audience load and editor commit';
        await signedInPreviewLoad;
        await until(() => new URL(visualFrame.contentWindow.location.href).searchParams.get('view_as') !== 'anonymous'
            && new URL(visualFrame.contentDocument.URL).searchParams.get('view_as') !== 'anonymous'
            && visualFrameDocumentCommitted(visualFrame, entryCssBytes));
        stage = 'signed-in preview route ' + safePublicRoute(visualFrame.contentWindow.location.href);
        const adminPageUrl = editorWindow.location.href;
        const originalVisualFetch = editorWindow.fetch.bind(editorWindow);
        let visualInteractionPosts = 0;
        editorWindow.fetch = async (...args) => {
            const method = String(args[1]?.method || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD') visualInteractionPosts++;
            return originalVisualFetch(...args);
        };
        const routeMatches = (actualHref, emittedHref) => {
            const actual = new URL(actualHref);
            const emitted = new URL(emittedHref, actualHref);
            const emittedRouteParameters = [...emitted.searchParams]
                .filter(([name]) => name !== 'preview' && name !== 'view_as');
            return actual.origin === emitted.origin && actual.pathname === emitted.pathname
                && actual.searchParams.get('preview') === 'visual'
                && emittedRouteParameters.every(([name, value]) => actual.searchParams.getAll(name).includes(value));
        };
        const actualHomeDocument = visualFrame.contentDocument;
        const seedCardLink = Array.from(actualHomeDocument.querySelectorAll('.gallery-card-title-link[href]'))
            .find(link => link.textContent.trim() === 'Workflow seed');
        stage = 'seed gallery card present ' + Boolean(seedCardLink);
        expect(seedCardLink);
        const seedGalleryHref = seedCardLink.href;
        const homeHrefBeforeSelection = visualFrame.contentWindow.location.href;
        const seedCardClick = new visualFrame.contentWindow.MouseEvent('click', {
            bubbles: true, cancelable: true, view: visualFrame.contentWindow,
        });
        const seedCardSelectionPrevented = seedCardLink.dispatchEvent(seedCardClick) === false;
        stage = 'ordinary seed card click selects only ' + seedCardSelectionPrevented;
        expect(seedCardSelectionPrevented);
        const inspector = editorDocument.querySelector('.theme-visual-editor-inspector');
        const visualLabels = editorDocument.querySelector('[data-visual-editor-labels]');
        const followLabel = editorRoot.dataset.visualEditorFollowLinkLabel;
        let followButton = Array.from(inspector.querySelectorAll('button'))
            .find(button => button.textContent.trim() === followLabel && !button.hidden);
        const seedFollowAvailable = !inspector.hidden && Boolean(followButton)
            && visualFrame.contentWindow.location.href === homeHrefBeforeSelection
            && editorWindow.location.href === adminPageUrl && visualInteractionPosts === 0;
        stage = 'seed selection offers Follow without navigation or mutation ' + seedFollowAvailable;
        expect(seedFollowAvailable);
        const seedGalleryLoad = waitForVisualFrameLoad(visualFrame, 'Follow to seed gallery');
        followButton.click();
        stage = 'wait for seed Follow load and editor commit';
        await seedGalleryLoad;
        await until(() => routeMatches(visualFrame.contentWindow.location.href, seedGalleryHref)
            && routeMatches(visualFrame.contentDocument.URL, seedGalleryHref)
            && visualFrameDocumentCommitted(visualFrame, entryCssBytes));
        const followedSeedPreservesEditor = draftText.value === entryCssBytes
            && editorWindow.location.href === adminPageUrl && visualInteractionPosts === 0;
        stage = 'Follow seed preserves editor state ' + followedSeedPreservesEditor;
        expect(followedSeedPreservesEditor);
        const seedGalleryUrl = visualFrame.contentWindow.location.href;
        const seedGalleryDocument = visualFrame.contentDocument;
        const protectedCardLink = Array.from(seedGalleryDocument.querySelectorAll('.gallery-card-title-link[href]'))
            .find(link => link.textContent.trim() === 'Workflow protected');
        stage = 'protected gallery card present ' + Boolean(protectedCardLink);
        expect(protectedCardLink);
        const protectedGalleryHref = protectedCardLink.href;
        const protectedCardClick = new visualFrame.contentWindow.MouseEvent('click', {
            bubbles: true, cancelable: true, view: visualFrame.contentWindow,
        });
        const protectedCardSelectionPrevented = protectedCardLink.dispatchEvent(protectedCardClick) === false;
        stage = 'ordinary protected card click selects only ' + protectedCardSelectionPrevented;
        expect(protectedCardSelectionPrevented);
        followButton = Array.from(inspector.querySelectorAll('button'))
            .find(button => button.textContent.trim() === followLabel && !button.hidden);
        const protectedFollowAvailable = Boolean(followButton) && visualFrame.contentWindow.location.href === seedGalleryUrl
            && editorWindow.location.href === adminPageUrl && visualInteractionPosts === 0;
        stage = 'protected selection offers Follow without navigation or mutation ' + protectedFollowAvailable;
        expect(protectedFollowAvailable);
        const protectedGalleryLoad = waitForVisualFrameLoad(visualFrame, 'Follow to protected gallery');
        followButton.click();
        stage = 'wait for protected Follow load and editor commit';
        await protectedGalleryLoad;
        await until(() => routeMatches(visualFrame.contentWindow.location.href, protectedGalleryHref)
            && routeMatches(visualFrame.contentDocument.URL, protectedGalleryHref)
            && visualFrameDocumentCommitted(visualFrame, entryCssBytes));
        const followedProtectedPreservesEditor = draftText.value === entryCssBytes
            && editorWindow.location.href === adminPageUrl && visualInteractionPosts === 0;
        stage = 'Follow protected gallery preserves editor state ' + followedProtectedPreservesEditor;
        expect(followedProtectedPreservesEditor);
        const protectedHeading = visualFrame.contentDocument.querySelector('.hero h1, h1');
        const protectedHeadingMatched = Boolean(protectedHeading && protectedHeading.matches('h1.gallery-title')
            && protectedHeading.textContent.trim() === 'Workflow protected');
        stage = 'protected route shows expected gallery heading ' + protectedHeadingMatched;
        expect(protectedHeadingMatched);
        protectedHeading.click();
        const styleLabel = editorRoot.dataset.visualEditorStyleElementLabel;
        const styleButton = Array.from(inspector.querySelectorAll('button'))
            .find(button => button.textContent.trim() === styleLabel);
        const styleActionAvailable = !inspector.hidden && Boolean(styleButton);
        stage = 'selected heading offers Style ' + styleActionAvailable;
        expect(styleActionAvailable);
        styleButton.click();
        const cssValueLabel = editorRoot.dataset.visualEditorCssValueLabel;
        const colorInput = inspector.querySelector('input[type="text"][aria-label="color · ' + cssValueLabel + '"]');
        stage = 'Style opens a CSS value input ' + Boolean(colorInput);
        expect(colorInput);
        const initialHeadingColor = visualFrame.contentWindow.getComputedStyle(protectedHeading).color;
        colorInput.value = '#123456';
        colorInput.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        const managedPreviewStyle = visualFrame.contentDocument.querySelector('style[data-theme-visual-editor-draft]');
        const visualStyleApplied = Boolean(managedPreviewStyle?.textContent.includes('body.public-page h1.gallery-title')
            && managedPreviewStyle.textContent.includes('color: #123456;')
            && (managedPreviewStyle.textContent.match(/color:\s*#123456;/g) || []).length === 1
            && visualFrame.contentWindow.getComputedStyle(protectedHeading).color === 'rgb(18, 52, 86)'
            && visualFrame.contentWindow.getComputedStyle(protectedHeading).color !== initialHeadingColor
            && draftText.value === entryCssBytes
            && visualInteractionPosts === 0 && editorWindow.location.href === adminPageUrl);
        stage = 'Style updates preview while preserving saved CSS ' + visualStyleApplied;
        expect(visualStyleApplied);
        const styledPreviewCss = managedPreviewStyle.textContent;
        stage = 'Anonymous fallback from protected signed-in gallery preserves the real visual draft';
        const anonymousFallbackLoad = waitForVisualFrameLoad(visualFrame, 'Anonymous fallback from protected gallery');
        anonymousButton.click();
        stage = 'wait for real PHP Anonymous fallback and editor commit';
        await anonymousFallbackLoad;
        await until(() => routeMatches(visualFrame.contentWindow.location.href, seedGalleryHref)
            && routeMatches(visualFrame.contentDocument.URL, seedGalleryHref)
            && new URL(visualFrame.contentWindow.location.href).searchParams.get('view_as') === 'anonymous'
            && visualFrameDocumentCommitted(visualFrame, styledPreviewCss));
        const anonymousFallbackUrl = new URL(visualFrame.contentWindow.location.href);
        const anonymousFallbackStatus = editorDocument.querySelector('.theme-visual-editor-draft-status');
        const anonymousFallbackLabels = editorDocument.querySelector('[data-visual-editor-extension-labels]');
        const anonymousFallbackStateResponse = await editorWindow.fetch(previewStateUrl, {cache: 'no-store'});
        const anonymousFallbackState = await anonymousFallbackStateResponse.json();
        const anonymousFallbackFlags = [
            anonymousFallbackUrl.origin === preparedPreview.origin && anonymousFallbackUrl.pathname.startsWith(appMountPath),
            anonymousFallbackUrl.searchParams.get('preview') === 'visual'
                && anonymousFallbackUrl.searchParams.get('view_as') === 'anonymous'
                && !anonymousFallbackUrl.searchParams.has('visual_notice'),
            visualFrame.contentDocument.querySelector('h1')?.textContent.trim() === 'Workflow seed',
            anonymousFallbackStatus?.textContent === anonymousFallbackLabels?.dataset.visualEditorAnonymousFallbackNoticeLabel,
            draftText.value === entryCssBytes
                && visualFrame.contentDocument.querySelector('style[data-theme-visual-editor-draft]')?.textContent === styledPreviewCss,
            editorWindow.location.href === adminPageUrl && visualInteractionPosts === 0
                && anonymousFallbackStateResponse.status === 200
                && JSON.stringify(anonymousFallbackState) === JSON.stringify(previewStateBefore),
        ];
        stage = 'Anonymous fallback preserved scope notice draft privacy ' + anonymousFallbackFlags.map(Number).join('');
        expect(anonymousFallbackFlags.every(Boolean));
        const protectedBackLoad = waitForVisualFrameLoad(visualFrame, 'Back to signed-in protected gallery');
        const fallbackBackButton = Array.from(editorDocument.querySelectorAll('.theme-visual-editor-hud button'))
            .find(button => button.textContent.trim() === editorRoot.dataset.visualEditorBackLabel);
        expect(Boolean(fallbackBackButton && !fallbackBackButton.disabled));
        fallbackBackButton.click();
        stage = 'wait for Back to signed-in protected gallery and editor commit';
        await protectedBackLoad;
        await until(() => routeMatches(visualFrame.contentWindow.location.href, protectedGalleryHref)
            && routeMatches(visualFrame.contentDocument.URL, protectedGalleryHref)
            && new URL(visualFrame.contentWindow.location.href).searchParams.get('view_as') !== 'anonymous'
            && visualFrameDocumentCommitted(visualFrame, styledPreviewCss));
        const protectedBackPreservesDraft = draftText.value === entryCssBytes
            && visualFrame.contentDocument.querySelector('style[data-theme-visual-editor-draft]')?.textContent === styledPreviewCss
            && !new URL(visualFrame.contentWindow.location.href).searchParams.has('visual_notice')
            && editorWindow.location.href === adminPageUrl && visualInteractionPosts === 0;
        stage = 'Back restores signed-in protected route and CSS draft ' + protectedBackPreservesDraft;
        expect(protectedBackPreservesDraft);
        const backLabel = editorRoot.dataset.visualEditorBackLabel;
        const homeLabel = editorRoot.dataset.visualEditorHomeLabel;
        const hud = editorDocument.querySelector('.theme-visual-editor-hud');
        const backButton = Array.from(hud.querySelectorAll('button'))
            .find(button => button.textContent.trim() === backLabel);
        const backAvailable = Boolean(backButton && !backButton.disabled);
        stage = 'Back becomes available after navigation ' + backAvailable;
        expect(backAvailable);
        const seedBackLoad = waitForVisualFrameLoad(visualFrame, 'Back to seed gallery');
        backButton.click();
        stage = 'wait for Back load and editor commit';
        await seedBackLoad;
        await until(() => routeMatches(visualFrame.contentWindow.location.href, seedGalleryUrl)
            && routeMatches(visualFrame.contentDocument.URL, seedGalleryUrl)
            && visualFrame.contentDocument.querySelector('h1')?.textContent.trim() === 'Workflow seed'
            && visualFrame.contentDocument?.querySelector('style[data-theme-visual-editor-draft]')?.textContent.includes('color: #123456;')
            && visualFrameDocumentCommitted(visualFrame, styledPreviewCss)
            && visualFrame.contentDocument.querySelector('h1.gallery-title')
            && visualFrame.contentWindow.getComputedStyle(visualFrame.contentDocument.querySelector('h1.gallery-title')).color === 'rgb(18, 52, 86)'
            && draftText.value === entryCssBytes && visualInteractionPosts === 0 && editorWindow.location.href === adminPageUrl);
        const homeButton = Array.from(hud.querySelectorAll('button'))
            .find(button => button.textContent.trim() === homeLabel);
        const homeAvailable = Boolean(homeButton && !homeButton.disabled && visualInteractionPosts === 0
            && editorWindow.location.href === adminPageUrl);
        stage = 'Home remains available without parent navigation ' + homeAvailable;
        expect(homeAvailable);
        const homePreviewLoad = waitForVisualFrameLoad(visualFrame, 'Home navigation');
        homeButton.click();
        stage = 'wait for Home load and editor commit';
        await homePreviewLoad;
        await until(() => routeMatches(visualFrame.contentWindow.location.href, preparedPreview.href)
            && routeMatches(visualFrame.contentDocument.URL, preparedPreview.href)
            && visualFrame.contentDocument?.querySelector('style[data-theme-visual-editor-draft]')?.textContent.includes('color: #123456;')
            && visualFrameDocumentCommitted(visualFrame, styledPreviewCss)
            && draftText.value === entryCssBytes && visualInteractionPosts === 0 && editorWindow.location.href === adminPageUrl);
        expect(visualFrame.contentDocument.querySelectorAll('body.public-page h1.gallery-title').length === 0
            && draftText.value === entryCssBytes && visualInteractionPosts === 0 && editorWindow.location.href === adminPageUrl);
        stage = 'edit global Theme image fit and position without a request';
        const globalBackground = visualFrame.contentDocument.querySelector('.theme-background-image');
        const backgroundFit = editorDocument.querySelector('[data-visual-editor-background-fit]');
        const backgroundPositionX = editorDocument.querySelector('[data-visual-editor-background-position-x]');
        const backgroundPositionY = editorDocument.querySelector('[data-visual-editor-background-position-y]');
        const backgroundPositionXNumber = editorDocument.querySelector('[data-visual-editor-background-position-x-number]');
        const backgroundPositionYNumber = editorDocument.querySelector('[data-visual-editor-background-position-y-number]');
        const resetBackgroundFit = editorDocument.querySelector('[data-visual-editor-background-reset-fit]');
        const resetBackgroundPosition = editorDocument.querySelector('[data-visual-editor-background-reset-position]');
        const cssRevisionBeforePresentation = editorRoot.querySelector('[data-css-override-revision]').value;
        const backgroundRevisionBeforePresentation = editorRoot.querySelector('[data-visual-editor-background-revision]').value;
        const backgroundOperationInput = editorRoot.querySelector('[data-visual-editor-background-operation]');
        const backgroundOperationState = value => ['keep', 'replace', 'remove'].includes(value) ? value : value ? 'other' : 'missing';
        const presentationOperationState = () => backgroundOperationState(backgroundOperationInput?.value)
            + '/' + backgroundOperationState(editorRoot.dataset.visualEditorBackgroundOperation);
        const initialPresentationDatasetOperation = backgroundOperationState(editorRoot.dataset.visualEditorBackgroundOperation);
        const presentationOperationPreserved = () => backgroundOperationState(backgroundOperationInput?.value) === 'keep'
            && backgroundOperationState(editorRoot.dataset.visualEditorBackgroundOperation) === initialPresentationDatasetOperation;
        stage = 'fit initial operation ' + presentationOperationState();
        expect(backgroundOperationState(backgroundOperationInput?.value) === 'keep');
        const savedBackgroundSize = globalBackground
            ? visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundSize : '';
        const savedBackgroundPosition = globalBackground
            ? visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundPosition : '';
        const initialPresentationFlags = [
            globalBackground?.dataset.themeBackgroundVisualEditorTarget === 'theme',
            Boolean(backgroundFit?.closest('fieldset') && !backgroundFit.closest('fieldset').hidden),
            Boolean(backgroundPositionX?.closest('fieldset') && !backgroundPositionX.closest('fieldset').hidden),
            Boolean(backgroundFit && backgroundFit.value === savedBackgroundSize),
            backgroundPositionXNumber?.type === 'number' && backgroundPositionYNumber?.type === 'number',
            backgroundPositionXNumber?.min === '0' && backgroundPositionXNumber.max === '100'
                && backgroundPositionXNumber.step === '1' && backgroundPositionYNumber?.min === '0'
                && backgroundPositionYNumber.max === '100' && backgroundPositionYNumber.step === '1',
            Boolean(backgroundPositionXNumber?.getAttribute('aria-label')?.includes('(%)')
                && backgroundPositionYNumber?.getAttribute('aria-label')?.includes('(%)')),
            Boolean(backgroundPositionX?.closest('label')?.textContent.includes('(%)')
                && backgroundPositionY?.closest('label')?.textContent.includes('(%)')),
            Boolean(backgroundPositionXNumber?.value === backgroundPositionX?.value
                && backgroundPositionYNumber?.value === backgroundPositionY?.value),
            Boolean(editorDocument.querySelector('[data-visual-editor-background-position-x-effective]')?.textContent
                === backgroundPositionX?.value + '%'
                && editorDocument.querySelector('[data-visual-editor-background-position-y-effective]')?.textContent
                === backgroundPositionY?.value + '%'),
            Boolean(savedBackgroundPosition === backgroundPositionX?.value + '% ' + backgroundPositionY?.value + '%'),
        ];
        stage = 'fit initial state ' + initialPresentationFlags.map(Number).join('');
        expect(initialPresentationFlags.every(Boolean));
        backgroundFit.value = 'contain';
        backgroundFit.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        stage = 'fit after fit operation ' + presentationOperationState();
        expect(presentationOperationPreserved());
        backgroundPositionX.value = '75';
        backgroundPositionX.dispatchEvent(new editorWindow.Event('input', {bubbles: true}));
        backgroundPositionX.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        stage = 'fit after X operation ' + presentationOperationState();
        expect(presentationOperationPreserved());
        backgroundPositionYNumber.value = '25';
        backgroundPositionYNumber.dispatchEvent(new editorWindow.Event('input', {bubbles: true}));
        backgroundPositionYNumber.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        stage = 'fit after Y operation ' + presentationOperationState();
        expect(presentationOperationPreserved());
        const presentationEffectFlags = [
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundSize === 'contain',
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundPosition === '75% 25%',
        ];
        stage = 'fit computed effect ' + presentationEffectFlags.map(Number).join('');
        expect(presentationEffectFlags.every(Boolean));
        const presentationControlFlags = [
            backgroundPositionX.value === '75' && backgroundPositionXNumber.value === '75',
            backgroundPositionY.value === '25' && backgroundPositionYNumber.value === '25',
            editorDocument.querySelector('[data-visual-editor-background-position-x-effective]')?.textContent === '75%'
                && editorDocument.querySelector('[data-visual-editor-background-position-y-effective]')?.textContent === '25%',
            editorDocument.querySelector('[data-visual-editor-background-override-conflict]')?.hidden === true,
        ];
        stage = 'fit control sync ' + presentationControlFlags.map(Number).join('');
        expect(presentationControlFlags.every(Boolean));
        const presentationNoWriteFlags = [
            draftText.value === entryCssBytes,
            editorRoot.querySelector('[data-css-override-revision]').value === cssRevisionBeforePresentation,
            editorRoot.querySelector('[data-visual-editor-background-revision]').value === backgroundRevisionBeforePresentation,
            editorRoot.querySelector('[data-visual-editor-background-operation]').value === 'keep',
            visualInteractionPosts === 0 && editorWindow.location.href === adminPageUrl,
        ];
        stage = 'fit no write ' + presentationNoWriteFlags.map(Number).join('');
        expect(presentationNoWriteFlags.every(Boolean));
        const presentationHistoryTrace = [];
        const capturePresentationHistoryState = () => presentationHistoryTrace.push(
            Math.min(99, visualInteractionPosts) + '-' + Number(editorWindow.location.href === adminPageUrl)
        );
        capturePresentationHistoryState();
        resetBackgroundFit.click();
        stage = 'fit after fit reset operation ' + presentationOperationState();
        expect(presentationOperationPreserved());
        capturePresentationHistoryState();
        resetBackgroundPosition.click();
        stage = 'fit after position reset operation ' + presentationOperationState();
        expect(presentationOperationPreserved());
        capturePresentationHistoryState();
        const presentationResetFlags = [
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundSize === savedBackgroundSize,
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundPosition === savedBackgroundPosition,
        ];
        stage = 'fit reset ' + presentationResetFlags.map(Number).join('');
        expect(presentationResetFlags.every(Boolean));
        const presentationUndo = Array.from(hud.querySelectorAll('button'))
            .find(button => button.textContent.trim() === editorRoot.dataset.visualEditorUndoLabel);
        const presentationRedo = Array.from(hud.querySelectorAll('button'))
            .find(button => button.textContent.trim() === editorRoot.dataset.visualEditorRedoLabel);
        stage = 'fit history controls ' + Boolean(presentationUndo) + Boolean(presentationRedo);
        expect(presentationUndo && presentationRedo);
        presentationUndo.click();
        capturePresentationHistoryState();
        stage = 'fit undo one operation ' + presentationOperationState();
        expect(presentationOperationState() === 'keep/keep');
        presentationUndo.click();
        capturePresentationHistoryState();
        stage = 'fit undo two operation ' + presentationOperationState();
        expect(presentationOperationState() === 'keep/keep');
        presentationUndo.click();
        capturePresentationHistoryState();
        stage = 'fit undo three operation ' + presentationOperationState();
        expect(presentationOperationState() === 'keep/keep');
        const presentationUndoFlags = [
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundSize === 'contain',
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundPosition === '75% 50%',
        ];
        stage = 'fit undo ' + presentationUndoFlags.map(Number).join('');
        expect(presentationUndoFlags.every(Boolean));
        presentationRedo.click();
        capturePresentationHistoryState();
        stage = 'fit redo one operation ' + presentationOperationState();
        expect(presentationOperationState() === 'keep/keep');
        presentationRedo.click();
        capturePresentationHistoryState();
        stage = 'fit redo two operation ' + presentationOperationState();
        expect(presentationOperationState() === 'keep/keep');
        presentationRedo.click();
        capturePresentationHistoryState();
        stage = 'fit redo three operation ' + presentationOperationState();
        expect(presentationOperationState() === 'keep/keep');
        const presentationRedoFlags = [
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundSize === savedBackgroundSize,
            visualFrame.contentWindow.getComputedStyle(globalBackground).backgroundPosition === savedBackgroundPosition,
            editorDocument.querySelector('[data-visual-editor-background-position-x-effective]')?.textContent
                === backgroundPositionX.value + '%'
                && editorDocument.querySelector('[data-visual-editor-background-position-y-effective]')?.textContent
                    === backgroundPositionY.value + '%',
            draftText.value === entryCssBytes,
            editorRoot.querySelector('[data-css-override-revision]').value === cssRevisionBeforePresentation,
            editorRoot.querySelector('[data-visual-editor-background-revision]').value === backgroundRevisionBeforePresentation,
            editorRoot.querySelector('[data-visual-editor-background-operation]').value === 'keep',
            visualInteractionPosts === 0 && editorWindow.location.href === adminPageUrl,
        ];
        stage = 'fit redo ' + presentationRedoFlags.map(Number).join('')
            + ' trace ' + presentationHistoryTrace.join('_');
        expect(presentationRedoFlags.every(Boolean));
        editorWindow.fetch = originalVisualFetch;
        stage = 'select real background image without a server request';
        const backgroundFile = hud.querySelector('[data-visual-editor-background-file]');
        const backgroundRevision = editorRoot.querySelector('[data-visual-editor-background-revision]');
        const backgroundOperation = editorRoot.querySelector('[data-visual-editor-background-operation]');
        const cssForm = editorDocument.querySelector('#admin-theme-css-overrides-form');
        const cssRevision = editorRoot.querySelector('[data-css-override-revision]');
        expect(backgroundFile instanceof editorWindow.HTMLInputElement && backgroundRevision instanceof editorWindow.HTMLInputElement
            && backgroundOperation instanceof editorWindow.HTMLInputElement
            && cssForm instanceof editorWindow.HTMLFormElement && cssRevision instanceof editorWindow.HTMLInputElement);
        const initialBackgroundRevision = backgroundRevision.value;
        const initialCssRevision = cssRevision.value;
        const visualSaveRequests = [];
        const visualSaveResponses = [];
        let releaseVisualSaveResponse = null;
        const visualSaveResponseGate = new Promise(resolve => { releaseVisualSaveResponse = resolve; });
        const originalEditorFetch = editorWindow.fetch.bind(editorWindow);
        editorWindow.fetch = async (...args) => {
            const method = String(args[1]?.method || 'GET').toUpperCase();
            if (method === 'POST') {
                const body = args[1]?.body;
                const submittedFile = body instanceof editorWindow.FormData ? body.get('theme_background_file') : null;
                const action = body instanceof editorWindow.FormData ? body.get('css_override_action') : '';
                visualSaveRequests.push({
                    action,
                    backgroundRevision: body instanceof editorWindow.FormData ? body.get('theme_background_revision') : '',
                    backgroundOperation: body instanceof editorWindow.FormData ? body.get('theme_background_operation') : '',
                    backgroundTarget: body instanceof editorWindow.FormData ? body.get('theme_background_target') : '',
                    hasBackgroundFileField: body instanceof editorWindow.FormData && body.has('theme_background_file'),
                    css: body instanceof editorWindow.FormData ? body.get('css_override_text') : '',
                    fileName: submittedFile instanceof editorWindow.File ? submittedFile.name : '',
                    fileSize: submittedFile instanceof editorWindow.File ? submittedFile.size : 0,
                    fileType: submittedFile instanceof editorWindow.File ? submittedFile.type : '',
                });
                const response = await fetchWithDeadline(originalEditorFetch, ...args);
                if (action === 'save') {
                    await visualSaveResponseGate;
                    visualSaveResponses.push({ok: response.ok, status: response.status, body: await response.clone().json()});
                }
                return response;
            }
            return fetchWithDeadline(originalEditorFetch, ...args);
        };
        const backgroundTransfer = new editorWindow.DataTransfer();
        backgroundTransfer.items.add(new editorWindow.File([bytes], 'visual-preview-background.jpg', {type: 'image/jpeg'}));
        backgroundFile.files = backgroundTransfer.files;
        backgroundFile.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        const pendingStatus = editorRoot.querySelector('[data-visual-editor-background-status]');
        await until(() => pendingStatus?.textContent.trim() !== ''
            && visualFrame.contentDocument?.querySelector('.theme-background-image')?.getAttribute('style')?.includes('blob:'));
        expect(backgroundFile.files.length === 1 && backgroundFile.files[0].name === 'visual-preview-background.jpg'
            && visualSaveRequests.length === 0);
        stage = 'review page width and image opacity as CSS-only preview changes';
        const widthModeLabel = visualLabels?.dataset.visualEditorWidthModeLabel || '';
        const widthValueLabel = visualLabels?.dataset.visualEditorWidthValueLabel || '';
        const opacityLabel = visualLabels?.dataset.visualEditorBackgroundOpacityLabel || '';
        const widthMode = hud?.querySelector('select[aria-label="' + widthModeLabel + '"]');
        const customWidth = hud?.querySelector('input[type="range"][aria-label="' + widthValueLabel + '"]');
        const imageOpacity = hud?.querySelector('input[type="range"][aria-label="' + opacityLabel + '"]');
        expect(widthMode instanceof editorWindow.HTMLSelectElement && customWidth instanceof editorWindow.HTMLInputElement
            && imageOpacity instanceof editorWindow.HTMLInputElement);
        widthMode.value = 'custom';
        widthMode.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        customWidth.value = '1640';
        customWidth.dispatchEvent(new editorWindow.Event('input', {bubbles: true}));
        customWidth.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        imageOpacity.value = '0.42';
        imageOpacity.dispatchEvent(new editorWindow.Event('input', {bubbles: true}));
        imageOpacity.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        const inspectBaseStylesheet = document => {
            const link = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).find(candidate => {
                try {
                    return new URL(candidate.href, document.location.href).pathname.endsWith('/assets/styles/base.css');
                } catch {
                    return false;
                }
            });
            if (!link) return {href: null, loaded: false, bodyReset: false};
            const href = new URL(link.href, document.location.href);
            const sheet = Array.from(document.styleSheets).find(candidate => {
                try {
                    return candidate.href !== null
                        && new URL(candidate.href, document.location.href).pathname === href.pathname;
                } catch {
                    return false;
                }
            });
            let bodyReset = false;
            if (sheet) {
                try {
                    bodyReset = Array.from(sheet.cssRules).some(rule => rule.selectorText === 'body'
                        && rule.style?.getPropertyValue('margin') === '0px');
                } catch {
                    bodyReset = false;
                }
            }
            return {href, loaded: Boolean(sheet), bodyReset};
        };
        const draftStyleBeforeApply = visualFrame.contentDocument.querySelector('style[data-theme-visual-editor-draft]');
        const previewViewportWidth = visualFrame.contentWindow.innerWidth;
        const previewViewportHeight = visualFrame.contentWindow.innerHeight;
        const previewBaseStyles = inspectBaseStylesheet(visualFrame.contentDocument);
        const previewRoot = visualFrame.contentDocument.documentElement;
        const previewDocumentWidth = previewRoot.clientWidth;
        const previewMain = visualFrame.contentDocument.querySelector('.site-main');
        const previewMainWidth = previewMain?.getBoundingClientRect().width || 0;
        const previewBackgroundOpacity = visualFrame.contentWindow.getComputedStyle(
            visualFrame.contentDocument.querySelector('.theme-background-image')
        ).opacity;
        const previewBodyStyle = visualFrame.contentWindow.getComputedStyle(visualFrame.contentDocument.body);
        const previewBodyMargins = [previewBodyStyle.marginLeft, previewBodyStyle.marginRight,
            previewBodyStyle.marginTop, previewBodyStyle.marginBottom].map(value => parseFloat(value) || 0);
        const previewBodyHorizontalPadding = [previewBodyStyle.paddingLeft, previewBodyStyle.paddingRight]
            .map(value => parseFloat(value) || 0);
        const previewBodyOutlineStyle = previewBodyStyle.outlineStyle;
        const previewBodyOutlineWidth = previewBodyStyle.outlineWidth;
        const previewBodyOutlineColor = previewBodyStyle.outlineColor;
        expect(draftStyleBeforeApply?.textContent.includes('body.public-page.public-page .site-header')
            && draftStyleBeforeApply.textContent.includes('min(1640px,calc(100% - 2rem))')
            && draftStyleBeforeApply.textContent.includes('body.public-page .theme-background-image')
            && draftStyleBeforeApply.textContent.includes('opacity: 0.42;')
            && previewViewportWidth > 0 && previewViewportHeight > 0 && previewMainWidth > 0
            && previewBackgroundOpacity === '0.42'
            && previewBodyOutlineStyle === 'solid' && previewBodyOutlineWidth === '7px'
            && previewBodyOutlineColor === 'rgb(12, 34, 56)'
            && visualFrame.contentDocument.querySelector('.theme-background-image')?.getAttribute('style')?.includes('blob:')
            && visualSaveRequests.length === 0);
        stage = 'apply local visual draft without saving';
        const applyLabel = editorRoot.dataset.visualEditorApplyExitLabel;
        const applyButton = Array.from(hud.querySelectorAll('button')).find(button => button.textContent.trim() === applyLabel);
        expect(applyButton);
        applyButton.click();
        const appliedCss = draftText.value;
        expect(!editorDocument.querySelector('.theme-visual-editor-workspace')
            && appliedCss.includes('PHP Gallery managed visual CSS BEGIN')
            && appliedCss.includes('min(1640px,calc(100% - 2rem))') && appliedCss.includes('opacity: 0.42;')
            && !appliedCss.includes('blob:') && !appliedCss.includes('visual-preview-background.jpg')
            && backgroundFile.files.length === 1 && visualSaveRequests.length === 0);
        stage = 'explicit CSS Save submits the reviewed real File';
        const saveButton = editorRoot.querySelector('button[name="css_override_action"][value="save"]');
        expect(saveButton && initialBackgroundRevision !== '' && initialCssRevision !== '');
        saveButton.click();
        // The request list is populated synchronously by the intercepted fetch; use the bounded until() deadline
        // so a missed submit event reports this exact stage instead of consuming the full fixture poll window.
        await until(() => visualSaveRequests.length === 1);
        const laterBackgroundFile = new editorWindow.File([bytes], 'selected-during-save.png', {type: 'image/jpeg'});
        const laterBackgroundTransfer = new editorWindow.DataTransfer();
        laterBackgroundTransfer.items.add(laterBackgroundFile);
        backgroundFile.files = laterBackgroundTransfer.files;
        backgroundFile.dispatchEvent(new editorWindow.Event('change', {bubbles: true}));
        releaseVisualSaveResponse();
        await until(() => backgroundFile.files.length === 1 && backgroundFile.files[0].name === 'selected-during-save.png'
            && backgroundRevision.value !== initialBackgroundRevision && cssRevision.value !== initialCssRevision
            && editorRoot.dataset.visualEditorBackgroundAvailable === '1'
            && editorRoot.dataset.visualEditorBackgroundUrl !== '');
        const submittedSave = visualSaveRequests[0];
        const savedBackgroundUrl = new URL(editorRoot.dataset.visualEditorBackgroundUrl, editorWindow.location.href);
        expect(submittedSave.action === 'save' && submittedSave.backgroundOperation === 'replace'
            && submittedSave.backgroundTarget === 'theme' && submittedSave.hasBackgroundFileField
            && submittedSave.backgroundRevision === initialBackgroundRevision
            && visualSaveResponses[0].ok && visualSaveResponses[0].body.ok
            && visualSaveResponses[0].body.background.operation === 'replace'
            && visualSaveResponses[0].body.background.target === 'theme'
            && submittedSave.css === appliedCss && submittedSave.fileName === 'visual-preview-background.jpg'
            && submittedSave.fileSize === bytes.byteLength && submittedSave.fileType === 'image/jpeg'
            && savedBackgroundUrl.origin === editorWindow.location.origin
            && !savedBackgroundUrl.href.includes('visual-preview-background.jpg')
            && backgroundFile.files[0].name === 'selected-during-save.png'
            && backgroundOperation.value === 'replace'
            && pendingStatus.textContent.includes('selected-during-save.png')
            && backgroundRevision.value !== initialBackgroundRevision);
        expect(draftText.value.includes('min(1640px,calc(100% - 2rem))') && draftText.value.includes('opacity: 0.42;'));
        expect(!editorDocument.querySelector('.theme-visual-editor-workspace'));
        stage = 'explicit Keep current Save omits the native file attachment';
        editorRoot.querySelector('[data-visual-editor-background-keep]').click();
        const keepBackgroundRevision = backgroundRevision.value;
        const keepCssRevision = cssRevision.value;
        saveButton.click();
        await until(() => visualSaveResponses.length === 2 && !draftText.readOnly && backgroundOperation.value === 'keep');
        const keepSave = visualSaveRequests[1];
        expect(keepSave.backgroundOperation === 'keep' && keepSave.backgroundTarget === 'theme'
            && !keepSave.hasBackgroundFileField && keepSave.fileName === '' && backgroundFile.files.length === 0
            && visualSaveResponses[1].ok && visualSaveResponses[1].body.ok
            && visualSaveResponses[1].body.background.operation === 'keep'
            && visualSaveResponses[1].body.background.target === 'theme'
            && backgroundRevision.value === keepBackgroundRevision && cssRevision.value === keepCssRevision);
        stage = 'fresh public homepage reload matches the saved visual preview';
        const savedPublicUrl = new URL(preparedPreview.href);
        savedPublicUrl.searchParams.delete('preview');
        savedPublicUrl.searchParams.delete('view_as');
        savedPublicFrame = editorDocument.createElement('iframe');
        savedPublicFrame.title = 'Fresh public CSS verification';
        savedPublicFrame.style.cssText = 'position:fixed;left:0;top:0;z-index:-1;visibility:hidden;pointer-events:none;width:'
            + previewViewportWidth + 'px;height:' + previewViewportHeight + 'px;border:0';
        const savedPublicLoad = waitForVisualFrameLoad(savedPublicFrame, 'fresh public homepage CSS reload');
        savedPublicFrame.src = savedPublicUrl.href;
        editorDocument.body.append(savedPublicFrame);
        await savedPublicLoad;
        await until(() => {
            const loadedDocument = savedPublicFrame.contentDocument;
            const loadedUrl = new URL(savedPublicFrame.contentWindow.location.href);
            const expectedHomePage = savedPublicUrl.searchParams.get('page');
            return loadedDocument?.readyState === 'complete' && loadedDocument.body?.classList.contains('public-page')
                && loadedDocument.querySelector('.site-main') && loadedDocument.querySelector('.theme-background-image')
                && loadedUrl.origin === savedPublicUrl.origin && loadedUrl.pathname === savedPublicUrl.pathname
                && loadedUrl.searchParams.get('page') === expectedHomePage
                && !loadedUrl.searchParams.has('preview') && !loadedUrl.searchParams.has('view_as')
                && !loadedDocument.querySelector('style[data-theme-visual-editor-draft]');
        });
        const reloadedPublicDocument = savedPublicFrame.contentDocument;
        const reloadedPublicWindow = savedPublicFrame.contentWindow;
        const reloadedBaseStyles = inspectBaseStylesheet(reloadedPublicDocument);
        const reloadedRoot = reloadedPublicDocument.documentElement;
        const reloadedDocumentWidth = reloadedRoot.clientWidth;
        const reloadedMain = reloadedPublicDocument.querySelector('.site-main');
        const reloadedMainWidth = reloadedMain.getBoundingClientRect().width;
        const reloadedManagedMainWidthRulePresent = Array.from(reloadedPublicDocument.styleSheets).some(sheet => {
            try {
                return Array.from(sheet.cssRules).some(rule => typeof rule.selectorText === 'string'
                    && rule.selectorText.split(',').some(selector => selector.trim() === 'body.public-page.public-page .site-main')
                    && rule.style?.getPropertyValue('width') !== '');
            } catch {
                return false;
            }
        });
        const reloadedBackgroundLayer = reloadedPublicDocument.querySelector('.theme-background-image');
        const reloadedBackgroundOpacity = reloadedPublicWindow.getComputedStyle(
            reloadedBackgroundLayer
        ).opacity;
        const reloadedBackgroundValue = reloadedPublicWindow.getComputedStyle(reloadedBackgroundLayer).backgroundImage;
        const reloadedAssetMatch = reloadedBackgroundValue.match(/url\(["']?([^"')]+)["']?\)/);
        let reloadedAssetUrl = null;
        try {
            if (reloadedAssetMatch) reloadedAssetUrl = new URL(reloadedAssetMatch[1], savedPublicUrl.href);
        } catch {
            reloadedAssetUrl = null;
        }
        const comparableAssetUrl = value => {
            const candidate = new URL(value.href);
            candidate.hash = '';
            candidate.searchParams.delete('preview');
            candidate.searchParams.delete('view_as');
            candidate.searchParams.sort();
            return candidate.href;
        };
        const publicBackgroundAssetMatchesSave = Boolean(reloadedAssetUrl
            && !reloadedAssetUrl.searchParams.has('preview') && !reloadedAssetUrl.searchParams.has('view_as')
            && comparableAssetUrl(reloadedAssetUrl) === comparableAssetUrl(savedBackgroundUrl));
        let publicBackgroundAssetDelivered = false;
        if (reloadedAssetUrl && publicBackgroundAssetMatchesSave) {
            const assetResponse = await fetchWithDeadline(reloadedPublicWindow.fetch.bind(reloadedPublicWindow),
                reloadedAssetUrl.href, {credentials: 'same-origin', cache: 'no-store'});
            const assetBytes = (await assetResponse.arrayBuffer()).byteLength;
            const assetContentType = (assetResponse.headers.get('Content-Type') || '').split(';', 1)[0].trim().toLowerCase();
            publicBackgroundAssetDelivered = assetResponse.status === 200 && /^image\/[a-z0-9.+-]+$/.test(assetContentType)
                && assetBytes > 0;
        }
        const reloadedBodyStyle = reloadedPublicWindow.getComputedStyle(reloadedPublicDocument.body);
        const reloadedBodyMargins = [reloadedBodyStyle.marginLeft, reloadedBodyStyle.marginRight,
            reloadedBodyStyle.marginTop, reloadedBodyStyle.marginBottom].map(value => parseFloat(value) || 0);
        const reloadedBodyHorizontalPadding = [reloadedBodyStyle.paddingLeft, reloadedBodyStyle.paddingRight]
            .map(value => parseFloat(value) || 0);
        const baseStylesheetPathsMatch = Boolean(previewBaseStyles.href && reloadedBaseStyles.href
            && previewBaseStyles.href.pathname === reloadedBaseStyles.href.pathname);
        const baseStylesheetContextMatches = Boolean(previewBaseStyles.href && reloadedBaseStyles.href
            && previewBaseStyles.href.searchParams.get('preview') === 'visual'
            && !previewBaseStyles.href.searchParams.has('view_as')
            && !reloadedBaseStyles.href.searchParams.has('preview')
            && !reloadedBaseStyles.href.searchParams.has('view_as'));
        const baseStylesheetRevisionMatches = Boolean(previewBaseStyles.href && reloadedBaseStyles.href
            && previewBaseStyles.href.searchParams.getAll('v').length === 1
            && previewBaseStyles.href.searchParams.get('v') !== ''
            && previewBaseStyles.href.searchParams.get('v') === reloadedBaseStyles.href.searchParams.get('v')
            && reloadedBaseStyles.href.searchParams.getAll('v').length === 1);
        const baseStylesheetResetMatches = previewBaseStyles.loaded && previewBaseStyles.bodyReset
            && reloadedBaseStyles.loaded && reloadedBaseStyles.bodyReset;
        const previewBodyBoxReset = previewBodyMargins.every(value => value === 0)
            && previewBodyHorizontalPadding.every(value => value === 0);
        const reloadedBodyBoxReset = reloadedBodyMargins.every(value => value === 0)
            && reloadedBodyHorizontalPadding.every(value => value === 0);
        const publicSaveParity = Math.abs(reloadedPublicWindow.innerWidth - previewViewportWidth) < 1
            && Math.abs(reloadedMainWidth - previewMainWidth) < 1
            && reloadedManagedMainWidthRulePresent
            && baseStylesheetPathsMatch && baseStylesheetContextMatches && baseStylesheetResetMatches
            && baseStylesheetRevisionMatches
            && previewBodyBoxReset && reloadedBodyBoxReset
            && reloadedBackgroundOpacity === previewBackgroundOpacity
            && publicBackgroundAssetMatchesSave && publicBackgroundAssetDelivered
            && reloadedBodyStyle.outlineStyle === previewBodyOutlineStyle
            && reloadedBodyStyle.outlineWidth === previewBodyOutlineWidth
            && reloadedBodyStyle.outlineColor === previewBodyOutlineColor
            && visualSaveRequests.length === 2 && visualSaveResponses[0].ok && visualSaveResponses[0].body.ok
            && visualSaveResponses[1].ok && visualSaveResponses[1].body.ok;
        const boundedDiagnosticPx = value => Math.max(-9999, Math.min(9999, Math.round(value)));
        const bodyBoxDeltaHundredths = Math.min(999, Math.round(Math.max(
            ...previewBodyMargins.map((value, index) => Math.abs(value - reloadedBodyMargins[index])),
            ...previewBodyHorizontalPadding.map((value, index) => Math.abs(value - reloadedBodyHorizontalPadding[index]))
        ) * 100));
        stage = 'p' + Number(publicSaveParity)
            + ' v' + boundedDiagnosticPx(previewViewportWidth) + '-' + boundedDiagnosticPx(reloadedPublicWindow.innerWidth)
            + ' d' + boundedDiagnosticPx(previewDocumentWidth - reloadedDocumentWidth)
            + ' m' + boundedDiagnosticPx(previewMainWidth) + '-' + boundedDiagnosticPx(reloadedMainWidth)
            + ' z' + Number(previewBodyBoxReset) + Number(reloadedBodyBoxReset)
            + ' u' + Number(baseStylesheetPathsMatch && baseStylesheetContextMatches)
            + ' s' + Number(previewBaseStyles.loaded) + Number(reloadedBaseStyles.loaded)
            + ' r' + Number(previewBaseStyles.bodyReset) + Number(reloadedBaseStyles.bodyReset)
            + ' c' + Number(baseStylesheetRevisionMatches)
            + ' w' + Number(reloadedManagedMainWidthRulePresent)
            + ' e' + bodyBoxDeltaHundredths;
        expect(publicSaveParity);
        savedPublicFrame.remove();
        savedPublicFrame = null;
        stage = 'explicit Remove background Save omits the native file attachment';
        editorRoot.querySelector('[data-visual-editor-background-remove]').click();
        const removeBackgroundRevision = backgroundRevision.value;
        const removeCssRevision = cssRevision.value;
        saveButton.click();
        await until(() => visualSaveResponses.length === 3 && !draftText.readOnly && backgroundOperation.value === 'keep'
            && editorRoot.dataset.visualEditorBackgroundAvailable === '0');
        const removeSave = visualSaveRequests[2];
        expect(removeSave.backgroundOperation === 'remove' && removeSave.backgroundTarget === 'theme'
            && !removeSave.hasBackgroundFileField && removeSave.fileName === '' && backgroundFile.files.length === 0
            && visualSaveResponses[2].ok && visualSaveResponses[2].body.ok
            && visualSaveResponses[2].body.background.operation === 'remove'
            && visualSaveResponses[2].body.background.target === 'theme'
            && backgroundRevision.value !== removeBackgroundRevision && cssRevision.value === removeCssRevision);
        stage = 'browser gallery workflows CSS and authenticated widget draft iframe';
        await fetch(location.pathname + '/result', {method: 'POST', body: JSON.stringify({ok: true, stage, imageHash})});
    } catch {
        savedPublicFrame?.remove();
        await fetch(location.pathname + '/result', {method: 'POST', body: JSON.stringify({ok: false, stage})});
    }
})();
