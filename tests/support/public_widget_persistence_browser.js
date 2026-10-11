/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/public_widget_persistence_browser.js
 * Module Type: Test Fixture
 * Purpose: Qualify public-widget persistence, publication, and anonymous route navigation in Chromium.
 * Responsibilities:
 *   - Save a draft through the real Admin editor and verify it after reload.
 *   - Preserve toolbar-authored Markdown across all maintained Admin locales and a localized validation failure.
 *   - Discard unsaved browser edits, publish the saved widget, verify anonymous no-script rendering and responsive enhancement, and remove fixture data.
 *   - Navigate the actual anonymous Home and Gallery routes, check one published widget on each, and inspect dynamic HTML cache headers.
 *   - Project the reloaded floating placement into protected Home and Gallery previews and compare actual and picker rectangles.
 *   - Verify a published floating widget stays below the real Gallery lightbox and fullscreen controls, follow replaced lightbox image nodes, and report bounded media-load diagnostics on failure.
 * Author: Rudolf Klusal
 * Disposable live-HTTP persistence journey for public content widgets.
 */
(
/** Run the focused widget persistence journey in the owned HTTP fixture. @return {Promise<void>} Writes a bounded fixture result with sanitized resource status and image decode state on media-load failures. */
async () => {
    let stage = 'widget browser login';
    let createdWidgetId = '';
    let anonymousNoScriptFrame = null;
    let anonymousPublicFrame = null;
    let cleanupOwnedWidget = async () => {};
    try {
        const expect = value => { if (!value) throw new Error('Assertion failed'); };
        const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
        const until = async (predicate, deadline = Date.now() + 20000, timeoutDetail = null) => {
            while (Date.now() < deadline) {
                const value = predicate();
                if (value) return value;
                await delay(50);
            }
            const detail = typeof timeoutDetail === 'function' ? timeoutDetail() : '';
            throw new Error('Timed out' + (detail ? ': ' + detail : ''));
        };
        const credentials = JSON.parse(document.querySelector('#credentials').textContent);
        const workflowToken = location.pathname.match(/^\/__workflow_([a-f0-9]{24})\/public-widgets$/)?.[1] || '';
        expect(workflowToken !== '');
        let adminAuthenticated = false;
        const loginAdmin = async () => {
            const loginResponse = await fetch('/index.php?page=admin_login', {credentials: 'same-origin', cache: 'no-store'});
            const loginDocument = new DOMParser().parseFromString(await loginResponse.text(), 'text/html');
            const csrfToken = loginDocument.querySelector('[name="csrf_token"]')?.value || '';
            expect(loginResponse.ok && csrfToken !== '');
            const loginResult = await fetch('/index.php?page=admin_login', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                body: new URLSearchParams({identifier: credentials.username, password: credentials.password, csrf_token: csrfToken}),
            });
            expect(loginResult.ok);
            adminAuthenticated = true;
        };
        const assertAnonymousHtmlCacheHeaders = response => {
            const cacheDirectives = (response.headers.get('cache-control') || '')
                .split(',').map(directive => directive.trim().toLowerCase());
            expect(cacheDirectives.includes('no-cache') && cacheDirectives.includes('must-revalidate')
                && cacheDirectives.includes('max-age=0')
                && response.headers.get('pragma')?.toLowerCase() === 'no-cache'
                && response.headers.get('expires') === '0'
                && /^\d+$/.test(response.headers.get('x-theme-content-revision') || ''));
        };
        const stateUrl = '/__workflow_' + workflowToken + '/preview-state';
        const readWidgetStateHash = async () => {
            const response = await fetch(stateUrl, {cache: 'no-store'});
            if (!response.ok) return '';
            const state = await response.json();
            return typeof state.public_widgets_sha256 === 'string' ? state.public_widgets_sha256 : '';
        };
        stage = 'submit credentials to the real Admin login route';
        await loginAdmin();
        stage = 'capture exact disposable public widget state before mutation';
        const widgetStateBefore = await readWidgetStateHash();
        expect(/^[a-f0-9]{64}$/.test(widgetStateBefore));
        const widgetTitle = 'Workflow browser widget ' + workflowToken;
        const widgetBoldText = 'Persisted browser widget content';
        const widgetContent = 'Persistence payload ' + workflowToken + ' **' + widgetBoldText
            + '** [Safe destination](https://example.test/widget-' + workflowToken + ')';
        const widgetEditorRoute = '/index.php?page=admin_theme&widgets=1&id=new';
        cleanupOwnedWidget = async () => {
            if (anonymousNoScriptFrame instanceof HTMLIFrameElement) {
                anonymousNoScriptFrame.remove();
                anonymousNoScriptFrame = null;
            }
            if (anonymousPublicFrame instanceof HTMLIFrameElement) {
                anonymousPublicFrame.remove();
                anonymousPublicFrame = null;
            }
            let widgetId = createdWidgetId;
            if (!/^[a-f0-9]{32}$/.test(widgetId)) {
                const listResponse = await fetch(widgetEditorRoute, {credentials: 'same-origin', cache: 'no-store'});
                expect(listResponse.ok);
                const listDocument = new DOMParser().parseFromString(await listResponse.text(), 'text/html');
                const ownedLinks = Array.from(listDocument.querySelectorAll('.public-widgets-items a'))
                    .filter(link => link.querySelector('strong')?.textContent?.trim() === widgetTitle);
                expect(ownedLinks.length <= 1);
                const editUrl = ownedLinks.length === 1
                    ? new URL(ownedLinks[0].getAttribute('href') || '', location.href)
                    : null;
                widgetId = editUrl?.searchParams.get('id') || '';
            }
            if (!/^[a-f0-9]{32}$/.test(widgetId)) {
                expect(await readWidgetStateHash() === widgetStateBefore);
                return;
            }
            if (!adminAuthenticated) {
                stage = 'restore the authenticated Admin session for token-owned widget cleanup';
                await loginAdmin();
            }
            const editUrl = new URL(widgetEditorRoute, location.href);
            editUrl.searchParams.set('id', widgetId);
            const editorResponse = await fetch(editUrl.href, {credentials: 'same-origin', cache: 'no-store'});
            expect(editorResponse.ok);
            const editorDocument = new DOMParser().parseFromString(await editorResponse.text(), 'text/html');
            const deleteForm = editorDocument.querySelector('form.public-widgets-form[data-public-widget-editor]');
            expect(deleteForm?.querySelector('[name="widget_id"]')?.value === widgetId);
            const deleteBody = new FormData(deleteForm);
            deleteBody.set('widget_action', 'delete');
            deleteBody.set('confirm_delete', '1');
            const deleteActionUrl = new URL(deleteForm.getAttribute('action') || widgetEditorRoute, location.href);
            const deleteResponse = await fetch(deleteActionUrl.href, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', body: deleteBody,
            });
            const deleteUrl = new URL(deleteResponse.url, location.href);
            expect(deleteResponse.ok && deleteResponse.redirected
                && deleteUrl.searchParams.get('page') === 'admin_theme'
                && deleteUrl.searchParams.get('widgets') === '1'
                && deleteUrl.searchParams.get('id') === 'new');
            createdWidgetId = '';
            expect(await readWidgetStateHash() === widgetStateBefore);
        };
        const applicationFrame = document.querySelector('#application');
        stage = 'open the actual Admin widget form';
        applicationFrame.src = widgetEditorRoute;
        const initialEditorDocument = await until(() => {
            const editor = applicationFrame.contentDocument?.querySelector('[data-public-widget-editor]');
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            return currentUrl.origin === location.origin && currentUrl.searchParams.get('widgets') === '1'
                && editor?.dataset.widgetEnhanced === '1' ? applicationFrame.contentDocument : null;
        });
        const initialForm = initialEditorDocument.querySelector('[data-public-widget-editor]');
        const initialTitle = initialForm.querySelector('[name="title"]');
        const initialContent = initialForm.querySelector('[name="content_md"]');
        const initialStatus = initialForm.querySelector('[name="status"]');
        const initialScope = initialForm.querySelector('[name="page_scope"]');
        const initialPlacement = initialForm.querySelector('[name="placement_mode"]');
        const initialFlowSlot = initialForm.querySelector('[name="flow_slot"]');
        const initialSourceLanguage = initialForm.querySelector('[name="source_language"]');
        const formatBoldButton = initialForm.querySelector('[data-widget-format="bold"]');
        expect(initialTitle && initialContent && initialStatus && initialScope && initialPlacement && initialFlowSlot
            && initialSourceLanguage && formatBoldButton);
        initialTitle.value = widgetTitle;
        initialTitle.dispatchEvent(new applicationFrame.contentWindow.Event('input', {bubbles: true}));
        const widgetDraftPrefix = 'Persistence payload ' + workflowToken + ' ';
        const widgetDraftSuffix = ' [Safe destination](https://example.test/widget-' + workflowToken + ')';
        initialContent.value = widgetDraftPrefix + widgetBoldText + widgetDraftSuffix;
        initialContent.setSelectionRange(widgetDraftPrefix.length, widgetDraftPrefix.length + widgetBoldText.length);
        initialContent.dispatchEvent(new applicationFrame.contentWindow.Event('input', {bubbles: true}));
        formatBoldButton.click();
        expect(initialContent.value === widgetContent);
        initialStatus.value = 'draft';
        initialStatus.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        initialScope.value = 'all';
        initialScope.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        initialPlacement.value = 'flow';
        initialPlacement.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        initialFlowSlot.value = 'home_before_grid';
        initialFlowSlot.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        initialSourceLanguage.value = 'sv';
        initialSourceLanguage.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        const forbiddenWidgetContent = widgetContent + '\n[Forbidden target](javascript:alert(1))';
        initialContent.value = forbiddenWidgetContent;
        initialContent.dispatchEvent(new applicationFrame.contentWindow.Event('input', {bubbles: true}));
        const openEditorUrl = applicationFrame.contentWindow.location.href;
        const switchAdminLocale = async localeCode => {
            const themeResponse = await fetch('/index.php?page=admin_theme', {
                credentials: 'same-origin', cache: 'no-store',
            });
            const themeDocument = new DOMParser().parseFromString(await themeResponse.text(), 'text/html');
            const themeForm = themeDocument.querySelector('[data-theme-form]');
            const adminLanguage = themeForm?.querySelector('[name="cms_language"]');
            const csrfToken = themeForm?.querySelector('[name="csrf_token"]')?.value || '';
            expect(themeResponse.ok && themeForm instanceof HTMLFormElement
                && adminLanguage instanceof HTMLSelectElement && csrfToken !== '');
            const languagePayload = new FormData(themeForm);
            languagePayload.set('cms_language', localeCode);
            languagePayload.set('theme_controls_changed', '0');
            const languageResponse = await fetch('/index.php?page=admin_theme', {
                method: 'POST', body: languagePayload, credentials: 'same-origin', cache: 'no-store',
            });
            const languageDocument = new DOMParser().parseFromString(await languageResponse.text(), 'text/html');
            const selectedAdminLanguage = languageDocument.querySelector('[name="cms_language"] option[selected]');
            expect(languageResponse.ok && languageResponse.redirected
                && new URL(languageResponse.url).searchParams.get('page') === 'admin_theme'
                && languageDocument.documentElement.lang === localeCode
                && selectedAdminLanguage?.value === localeCode);
            return languageDocument;
        };
        for (const locale of [
            {code: 'en', previewLabel: 'Preview without saving'},
            {code: 'de', previewLabel: 'Vorschau ohne Speichern'},
            {code: 'sv', previewLabel: 'Förhandsvisa utan att spara'},
            {code: 'cs', previewLabel: 'Náhled bez uložení'},
        ]) {
            stage = 'switch the authenticated Admin locale to ' + locale.code + ' while retaining the formatted widget draft';
            await switchAdminLocale(locale.code);
            const localizedEditorResponse = await fetch(openEditorUrl, {credentials: 'same-origin', cache: 'no-store'});
            const localizedEditorDocument = new DOMParser().parseFromString(await localizedEditorResponse.text(), 'text/html');
            expect(localizedEditorResponse.ok && localizedEditorDocument.documentElement.lang === locale.code
                && localizedEditorDocument.querySelector('[data-public-widget-editor] button[value="preview"]')
                    ?.textContent?.trim() === locale.previewLabel);
            expect(applicationFrame.contentDocument === initialEditorDocument
                && applicationFrame.contentWindow.location.href === openEditorUrl
                && initialTitle.value === widgetTitle && initialContent.value === forbiddenWidgetContent);
            expect(await readWidgetStateHash() === widgetStateBefore);
        }
        stage = 'reject the formatted widget draft and retain it with Czech validation feedback';
        const createButton = initialForm.querySelector('button[type="submit"][name="widget_action"][value="create"]');
        expect(createButton);
        createButton.click();
        const rejectedEditorDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const form = currentDocument?.querySelector('[data-public-widget-editor]');
            const error = currentDocument?.querySelector('[data-widget-error-field="content_md"]');
            return currentDocument !== initialEditorDocument && currentUrl.searchParams.get('widgets') === '1'
                && error && form?.dataset.widgetEnhanced === '1' ? currentDocument : null;
        });
        const rejectedForm = rejectedEditorDocument.querySelector('[data-public-widget-editor]');
        const rejectedError = rejectedEditorDocument.querySelector('[data-widget-error-field="content_md"]');
        expect(rejectedEditorDocument.documentElement.lang === 'cs'
            && rejectedForm.querySelector('[name="title"]')?.value === widgetTitle
            && rejectedForm.querySelector('[name="content_md"]')?.value === forbiddenWidgetContent
            && rejectedForm.querySelector('[name="source_language"]')?.value === 'sv'
            && rejectedError?.textContent?.trim() === 'Použijte cestu v rámci webu nebo adresu HTTP(S).');
        expect(await readWidgetStateHash() === widgetStateBefore);
        const retryContent = rejectedForm.querySelector('[name="content_md"]');
        const retryCreateButton = rejectedForm.querySelector('button[type="submit"][name="widget_action"][value="create"]');
        const previewButton = rejectedForm.querySelector('button[type="submit"][name="widget_action"][value="preview"]');
        const previewStatus = rejectedEditorDocument.querySelector('[data-widget-preview] [role="status"]');
        const rejectedEditorUrl = applicationFrame.contentWindow.location.href;
        expect(retryContent instanceof applicationFrame.contentWindow.HTMLTextAreaElement
            && retryCreateButton && previewButton && previewStatus);
        stage = 'reject the unsafe draft through the separate live Preview without saving action';
        previewButton.click();
        await until(() => previewStatus.textContent.trim()
            === 'Použijte cestu v rámci webu nebo adresu HTTP(S).');
        expect(applicationFrame.contentDocument === rejectedEditorDocument
            && applicationFrame.contentWindow.location.href === rejectedEditorUrl
            && retryContent.value === forbiddenWidgetContent
            && rejectedForm.querySelector('[name="source_language"]')?.value === 'sv'
            && await readWidgetStateHash() === widgetStateBefore);
        stage = 'render the corrected formatted draft through Preview without saving';
        retryContent.value = widgetContent;
        retryContent.dispatchEvent(new applicationFrame.contentWindow.Event('input', {bubbles: true}));
        previewButton.click();
        await until(() => previewStatus.textContent.trim()
            === 'Pouze náhled. Žádné změny widgetu nebyly uloženy.');
        const livePreviewBody = rejectedEditorDocument.querySelector('[data-widget-preview-body]');
        const livePreviewTitle = rejectedEditorDocument.querySelector('[data-widget-preview] .public-content-widget-title');
        expect(applicationFrame.contentDocument === rejectedEditorDocument
            && applicationFrame.contentWindow.location.href === rejectedEditorUrl
            && retryContent.value === widgetContent
            && rejectedForm.querySelector('[name="source_language"]')?.value === 'sv'
            && livePreviewTitle?.textContent === widgetTitle
            && livePreviewBody?.querySelector('strong')?.textContent === widgetBoldText
            && livePreviewBody?.querySelector('a')?.href === 'https://example.test/widget-' + workflowToken
            && await readWidgetStateHash() === widgetStateBefore);
        stage = 'create the corrected widget draft with the real Admin form submit button';
        retryCreateButton.click();
        const createdEditorDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const editor = currentDocument?.querySelector('[data-public-widget-editor]');
            return currentDocument !== rejectedEditorDocument && currentUrl.searchParams.get('widgets') === '1'
                && /^[a-f0-9]{32}$/.test(currentUrl.searchParams.get('id') || '')
                && editor?.dataset.widgetEnhanced === '1' ? currentDocument : null;
        });
        createdWidgetId = new URL(applicationFrame.contentWindow.location.href).searchParams.get('id') || '';
        const savedWidgetFormMatches = (form, expectedStatus, expectedPlacement) => form.querySelector('[name="title"]')?.value === widgetTitle
            && form.querySelector('[name="content_md"]')?.value === widgetContent
            && form.querySelector('[name="source_language"]')?.value === 'sv'
            && form.querySelector('[name="status"]')?.value === expectedStatus
            && form.querySelector('[name="page_scope"]')?.value === 'all'
            && form.querySelector('[name="placement_mode"]')?.value === expectedPlacement
            && form.querySelector('[name="flow_slot"]')?.value === 'home_before_grid';
        expect(/^[a-f0-9]{32}$/.test(createdWidgetId)
            && savedWidgetFormMatches(createdEditorDocument.querySelector('[data-public-widget-editor]'), 'draft', 'flow'));
        stage = 'reload and reopen the saved draft';
        applicationFrame.contentWindow.location.reload();
        let reopenedDraftDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const editor = currentDocument?.querySelector('[data-public-widget-editor]');
            return currentDocument !== createdEditorDocument && currentUrl.searchParams.get('id') === createdWidgetId
                && editor?.dataset.widgetEnhanced === '1' ? currentDocument : null;
        });
        let reopenedDraftForm = reopenedDraftDocument.querySelector('[data-public-widget-editor]');
        expect(reopenedDraftDocument.documentElement.lang === 'cs'
            && savedWidgetFormMatches(reopenedDraftForm, 'draft', 'flow'));
        const savedDraftStateHash = await readWidgetStateHash();
        expect(/^[a-f0-9]{64}$/.test(savedDraftStateHash));
        stage = 'switch Admin locale to German and reload the saved Swedish-content flow draft';
        await switchAdminLocale('de');
        const previousDraftDocument = reopenedDraftDocument;
        applicationFrame.contentWindow.location.reload();
        reopenedDraftDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const editor = currentDocument?.querySelector('[data-public-widget-editor]');
            return currentDocument !== previousDraftDocument && currentDocument?.documentElement.lang === 'de'
                && currentUrl.searchParams.get('id') === createdWidgetId
                && editor?.dataset.widgetEnhanced === '1' ? currentDocument : null;
        });
        reopenedDraftForm = reopenedDraftDocument.querySelector('[data-public-widget-editor]');
        expect(savedWidgetFormMatches(reopenedDraftForm, 'draft', 'flow')
            && await readWidgetStateHash() === savedDraftStateHash);
        const discardLink = reopenedDraftForm.querySelector('a.button.secondary[href]');
        const discardUrl = new URL(discardLink?.getAttribute('href') || '', location.href);
        expect(discardLink && discardUrl.origin === location.origin
            && discardUrl.searchParams.get('id') === createdWidgetId);
        const discardedTitle = reopenedDraftForm.querySelector('[name="title"]');
        const discardedContent = reopenedDraftForm.querySelector('[name="content_md"]');
        discardedTitle.value = 'Unsaved browser title ' + workflowToken;
        discardedTitle.dispatchEvent(new applicationFrame.contentWindow.Event('input', {bubbles: true}));
        discardedContent.value = 'Unsaved browser content ' + workflowToken;
        discardedContent.dispatchEvent(new applicationFrame.contentWindow.Event('input', {bubbles: true}));
        expect(discardedTitle.value !== widgetTitle && discardedContent.value !== widgetContent);
        stage = 'click Discard in the real Admin editor and verify the saved draft is restored';
        discardLink.click();
        const discardedDraftDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const editor = currentDocument?.querySelector('[data-public-widget-editor]');
            return currentDocument !== reopenedDraftDocument && currentUrl.searchParams.get('id') === createdWidgetId
                && editor?.dataset.widgetEnhanced === '1' ? currentDocument : null;
        });
        const discardedDraftForm = discardedDraftDocument.querySelector('[data-public-widget-editor]');
        expect(savedWidgetFormMatches(discardedDraftForm, 'draft', 'flow'));
        const discardedDraftStateHash = await readWidgetStateHash();
        expect(discardedDraftStateHash === savedDraftStateHash);
        stage = 'publish the saved draft through the Admin save button';
        const publishedStatus = discardedDraftForm.querySelector('[name="status"]');
        const publishedPlacement = discardedDraftForm.querySelector('[name="placement_mode"]');
        const saveButton = discardedDraftForm.querySelector('button[type="submit"][name="widget_action"][value="save"]');
        expect(publishedStatus instanceof applicationFrame.contentWindow.HTMLSelectElement
            && publishedPlacement instanceof applicationFrame.contentWindow.HTMLSelectElement);
        publishedStatus.value = 'published';
        publishedStatus.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        // Keep the locale-validation journey on its original flow placement, then publish this record as floating for the visitor-rendering integration check.
        publishedPlacement.value = 'floating';
        publishedPlacement.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        const publishedAnchor = discardedDraftForm.querySelector('[name="floating_anchor"]');
        const publishedWidth = discardedDraftForm.querySelector('[name="width_px"]');
        expect(publishedAnchor instanceof applicationFrame.contentWindow.HTMLSelectElement
            && publishedWidth instanceof applicationFrame.contentWindow.HTMLInputElement);
        publishedAnchor.value = 'middle-right';
        publishedAnchor.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        publishedWidth.value = '480';
        publishedWidth.dispatchEvent(new applicationFrame.contentWindow.Event('input', {bubbles: true}));
        publishedWidth.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
        saveButton.click();
        const savedPublishedDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const editor = currentDocument?.querySelector('[data-public-widget-editor]');
            return currentDocument !== discardedDraftDocument && currentUrl.searchParams.get('id') === createdWidgetId
                && editor?.dataset.widgetEnhanced === '1' ? currentDocument : null;
        });
        expect(savedWidgetFormMatches(savedPublishedDocument.querySelector('[data-public-widget-editor]'), 'published', 'floating')
            && savedPublishedDocument.querySelector('[name="placement_mode"]')?.value === 'floating'
            && savedPublishedDocument.querySelector('[name="floating_anchor"]')?.value === 'middle-right'
            && savedPublishedDocument.querySelector('[name="width_px"]')?.value === '480');
        stage = 'reload the published editor and verify the persisted publication state';
        applicationFrame.contentWindow.location.reload();
        const reopenedPublishedDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const editor = currentDocument?.querySelector('[data-public-widget-editor]');
            return currentDocument !== savedPublishedDocument && currentUrl.searchParams.get('id') === createdWidgetId
                && editor?.dataset.widgetEnhanced === '1' ? currentDocument : null;
        });
        const reopenedPublishedForm = reopenedPublishedDocument.querySelector('[data-public-widget-editor]');
        expect(savedWidgetFormMatches(reopenedPublishedForm, 'published', 'floating')
            && reopenedPublishedForm.querySelector('[name="placement_mode"]')?.value === 'floating'
            && reopenedPublishedForm.querySelector('[name="floating_anchor"]')?.value === 'middle-right'
            && reopenedPublishedForm.querySelector('[name="width_px"]')?.value === '480');
        stage = 'verify the saved floating rectangle in the protected Home and Gallery previews after reload';
        const previewHost = reopenedPublishedDocument.querySelector('[data-widget-theme-preview]');
        const previewFrame = previewHost?.querySelector('iframe.public-widgets-theme-frame');
        const previewPageSelect = reopenedPublishedDocument.querySelector('[data-widget-preview-page-selector]');
        const placementStage = reopenedPublishedDocument.querySelector('.public-widgets-placement-stage');
        const placementMarker = placementStage?.querySelector('.public-widgets-position-marker');
        expect(previewHost && previewFrame && previewPageSelect && placementStage && placementMarker);
        let savedPreviewLayout = null;
        reopenedPublishedForm.addEventListener('public-widget-placement-geometry', event => {
            savedPreviewLayout = event.detail && typeof event.detail === 'object' ? event.detail : null;
        });
        // Compare the persisted settings with both the real preview solver rectangle and its placement-map projection.
        const verifySavedPlacementPreview = async page => {
            stage = 'verify the saved floating rectangle in the protected ' + page + ' preview after reload';
            const savedPreviewDeadline = Date.now() + 20000;
            savedPreviewLayout = null;
            previewPageSelect.value = page;
            previewPageSelect.dispatchEvent(new applicationFrame.contentWindow.Event('change', {bubbles: true}));
            const projection = await until(() => {
                const layout = savedPreviewLayout;
                const previewDocument = previewFrame.contentDocument;
                const previewWindow = previewFrame.contentWindow;
                const draft = previewDocument?.querySelector('[data-widget-theme-draft="1"]');
                const placement = layout?.placements?.find(item => item.draft);
                const pageMarker = page === 'home'
                    ? previewDocument?.querySelector('main.site-main .gallery-list-frame')
                    : previewDocument?.querySelector('main.site-main .hero[data-public-gallery-id]');
                const previewUrl = previewWindow ? new URL(previewWindow.location.href) : null;
                return layout?.page === page && layout.device === 'desktop' && layout.geometry?.supported === true
                    && layout.draftPositioned === true && placement?.rect && draft?.isConnected
                    && draft.classList.contains('is-public-widget-floating') && draft.dataset.publicWidgetId === createdWidgetId
                    && pageMarker && previewDocument?.body?.classList.contains('public-page')
                    && (previewWindow.visualViewport ? previewWindow.visualViewport.height : previewWindow.innerHeight)
                        === layout.geometry.viewportHeight
                    && previewUrl?.searchParams.get('preview') === 'visual'
                    && previewUrl.searchParams.get('view_as') === 'anonymous'
                    && previewPageSelect.value === page && placementStage.dataset.device === layout.device
                    && placementStage.dataset.mode === 'floating' && placementStage.dataset.geometry === 'measured'
                    && !placementMarker.hidden ? {layout, placement, draft} : null;
            }, savedPreviewDeadline, () => {
                const previewDocument = previewFrame.contentDocument;
                const previewWindow = previewFrame.contentWindow;
                const previewUrl = previewWindow ? new URL(previewWindow.location.href) : null;
                const observedDraft = previewDocument?.querySelector('[data-widget-theme-draft="1"]');
                const observedPlacement = savedPreviewLayout?.placements?.find(item => item.draft);
                const viewportHeight = previewWindow?.visualViewport
                    ? previewWindow.visualViewport.height : previewWindow?.innerHeight;
                // Keep these bits in the same order as the readiness predicate.
                const guardBits = [
                    Number(Boolean(observedPlacement?.rect)),
                    Number(Boolean(observedDraft?.classList.contains('is-public-widget-floating'))),
                    Number(observedDraft?.dataset.publicWidgetId === createdWidgetId),
                    Number(viewportHeight === savedPreviewLayout?.geometry?.viewportHeight),
                    Number(placementStage.dataset.device === savedPreviewLayout?.device),
                    Number(placementStage.dataset.mode === 'floating'),
                    Number(!placementMarker.hidden),
                ];
                return 'layout-page-' + (savedPreviewLayout?.page || 'missing')
                    + ' layout-device-' + (savedPreviewLayout?.device || 'missing')
                    + ' geometry-supported-' + Number(savedPreviewLayout?.geometry?.supported === true)
                    + ' positioned-' + Number(savedPreviewLayout?.draftPositioned === true)
                    + ' draft-' + Number(Boolean(previewDocument?.querySelector('[data-widget-theme-draft="1"]')))
                    + ' page-marker-' + Number(Boolean(page === 'home'
                        ? previewDocument?.querySelector('main.site-main .gallery-list-frame')
                        : previewDocument?.querySelector('main.site-main .hero[data-public-gallery-id]')))
                    + ' preview-' + Number(previewUrl?.searchParams.get('preview') === 'visual')
                    + ' audience-' + Number(previewUrl?.searchParams.get('view_as') === 'anonymous')
                    + ' selected-' + (previewPageSelect.value || 'missing')
                    + ' map-' + (placementStage.dataset.geometry || 'missing')
                    + ' client-' + (previewDocument?.documentElement.clientWidth || 0)
                    + '/' + (savedPreviewLayout?.geometry?.viewportWidth || 0)
                    + ' inner-' + (previewWindow?.innerWidth || 0)
                    + ' guards-' + guardBits.join('');
            });
            const geometry = projection.layout.geometry;
            const expected = projection.placement.rect;
            const actual = projection.draft.getBoundingClientRect();
            const marker = placementMarker.getBoundingClientRect();
            const stageBounds = placementStage.getBoundingClientRect();
            const stageOrigin = {
                left: stageBounds.left + placementStage.clientLeft,
                top: stageBounds.top + placementStage.clientTop,
            };
            const expectedMarker = {
                left: stageOrigin.left + expected.left / geometry.viewportWidth * placementStage.clientWidth,
                top: stageOrigin.top + expected.top / geometry.viewportHeight * placementStage.clientHeight,
                width: expected.width / geometry.viewportWidth * placementStage.clientWidth,
                height: expected.height / geometry.viewportHeight * placementStage.clientHeight,
            };
            const draftFieldsMatchSavedForm = projection.draft.dataset.publicWidgetFloating === '1'
                && projection.draft.dataset.publicWidgetAnchor === reopenedPublishedForm.querySelector('[name="floating_anchor"]')?.value
                && projection.draft.dataset.publicWidgetWidth === reopenedPublishedForm.querySelector('[name="width_px"]')?.value
                && projection.draft.dataset.publicWidgetX === reopenedPublishedForm.querySelector('[name="x_permille"]')?.value
                && projection.draft.dataset.publicWidgetY === reopenedPublishedForm.querySelector('[name="y_permille"]')?.value;
            const draftDeltas = [actual.left - expected.left, actual.top - expected.top,
                actual.width - expected.width, actual.height - expected.height];
            const markerDeltas = [marker.left - expectedMarker.left, marker.top - expectedMarker.top,
                marker.width - expectedMarker.width, marker.height - expectedMarker.height];
            const draftMatches = draftDeltas.every(delta => Math.abs(delta) <= 1.5);
            const markerMatches = markerDeltas.every(delta => Math.abs(delta) <= 1.5);
            if (!draftFieldsMatchSavedForm || !draftMatches || !markerMatches) {
                const formatDeltas = deltas => deltas.map(delta => (Math.round(delta * 100) / 100).toFixed(2)).join('_');
                stage = 'compare saved floating rectangles in protected ' + page + ' preview after reload';
                throw new Error('placement fields-' + Number(draftFieldsMatchSavedForm)
                    + ' draft-deltas-' + formatDeltas(draftDeltas) + ' marker-deltas-' + formatDeltas(markerDeltas));
            }
        };
        await verifySavedPlacementPreview('home');
        await verifySavedPlacementPreview('gallery');
        stage = 'verify the published floating widget in a fresh anonymous server-rendered Home response';
        const homeRoute = '/index.php?page=home';
        const publicResponse = await fetch(homeRoute, {credentials: 'omit', cache: 'no-store'});
        const publicHtml = await publicResponse.text();
        const publicDocument = new DOMParser().parseFromString(publicHtml, 'text/html');
        const publicUrl = new URL(publicResponse.url, location.href);
        const homeWidgets = publicDocument.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]');
        const publicWidget = homeWidgets[0];
        const publicZone = publicWidget?.closest('[data-public-widget-zone]');
        expect(publicResponse.status === 200 && publicUrl.origin === location.origin
            && publicUrl.searchParams.get('page') === 'home' && publicDocument.body?.classList.contains('public-page')
            && homeWidgets.length === 1
            && publicWidget?.querySelector('.public-content-widget-title')?.textContent === widgetTitle
            && publicWidget?.querySelector('.public-content-widget-body strong')?.textContent === widgetBoldText
            && publicWidget?.querySelector('.public-content-widget-body a')?.href
                === 'https://example.test/widget-' + workflowToken
            && publicWidget?.dataset.publicWidgetFloating === '1'
            && publicZone?.dataset.publicWidgetZone === 'floating'
            && !publicWidget.hasAttribute('data-widget-theme-draft'));
        assertAnonymousHtmlCacheHeaders(publicResponse);
        stage = 'load the anonymous Home response with scripts disabled and verify server-rendered flow output';
        anonymousNoScriptFrame = document.createElement('iframe');
        anonymousNoScriptFrame.title = 'Isolated anonymous public widget no-script rendering check';
        anonymousNoScriptFrame.setAttribute('aria-hidden', 'true');
        anonymousNoScriptFrame.setAttribute('sandbox', 'allow-same-origin');
        anonymousNoScriptFrame.setAttribute('referrerpolicy', 'same-origin');
        anonymousNoScriptFrame.tabIndex = -1;
        anonymousNoScriptFrame.style.cssText = 'position:fixed;left:-1400px;top:0;width:1280px;height:900px;border:0;pointer-events:none';
        document.body.append(anonymousNoScriptFrame);
        anonymousNoScriptFrame.srcdoc = publicHtml;
        const noScriptPublicWidget = await until(() => {
            const frameDocument = anonymousNoScriptFrame?.contentDocument;
            const matchingWidgets = frameDocument
                ? frameDocument.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]') : [];
            const widget = matchingWidgets[0];
            const widgetStyle = widget && frameDocument?.defaultView?.getComputedStyle(widget);
            const widgetBounds = widget?.getBoundingClientRect();
            return frameDocument?.readyState === 'complete' && matchingWidgets.length === 1
                && frameDocument.body?.classList.contains('public-page')
                && widget?.tagName === 'ARTICLE'
                && widget.querySelector('.public-content-widget-title')?.textContent === widgetTitle
                && widget.querySelector('.public-content-widget-body a')?.href
                    === 'https://example.test/widget-' + workflowToken
                && !widget.classList.contains('is-public-widget-floating')
                && !widget.hidden && widgetStyle?.position !== 'fixed'
                && widgetStyle?.display !== 'none' && widgetStyle?.visibility !== 'hidden'
                && widgetBounds?.width > 0 && widgetBounds?.height > 0
                ? widget : null;
        });
        expect(anonymousNoScriptFrame.getAttribute('sandbox') === 'allow-same-origin'
            && noScriptPublicWidget.dataset.publicWidgetFloating === '1');
        stage = 'load the anonymous Home response with production assets and verify desktop floating enhancement';
        anonymousPublicFrame = document.createElement('iframe');
        anonymousPublicFrame.title = 'Isolated anonymous public widget rendering check';
        anonymousPublicFrame.setAttribute('aria-hidden', 'true');
        anonymousPublicFrame.tabIndex = -1;
        anonymousPublicFrame.style.cssText = 'position:fixed;left:-1400px;top:0;width:1280px;height:900px;border:0;pointer-events:none';
        document.body.append(anonymousPublicFrame);
        anonymousPublicFrame.srcdoc = publicHtml;
        const desktopPublicWidget = await until(() => {
            const frameDocument = anonymousPublicFrame?.contentDocument;
            const matchingWidgets = frameDocument
                ? frameDocument.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]') : [];
            const widget = matchingWidgets[0];
            return frameDocument?.readyState === 'complete' && matchingWidgets.length === 1
                && widget?.classList.contains('is-public-widget-floating')
                && frameDocument.defaultView?.getComputedStyle(widget).position === 'fixed'
                ? widget : null;
        });
        expect(desktopPublicWidget.tagName === 'ARTICLE'
            && desktopPublicWidget.querySelector('.public-content-widget-title')?.textContent === widgetTitle
            && desktopPublicWidget.querySelector('.public-content-widget-body a')?.href
                === 'https://example.test/widget-' + workflowToken);
        stage = 'resize the anonymous Home document to mobile width and verify the same widget returns to flow';
        anonymousPublicFrame.style.width = '390px';
        anonymousPublicFrame.style.height = '740px';
        await until(() => {
            const frameDocument = anonymousPublicFrame?.contentDocument;
            const currentWidget = frameDocument?.querySelector('[data-public-widget-id="' + createdWidgetId + '"]');
            const widgetStyle = currentWidget && frameDocument?.defaultView?.getComputedStyle(currentWidget);
            const widgetBounds = currentWidget?.getBoundingClientRect();
            return frameDocument?.documentElement.clientWidth >= 320
                && frameDocument.documentElement.clientWidth < 960
                && currentWidget === desktopPublicWidget
                && !currentWidget.classList.contains('is-public-widget-floating')
                && !currentWidget.hidden
                && widgetStyle?.position !== 'fixed'
                && widgetStyle?.display !== 'none'
                && widgetStyle?.visibility !== 'hidden'
                && widgetBounds?.width > 0 && widgetBounds?.height > 0
                ? currentWidget : null;
        });
        const mobilePublicDocument = anonymousPublicFrame.contentDocument;
        const mobilePublicWidgets = mobilePublicDocument.querySelectorAll(
            '[data-public-widget-id="' + createdWidgetId + '"]');
        expect(mobilePublicWidgets.length === 1 && mobilePublicWidgets[0] === desktopPublicWidget
            && mobilePublicWidgets[0].textContent.includes(widgetTitle));
        stage = 'load the anonymous Gallery page with its published floating widget and production viewer';
        const galleryRoute = '/index.php?page=gallery&gallery_path=seed';
        const galleryResponse = await fetch(galleryRoute, {
            credentials: 'omit', cache: 'no-store',
        });
        const galleryHtml = await galleryResponse.text();
        const galleryUrl = new URL(galleryResponse.url, location.href);
        const galleryServerDocument = new DOMParser().parseFromString(galleryHtml, 'text/html');
        const galleryWidgets = galleryServerDocument.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]');
        const galleryServerWidget = galleryWidgets[0];
        expect(galleryResponse.status === 200 && galleryUrl.origin === location.origin
            && galleryUrl.searchParams.get('page') === 'gallery'
            && galleryServerDocument.body?.classList.contains('public-page')
            && galleryWidgets.length === 1
            && galleryServerWidget?.dataset.publicWidgetFloating === '1'
            && galleryServerWidget.closest('[data-public-widget-zone]')?.dataset.publicWidgetZone === 'floating'
            && !galleryServerWidget.hasAttribute('data-widget-theme-draft')
            && galleryServerDocument.querySelectorAll('[data-lightbox-image]').length >= 2
            && galleryServerDocument.querySelector('[data-lightbox-config]'));
        assertAnonymousHtmlCacheHeaders(galleryResponse);
        stage = 'end the Admin session before opening the actual anonymous Gallery route';
        const logoutResponse = await fetch('/index.php?page=admin_logout', {credentials: 'same-origin', cache: 'no-store'});
        const logoutUrl = new URL(logoutResponse.url, location.href);
        expect(logoutResponse.ok && logoutResponse.redirected && logoutUrl.origin === location.origin);
        adminAuthenticated = false;
        const adminRouteAfterLogout = await fetch(widgetEditorRoute, {credentials: 'same-origin', cache: 'no-store'});
        const adminRouteAfterLogoutUrl = new URL(adminRouteAfterLogout.url, location.href);
        expect(adminRouteAfterLogout.ok && adminRouteAfterLogout.redirected
            && adminRouteAfterLogoutUrl.searchParams.get('page') === 'admin_login');

        applicationFrame.style.cssText = 'position:fixed;inset:0;width:1280px;height:900px;border:0';
        applicationFrame.allow = 'fullscreen';
        applicationFrame.allowFullscreen = true;
        applicationFrame.src = galleryRoute;
        const liveGalleryDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const widget = currentDocument?.querySelector('[data-public-widget-id="' + createdWidgetId + '"]');
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const navigationEntry = currentDocument?.defaultView?.performance?.getEntriesByType('navigation')?.[0];
            return currentDocument?.readyState === 'complete'
                && currentUrl.origin === location.origin && currentUrl.pathname === '/index.php'
                && currentUrl.searchParams.get('page') === 'gallery'
                && currentUrl.searchParams.get('gallery_path') === 'seed'
                && navigationEntry?.type === 'navigate' && navigationEntry.responseStatus === 200
                && currentDocument.defaultView?.PHPGalleryTelemetryLightboxOwner === 'native'
                && widget?.classList.contains('is-public-widget-floating')
                && currentDocument.querySelectorAll('[data-lightbox-image]').length >= 2
                ? currentDocument : null;
        });
        const liveGalleryWindow = liveGalleryDocument.defaultView;
        const liveGalleryWidget = liveGalleryDocument.querySelector('[data-public-widget-id="' + createdWidgetId + '"]');
        const liveGalleryWidgetBounds = liveGalleryWidget.getBoundingClientRect();
        expect(liveGalleryDocument.body.classList.contains('public-page')
            && liveGalleryWidget.dataset.publicWidgetFloating === '1'
            && liveGalleryWidget.dataset.publicWidgetAnchor === 'middle-right'
            && !liveGalleryWidget.hasAttribute('data-widget-theme-draft')
            && liveGalleryWindow.getComputedStyle(liveGalleryWidget).position === 'fixed'
            && liveGalleryWidgetBounds.width > 0 && liveGalleryWidgetBounds.height > 0);
        stage = 'locate the first production Gallery photo activation link';
        const firstGalleryPhoto = liveGalleryDocument.querySelector('.image-preview-link');
        expect(firstGalleryPhoto);
        const firstLightboxTarget = firstGalleryPhoto.closest('[data-lightbox-image], [data-lightbox-source]');
        expect(firstLightboxTarget);
        stage = 'verify the first real Gallery photo exposes an authorized lightbox source';
        expect(Boolean(firstLightboxTarget.dataset.previewSrc || firstLightboxTarget.dataset.fullSrc));
        stage = 'open the real Gallery lightbox overlay after selecting a photo';
        firstLightboxTarget.click();
        const liveLightbox = await until(() => {
            const overlay = liveGalleryDocument.querySelector('[data-lightbox]');
            return overlay && !overlay.hidden ? overlay : null;
        });
        const currentLightboxImage = () => liveLightbox.querySelector('[data-lightbox-img]');
        stage = 'load the first real Gallery lightbox image';
        await until(() => {
            const image = currentLightboxImage();
            return image?.isConnected && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0;
        }, undefined, () => {
            const describeResource = source => {
                if (!source) return 'absent';
                try {
                    const sourceUrl = new URL(source, liveGalleryWindow.location.href);
                    const resource = liveGalleryWindow.performance.getEntriesByName(sourceUrl.href).at(-1);
                    const page = sourceUrl.searchParams.get('page') || '';
                    const size = sourceUrl.searchParams.get('size') || '';
                    const kind = page === 'media' ? 'media'
                        : (size !== '' || /\/thumb(?:\/|$)/i.test(sourceUrl.pathname) ? 'thumb' : 'route');
                    const safeSize = /^(?:small|medium|large|original|[0-9]{1,5})$/i.test(size) ? size : 'other';
                    const status = Number(resource?.responseStatus || 0);
                    const encoded = Math.min(99999999, Math.max(0, Number(resource?.encodedBodySize || 0)));
                    const decoded = Math.min(99999999, Math.max(0, Number(resource?.decodedBodySize || 0)));
                    return kind + '-' + safeSize + '-s' + status + '-e' + encoded + '-d' + decoded;
                } catch (_) {
                    return 'invalid-url';
                }
            };
            const classifyLiveSource = source => {
                if (!source) return 'absent';
                try {
                    const resolved = new URL(source, liveGalleryWindow.location.href).href;
                    if (resolved === new URL(firstLightboxTarget.dataset.fullSrc, liveGalleryWindow.location.href).href) return 'full';
                    if (resolved === new URL(firstLightboxTarget.dataset.previewSrc, liveGalleryWindow.location.href).href) return 'preview';
                    return 'other';
                } catch (_) {
                    return 'invalid';
                }
            };
            const preview = firstLightboxTarget.dataset.previewSrc || '';
            const full = firstLightboxTarget.dataset.fullSrc || '';
            const liveLightboxImage = currentLightboxImage();
            const currentSource = liveLightboxImage?.currentSrc || '';
            const assignedSource = liveLightboxImage?.getAttribute('src') || '';
            const complete = Boolean(liveLightboxImage?.isConnected && liveLightboxImage.complete);
            const width = Math.min(99999, Math.max(0, Number(liveLightboxImage?.naturalWidth || 0)));
            const height = Math.min(99999, Math.max(0, Number(liveLightboxImage?.naturalHeight || 0)));
            return 'p-' + describeResource(preview)
                + ' f-' + describeResource(full)
                + ' live-' + classifyLiveSource(currentSource)
                + ' attr-' + classifyLiveSource(assignedSource)
                + ' done-' + Number(complete)
                + ' w' + width + '-h' + height;
        });
        const lightboxHitTarget = element => {
            const bounds = element.getBoundingClientRect();
            if (bounds.width <= 0 || bounds.height <= 0) return null;
            return liveGalleryDocument.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
        };
        const isLightboxHitTarget = element => {
            const target = lightboxHitTarget(element);
            return target instanceof liveGalleryWindow.Element && (target === element || element.contains(target));
        };
        const widgetCenterTarget = () => {
            const currentBounds = liveGalleryWidget.getBoundingClientRect();
            return liveGalleryDocument.elementFromPoint(
                currentBounds.left + currentBounds.width / 2,
                currentBounds.top + currentBounds.height / 2);
        };
        const initialStage = liveLightbox.querySelector('[data-lightbox-stage]');
        const initialNext = liveLightbox.querySelector('.lightbox-next');
        expect(!liveLightbox.hidden && liveGalleryDocument.body.classList.contains('has-lightbox')
            && liveLightbox.contains(widgetCenterTarget())
            && isLightboxHitTarget(initialStage) && isLightboxHitTarget(initialNext));
        stage = 'zoom the actual Gallery photo while the floating widget is behind the viewer';
        const initialZoomIn = Array.from(liveLightbox.querySelectorAll('[data-lightbox-action="zoom-in"]'))
            .find(button => button.getBoundingClientRect().width > 0 && button.getBoundingClientRect().height > 0);
        const initialZoomStatus = liveLightbox.querySelector('[data-lightbox-zoom-status]');
        const initialZoomBefore = initialZoomStatus?.textContent;
        expect(initialZoomIn && isLightboxHitTarget(initialZoomIn));
        initialZoomIn.click();
        await until(() => initialZoomStatus?.textContent !== initialZoomBefore);
        expect(liveLightbox.classList.contains('is-zoomed'));
        stage = 'advance the actual Gallery viewer without widget interception';
        const nextSourceBefore = liveLightbox.querySelector('[data-lightbox-img]').currentSrc;
        expect(isLightboxHitTarget(initialNext));
        initialNext.click();
        await until(() => liveLightbox.querySelector('[data-lightbox-img]').currentSrc !== nextSourceBefore);
        expect(liveLightbox.contains(widgetCenterTarget()));
        stage = 'enter native fullscreen from the actual Gallery control';
        const fullscreenControl = liveLightbox.querySelector('.lightbox-toolbar [data-lightbox-action="fullscreen"]');
        const fullscreenBounds = fullscreenControl?.getBoundingClientRect();
        const frameBounds = liveGalleryWindow.frameElement?.getBoundingClientRect();
        expect(fullscreenControl && frameBounds && isLightboxHitTarget(fullscreenControl));
        window.__publicWidgetFullscreenTarget = {
            x: Math.round(frameBounds.left + fullscreenBounds.left + fullscreenBounds.width / 2),
            y: Math.round(frameBounds.top + fullscreenBounds.top + fullscreenBounds.height / 2),
        };
        await until(() => liveGalleryDocument.fullscreenElement === liveLightbox);
        expect(liveLightbox.classList.contains('is-fullscreen')
            && liveGalleryDocument.body.classList.contains('has-lightbox')
            && liveLightbox.contains(widgetCenterTarget()));
        stage = 'use fullscreen Gallery controls above the floating widget';
        const fullscreenClose = liveLightbox.querySelector('[data-lightbox-action="close"]');
        const fullscreenNext = liveLightbox.querySelector('.lightbox-next');
        const fullscreenZoomIn = Array.from(liveLightbox.querySelectorAll('[data-lightbox-action="zoom-in"]'))
            .find(button => button.getBoundingClientRect().width > 0 && button.getBoundingClientRect().height > 0);
        const fullscreenControlsHit = () => Boolean(fullscreenClose && fullscreenNext && fullscreenZoomIn
            && isLightboxHitTarget(fullscreenClose) && isLightboxHitTarget(fullscreenNext)
            && isLightboxHitTarget(fullscreenZoomIn));
        await until(() => liveLightbox.classList.contains('is-ui-visible') && fullscreenControlsHit(), undefined, () => {
            const describeHit = element => {
                const hit = element ? lightboxHitTarget(element) : null;
                return hit instanceof liveGalleryWindow.Element
                    ? (hit === element || element.contains(hit) ? 'control' : hit.tagName.toLowerCase())
                    : 'none';
            };
            return 'ui' + Number(liveLightbox.classList.contains('is-ui-visible'))
                + '-close-' + describeHit(fullscreenClose)
                + '-next-' + describeHit(fullscreenNext)
                + '-zoom-' + describeHit(fullscreenZoomIn);
        });
        const fullscreenZoomStatus = liveLightbox.querySelector('[data-lightbox-zoom-status]');
        const fullscreenZoomBefore = fullscreenZoomStatus?.textContent;
        fullscreenZoomIn.click();
        await until(() => fullscreenZoomStatus?.textContent !== fullscreenZoomBefore);
        const fullscreenNextSource = liveLightbox.querySelector('[data-lightbox-img]').currentSrc;
        fullscreenNext.click();
        await until(() => liveLightbox.querySelector('[data-lightbox-img]').currentSrc !== fullscreenNextSource);
        expect(liveLightbox.contains(widgetCenterTarget()) && isLightboxHitTarget(fullscreenClose));
        fullscreenClose.click();
        await until(() => liveLightbox.hidden && liveGalleryDocument.fullscreenElement === null);
        expect(!liveLightbox.classList.contains('is-fullscreen')
            && !liveGalleryDocument.body.classList.contains('has-lightbox'));

        stage = 'navigate the browser frame to the actual anonymous Home route and verify its published widget';
        applicationFrame.src = homeRoute;
        applicationFrame.removeAttribute('srcdoc');
        const navigatedHomeDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const navigationEntry = currentDocument?.defaultView?.performance?.getEntriesByType('navigation')?.[0];
            const matchingWidgets = currentDocument?.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]');
            const widget = matchingWidgets?.[0];
            return currentDocument?.readyState === 'complete'
                && currentUrl.origin === location.origin && currentUrl.pathname === '/index.php'
                && currentUrl.searchParams.get('page') === 'home'
                && navigationEntry?.type === 'navigate' && navigationEntry.responseStatus === 200
                && currentDocument.body?.classList.contains('public-page')
                && matchingWidgets.length === 1
                && widget?.querySelector('.public-content-widget-title')?.textContent === widgetTitle
                && !widget.hasAttribute('data-widget-theme-draft')
                ? currentDocument : null;
        });
        expect(navigatedHomeDocument.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]').length === 1);

        stage = 'navigate the browser frame to the actual anonymous Gallery route and verify its published widget';
        applicationFrame.src = galleryRoute;
        const navigatedGalleryDocument = await until(() => {
            const currentDocument = applicationFrame.contentDocument;
            const currentUrl = new URL(applicationFrame.contentWindow.location.href);
            const navigationEntry = currentDocument?.defaultView?.performance?.getEntriesByType('navigation')?.[0];
            const matchingWidgets = currentDocument?.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]');
            const widget = matchingWidgets?.[0];
            return currentDocument?.readyState === 'complete'
                && currentUrl.origin === location.origin && currentUrl.pathname === '/index.php'
                && currentUrl.searchParams.get('page') === 'gallery'
                && currentUrl.searchParams.get('gallery_path') === 'seed'
                && navigationEntry?.type === 'navigate' && navigationEntry.responseStatus === 200
                && currentDocument.body?.classList.contains('public-page')
                && matchingWidgets.length === 1
                && widget?.querySelector('.public-content-widget-title')?.textContent === widgetTitle
                && !widget.hasAttribute('data-widget-theme-draft')
                ? currentDocument : null;
        });
        expect(navigatedGalleryDocument.querySelectorAll('[data-public-widget-id="' + createdWidgetId + '"]').length === 1);

        stage = 'delete the token-owned widget through the CSRF-protected Admin route';
        await cleanupOwnedWidget();
        stage = 'public widget lifecycle and anonymous Home and Gallery routes verified with baseline restored';
        await fetch(location.pathname + '/result', {method: 'POST', body: JSON.stringify({ok: true, stage})});
    } catch (error) {
        const failedStage = stage;
        let cleanupFailed = false;
        try {
            await cleanupOwnedWidget();
        } catch {
            cleanupFailed = true;
        }
        stage = cleanupFailed ? 'widget lifecycle cleanup failed' : failedStage;
        const detail = error instanceof Error
            ? error.message.replace(/[^a-zA-Z0-9 ._-]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180) : '';
        await fetch(location.pathname + '/result', {method: 'POST', body: JSON.stringify({ok: false, stage, detail})});
    }
})();
