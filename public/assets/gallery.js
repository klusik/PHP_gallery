(() => {
    /**
     * Handles setup theme override form behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function setupThemeOverrideForm() {
        // form stores state or configuration for the gallery front-end flow.
        const form = document.querySelector('[data-theme-form]');
        if (!form) {
            return;
        }
        // changed stores state or configuration for the gallery front-end flow.
        const changed = form.querySelector('[data-theme-controls-changed]');
        if (!changed) {
            return;
        }
        form.querySelectorAll('[data-theme-override-control]').forEach((control) => {
            control.addEventListener('input', () => {
                changed.value = '1';
            });
            control.addEventListener('change', () => {
                changed.value = '1';
            });
        });
        // opacityControl stores state or configuration for the gallery front-end flow.
        const opacityControl = form.querySelector('[data-theme-background-opacity]');
        // opacityDisplay stores state or configuration for the gallery front-end flow.
        const opacityDisplay = form.querySelector('[data-theme-background-opacity-display]');
        if (opacityControl && opacityDisplay) {
            /**
             * Handles sync opacity behavior for the gallery UI.
             * @returns {*} Result of the UI operation, when a value is produced.
             */
            const syncOpacity = () => {
                opacityDisplay.textContent = `${opacityControl.value}%`;
            };
            opacityControl.addEventListener('input', syncOpacity);
            opacityControl.addEventListener('change', syncOpacity);
            syncOpacity();
        }
    }


    /**
     * Handles setup favicon cropper behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function setupFaviconCropper() {
        // input stores state or configuration for the gallery front-end flow.
        const input = document.querySelector('[data-favicon-input]');
        // cropper stores state or configuration for the gallery front-end flow.
        const cropper = document.querySelector('[data-favicon-cropper]');
        // canvas stores state or configuration for the gallery front-end flow.
        const canvas = document.querySelector('[data-favicon-canvas]');
        // preview stores state or configuration for the gallery front-end flow.
        const preview = document.querySelector('[data-favicon-preview]');
        // zoom stores state or configuration for the gallery front-end flow.
        const zoom = document.querySelector('[data-favicon-zoom]');
        // output stores state or configuration for the gallery front-end flow.
        const output = document.querySelector('[data-favicon-cropped]');
        if (!input || !cropper || !canvas || !preview || !zoom || !output) {
            return;
        }
        // context stores state or configuration for the gallery front-end flow.
        const context = canvas.getContext('2d');
        // previewContext stores state or configuration for the gallery front-end flow.
        const previewContext = preview.getContext('2d');
        // image stores state or configuration for the gallery front-end flow.
        const image = new Image();
        // imageLoaded stores state or configuration for the gallery front-end flow.
        let imageLoaded = false;
        // dragging stores state or configuration for the gallery front-end flow.
        let dragging = false;
        // lastPointerX stores state or configuration for the gallery front-end flow.
        let lastPointerX = 0;
        // lastPointerY stores state or configuration for the gallery front-end flow.
        let lastPointerY = 0;
        // offsetX stores state or configuration for the gallery front-end flow.
        let offsetX = 0;
        // offsetY stores state or configuration for the gallery front-end flow.
        let offsetY = 0;

        /**
         * Handles draw favicon crop behavior for the gallery UI.
         * @returns {*} Result of the UI operation, when a value is produced.
         */
        function drawFaviconCrop() {
            if (!imageLoaded) {
                return;
            }
            // scale stores state or configuration for the gallery front-end flow.
            const scale = Math.max(canvas.width / image.width, canvas.height / image.height) * Number(zoom.value || 1);
            // drawWidth stores state or configuration for the gallery front-end flow.
            const drawWidth = image.width * scale;
            // drawHeight stores state or configuration for the gallery front-end flow.
            const drawHeight = image.height * scale;
            // minOffsetX stores state or configuration for the gallery front-end flow.
            const minOffsetX = canvas.width - drawWidth;
            // minOffsetY stores state or configuration for the gallery front-end flow.
            const minOffsetY = canvas.height - drawHeight;
            offsetX = Math.min(0, Math.max(minOffsetX, offsetX));
            offsetY = Math.min(0, Math.max(minOffsetY, offsetY));
            context.clearRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, offsetX, offsetY, drawWidth, drawHeight);
            context.strokeRect(0.5, 0.5, canvas.width - 1, canvas.height - 1);
            previewContext.clearRect(0, 0, preview.width, preview.height);
            previewContext.drawImage(canvas, 0, 0, preview.width, preview.height);
            output.value = canvas.toDataURL('image/png');
        }

        input.addEventListener('change', () => {
            // file stores state or configuration for the gallery front-end flow.
            const file = input.files && input.files[0] ? input.files[0] : null;
            output.value = '';
            if (!file || !file.type.startsWith('image/')) {
                cropper.hidden = true;
                imageLoaded = false;
                return;
            }
            // reader stores state or configuration for the gallery front-end flow.
            const reader = new FileReader();
            reader.addEventListener('load', () => {
                image.addEventListener('load', () => {
                    imageLoaded = true;
                    zoom.value = '1';
                    // baseScale stores state or configuration for the gallery front-end flow.
                    const baseScale = Math.max(canvas.width / image.width, canvas.height / image.height);
                    offsetX = (canvas.width - image.width * baseScale) / 2;
                    offsetY = (canvas.height - image.height * baseScale) / 2;
                    cropper.hidden = false;
                    drawFaviconCrop();
                }, {once: true});
                image.src = String(reader.result || '');
            });
            reader.readAsDataURL(file);
        });

        zoom.addEventListener('input', drawFaviconCrop);
        zoom.addEventListener('change', drawFaviconCrop);
        canvas.addEventListener('pointerdown', (event) => {
            if (!imageLoaded) {
                return;
            }
            dragging = true;
            lastPointerX = event.clientX;
            lastPointerY = event.clientY;
            canvas.setPointerCapture(event.pointerId);
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!dragging) {
                return;
            }
            offsetX += event.clientX - lastPointerX;
            offsetY += event.clientY - lastPointerY;
            lastPointerX = event.clientX;
            lastPointerY = event.clientY;
            drawFaviconCrop();
        });
        canvas.addEventListener('pointerup', () => {
            dragging = false;
        });
        canvas.addEventListener('pointercancel', () => {
            dragging = false;
        });
    }

    // Submit votes through fetch so the selected state and score update without
    // leaving the lightbox/gallery page.
    document.addEventListener('submit', async (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target.closest('[data-vote-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        // Variable `body` stores this steps working value.
        const body = new FormData(form);
        if (event.submitter && event.submitter.name) {
            body.set(event.submitter.name, event.submitter.value);
        }
        // Variable `response` stores this steps working value.
        const response = await fetch(form.action, {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            return;
        }
        // Variable `result` stores this steps working value.
        const result = await response.json();
        document.querySelectorAll(`[data-score-for="${result.image_id}"]`).forEach((node) => {
            node.textContent = result.score;
        });
        document.querySelectorAll(`[data-image-id="${result.image_id}"]`).forEach((node) => {
            node.dataset.score = String(result.score);
            node.dataset.userVote = String(result.vote);
        });
        form.querySelectorAll('button[name="vote"]').forEach((button) => {
            // Variable `active` stores this steps working value.
            const active = button.value === String(result.vote);
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        // Variable `lightbox` stores this steps working value.
        const lightbox = document.querySelector('[data-lightbox]');
        // Variable `lightboxScore` stores this steps working value.
        const lightboxScore = document.querySelector('[data-lightbox-score]');
        if (lightbox && lightboxScore && lightbox.dataset.currentImageId === String(result.image_id)) {
            lightboxScore.textContent = String(result.score);
            updateLightboxVoteIndicator(String(result.vote));
        }
    });

    // Table-level select-all checkboxes are scoped by input name and form. When
    // a table is filtered, hidden rows are left untouched so bulk operations
    // only apply to what the admin can currently see.
    document.querySelectorAll('[data-select-all]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            // Variable `name` stores this steps working value.
            const name = checkbox.dataset.selectAll;
            // Variable `scope` stores this steps working value.
            const scope = checkbox.closest('form') || document;
            scope.querySelectorAll(`input[type="checkbox"][name="${name}"]`).forEach((item) => {
                // Variable `row` stores this steps working value.
                const row = item.closest('tr');
                if (row && row.hidden) {
                    return;
                }
                item.checked = checkbox.checked;
            });
        });
    });


    // Confirm destructive gallery bulk deletes with the exact selected names.
    document.addEventListener('submit', (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-gallery-bulk-form]')) {
            return;
        }
        // Variable `action` stores this steps working value.
        const action = form.querySelector('select[name="action"]');
        if (!(action instanceof HTMLSelectElement) || action.value !== 'delete') {
            return;
        }
        // Variable `selectedRows` stores this steps working value.
        const selectedRows = Array.from(form.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]:checked'))
            .map((checkbox) => checkbox.closest('[data-gallery-row]'))
            .filter((row) => row instanceof HTMLElement);
        if (!selectedRows.length) {
            event.preventDefault();
            window.alert('Select at least one gallery to delete.');
            return;
        }
        // Variable `names` stores this steps working value.
        const names = selectedRows.map((row) => row.dataset.galleryTitle || row.querySelector('.tree-title a')?.textContent?.trim() || `Gallery ${row.dataset.galleryId || ''}`.trim());
        // Variable `message` stores this steps working value.
        const message = [
            'Delete these gallery folders and all subgalleries?',
            '',
            ...names.map((name) => `• ${name}`),
            '',
            'This removes the folders from disk and deletes their database records. This cannot be undone.'
        ].join('\n');
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    // Function `setupBackToTopButton` executes this focused behavior.
    function setupBackToTopButton() {
        // scope stores state or configuration for the gallery front-end flow.
        const scope = document.querySelector('[data-back-to-top-scope]');
        // listing stores state or configuration for the gallery front-end flow.
        const listing = document.querySelector('[data-back-to-top-list]') || document.querySelector('[data-gallery-image-list]');
        // button stores state or configuration for the gallery front-end flow.
        const button = document.querySelector('[data-back-to-top-button]');
        if (!scope || !listing || !button) {
            return;
        }

        // ticking stores state or configuration for the gallery front-end flow.
        let ticking = false;

        /**
         * Handles should show button behavior for the gallery UI.
         * @returns {*} Result of the UI operation, when a value is produced.
         */
        function shouldShowButton() {
            if (document.body.classList.contains('has-lightbox') || document.body.classList.contains('has-mobile-lightbox') || document.fullscreenElement) {
                return false;
            }
            // scopeRect stores state or configuration for the gallery front-end flow.
            const scopeRect = scope.getBoundingClientRect();
            // listingRect stores state or configuration for the gallery front-end flow.
            const listingRect = listing.getBoundingClientRect();
            // enteredListing stores state or configuration for the gallery front-end flow.
            const enteredListing = listingRect.top < window.innerHeight * 0.72;
            // stillInsideListing stores state or configuration for the gallery front-end flow.
            const stillInsideListing = scopeRect.bottom > window.innerHeight * 0.24;
            return enteredListing && stillInsideListing && window.scrollY > 180;
        }

        /**
         * Handles update visibility behavior for the gallery UI.
         * @returns {*} Result of the UI operation, when a value is produced.
         */
        function updateVisibility() {
            ticking = false;
            // visible stores state or configuration for the gallery front-end flow.
            const visible = shouldShowButton();
            button.hidden = !visible;
            button.classList.toggle('is-visible', visible);
        }

        /**
         * Handles request visibility update behavior for the gallery UI.
         * @returns {*} Result of the UI operation, when a value is produced.
         */
        function requestVisibilityUpdate() {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(updateVisibility);
        }

        button.addEventListener('click', () => {
            window.scrollTo({top: 0, behavior: 'smooth'});
        });
        window.addEventListener('scroll', requestVisibilityUpdate, {passive: true});
        window.addEventListener('resize', requestVisibilityUpdate);
        document.addEventListener('fullscreenchange', requestVisibilityUpdate);
        updateVisibility();
    }

    setupAdminGalleryFilters();
    setupThumbnailProgress();
    setupGalleryRefreshProgress();
    setupGalleryUploadProgress();
    setupPictureGame();
    setupAdminLogStatusForms();
    setupGpsMaps();
    setupBackToTopButton();
    setupThemeOverrideForm();
    setupFaviconCropper();

    // Tag fields still store comma-separated text, but this small helper makes
    // reused tags discoverable while the admin types.
    document.querySelectorAll('[data-tag-input]').forEach((input) => {
        // Variable `list` stores this steps working value.
        const list = document.querySelector(`#${input.getAttribute('list')}`);
        // Variable `names` stores this steps working value.
        const names = list ? Array.from(list.options).map((option) => option.value) : [];
        // Variable `suggestions` stores this steps working value.
        const suggestions = document.createElement('div');
        suggestions.className = 'tag-suggestions';
        input.insertAdjacentElement('afterend', suggestions);

        // Function `currentPrefix` executes this focused behavior.
        function currentPrefix() {
            // Variable `parts` stores this steps working value.
            const parts = input.value.split(',');
            return parts[parts.length - 1].trim().toLowerCase();
        }

        // Function `choose` executes this focused behavior.
        function choose(name) {
            // Variable `parts` stores this steps working value.
            const parts = input.value.split(',');
            parts[parts.length - 1] = ` ${name}`;
            input.value = parts.map((part) => part.trim()).filter(Boolean).join(', ');
            suggestions.innerHTML = '';
            input.focus();
        }

        input.addEventListener('input', () => {
            // Variable `prefix` stores this steps working value.
            const prefix = currentPrefix();
            suggestions.innerHTML = '';
            if (!prefix) {
                return;
            }
            names.filter((name) => name.toLowerCase().startsWith(prefix)).slice(0, 6).forEach((name) => {
                // Variable `button` stores this steps working value.
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = name;
                button.addEventListener('click', () => choose(name));
                suggestions.append(button);
            });
        });
    });

    // Lightbox state is derived from rendered image cards. Normal image links
    // remain valid when JavaScript is unavailable.
    const cards = Array.from(document.querySelectorAll('[data-lightbox-image]'));
    // Variable `overlay` stores this steps working value.
    const overlay = document.querySelector('[data-lightbox]');
    setupAdminGalleryTree();
    if (!overlay || cards.length === 0) {
        return;
    }

    // Variable `image` stores this steps working value.
    const image = overlay.querySelector('[data-lightbox-img]');
    // stageLink stores state or configuration for the gallery front-end flow.
    const stageLink = image ? image.closest('.lightbox-stage-link') : null;
    // lightboxImageTransitionDuration stores state or configuration for the gallery front-end flow.
    const lightboxImageTransitionDuration = 80;
    // lightboxPreviewPreloadRadius stores state or configuration for the gallery front-end flow.
    const lightboxPreviewPreloadRadius = 8;
    // lightboxFullPreloadRadius stores state or configuration for the gallery front-end flow.
    const lightboxFullPreloadRadius = 2;
    // lightboxFullSwapIdleDelay stores state or configuration for the gallery front-end flow.
    const lightboxFullSwapIdleDelay = 80;
    // lightboxDecodedImageCacheLimit stores state or configuration for the gallery front-end flow.
    const lightboxDecodedImageCacheLimit = 48;
    // transitionImage stores state or configuration for the gallery front-end flow.
    let transitionImage = null;
    // activeLightboxTransitionToken stores state or configuration for the gallery front-end flow.
    let activeLightboxTransitionToken = 0;
    // pendingFullImageSwapTimer stores state or configuration for the gallery front-end flow.
    let pendingFullImageSwapTimer = null;
    // Variable `title` stores this steps working value.
    const title = overlay.querySelector('[data-lightbox-title]');
    // Variable `description` stores this steps working value.
    const description = overlay.querySelector('[data-lightbox-description]');
    // Variable `score` stores this steps working value.
    const score = overlay.querySelector('[data-lightbox-score]');
    // counter stores state or configuration for the gallery front-end flow.
    const counter = overlay.querySelector('[data-lightbox-counter]');
    // Variable `lightboxVoteForm` stores this steps working value.
    const lightboxVoteForm = overlay.querySelector('[data-lightbox-vote-form]');
    // Variable `lightboxVoteIndicator` stores this steps working value.
    const lightboxVoteIndicator = overlay.querySelector('[data-lightbox-vote-indicator]');
    // Variable `lightboxMapButton` stores this steps working value.
    const lightboxMapButton = overlay.querySelector('[data-lightbox-map]');
    // lightboxMapSplit stores state or configuration for the gallery front-end flow.
    const lightboxMapSplit = overlay.querySelector('[data-lightbox-map-split]');
    // lightboxMapSplitClose stores state or configuration for the gallery front-end flow.
    const lightboxMapSplitClose = overlay.querySelector('[data-lightbox-map-split-close]');
    // lightboxMapSplitTitle stores state or configuration for the gallery front-end flow.
    const lightboxMapSplitTitle = overlay.querySelector('[data-lightbox-map-split-title]');
    // lightboxMapSplitCanvas stores state or configuration for the gallery front-end flow.
    const lightboxMapSplitCanvas = overlay.querySelector('[data-lightbox-map-split-canvas]');
    // Variable `currentIndex` stores this steps working value.
    let currentIndex = 0;
    // lightboxReturnUrl stores state or configuration for the gallery front-end flow.
    let lightboxReturnUrl = window.location.href;
    // lightboxHistoryActive stores state or configuration for the gallery front-end flow.
    let lightboxHistoryActive = false;
    // Variable `preloadedSources` stores this steps working value.
    const preloadedSources = new Set();
    // decodedLightboxImages stores state or configuration for the gallery front-end flow.
    const decodedLightboxImages = new Map();
    // fullscreenHideTimer stores state or configuration for the gallery front-end flow.
    let fullscreenHideTimer = null;
    // touchGesture stores state or configuration for the gallery front-end flow.
    let touchGesture = null;
    // isMobileTouchDevice stores state or configuration for the gallery front-end flow.
    const isMobileTouchDevice = detectMobileTouchDevice();
    // galleryDevModeEnabled stores state or configuration for the gallery front-end flow.
    const galleryDevModeEnabled = Boolean(document.body?.dataset.devMode === '1' || window.PHPGalleryDevMode?.enabled);
    // galleryDevModeState stores state or configuration for the gallery front-end flow.
    const galleryDevModeState = {
        overlay: null,
        text: null,
        canvas: null,
        canvasContext: null,
        startedAt: performance.now(),
        lastRenderAt: 0,
        currentIndex: -1,
        currentSource: '',
        currentSourceKind: '',
        sourceStats: new Map(),
        eventLog: [],
        samples: [],
        preloadStarted: 0,
        loadStarted: 0,
        cacheHits: 0,
        cacheMisses: 0,
        decodeErrors: 0,
        evictions: 0,
        frameMs: 0,
        lastFrameAt: 0,
    };
    setupGalleryDevModeOverlay();
    // supportsPointerGestures stores state or configuration for the gallery front-end flow.
    const supportsPointerGestures = Boolean(window.PointerEvent);
    // isLightboxDebugEnabled stores state or configuration for the gallery front-end flow.
    const isLightboxDebugEnabled = detectLightboxDebugFlag();
    overlay.classList.toggle('is-mobile-device', isMobileTouchDevice);
    window.__LIGHTBOX_DEBUG__ = isLightboxDebugEnabled;

    // Function `syncLightboxVote` executes this focused behavior.
    function syncLightboxVote(card) {
        if (!lightboxVoteForm || lightboxVoteForm.closest('[hidden]')) {
            return;
        }
        // Variable `vote` stores this steps working value.
        const vote = card.dataset.userVote || '0';
        lightboxVoteForm.querySelector('input[name="image_id"]').value = card.dataset.imageId || '';
        score.dataset.scoreFor = card.dataset.imageId || '';
        lightboxVoteForm.querySelectorAll('button[name="vote"]').forEach((button) => {
            // Variable `active` stores this steps working value.
            const active = button.value === vote;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        updateLightboxVoteIndicator(vote);
    }

    // Function `updateLightboxVoteIndicator` executes this focused behavior.
    function updateLightboxVoteIndicator(vote) {
        if (!lightboxVoteIndicator) {
            return;
        }
        lightboxVoteIndicator.classList.toggle('is-up', vote === '1');
        lightboxVoteIndicator.classList.toggle('is-down', vote === '-1');
        lightboxVoteIndicator.textContent = vote === '1' ? 'Voted up' : vote === '-1' ? 'Voted down' : 'No vote';
    }

    /**
     * Handles clear lightbox stage focus behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearLightboxStageFocus() {
        if (stageLink && document.activeElement === stageLink) {
            stageLink.blur();
        }
    }

    // activeLightboxImageToken stores state or configuration for the gallery front-end flow.
    let activeLightboxImageToken = 0;

    /**
     * Handles clear pending full image swap behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearPendingFullImageSwap() {
        if (pendingFullImageSwapTimer) {
            window.clearTimeout(pendingFullImageSwapTimer);
            pendingFullImageSwapTimer = null;
        }
    }

    /**
     * Handles decode loaded image behavior for the gallery UI.
     * @param {*} loadedImage Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function decodeLoadedImage(loadedImage) {
        if (typeof loadedImage.decode !== 'function') {
            return Promise.resolve();
        }
        return loadedImage.decode().catch(() => undefined);
    }

    /**
     * Handles setup gallery dev mode overlay behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function setupGalleryDevModeOverlay() {
        if (!galleryDevModeEnabled) {
            return;
        }
        // shell stores state or configuration for the gallery front-end flow.
        const shell = document.createElement('section');
        shell.className = 'gallery-dev-overlay';
        shell.setAttribute('aria-label', 'Gallery dev mode diagnostics');
        shell.innerHTML = '<header><strong>DEV</strong><span data-dev-title>viewer diagnostics</span></header><pre data-dev-text></pre><canvas width="340" height="72" data-dev-canvas></canvas><footer><span>Drag disabled</span><span>admin only</span></footer>';
        galleryDevModeState.overlay = shell;
        galleryDevModeState.text = shell.querySelector('[data-dev-text]');
        galleryDevModeState.canvas = shell.querySelector('[data-dev-canvas]');
        galleryDevModeState.canvasContext = galleryDevModeState.canvas ? galleryDevModeState.canvas.getContext('2d') : null;
        overlay.append(shell);
        cards.forEach((card, index) => {
            devRegisterSource(card.dataset.previewSrc || card.dataset.fullSrc || '', 'preview', index, 'idle');
            devRegisterSource(card.dataset.fullSrc || card.dataset.previewSrc || '', 'full', index, 'idle');
        });
        requestAnimationFrame(devFrameTick);
        window.setInterval(renderGalleryDevModeOverlay, 350);
        renderGalleryDevModeOverlay();
    }

    /**
     * Handles dev frame tick behavior for the gallery UI.
     * @param {*} timestamp Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devFrameTick(timestamp) {
        if (!galleryDevModeEnabled) {
            return;
        }
        if (galleryDevModeState.lastFrameAt > 0) {
            galleryDevModeState.frameMs = timestamp - galleryDevModeState.lastFrameAt;
        }
        galleryDevModeState.lastFrameAt = timestamp;
        requestAnimationFrame(devFrameTick);
    }

    /**
     * Handles dev register source behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} kind Value supplied by the caller or event context.
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} status Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devRegisterSource(src, kind, index, status) {
        if (!galleryDevModeEnabled || !src) {
            return null;
        }
        // existing stores state or configuration for the gallery front-end flow.
        const existing = galleryDevModeState.sourceStats.get(src) || {};
        // card stores state or configuration for the gallery front-end flow.
        const card = cards[index] || null;
        // width stores state or configuration for the gallery front-end flow.
        const width = Number.parseInt(card?.dataset.imageWidth || '0', 10) || existing.width || 0;
        // height stores state or configuration for the gallery front-end flow.
        const height = Number.parseInt(card?.dataset.imageHeight || '0', 10) || existing.height || 0;
        // stat stores state or configuration for the gallery front-end flow.
        const stat = {
            src,
            kind: existing.kind || kind,
            index: Number.isInteger(existing.index) ? existing.index : index,
            status: status || existing.status || 'idle',
            width,
            height,
            naturalWidth: existing.naturalWidth || 0,
            naturalHeight: existing.naturalHeight || 0,
            startedAt: existing.startedAt || 0,
            finishedAt: existing.finishedAt || 0,
            lastUsedAt: performance.now(),
            lastReason: existing.lastReason || '',
        };
        if (existing.kind && existing.kind !== kind) {
            stat.kind = 'shared';
        }
        galleryDevModeState.sourceStats.set(src, stat);
        return stat;
    }

    /**
     * Handles dev find source kind behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devFindSourceKind(src) {
        if (!src) {
            return '';
        }
        for (const card of cards) {
            if (card.dataset.previewSrc === src && card.dataset.fullSrc === src) {
                return 'preview+full';
            }
            if (card.dataset.previewSrc === src) {
                return 'preview';
            }
            if (card.dataset.fullSrc === src) {
                return 'full';
            }
        }
        return 'unknown';
    }

    /**
     * Handles dev find source index behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devFindSourceIndex(src) {
        if (!src) {
            return -1;
        }
        return cards.findIndex((card) => card.dataset.previewSrc === src || card.dataset.fullSrc === src);
    }

    /**
     * Handles dev mark source behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} status Value supplied by the caller or event context.
     * @param {*} reason Value supplied by the caller or event context.
     * @param {*} imageNode Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devMarkSource(src, status, reason, imageNode = null) {
        if (!galleryDevModeEnabled || !src) {
            return;
        }
        // index stores state or configuration for the gallery front-end flow.
        const index = devFindSourceIndex(src);
        // kind stores state or configuration for the gallery front-end flow.
        const kind = devFindSourceKind(src);
        // stat stores state or configuration for the gallery front-end flow.
        const stat = devRegisterSource(src, kind, index, status);
        if (!stat) {
            return;
        }
        stat.status = status;
        stat.lastReason = reason || '';
        stat.lastUsedAt = performance.now();
        if (status === 'loading' || status === 'preloading') {
            stat.startedAt = stat.startedAt || performance.now();
        }
        if (status === 'ready' || status === 'error') {
            stat.finishedAt = performance.now();
        }
        if (imageNode) {
            stat.naturalWidth = imageNode.naturalWidth || stat.naturalWidth || 0;
            stat.naturalHeight = imageNode.naturalHeight || stat.naturalHeight || 0;
        }
        galleryDevModeState.sourceStats.set(src, stat);
        devLog(`${kind}:${status}:${reason || 'state'}`);
    }

    /**
     * Handles dev log behavior for the gallery UI.
     * @param {*} message Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devLog(message) {
        if (!galleryDevModeEnabled) {
            return;
        }
        galleryDevModeState.eventLog.unshift(`${formatDevTime(performance.now() - galleryDevModeState.startedAt)} ${message}`);
        galleryDevModeState.eventLog = galleryDevModeState.eventLog.slice(0, 8);
    }

    /**
     * Handles dev decoded memory bytes behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devDecodedMemoryBytes() {
        // total stores state or configuration for the gallery front-end flow.
        let total = 0;
        galleryDevModeState.sourceStats.forEach((stat, src) => {
            if (!decodedLightboxImages.has(src) || stat.status !== 'ready') {
                return;
            }
            // width stores state or configuration for the gallery front-end flow.
            const width = stat.naturalWidth || stat.width || 0;
            // height stores state or configuration for the gallery front-end flow.
            const height = stat.naturalHeight || stat.height || 0;
            if (width > 0 && height > 0) {
                total += width * height * 4;
            }
        });
        return total;
    }

    /**
     * Handles dev status counts behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devStatusCounts() {
        // counts stores state or configuration for the gallery front-end flow.
        const counts = {idle: 0, preloading: 0, loading: 0, ready: 0, error: 0};
        galleryDevModeState.sourceStats.forEach((stat) => {
            counts[stat.status] = (counts[stat.status] || 0) + 1;
        });
        return counts;
    }

    /**
     * Handles dev current window summary behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devCurrentWindowSummary() {
        if (galleryDevModeState.currentIndex < 0) {
            return 'not open';
        }
        // rows stores state or configuration for the gallery front-end flow.
        const rows = [];
        // current stores state or configuration for the gallery front-end flow.
        const current = galleryDevModeState.currentIndex;
        for (let offset = -3; offset <= 3; offset += 1) {
            // index stores state or configuration for the gallery front-end flow.
            const index = (current + offset + cards.length) % cards.length;
            // card stores state or configuration for the gallery front-end flow.
            const card = cards[index];
            // preview stores state or configuration for the gallery front-end flow.
            const preview = galleryDevModeState.sourceStats.get(card?.dataset.previewSrc || '');
            // full stores state or configuration for the gallery front-end flow.
            const full = galleryDevModeState.sourceStats.get(card?.dataset.fullSrc || '');
            // mark stores state or configuration for the gallery front-end flow.
            const mark = offset === 0 ? '*' : (offset > 0 ? '+' : '');
            rows.push(`${mark}${offset}:P${devShortStatus(preview)} F${devShortStatus(full)}`);
        }
        return rows.join(' ');
    }

    /**
     * Handles dev short status behavior for the gallery UI.
     * @param {*} stat Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devShortStatus(stat) {
        if (!stat) {
            return '?';
        }
        return {idle: 'i', preloading: 'p', loading: 'l', ready: 'r', error: 'e'}[stat.status] || '?';
    }

    /**
     * Handles dev browser memory line behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devBrowserMemoryLine() {
        // memory stores state or configuration for the gallery front-end flow.
        const memory = performance.memory;
        if (memory && typeof memory.usedJSHeapSize === 'number') {
            return `heap ${formatBytes(memory.usedJSHeapSize)} / ${formatBytes(memory.jsHeapSizeLimit)}`;
        }
        if (navigator.deviceMemory) {
            return `deviceMemory ${navigator.deviceMemory} GB, heap unavailable`;
        }
        return 'heap unavailable';
    }

    /**
     * Handles dev connection line behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devConnectionLine() {
        // connection stores state or configuration for the gallery front-end flow.
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!connection) {
            return 'network hints unavailable';
        }
        // parts stores state or configuration for the gallery front-end flow.
        const parts = [];
        if (connection.effectiveType) {
            parts.push(connection.effectiveType);
        }
        if (typeof connection.downlink === 'number') {
            parts.push(`${connection.downlink} Mbps`);
        }
        if (connection.saveData) {
            parts.push('save-data');
        }
        return parts.length ? parts.join(', ') : 'network hints unavailable';
    }

    /**
     * Handles render gallery dev mode overlay behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function renderGalleryDevModeOverlay() {
        if (!galleryDevModeEnabled || !galleryDevModeState.overlay || !galleryDevModeState.text) {
            return;
        }
        // now stores state or configuration for the gallery front-end flow.
        const now = performance.now();
        // counts stores state or configuration for the gallery front-end flow.
        const counts = devStatusCounts();
        // decodedBytes stores state or configuration for the gallery front-end flow.
        const decodedBytes = devDecodedMemoryBytes();
        // cacheLimit stores state or configuration for the gallery front-end flow.
        const cacheLimit = lightboxDecodedImageCacheLimit;
        // browserMode stores state or configuration for the gallery front-end flow.
        const browserMode = isLightboxFullscreen() ? 'fullscreen' : (overlay.hidden ? 'closed' : 'normal');
        // currentCard stores state or configuration for the gallery front-end flow.
        const currentCard = cards[galleryDevModeState.currentIndex] || null;
        // currentSize stores state or configuration for the gallery front-end flow.
        const currentSize = currentCard ? `${currentCard.dataset.imageWidth || '?'}x${currentCard.dataset.imageHeight || '?'}` : 'n/a';
        // historySample stores state or configuration for the gallery front-end flow.
        const historySample = {
            ready: counts.ready,
            cached: decodedLightboxImages.size,
            memory: decodedBytes,
            frame: galleryDevModeState.frameMs,
            time: now,
        };
        galleryDevModeState.samples.push(historySample);
        galleryDevModeState.samples = galleryDevModeState.samples.slice(-90);
        // lines stores state or configuration for the gallery front-end flow.
        const lines = [
            `mode ${browserMode} | image ${galleryDevModeState.currentIndex + 1 || 0}/${cards.length} | ${currentSize} | src ${galleryDevModeState.currentSourceKind || 'none'}`,
            `preload radius P${lightboxPreviewPreloadRadius}/F${lightboxFullPreloadRadius} | cache ${decodedLightboxImages.size}/${cacheLimit} | known ${galleryDevModeState.sourceStats.size}`,
            `state idle ${counts.idle} | pre ${counts.preloading} | load ${counts.loading} | ready ${counts.ready} | err ${counts.error}`,
            `events preload ${galleryDevModeState.preloadStarted} | load ${galleryDevModeState.loadStarted} | hit ${galleryDevModeState.cacheHits} | miss ${galleryDevModeState.cacheMisses} | evict ${galleryDevModeState.evictions}`,
            `decoded estimate ${formatBytes(decodedBytes)} | ${devBrowserMemoryLine()} | frame ${galleryDevModeState.frameMs.toFixed(1)} ms`,
            `network ${devConnectionLine()} | active ${shortenDevUrl(galleryDevModeState.currentSource)}`,
            `window ${devCurrentWindowSummary()}`,
            `recent ${galleryDevModeState.eventLog.slice(0, 3).join(' | ') || 'none'}`,
        ];
        galleryDevModeState.text.textContent = lines.join('\n');
        drawGalleryDevModeGraph();
    }

    /**
     * Handles draw gallery dev mode graph behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function drawGalleryDevModeGraph() {
        // canvas stores state or configuration for the gallery front-end flow.
        const canvas = galleryDevModeState.canvas;
        // context stores state or configuration for the gallery front-end flow.
        const context = galleryDevModeState.canvasContext;
        if (!canvas || !context) {
            return;
        }
        // width stores state or configuration for the gallery front-end flow.
        const width = canvas.width;
        // height stores state or configuration for the gallery front-end flow.
        const height = canvas.height;
        context.clearRect(0, 0, width, height);
        context.globalAlpha = 1;
        context.fillStyle = 'rgba(0,0,0,0.42)';
        context.fillRect(0, 0, width, height);
        // samples stores state or configuration for the gallery front-end flow.
        const samples = galleryDevModeState.samples;
        if (samples.length < 2) {
            return;
        }
        // maxMemory stores state or configuration for the gallery front-end flow.
        const maxMemory = Math.max(1, ...samples.map((sample) => sample.memory));
        // maxReady stores state or configuration for the gallery front-end flow.
        const maxReady = Math.max(1, ...samples.map((sample) => sample.ready));
        // maxFrame stores state or configuration for the gallery front-end flow.
        const maxFrame = Math.max(16, ...samples.map((sample) => sample.frame));
        drawDevLine(samples, (sample) => sample.memory / maxMemory, height, width);
        drawDevLine(samples, (sample) => sample.ready / maxReady, height, width, 0.66);
        drawDevLine(samples, (sample) => Math.min(1, sample.frame / maxFrame), height, width, 0.36);
        context.globalAlpha = 0.8;
        context.fillStyle = 'rgba(255,255,255,0.85)';
        context.font = '10px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
        context.fillText('memory / ready / frame', 8, 14);
    }

    /**
     * Handles draw dev line behavior for the gallery UI.
     * @param {*} samples Value supplied by the caller or event context.
     * @param {*} selector Value supplied by the caller or event context.
     * @param {*} height Value supplied by the caller or event context.
     * @param {*} width Value supplied by the caller or event context.
     * @param {*} alpha Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function drawDevLine(samples, selector, height, width, alpha = 1) {
        // context stores state or configuration for the gallery front-end flow.
        const context = galleryDevModeState.canvasContext;
        if (!context) {
            return;
        }
        context.beginPath();
        samples.forEach((sample, index) => {
            // x stores state or configuration for the gallery front-end flow.
            const x = (index / Math.max(1, samples.length - 1)) * width;
            // y stores state or configuration for the gallery front-end flow.
            const y = height - (selector(sample) * (height - 18)) - 4;
            if (index === 0) {
                context.moveTo(x, y);
            } else {
                context.lineTo(x, y);
            }
        });
        context.globalAlpha = alpha;
        context.strokeStyle = 'rgba(255,255,255,0.92)';
        context.lineWidth = 1.5;
        context.stroke();
        context.globalAlpha = 1;
    }

    /**
     * Handles format bytes behavior for the gallery UI.
     * @param {*} bytes Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function formatBytes(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '0 B';
        }
        // units stores state or configuration for the gallery front-end flow.
        const units = ['B', 'KB', 'MB', 'GB'];
        // value stores state or configuration for the gallery front-end flow.
        let value = bytes;
        // unitIndex stores state or configuration for the gallery front-end flow.
        let unitIndex = 0;
        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }
        return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
    }

    /**
     * Handles format dev time behavior for the gallery UI.
     * @param {*} ms Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function formatDevTime(ms) {
        return `${(ms / 1000).toFixed(1)}s`;
    }

    /**
     * Handles shorten dev url behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function shortenDevUrl(src) {
        if (!src) {
            return 'none';
        }
        try {
            // url stores state or configuration for the gallery front-end flow.
            const url = new URL(src, window.location.href);
            // last stores state or configuration for the gallery front-end flow.
            const last = url.pathname.split('/').filter(Boolean).pop() || url.pathname;
            return decodeURIComponent(last).slice(0, 46);
        } catch {
            return src.slice(0, 46);
        }
    }

    /**
     * Handles load fresh decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function loadFreshDecodedLightboxImage(src) {
        return new Promise((resolve, reject) => {
            if (!src) {
                reject(new Error('Missing lightbox image source.'));
                return;
            }
            galleryDevModeState.loadStarted += galleryDevModeEnabled ? 1 : 0;
            devMarkSource(src, 'loading', 'fresh');
            // loadedImage stores state or configuration for the gallery front-end flow.
            const loadedImage = new Image();
            loadedImage.decoding = 'async';
            loadedImage.loading = 'eager';
            loadedImage.onload = () => {
                decodeLoadedImage(loadedImage).then(() => {
                    devMarkSource(src, 'ready', 'decoded', loadedImage);
                    resolve(loadedImage);
                });
            };
            loadedImage.onerror = () => {
                galleryDevModeState.decodeErrors += galleryDevModeEnabled ? 1 : 0;
                devMarkSource(src, 'error', 'load');
                reject(new Error('Lightbox image load failed.'));
            };
            loadedImage.src = src;
        });
    }

    /**
     * Handles remember decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} preloadPromise Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function rememberDecodedLightboxImage(src, preloadPromise) {
        if (decodedLightboxImages.has(src)) {
            decodedLightboxImages.delete(src);
        }
        decodedLightboxImages.set(src, preloadPromise);
        trimDecodedLightboxImageCache();
        return preloadPromise;
    }

    /**
     * Handles trim decoded lightbox image cache behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function trimDecodedLightboxImageCache() {
        while (decodedLightboxImages.size > lightboxDecodedImageCacheLimit) {
            // oldestKey stores state or configuration for the gallery front-end flow.
            const oldestKey = decodedLightboxImages.keys().next().value;
            if (!oldestKey) {
                return;
            }
            decodedLightboxImages.delete(oldestKey);
            if (galleryDevModeEnabled) {
                galleryDevModeState.evictions += 1;
                devLog(`evict:${shortenDevUrl(oldestKey)}`);
            }
        }
    }

    /**
     * Handles preload decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function preloadDecodedLightboxImage(src) {
        if (!src) {
            return Promise.resolve(null);
        }
        if (decodedLightboxImages.has(src)) {
            galleryDevModeState.cacheHits += galleryDevModeEnabled ? 1 : 0;
            devMarkSource(src, 'preloading', 'preload-hit');
            // cachedPromise stores state or configuration for the gallery front-end flow.
            const cachedPromise = decodedLightboxImages.get(src);
            decodedLightboxImages.delete(src);
            decodedLightboxImages.set(src, cachedPromise);
            return cachedPromise;
        }
        galleryDevModeState.cacheMisses += galleryDevModeEnabled ? 1 : 0;
        galleryDevModeState.preloadStarted += galleryDevModeEnabled ? 1 : 0;
        devMarkSource(src, 'preloading', 'preload-miss');
        // preloadPromise stores state or configuration for the gallery front-end flow.
        const preloadPromise = loadFreshDecodedLightboxImage(src).catch(() => null);
        return rememberDecodedLightboxImage(src, preloadPromise);
    }

    /**
     * Handles load decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function loadDecodedLightboxImage(src) {
        if (!src) {
            return Promise.reject(new Error('Missing lightbox image source.'));
        }
        if (decodedLightboxImages.has(src)) {
            galleryDevModeState.cacheHits += galleryDevModeEnabled ? 1 : 0;
            devMarkSource(src, 'loading', 'load-hit');
            // cachedPromise stores state or configuration for the gallery front-end flow.
            const cachedPromise = decodedLightboxImages.get(src);
            decodedLightboxImages.delete(src);
            decodedLightboxImages.set(src, cachedPromise);
            return cachedPromise.then((preloadedImage) => {
                if (preloadedImage) {
                    return preloadedImage;
                }
                // freshPromise stores state or configuration for the gallery front-end flow.
                const freshPromise = loadFreshDecodedLightboxImage(src);
                rememberDecodedLightboxImage(src, freshPromise.catch(() => null));
                return freshPromise;
            });
        }
        galleryDevModeState.cacheMisses += galleryDevModeEnabled ? 1 : 0;
        // freshPromise stores state or configuration for the gallery front-end flow.
        const freshPromise = loadFreshDecodedLightboxImage(src);
        rememberDecodedLightboxImage(src, freshPromise.catch(() => null));
        return freshPromise;
    }

    /**
     * Handles remove transition image behavior for the gallery UI.
     * @param {*} node Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function removeTransitionImage(node) {
        // imageToRemove stores state or configuration for the gallery front-end flow.
        const imageToRemove = node || transitionImage;
        if (!imageToRemove) {
            return;
        }
        imageToRemove.remove();
        if (!node || transitionImage === node) {
            transitionImage = null;
        }
    }

    /**
     * Handles update normal lightbox stage size behavior for the gallery UI.
     * @param {*} card Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function updateNormalLightboxStageSize(card) {
        if (!stageLink || !card || overlay.classList.contains('is-fullscreen') || overlay.classList.contains('is-mobile-fullscreen')) {
            return;
        }
        // naturalWidth stores state or configuration for the gallery front-end flow.
        const naturalWidth = Number.parseInt(card.dataset.imageWidth || '0', 10);
        // naturalHeight stores state or configuration for the gallery front-end flow.
        const naturalHeight = Number.parseInt(card.dataset.imageHeight || '0', 10);
        if (!naturalWidth || !naturalHeight) {
            stageLink.style.removeProperty('--lightbox-stage-width');
            stageLink.style.removeProperty('--lightbox-stage-height');
            return;
        }
        // rootFontSize stores state or configuration for the gallery front-end flow.
        const rootFontSize = Number.parseFloat(window.getComputedStyle(document.documentElement).fontSize) || 16;
        // availableWidth stores state or configuration for the gallery front-end flow.
        const availableWidth = Math.max(240, window.innerWidth - (12 * rootFontSize));
        // availableHeight stores state or configuration for the gallery front-end flow.
        const availableHeight = Math.max(180, window.innerHeight * 0.78);
        // imageRatio stores state or configuration for the gallery front-end flow.
        const imageRatio = naturalWidth / naturalHeight;
        // stageWidth stores state or configuration for the gallery front-end flow.
        let stageWidth = availableWidth;
        // stageHeight stores state or configuration for the gallery front-end flow.
        let stageHeight = stageWidth / imageRatio;
        if (stageHeight > availableHeight) {
            stageHeight = availableHeight;
            stageWidth = stageHeight * imageRatio;
        }
        stageLink.style.setProperty('--lightbox-stage-width', `${Math.round(stageWidth)}px`);
        stageLink.style.setProperty('--lightbox-stage-height', `${Math.round(stageHeight)}px`);
    }

    /**
     * Handles apply lightbox image source behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function applyLightboxImageSource(src, altText) {
        if (!src) {
            image.alt = altText;
            return;
        }
        galleryDevModeState.currentSource = src;
        galleryDevModeState.currentSourceKind = devFindSourceKind(src);
        devMarkSource(src, 'ready', 'display');
        if (image.getAttribute('src') === src) {
            image.alt = altText;
            return;
        }
        image.src = src;
        image.alt = altText;
    }

    /**
     * Handles show lightbox image source behavior for the gallery UI.
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} token Value supplied by the caller or event context.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @param {*} immediate Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function showLightboxImageSource(index, token, src, altText, immediate) {
        if (!src) {
            return Promise.resolve(false);
        }
        if (immediate || !stageLink || !image.getAttribute('src')) {
            activeLightboxTransitionToken += 1;
            removeTransitionImage();
            applyLightboxImageSource(src, altText);
            return Promise.resolve(true);
        }
        return loadDecodedLightboxImage(src).then((loadedImage) => new Promise((resolve) => {
            if (currentIndex !== index || activeLightboxImageToken !== token) {
                resolve(false);
                return;
            }
            if (image.getAttribute('src') === src) {
                image.alt = altText;
                resolve(true);
                return;
            }
            activeLightboxTransitionToken += 1;
            // transitionToken stores state or configuration for the gallery front-end flow.
            const transitionToken = activeLightboxTransitionToken;
            removeTransitionImage();
            // transitionNode stores state or configuration for the gallery front-end flow.
            const transitionNode = loadedImage.cloneNode(false);
            transitionNode.alt = '';
            transitionNode.setAttribute('aria-hidden', 'true');
            transitionNode.className = 'lightbox-transition-image';
            transitionImage = transitionNode;
            stageLink.append(transitionNode);
            requestAnimationFrame(() => {
                if (
                    currentIndex !== index ||
                    activeLightboxImageToken !== token ||
                    activeLightboxTransitionToken !== transitionToken ||
                    transitionImage !== transitionNode
                ) {
                    removeTransitionImage(transitionNode);
                    resolve(false);
                    return;
                }
                transitionNode.classList.add('is-visible');
                window.setTimeout(() => {
                    if (
                        currentIndex !== index ||
                        activeLightboxImageToken !== token ||
                        activeLightboxTransitionToken !== transitionToken ||
                        transitionImage !== transitionNode
                    ) {
                        removeTransitionImage(transitionNode);
                        resolve(false);
                        return;
                    }
                    applyLightboxImageSource(src, altText);
                    requestAnimationFrame(() => {
                        removeTransitionImage(transitionNode);
                        resolve(true);
                    });
                }, lightboxImageTransitionDuration);
            });
        })).catch(() => false);
    }

    /**
     * Handles swap lightbox image after decode behavior for the gallery UI.
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} token Value supplied by the caller or event context.
     * @param {*} previewSrc Value supplied by the caller or event context.
     * @param {*} fullSrc Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function swapLightboxImageAfterDecode(index, token, previewSrc, fullSrc, altText) {
        if (!fullSrc || !previewSrc || fullSrc === previewSrc) {
            return Promise.resolve(false);
        }
        clearPendingFullImageSwap();
        return new Promise((resolve) => {
            pendingFullImageSwapTimer = window.setTimeout(() => {
                pendingFullImageSwapTimer = null;
                loadDecodedLightboxImage(fullSrc).then(() => {
                    if (currentIndex !== index || activeLightboxImageToken !== token) {
                        resolve(false);
                        return;
                    }
                    applyLightboxImageSource(fullSrc, altText);
                    resolve(true);
                }).catch(() => resolve(false));
            }, lightboxFullSwapIdleDelay);
        });
    }

    // Function `openAt` executes this focused behavior.
    function openAt(index) {
        // Variable `card` stores this steps working value.
        const card = cards[index];
        if (!card) {
            return;
        }
        currentIndex = index;
        galleryDevModeState.currentIndex = index;
        activeLightboxImageToken += 1;
        activeLightboxTransitionToken += 1;
        clearPendingFullImageSwap();
        // imageToken stores state or configuration for the gallery front-end flow.
        const imageToken = activeLightboxImageToken;
        // pageUrl stores state or configuration for the gallery front-end flow.
        const pageUrl = card.dataset.pageUrl || '';
        // galleryUrl stores state or configuration for the gallery front-end flow.
        const galleryUrl = card.dataset.galleryUrl || window.location.href;
        if (!lightboxHistoryActive) {
            lightboxReturnUrl = galleryUrl;
            lightboxHistoryActive = true;
        }
        if (pageUrl && window.history && window.history.replaceState) {
            window.history.replaceState({lightbox: true}, '', pageUrl);
        }
        // previewSrc stores state or configuration for the gallery front-end flow.
        const previewSrc = card.dataset.previewSrc || card.dataset.fullSrc || '';
        // fullSrc stores state or configuration for the gallery front-end flow.
        const fullSrc = card.dataset.fullSrc || previewSrc;
        // altText stores state or configuration for the gallery front-end flow.
        const altText = card.dataset.title || '';
        updateNormalLightboxStageSize(card);
        // shouldShowImmediately stores state or configuration for the gallery front-end flow.
        const shouldShowImmediately = overlay.hidden || !image.getAttribute('src');
        preloadCardLightboxImages(card, true);
        showLightboxImageSource(index, imageToken, previewSrc, altText, shouldShowImmediately).then((wasDisplayed) => {
            if (!wasDisplayed || currentIndex !== index || activeLightboxImageToken !== imageToken) {
                return;
            }
            swapLightboxImageAfterDecode(index, imageToken, previewSrc, fullSrc, altText);
        });
        title.textContent = card.dataset.title || '';
        description.textContent = card.dataset.description || 'No description.';
        score.textContent = card.dataset.score || '0';
        if (counter) {
            counter.textContent = `${index + 1} / ${cards.length}`;
        }
        overlay.dataset.currentImageId = card.dataset.imageId || '';
        overlay.dataset.currentTitle = card.dataset.title || '';
        syncLightboxVote(card);
        if (lightboxMapButton) {
            // hasMapPoint stores state or configuration for the gallery front-end flow.
            const hasMapPoint = Boolean(card.dataset.mapPoint && card.dataset.mapPoint.trim());
            lightboxMapButton.hidden = !hasMapPoint;
            lightboxMapButton.dataset.mapPoint = hasMapPoint ? card.dataset.mapPoint.trim() : '';
        }
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            // mapPoint stores state or configuration for the gallery front-end flow.
            const mapPoint = card.dataset.mapPoint || '';
            if (mapPoint.trim()) {
                openLightboxMapSplit(mapPoint, card.dataset.title || title.textContent || 'Map');
            } else if (lightboxMapSplitTitle) {
                lightboxMapSplitTitle.textContent = card.dataset.title || title.textContent || 'Map';
            }
        }
        preloadAdjacentImages(index);
        overlay.hidden = false;
        document.body.classList.add('has-lightbox');
        updateLightboxViewportMode();
        showLightboxHud();
    }

    /**
     * Handles step behavior for the gallery UI.
     * @param {*} offset Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function step(offset) {
        if (cards.length === 0) {
            return;
        }
        // nextIndex stores state or configuration for the gallery front-end flow.
        const nextIndex = (currentIndex + offset + cards.length) % cards.length;
        openAt(nextIndex);
    }

    // Function `close` executes this focused behavior.
    function close() {
        exitLightboxFullscreen();
        clearLightboxHudTimer();
        overlay.classList.remove('is-ui-visible');
        clearTouchGesture();
        updateLightboxViewportMode();
        overlay.hidden = true;
        clearPendingFullImageSwap();
        removeTransitionImage();
        image.removeAttribute('src');
        galleryDevModeState.currentSource = '';
        galleryDevModeState.currentSourceKind = '';
        galleryDevModeState.currentIndex = -1;
        document.body.classList.remove('has-lightbox');
        if (lightboxHistoryActive && lightboxReturnUrl && window.history && window.history.replaceState) {
            window.history.replaceState({}, '', lightboxReturnUrl);
        }
        lightboxHistoryActive = false;
    }


    /**
     * Handles preload card lightbox images behavior for the gallery UI.
     * @param {*} card Value supplied by the caller or event context.
     * @param {*} includeFullImage Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function preloadCardLightboxImages(card, includeFullImage) {
        if (!card) {
            return;
        }
        // previewSrc stores state or configuration for the gallery front-end flow.
        const previewSrc = card.dataset.previewSrc || card.dataset.fullSrc || '';
        // fullSrc stores state or configuration for the gallery front-end flow.
        const fullSrc = card.dataset.fullSrc || previewSrc;
        [previewSrc, includeFullImage ? fullSrc : ''].forEach((src) => {
            if (!src) {
                return;
            }
            preloadedSources.add(src);
            devMarkSource(src, 'preloading', includeFullImage && src === fullSrc ? 'adjacent-full' : 'adjacent-preview');
            preloadDecodedLightboxImage(src);
        });
    }

    /**
     * Handles preload adjacent images behavior for the gallery UI.
     * @param {*} index Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function preloadAdjacentImages(index) {
        if (shouldLimitLightboxPreloading()) {
            return;
        }
        // previewOffsets stores state or configuration for the gallery front-end flow.
        const previewOffsets = [];
        for (let distance = 1; distance <= lightboxPreviewPreloadRadius; distance += 1) {
            previewOffsets.push(distance, -distance);
        }
        previewOffsets.forEach((offset) => {
            // normalizedIndex stores state or configuration for the gallery front-end flow.
            const normalizedIndex = (index + offset + cards.length) % cards.length;
            // card stores state or configuration for the gallery front-end flow.
            const card = cards[normalizedIndex];
            preloadCardLightboxImages(card, Math.abs(offset) <= lightboxFullPreloadRadius);
        });
    }

    /**
     * Handles should limit lightbox preloading behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function shouldLimitLightboxPreloading() {
        // connection stores state or configuration for the gallery front-end flow.
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!connection) {
            return false;
        }
        if (connection.saveData) {
            return true;
        }
        return ['slow-2g', '2g'].includes(connection.effectiveType);
    }

    cards.forEach((card, index) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('form, [data-admin-inline-editor], [data-photo-map], [data-gallery-map-url]')) {
                return;
            }
            event.preventDefault();
            openAt(index);
        });
    });

    overlay.addEventListener('click', (event) => {
        // target stores state or configuration for the gallery front-end flow.
        const target = event.target instanceof Element ? event.target : null;
        // actionTarget stores state or configuration for the gallery front-end flow.
        const actionTarget = target?.closest('[data-lightbox-action]');
        // Variable `action` stores this steps working value.
        const action = actionTarget?.dataset.lightboxAction;
        if (target?.closest('[data-lightbox-stage]')) {
            event.preventDefault();
            clearLightboxStageFocus();
            toggleLightboxFullscreen().finally(clearLightboxStageFocus);
            return;
        }
        if (action === 'close' || event.target === overlay) {
            close();
            return;
        }
        if (action === 'previous') {
            step(-1);
            return;
        }
        if (action === 'next') {
            step(1);
            return;
        }
        if (action === 'fullscreen') {
            event.preventDefault();
            toggleLightboxFullscreen();
            return;
        }
        // mapButton stores state or configuration for the gallery front-end flow.
        const mapButton = target?.closest('[data-lightbox-map]');
        if (mapButton) {
            event.preventDefault();
            if (isLightboxFullscreen()) {
                toggleLightboxMapSplit(mapButton.dataset.mapPoint || '', overlay.dataset.currentTitle || '');
            } else {
                openPhotoMapFromJson(mapButton.dataset.mapPoint || '');
            }
        }
    });

    if (lightboxMapSplitClose) {
        lightboxMapSplitClose.addEventListener('click', closeLightboxMapSplit);
    }

    if (stageLink) {
        stageLink.addEventListener('mousedown', (event) => {
            if (event.button === 0) {
                event.preventDefault();
            }
        });
    }

    overlay.addEventListener('mousemove', showLightboxHud);
    overlay.addEventListener('pointermove', showLightboxHud);
    overlay.addEventListener('mouseleave', scheduleHideLightboxHud);
    if (supportsPointerGestures) {
        overlay.addEventListener('pointerdown', startTouchGesture);
        overlay.addEventListener('pointermove', trackTouchGesture);
        overlay.addEventListener('pointerup', finishTouchGesture);
        overlay.addEventListener('pointercancel', clearTouchGesture);
    } else {
        overlay.addEventListener('touchstart', startTouchGesture, {passive: false});
        overlay.addEventListener('touchmove', trackTouchGesture, {passive: false});
        overlay.addEventListener('touchend', finishTouchGesture, {passive: false});
        overlay.addEventListener('touchcancel', clearTouchGesture);
    }
    overlay.addEventListener('fullscreenchange', syncLightboxFullscreenState);
    document.addEventListener('fullscreenchange', syncLightboxFullscreenState);
    window.addEventListener('resize', () => {
        if (overlay.hidden || currentIndex < 0) {
            return;
        }
        updateNormalLightboxStageSize(cards[currentIndex]);
    });

    document.addEventListener('keydown', (event) => {
        if (overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            if (isLightboxFullscreen()) {
                event.preventDefault();
                exitLightboxFullscreen();
                return;
            }
            close();
        }
        if (event.key === 'ArrowLeft') {
            step(-1);
        }
        if (event.key === 'ArrowRight') {
            step(1);
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            submitLightboxVote(1);
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            submitLightboxVote(-1);
        }
        if (event.key === 'f' || (event.key === 'F' && event.shiftKey === false) || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f')) {
            event.preventDefault();
            toggleLightboxFullscreen();
        }
    });

    // Function `submitLightboxVote` executes this focused behavior.
    function submitLightboxVote(value) {
        if (!lightboxVoteForm || lightboxVoteForm.closest('[hidden]')) {
            return;
        }
        // Variable `button` stores this steps working value.
        const button = lightboxVoteForm.querySelector(`button[name="vote"][value="${value}"]`);
        if (button) {
            button.click();
        }
    }

    /**
     * Handles is lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function isLightboxFullscreen() {
        return overlay.classList.contains('is-fullscreen');
    }

    /**
     * Handles toggle lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function toggleLightboxFullscreen() {
        debugLightbox('toggle:before', {
            mobile: isMobileTouchDevice,
            fullscreen: isLightboxFullscreen(),
            browserFullscreen: Boolean(document.fullscreenElement),
        });
        if (isLightboxFullscreen()) {
            await exitLightboxFullscreen();
            debugLightbox('toggle:exit');
            return;
        }
        await enterLightboxFullscreen();
        debugLightbox('toggle:enter');
    }

    /**
     * Handles enter lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function enterLightboxFullscreen() {
        overlay.classList.add('is-fullscreen');
        overlay.classList.remove('is-ui-visible');
        if (isMobileTouchDevice) {
            overlay.classList.add('is-mobile-fullscreen');
            document.body.classList.add('has-mobile-lightbox');
            debugLightbox('enter:mobile-css');
            showLightboxHud();
            return;
        }
        try {
            if (overlay.requestFullscreen) {
                await overlay.requestFullscreen();
                debugLightbox('enter:native');
                return;
            }
        } catch {
            // Browser fullscreen can fail; the CSS fullscreen fallback still applies.
        }
        overlay.classList.add('is-mobile-fullscreen');
        document.body.classList.add('has-mobile-lightbox');
        debugLightbox('enter:fallback-css');
        showLightboxHud();
    }

    /**
     * Handles exit lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function exitLightboxFullscreen() {
        overlay.classList.remove('is-fullscreen');
        overlay.classList.remove('is-mobile-fullscreen');
        closeLightboxMapSplit();
        document.body.classList.remove('has-mobile-lightbox');
        if (!isMobileTouchDevice && document.fullscreenElement) {
            try {
                await document.exitFullscreen();
            } catch {
                // Ignore fullscreen exit failures.
            }
        }
        clearLightboxStageFocus();
        debugLightbox('exit');
    }

    /**
     * Handles sync lightbox fullscreen state behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function syncLightboxFullscreenState() {
        if (isMobileTouchDevice) {
            return;
        }
        if (!document.fullscreenElement && overlay.classList.contains('is-fullscreen')) {
            overlay.classList.remove('is-fullscreen');
            overlay.classList.remove('is-mobile-fullscreen');
            overlay.classList.remove('is-ui-visible');
            document.body.classList.remove('has-mobile-lightbox');
            clearLightboxStageFocus();
            debugLightbox('sync:browser-exit');
            return;
        }
        if (document.fullscreenElement === overlay) {
            overlay.classList.add('is-fullscreen');
            overlay.classList.remove('is-mobile-fullscreen');
            document.body.classList.remove('has-mobile-lightbox');
            overlay.classList.remove('is-ui-visible');
            debugLightbox('sync:browser-enter');
        }
    }

    /**
     * Handles clear lightbox hud timer behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearLightboxHudTimer() {
        if (fullscreenHideTimer) {
            clearTimeout(fullscreenHideTimer);
            fullscreenHideTimer = null;
        }
    }

    /**
     * Handles show lightbox hud behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function showLightboxHud() {
        clearLightboxHudTimer();
        overlay.classList.add('is-ui-visible');
        if (isLightboxFullscreen()) {
            fullscreenHideTimer = window.setTimeout(() => {
                overlay.classList.remove('is-ui-visible');
            }, 1800);
        } else {
            overlay.classList.remove('is-ui-visible');
        }
    }

    /**
     * Handles schedule hide lightbox hud behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function scheduleHideLightboxHud() {
        if (!isLightboxFullscreen()) {
            return;
        }
        clearLightboxHudTimer();
        fullscreenHideTimer = window.setTimeout(() => {
            if (isLightboxFullscreen()) {
                overlay.classList.remove('is-ui-visible');
            }
        }, 1200);
    }

    /**
     * Handles update lightbox viewport mode behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function updateLightboxViewportMode() {
        document.body.classList.toggle('has-mobile-lightbox', overlay.classList.contains('is-mobile-fullscreen'));
    }

    /**
     * Handles clear touch gesture behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearTouchGesture() {
        if (touchGesture && touchGesture.pointerId !== null) {
            try {
                overlay.releasePointerCapture?.(touchGesture.pointerId);
            } catch {
                // Ignore pointer capture release failures from older mobile engines.
            }
        }
        touchGesture = null;
    }

    /**
     * Handles start touch gesture behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function startTouchGesture(event) {
        if (overlay.hidden || !isLightboxFullscreen()) {
            return;
        }
        // point stores state or configuration for the gallery front-end flow.
        const point = lightboxGesturePoint(event);
        if (!point) {
            return;
        }
        if (event.type === 'pointerdown' && (event.pointerType === 'mouse' || event.button !== 0)) {
            return;
        }
        if (isMobileTouchDevice) {
            showLightboxHud();
        }
        // target stores state or configuration for the gallery front-end flow.
        const target = event.target instanceof Element ? event.target : null;
        if (isLightboxControlTarget(target)) {
            return;
        }
        touchGesture = {
            pointerId: event.type === 'pointerdown' ? event.pointerId : null,
            startX: point.clientX,
            startY: point.clientY,
            lastX: point.clientX,
            lastY: point.clientY,
            startedAt: Date.now(),
            active: true,
        };
        if (touchGesture.pointerId !== null) {
            try {
                overlay.setPointerCapture?.(touchGesture.pointerId);
            } catch {
                // Pointer capture is best-effort on mobile browsers.
            }
        }
    }

    /**
     * Handles track touch gesture behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function trackTouchGesture(event) {
        if (!touchGesture || !touchGesture.active) {
            return;
        }
        if (touchGesture.pointerId !== null && event.pointerId !== touchGesture.pointerId) {
            return;
        }
        // point stores state or configuration for the gallery front-end flow.
        const point = lightboxGesturePoint(event);
        if (!point) {
            return;
        }
        touchGesture.lastX = point.clientX;
        touchGesture.lastY = point.clientY;
        // dx stores state or configuration for the gallery front-end flow.
        const dx = touchGesture.lastX - touchGesture.startX;
        // dy stores state or configuration for the gallery front-end flow.
        const dy = touchGesture.lastY - touchGesture.startY;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 12) {
            event.preventDefault();
        }
        if (Math.abs(dx) > 18 || Math.abs(dy) > 18) {
            overlay.classList.add('is-ui-visible');
        }
    }

    /**
     * Handles finish touch gesture behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function finishTouchGesture(event) {
        if (!touchGesture || !touchGesture.active) {
            return;
        }
        if (touchGesture.pointerId !== null && event.pointerId !== touchGesture.pointerId) {
            return;
        }
        // point stores state or configuration for the gallery front-end flow.
        const point = lightboxGesturePoint(event) || {clientX: touchGesture.lastX, clientY: touchGesture.lastY};
        // dx stores state or configuration for the gallery front-end flow.
        const dx = point.clientX - touchGesture.startX;
        // dy stores state or configuration for the gallery front-end flow.
        const dy = point.clientY - touchGesture.startY;
        // elapsed stores state or configuration for the gallery front-end flow.
        const elapsed = Date.now() - touchGesture.startedAt;
        clearTouchGesture();
        if (Math.abs(dx) < 42 || Math.abs(dx) < Math.abs(dy) || elapsed > 1200) {
            return;
        }
        event.preventDefault();
        if (dx < 0) {
            step(1);
        } else {
            step(-1);
        }
    }

    /**
     * Handles lightbox gesture point behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function lightboxGesturePoint(event) {
        if (event.changedTouches && event.changedTouches.length > 0) {
            return event.changedTouches[0];
        }
        if (event.touches && event.touches.length > 0) {
            return event.touches[0];
        }
        if (typeof event.clientX === 'number' && typeof event.clientY === 'number') {
            return event;
        }
        return null;
    }

    /**
     * Handles is lightbox control target behavior for the gallery UI.
     * @param {*} target Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function isLightboxControlTarget(target) {
        if (!target) {
            return false;
        }
        if (target.closest('.lightbox-hud')) {
            return true;
        }
        if (target.closest('.lightbox-meta')) {
            return Boolean(target.closest('button, a, input, textarea, select, form'));
        }
        return Boolean(target.closest('button, input, textarea, select, form'));
    }

    /**
     * Handles detect mobile touch device behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function detectMobileTouchDevice() {
        // hasTouch stores state or configuration for the gallery front-end flow.
        const hasTouch = navigator.maxTouchPoints > 0 || window.matchMedia?.('(pointer: coarse)').matches;
        if (!hasTouch) {
            return false;
        }
        // userAgent stores state or configuration for the gallery front-end flow.
        const userAgent = navigator.userAgent || '';
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i.test(userAgent)
            || (/Macintosh/i.test(userAgent) && navigator.maxTouchPoints > 1);
    }

    /**
     * Handles debug lightbox behavior for the gallery UI.
     * @param {*} message Value supplied by the caller or event context.
     * @param {*} details Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function debugLightbox(message, details = {}) {
        if (!isLightboxDebugEnabled) {
            return;
        }
        console.debug('[lightbox]', message, details);
    }

    /**
     * Handles detect lightbox debug flag behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function detectLightboxDebugFlag() {
        if (new URLSearchParams(window.location.search).has('lightbox_debug')) {
            return true;
        }
        try {
            return window.localStorage.getItem('lightbox_debug') === '1';
        } catch {
            return false;
        }
    }


    // Function `setupGpsMaps` executes this focused behavior.
    function setupGpsMaps() {
        document.addEventListener('click', async (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }
            // Variable `photoButton` stores this steps working value.
            const photoButton = event.target.closest('[data-photo-map]');
            if (photoButton) {
                // The photo pin is rendered inside the clickable image card.
                // Stop the card click handler as early as possible so the pin
                // opens the map directly instead of opening the photo lightbox.
                event.preventDefault();
                event.stopPropagation();
                // Variable `card` stores this steps working value.
                const card = photoButton.closest('[data-lightbox-image]');
                openPhotoMapFromJson(photoButton.dataset.mapPoint || card?.dataset.mapPoint || '');
                return;
            }
            // Variable `galleryButton` stores this steps working value.
            const galleryButton = event.target.closest('[data-gallery-map-url]');
            if (galleryButton) {
                event.preventDefault();
                event.stopPropagation();
                await openGalleryMap(galleryButton.dataset.galleryMapUrl || '', galleryButton.dataset.galleryMapTitle || 'Gallery map');
            }
        }, true);
    }

    // Function `openPhotoMapFromJson` executes this focused behavior.
    function openPhotoMapFromJson(json) {
        if (!json) {
            return;
        }
        try {
            // Variable `point` stores this steps working value.
            const point = JSON.parse(json);
            openMapOverlay(point.title || 'Photo location', [point]);
        } catch {
            // Invalid rendered JSON should not break the gallery UI.
        }
    }

    // Function `openGalleryMap` executes this focused behavior.
    async function openGalleryMap(url, title) {
        if (!url) {
            return;
        }
        try {
            // Variable `response` stores this steps working value.
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            if (!response.ok) {
                return;
            }
            // Variable `payload` stores this steps working value.
            const payload = await response.json();
            openMapOverlay(payload.title || title, payload.points || []);
        } catch {
            // Network and JSON errors are ignored so the normal gallery remains usable.
        }
    }

    // Function `ensureLeaflet` executes this focused behavior.
    function ensureLeaflet() {
        if (window.L && document.querySelector('link[data-gallery-leaflet-css]')) {
            configureLeafletMarkerIcon();
            return Promise.resolve();
        }
        if (window.galleryLeafletLoading) {
            return window.galleryLeafletLoading;
        }

        // Leaflet depends on its stylesheet for tile-pane positioning and image
        // state during zoom/fullscreen transitions. The app keeps a local CSS
        // fallback below, but loading the official stylesheet first prevents
        // Chromium fullscreen from showing stale tile panes as a visible grid.
        window.galleryLeafletLoading = Promise.all([
            ensureLeafletStylesheet(),
            ensureLeafletScript(),
        ]).then(() => {
            configureLeafletMarkerIcon();
        });

        return window.galleryLeafletLoading;
    }

    // Function `ensureLeafletStylesheet` executes this focused behavior.
    function ensureLeafletStylesheet() {
        // existingStylesheet stores state or configuration for the gallery front-end flow.
        const existingStylesheet = document.querySelector('link[data-gallery-leaflet-css]');
        if (existingStylesheet) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            // Variable `stylesheet` stores this steps working value.
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            stylesheet.integrity = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
            stylesheet.crossOrigin = '';
            stylesheet.dataset.galleryLeafletCss = 'true';
            stylesheet.onload = () => resolve();
            stylesheet.onerror = () => resolve();
            document.head.append(stylesheet);
        });
    }

    // Function `configureLeafletMarkerIcon` executes this focused behavior.
    function configureLeafletMarkerIcon() {
        if (!window.L || !L.Icon || !L.Icon.Default) {
            return;
        }

        // Leaflet normally detects marker image URLs from leaflet.css. The app
        // loads Leaflet dynamically and can run inside fullscreen/modal scopes,
        // where custom gallery image CSS may make that detection unreliable.
        // Use explicit upstream image URLs so normal maps and fullscreen split
        // maps both keep the blue GPS marker.
        L.Icon.Default.mergeOptions({
            iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
            iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
            shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        });
    }

    // Function `getGalleryMapMarkerIcon` executes this focused behavior.
    function getGalleryMapMarkerIcon() {
        if (!window.L || !L.divIcon) {
            return undefined;
        }

        if (!window.galleryMapMarkerIcon) {
            window.galleryMapMarkerIcon = L.divIcon({
                className: 'gallery-leaflet-marker',
                html: '<span class="gallery-leaflet-marker-shadow" aria-hidden="true"></span><span class="gallery-leaflet-marker-pin" aria-hidden="true"></span>',
                iconAnchor: [13, 40],
                iconSize: [26, 40],
                popupAnchor: [0, -36],
            });
        }

        return window.galleryMapMarkerIcon;
    }

    // Function `ensureLeafletScript` executes this focused behavior.
    function ensureLeafletScript() {
        if (window.L) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            // Variable `existingScript` stores this steps working value.
            const existingScript = document.querySelector('script[data-gallery-leaflet-js]');
            if (existingScript) {
                existingScript.addEventListener('load', () => resolve(), {once: true});
                existingScript.addEventListener('error', () => reject(new Error('Leaflet failed to load.')), {once: true});
                return;
            }

            // Variable `script` stores this steps working value.
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
            script.crossOrigin = '';
            script.dataset.galleryLeafletJs = 'true';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Leaflet failed to load.'));
            document.head.append(script);
        });
    }

    // Function `afterNextPaint` executes this focused behavior.
    function afterNextPaint() {
        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(resolve);
            });
        });
    }

    // Function `openMapOverlay` executes this focused behavior.
    async function openMapOverlay(title, points) {
        if (!Array.isArray(points) || points.length === 0) {
            return;
        }
        await ensureLeaflet();
        // Variable `overlay` stores this steps working value.
        let overlay = document.querySelector('[data-map-overlay]');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'map-overlay';
            overlay.dataset.mapOverlay = 'true';
            overlay.innerHTML = '<div class="map-dialog"><button type="button" class="map-close" data-map-close>Close</button><h2 data-map-title></h2><div class="map-canvas" data-map-canvas></div><p class="muted map-attribution-note">Map tiles by OpenStreetMap contributors. Heavy production traffic should use a dedicated tile provider.</p></div>';
            document.body.append(overlay);
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay || event.target.closest('[data-map-close]')) {
                    overlay.hidden = true;
                    document.body.classList.remove('has-map-overlay');
                }
            });
        }
        overlay.hidden = false;
        document.body.classList.add('has-map-overlay');
        overlay.querySelector('[data-map-title]').textContent = title;

        // Wait until the overlay is painted. Leaflet reads the canvas size at
        // startup, so initializing it in the same task that unhides the modal
        // can produce partially offset tiles in Chromium-based browsers.
        await afterNextPaint();

        // Variable `canvas` stores this steps working value.
        const canvas = overlay.querySelector('[data-map-canvas]');
        await waitForElementSize(canvas);
        if (overlay.galleryLeafletMap) {
            overlay.galleryLeafletMap.remove();
            overlay.galleryLeafletMap = null;
        }
        canvas.innerHTML = '';

        // Variable `map` stores this steps working value.
        const map = L.map(canvas, {
            fadeAnimation: false,
            markerZoomAnimation: false,
            zoomAnimation: false,
        });
        overlay.galleryLeafletMap = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        // Variable `bounds` stores this steps working value.
        const bounds = [];
        points.forEach((point) => {
            if (typeof point.lat !== 'number' || typeof point.lng !== 'number') {
                return;
            }
            // Variable `marker` stores this steps working value.
            const marker = L.marker([point.lat, point.lng], {icon: getGalleryMapMarkerIcon()}).addTo(map);
            marker.bindPopup(mapPopupHtml(point));
            bounds.push([point.lat, point.lng]);
        });

        setInitialMapViewport(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map);
        stabilizeMapAfterLayout(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map);
    }

    /**
     * Handles toggle lightbox map split behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function toggleLightboxMapSplit(json, title) {
        if (!json || !isLightboxFullscreen()) {
            return;
        }
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            closeLightboxMapSplit();
            return;
        }
        openLightboxMapSplit(json, title);
    }

    /**
     * Handles open lightbox map split behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function openLightboxMapSplit(json, title) {
        // points stores state or configuration for the gallery front-end flow.
        const points = parseMapPoints(json);
        if (!points.length || !lightboxMapSplit || !lightboxMapSplitCanvas) {
            return;
        }
        await ensureLeaflet();
        lightboxMapSplit.hidden = false;
        lightboxMapSplitTitle.textContent = title || 'Map';
        overlay.classList.add('is-map-split');
        await waitForElementSize(lightboxMapSplitCanvas);
        if (overlay.galleryLeafletSplitMap) {
            overlay.galleryLeafletSplitMap.remove();
            overlay.galleryLeafletSplitMap = null;
        }
        lightboxMapSplitCanvas.innerHTML = '';
        // map stores state or configuration for the gallery front-end flow.
        const map = L.map(lightboxMapSplitCanvas, {
            fadeAnimation: false,
            markerZoomAnimation: false,
            zoomAnimation: false,
        });
        overlay.galleryLeafletSplitMap = map;
        if (overlay.galleryLeafletSplitResizeObserver) {
            overlay.galleryLeafletSplitResizeObserver.disconnect();
            overlay.galleryLeafletSplitResizeObserver = null;
        }
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);
        // bounds stores state or configuration for the gallery front-end flow.
        const bounds = [];
        points.forEach((point) => {
            if (typeof point.lat !== 'number' || typeof point.lng !== 'number') {
                return;
            }
            // marker stores state or configuration for the gallery front-end flow.
            const marker = L.marker([point.lat, point.lng], {icon: getGalleryMapMarkerIcon()}).addTo(map);
            marker.bindPopup(mapPopupHtml(point));
            bounds.push([point.lat, point.lng]);
        });
        setInitialMapViewport(map, bounds, {padding: [24, 24]}, () => overlay.galleryLeafletSplitMap === map);
        stabilizeMapAfterLayout(map, bounds, {padding: [24, 24]}, () => overlay.galleryLeafletSplitMap === map);
        overlay.galleryLeafletSplitResizeObserver = new ResizeObserver(() => {
            if (isUsableLeafletMap(overlay.galleryLeafletSplitMap)) {
                overlay.galleryLeafletSplitMap.invalidateSize(false);
            }
        });
        overlay.galleryLeafletSplitResizeObserver.observe(lightboxMapSplitCanvas);
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                if (isUsableLeafletMap(overlay.galleryLeafletSplitMap)) {
                    overlay.galleryLeafletSplitMap.invalidateSize(false);
                }
            });
        });
    }

    /**
     * Handles is usable leaflet map behavior for the gallery UI.
     * @param {*} map Value supplied by the caller or event context.
     * @param {*} isCurrent Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function isUsableLeafletMap(map, isCurrent = () => true) {
        return Boolean(
            map &&
            isCurrent() &&
            map._container &&
            map._container.isConnected &&
            map._mapPane
        );
    }

    // Function `setInitialMapViewport` executes this focused behavior.
    function setInitialMapViewport(map, bounds, options, isCurrent = () => true) {
        requestAnimationFrame(() => {
            if (!isUsableLeafletMap(map, isCurrent) || bounds.length === 0) {
                return;
            }
            try {
                map.invalidateSize(false);
                if (bounds.length === 1) {
                    map.setView(bounds[0], 15, {animate: false});
                } else if (bounds.length > 1) {
                    map.fitBounds(bounds, {...options, animate: false});
                }
            } catch {
                // Leaflet can briefly expose a stale map pane while overlays are
                // being recreated. Later stabilization passes will retry.
            }
        });
    }

    // Function `stabilizeMapAfterLayout` executes this focused behavior.
    function stabilizeMapAfterLayout(map, bounds, options, isCurrent = () => true) {
        // refreshDelays stores state or configuration for the gallery front-end flow.
        const refreshDelays = [0, 60, 150, 350];
        refreshDelays.forEach((delay) => {
            window.setTimeout(() => {
                if (!isUsableLeafletMap(map, isCurrent)) {
                    return;
                }
                setInitialMapViewport(map, bounds, options, isCurrent);
            }, delay);
        });
    }

    /**
     * Handles wait for element size behavior for the gallery UI.
     * @param {*} element Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function waitForElementSize(element) {
        for (let attempt = 0; attempt < 12; attempt += 1) {
            // rect stores state or configuration for the gallery front-end flow.
            const rect = element.getBoundingClientRect();
            // computed stores state or configuration for the gallery front-end flow.
            const computed = window.getComputedStyle(element);
            if (rect.width > 0 && rect.height > 0 && computed.display !== 'none' && computed.visibility !== 'hidden') {
                return;
            }
            await afterNextPaint();
        }
    }

    /**
     * Handles close lightbox map split behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function closeLightboxMapSplit() {
        if (overlay.galleryLeafletSplitResizeObserver) {
            overlay.galleryLeafletSplitResizeObserver.disconnect();
            overlay.galleryLeafletSplitResizeObserver = null;
        }
        if (lightboxMapSplit) {
            lightboxMapSplit.hidden = true;
        }
        overlay.classList.remove('is-map-split');
        if (overlay.galleryLeafletSplitMap) {
            overlay.galleryLeafletSplitMap.remove();
            overlay.galleryLeafletSplitMap = null;
        }
        if (lightboxMapSplitCanvas) {
            lightboxMapSplitCanvas.innerHTML = '';
        }
    }

    /**
     * Handles parse map points behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function parseMapPoints(json) {
        try {
            // parsed stores state or configuration for the gallery front-end flow.
            const parsed = JSON.parse(json);
            return Array.isArray(parsed) ? parsed : [parsed];
        } catch {
            return [];
        }
    }

    // Function `mapPopupHtml` executes this focused behavior.
    function mapPopupHtml(point) {
        // Variable `title` stores this steps working value.
        const title = escapeHtml(point.title || 'Photo');
        // Variable `description` stores this steps working value.
        const description = point.description ? `<p>${escapeHtml(point.description)}</p>` : '';
        // Variable `thumb` stores this steps working value.
        const thumb = point.thumb ? `<img decoding="async" loading="lazy" src="${escapeAttribute(point.thumb)}" alt="">` : '';
        // Variable `image` stores this steps working value.
        const image = point.image ? `<p><a href="${escapeAttribute(point.image)}">Open photo</a></p>` : '';
        return `<div class="map-popup">${thumb}<h3>${title}</h3>${description}${image}</div>`;
    }

    // Function `escapeHtml` executes this focused behavior.
    function escapeHtml(value) {
        return String(value).replace(/[&<>"]/g, (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[character]));
    }

    // Function `escapeAttribute` executes this focused behavior.
    function escapeAttribute(value) {
        return escapeHtml(value).replace(/'/g, '&#039;');
    }

    // Function `setupGalleryRefreshProgress` executes this focused behavior.
    function setupGalleryRefreshProgress() {
        document.querySelectorAll('[data-refresh-galleries-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            form.addEventListener('submit', (event) => {
                if (form.dataset.submitting === '1') {
                    return;
                }
                event.preventDefault();
                form.dataset.submitting = '1';
                // Variable `button` stores this steps working value.
                const button = form.querySelector('button[type="submit"], input[type="submit"]');
                if (button) {
                    button.disabled = true;
                    if ('value' in button && button.tagName === 'INPUT') {
                        button.value = 'Scanning...';
                    } else {
                        button.textContent = 'Scanning...';
                    }
                }
                // progress stores state or configuration for the gallery front-end flow.
                const progress = ensureGalleryRefreshProgress(form);
                progress.hidden = false;
                requestAnimationFrame(() => {
                    setTimeout(() => HTMLFormElement.prototype.submit.call(form), 40);
                });
            });
        });
    }

    // Function `ensureGalleryRefreshProgress` executes this focused behavior.
    function ensureGalleryRefreshProgress(form) {
        // progress stores state or configuration for the gallery front-end flow.
        let progress = document.querySelector('[data-gallery-refresh-progress]');
        if (progress) {
            return progress;
        }
        progress = document.createElement('div');
        progress.className = 'thumbnail-progress';
        progress.dataset.galleryRefreshProgress = 'true';
        progress.innerHTML = '<progress class="thumbnail-progress-bar"></progress><p class="muted">Scanning existing galleries and checking for new gallery folders...</p>';
        // target stores state or configuration for the gallery front-end flow.
        const target = form.closest('.hero') || form;
        target.insertAdjacentElement('afterend', progress);
        return progress;
    }

    // Function `setupGalleryUploadProgress` executes this focused behavior.
    function setupGalleryUploadProgress() {
        document.querySelectorAll('[data-gallery-upload-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                runGalleryUpload(form);
            });
        });
    }

    // Function `runGalleryUpload` executes this focused behavior.
    async function runGalleryUpload(form) {
        // progress stores state or configuration for the gallery front-end flow.
        const progress = ensureThumbnailProgress(form);
        // buttons stores state or configuration for the gallery front-end flow.
        const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
        buttons.forEach((button) => {
            button.disabled = true;
        });
        try {
            // createThumbnails stores state or configuration for the gallery front-end flow.
            const createThumbnails = Boolean(form.querySelector('input[name="create_thumbnails"]')?.checked);
            // result stores state or configuration for the gallery front-end flow.
            const result = await runGalleryUploadFiles(form, progress, createThumbnails);
            if (createThumbnails) {
                updateThumbnailProgress(progress, result.uploaded || 0, result.total_files || 0, result.thumbnails || 0, result.thumbnail_skipped || 0, 'Upload and thumbnail job complete.');
            } else {
                updateBasicProgress(progress, 100, `Uploaded ${result.uploaded || 0} images. Scanning complete.`);
            }
            window.location.href = result.redirect_url || adminUrlWithParams({uploaded: result.uploaded || 0, scanned: result.scanned || 0, thumbnails: result.thumbnails || 0});
        } catch (error) {
            updateBasicProgress(progress, 100, error.message || 'Upload failed.');
        } finally {
            buttons.forEach((button) => {
                button.disabled = false;
            });
        }
    }

    /**
     * Handles selected gallery upload files behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function selectedGalleryUploadFiles(form) {
        // fileInput stores state or configuration for the gallery front-end flow.
        const fileInput = form.querySelector('input[type="file"][name="images[]"]');
        if (!(fileInput instanceof HTMLInputElement) || !fileInput.files || fileInput.files.length === 0) {
            return [];
        }
        return Array.from(fileInput.files);
    }

    /**
     * Handles gallery upload base body behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function galleryUploadBaseBody(form) {
        // body stores state or configuration for the gallery front-end flow.
        const body = new FormData();
        Array.from(form.elements).forEach((field) => {
            if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
                return;
            }
            if (!field.name || field.disabled || field.type === 'file') {
                return;
            }
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }
            body.append(field.name, field.value);
        });
        body.set('ajax', '1');
        return body;
    }

    /**
     * Handles clone gallery upload body behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @param {*} files Value supplied by the caller or event context.
     * @param {*} galleryId Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function cloneGalleryUploadBody(form, files, galleryId) {
        // body stores state or configuration for the gallery front-end flow.
        const body = galleryUploadBaseBody(form);
        if (galleryId > 0) {
            body.set('upload_mode', 'existing');
            body.set('gallery_id', String(galleryId));
        }
        files.forEach((file) => {
            body.append('images[]', file, file.name);
        });
        return body;
    }

    /**
     * Handles run gallery upload files behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @param {*} progress Value supplied by the caller or event context.
     * @param {*} createThumbnails Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function runGalleryUploadFiles(form, progress, createThumbnails) {
        // files stores state or configuration for the gallery front-end flow.
        const files = selectedGalleryUploadFiles(form);
        if (files.length === 0) {
            throw new Error('Choose at least one image to upload.');
        }

        // uploaded stores state or configuration for the gallery front-end flow.
        let uploaded = 0;
        // scanned stores state or configuration for the gallery front-end flow.
        let scanned = 0;
        // thumbnails stores state or configuration for the gallery front-end flow.
        let thumbnails = 0;
        // thumbnailSkipped stores state or configuration for the gallery front-end flow.
        let thumbnailSkipped = 0;
        // galleryId stores state or configuration for the gallery front-end flow.
        let galleryId = Number(form.querySelector('select[name="gallery_id"]')?.value || 0);
        // redirectUrl stores state or configuration for the gallery front-end flow.
        let redirectUrl = '';
        // galleryIds stores state or configuration for the gallery front-end flow.
        const galleryIds = [];

        for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
            // file stores state or configuration for the gallery front-end flow.
            const file = files[fileIndex];
            // humanIndex stores state or configuration for the gallery front-end flow.
            const humanIndex = fileIndex + 1;
            updateBasicProgress(progress, Math.round((fileIndex / files.length) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
            // uploadResult stores state or configuration for the gallery front-end flow.
            const uploadResult = await sendGalleryUploadChunk(form, cloneGalleryUploadBody(form, [file], galleryId), (event) => {
                if (!event.lengthComputable) {
                    updateBasicProgress(progress, Math.round((fileIndex / files.length) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
                    return;
                }
                // completedPart stores state or configuration for the gallery front-end flow.
                const completedPart = fileIndex / files.length;
                // currentPart stores state or configuration for the gallery front-end flow.
                const currentPart = (event.loaded / event.total) / files.length;
                updateBasicProgress(progress, Math.round((completedPart + currentPart) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
            });

            if (!galleryId) {
                galleryId = Number(uploadResult.gallery_id || 0);
            }
            if (galleryId && !galleryIds.includes(galleryId)) {
                galleryIds.push(galleryId);
            }
            uploaded += Number(uploadResult.uploaded || 0);
            scanned += Number(uploadResult.scanned || 0);
            redirectUrl = uploadResult.redirect_url || redirectUrl;

            if (createThumbnails) {
                // imageIds stores state or configuration for the gallery front-end flow.
                const imageIds = Array.isArray(uploadResult.image_ids) ? uploadResult.image_ids : [];
                // thumbResult stores state or configuration for the gallery front-end flow.
                const thumbResult = await runUploadedImageThumbnailJob(form, progress, imageIds, humanIndex, files.length, file.name, thumbnails, thumbnailSkipped);
                thumbnails += Number(thumbResult.created || 0);
                thumbnailSkipped += Number(thumbResult.skipped || 0);
            }
        }

        return {
            ok: true,
            gallery_id: galleryId,
            gallery_ids: galleryIds.length > 0 ? galleryIds : (galleryId ? [galleryId] : []),
            uploaded,
            scanned,
            thumbnails,
            thumbnail_skipped: thumbnailSkipped,
            total_files: files.length,
            redirect_url: appendUploadResultParams(redirectUrl, uploaded, scanned, thumbnails),
        };
    }

    /**
     * Handles append upload result params behavior for the gallery UI.
     * @param {*} urlValue Value supplied by the caller or event context.
     * @param {*} uploaded Value supplied by the caller or event context.
     * @param {*} scanned Value supplied by the caller or event context.
     * @param {*} thumbnails Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function appendUploadResultParams(urlValue, uploaded, scanned, thumbnails) {
        // url stores state or configuration for the gallery front-end flow.
        const url = new URL(urlValue || window.location.href, window.location.href);
        url.searchParams.set('uploaded', String(uploaded));
        url.searchParams.set('scanned', String(scanned));
        url.searchParams.set('thumbnails', String(thumbnails));
        return url.toString();
    }

    /**
     * Handles send gallery upload chunk behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @param {*} body Value supplied by the caller or event context.
     * @param {*} progressHandler Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function sendGalleryUploadChunk(form, body, progressHandler) {
        return new Promise((resolve, reject) => {
            // xhr stores state or configuration for the gallery front-end flow.
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action || window.location.href);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', progressHandler);
            xhr.addEventListener('load', () => {
                try {
                    // contentType stores state or configuration for the gallery front-end flow.
                    const contentType = (xhr.getResponseHeader('Content-Type') || '').toLowerCase();
                    // responseText stores state or configuration for the gallery front-end flow.
                    const responseText = xhr.responseText || '';
                    if (!contentType.includes('application/json')) {
                        // snippet stores state or configuration for the gallery front-end flow.
                        const snippet = responseText.trim().slice(0, 180).replace(/\s+/g, ' ');
                        if (snippet.includes('Maximum number of allowable file uploads exceeded')) {
                            throw new Error('The server refused too many files in one request. Upload batching is enabled, but this server returned the PHP upload-limit warning before processing the request.');
                        }
                        throw new Error(snippet.startsWith('<') ? 'Server returned HTML instead of JSON. Check the PHP error log for the exact upload error.' : 'Server returned an unexpected response.');
                    }
                    // result stores state or configuration for the gallery front-end flow.
                    const result = JSON.parse(responseText || '{}');
                    if (xhr.status < 200 || xhr.status >= 300 || !result.ok) {
                        throw new Error(result.error || 'Upload failed.');
                    }
                    resolve(result);
                } catch (error) {
                    reject(error);
                }
            });
            xhr.addEventListener('error', () => {
                reject(new Error('Upload failed.'));
            });
            xhr.send(body);
        });
    }

    /**
     * Handles run uploaded image thumbnail job behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @param {*} progress Value supplied by the caller or event context.
     * @param {*} imageIds Value supplied by the caller or event context.
     * @param {*} fileIndex Value supplied by the caller or event context.
     * @param {*} totalFiles Value supplied by the caller or event context.
     * @param {*} filename Value supplied by the caller or event context.
     * @param {*} createdBefore Value supplied by the caller or event context.
     * @param {*} skippedBefore Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function runUploadedImageThumbnailJob(form, progress, imageIds, fileIndex, totalFiles, filename, createdBefore, skippedBefore) {
        if (!imageIds.length) {
            updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore, skippedBefore, `Uploaded ${fileIndex} of ${totalFiles}: ${filename}. No database image record was returned for thumbnails.`);
            return {created: 0, skipped: 0};
        }

        // offset stores state or configuration for the gallery front-end flow.
        let offset = 0;
        // total stores state or configuration for the gallery front-end flow.
        let total = 0;
        // created stores state or configuration for the gallery front-end flow.
        let created = 0;
        // skipped stores state or configuration for the gallery front-end flow.
        let skipped = 0;
        while (true) {
            // body stores state or configuration for the gallery front-end flow.
            const body = new FormData();
            body.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
            body.set('ajax', '1');
            body.set('offset', String(offset));
            body.set('batch_size', '1');
            body.set('gallery_id', String(Number(form.querySelector('select[name="gallery_id"]')?.value || 0)));
            imageIds.forEach((imageId) => {
                body.append('image_ids[]', String(imageId));
            });
            // response stores state or configuration for the gallery front-end flow.
            const response = await fetch(thumbnailEndpoint(form, null), {
                method: 'POST',
                body,
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                throw new Error('Thumbnail request failed.');
            }
            // result stores state or configuration for the gallery front-end flow.
            const result = await response.json();
            total = result.total || imageIds.length;
            offset = result.next_offset || 0;
            created += result.created || 0;
            skipped += result.skipped || 0;
            updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Uploaded ${fileIndex} of ${totalFiles}: ${filename}. Creating thumbnails ${Math.min(offset, total)} of ${total}...`);
            if (result.done) {
                updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Finished ${fileIndex} of ${totalFiles}: ${filename}`);
                return {created, skipped};
            }
        }
    }

    // Function `setupThumbnailProgress` executes this focused behavior.
    function setupThumbnailProgress() {
        document.addEventListener('click', async (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }
            // Variable `button` stores this steps working value.
            const button = event.target.closest('[data-create-all-thumbnails]');
            if (!button) {
                return;
            }
            // Variable `form` stores this steps working value.
            const form = document.querySelector('[data-gallery-bulk-form]');
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            form.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
                checkbox.checked = true;
            });
            form.querySelectorAll('input[type="checkbox"][data-select-all="gallery_ids[]"]').forEach((checkbox) => {
                checkbox.checked = true;
            });
            // Variable `action` stores this steps working value.
            const action = form.querySelector('select[name="action"]');
            if (action) {
                action.value = 'thumbs';
            }
            button.disabled = true;
            try {
                await runThumbnailJob(form, null);
            } finally {
                button.disabled = false;
            }
        });

        document.addEventListener('submit', (event) => {
            // Variable `form` stores this steps working value.
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !isThumbnailSubmission(form, event.submitter)) {
                return;
            }
            event.preventDefault();
            runThumbnailJob(form, event.submitter);
        });

        document.addEventListener('submit', (event) => {
            // form stores state or configuration for the gallery front-end flow.
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-import-galleries-form]')) {
                return;
            }
            if (!form.querySelector('input[name="create_thumbnails"]')?.checked) {
                return;
            }
            event.preventDefault();
            runImportWithThumbnailProgress(form);
        });
    }

    /**
     * Handles run import with thumbnail progress behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function runImportWithThumbnailProgress(form) {
        // progress stores state or configuration for the gallery front-end flow.
        const progress = ensureThumbnailProgress(form);
        // buttons stores state or configuration for the gallery front-end flow.
        const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
        buttons.forEach((button) => {
            button.disabled = true;
        });
        updateThumbnailProgress(progress, 0, 0, 0, 0, 'Importing selected galleries...');
        try {
            // importBody stores state or configuration for the gallery front-end flow.
            const importBody = new FormData(form);
            importBody.set('ajax', '1');
            // importResponse stores state or configuration for the gallery front-end flow.
            const importResponse = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: importBody,
                headers: {'Accept': 'application/json'},
            });
            if (!importResponse.ok) {
                throw new Error('Import request failed.');
            }
            // importResult stores state or configuration for the gallery front-end flow.
            const importResult = await importResponse.json();
            // galleryIds stores state or configuration for the gallery front-end flow.
            const galleryIds = Array.isArray(importResult.gallery_ids) ? importResult.gallery_ids : [];
            if (galleryIds.length === 0) {
                updateThumbnailProgress(progress, 0, 0, 0, 0, `Import complete. ${importResult.imported || 0} galleries imported, ${importResult.scanned || 0} images scanned.`);
                window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: 0});
                return;
            }
            // offset stores state or configuration for the gallery front-end flow.
            let offset = 0;
            // total stores state or configuration for the gallery front-end flow.
            let total = 0;
            // created stores state or configuration for the gallery front-end flow.
            let created = 0;
            // skipped stores state or configuration for the gallery front-end flow.
            let skipped = 0;
            while (true) {
                // thumbBody stores state or configuration for the gallery front-end flow.
                const thumbBody = new FormData();
                thumbBody.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
                thumbBody.set('ajax', '1');
                thumbBody.set('offset', String(offset));
                thumbBody.set('batch_size', '6');
                galleryIds.forEach((galleryId) => {
                    thumbBody.append('gallery_ids[]', String(galleryId));
                });
                // response stores state or configuration for the gallery front-end flow.
                const response = await fetch(thumbnailEndpoint(form, null), {
                    method: 'POST',
                    body: thumbBody,
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) {
                    throw new Error('Thumbnail request failed.');
                }
                // result stores state or configuration for the gallery front-end flow.
                const result = await response.json();
                total = result.total || 0;
                offset = result.next_offset || 0;
                created += result.created || 0;
                skipped += result.skipped || 0;
                updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, `Imported ${importResult.imported || 0} galleries, scanned ${importResult.scanned || 0} images. Creating thumbnails...`);
                if (result.done) {
                    updateThumbnailProgress(progress, total, total, created, skipped, 'Import and thumbnail job complete.');
                    window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: created});
                    break;
                }
            }
        } catch (error) {
            updateThumbnailProgress(progress, 0, 0, 0, 0, 'Import or thumbnail job failed.');
        } finally {
            buttons.forEach((button) => {
                button.disabled = false;
            });
        }
    }

    /**
     * Handles admin url with params behavior for the gallery UI.
     * @param {*} params Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function adminUrlWithParams(params) {
        // url stores state or configuration for the gallery front-end flow.
        const url = new URL(window.location.href);
        url.search = '?page=admin';
        Object.entries(params).forEach(([key, value]) => {
            url.searchParams.set(key, String(value));
        });
        return url.toString();
    }

    // Function `isThumbnailSubmission` executes this focused behavior.
    function isThumbnailSubmission(form, submitter) {
        // Variable `action` stores this steps working value.
        const action = submitter?.formAction || form.action || '';
        // Variable `selectedAction` stores this steps working value.
        const selectedAction = form.querySelector('select[name="action"]')?.value || '';
        return action.includes('admin_create_thumbnails') || selectedAction === 'thumbs';
    }

    // Function `thumbnailEndpoint` executes this focused behavior.
    function thumbnailEndpoint(form, submitter) {
        // Variable `action` stores this steps working value.
        const action = submitter?.formAction || form.action || window.location.href;
        // Variable `endpoint` stores this steps working value.
        const endpoint = new URL(action, window.location.href);
        endpoint.searchParams.set('page', 'admin_create_thumbnails');
        return endpoint.toString();
    }

    /**
     * Handles run thumbnail job behavior for the gallery UI.
     * @param {*} form Value supplied by the caller or event context.
     * @param {*} submitter Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function runThumbnailJob(form, submitter) {
        // Variable `progress` stores this steps working value.
        const progress = ensureThumbnailProgress(form);
        // Variable `buttons` stores this steps working value.
        const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
        buttons.forEach((button) => {
            button.disabled = true;
        });
        // Variable `offset` stores this steps working value.
        let offset = 0;
        // Variable `total` stores this steps working value.
        let total = 0;
        // Variable `created` stores this steps working value.
        let created = 0;
        // Variable `skipped` stores this steps working value.
        let skipped = 0;
        updateThumbnailProgress(progress, 0, 0, created, skipped, 'Preparing thumbnails...');
        try {
            while (true) {
                // Variable `body` stores this steps working value.
                const body = new FormData(form);
                if (submitter?.name) {
                    body.set(submitter.name, submitter.value);
                }
                body.set('ajax', '1');
                body.set('offset', String(offset));
                body.set('batch_size', '6');
                // Variable `response` stores this steps working value.
                const response = await fetch(thumbnailEndpoint(form, submitter), {
                    method: 'POST',
                    body,
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) {
                    throw new Error('Thumbnail request failed.');
                }
                // Variable `result` stores this steps working value.
                const result = await response.json();
                total = result.total || 0;
                offset = result.next_offset || 0;
                created += result.created || 0;
                skipped += result.skipped || 0;
                updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, 'Creating thumbnails...');
                if (result.done) {
                    updateThumbnailProgress(progress, total, total, created, skipped, 'Thumbnail job complete.');
                    break;
                }
            }
        } catch (error) {
            updateThumbnailProgress(progress, offset, total, created, skipped, 'Thumbnail job failed.');
        } finally {
            buttons.forEach((button) => {
                button.disabled = false;
            });
        }
    }

    // Function `setupPictureGame` executes this focused behavior.
    function setupPictureGame() {
        // Variable `game` stores this steps working value.
        const game = document.querySelector('[data-picture-game]');
        if (!game) {
            return;
        }
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }
            // Variable `side` stores this steps working value.
            const side = event.key === 'ArrowLeft' ? 'left' : 'right';
            // Variable `button` stores this steps working value.
            const button = game.querySelector(`[data-picture-game-choice="${side}"]`);
            if (button) {
                event.preventDefault();
                button.click();
            }
        });
    }

    // Function `setupAdminLogStatusForms` executes this focused behavior.
    function setupAdminLogStatusForms() {
        document.querySelectorAll('[data-admin-log-status-select]').forEach((select) => {
            // Variable `originalValue` stores this steps working value.
            let originalValue = select.value;
            select.addEventListener('change', async () => {
                // Variable `body` stores this steps working value.
                const body = new FormData();
                body.set('csrf_token', select.dataset.csrfToken || '');
                body.set('action', 'single');
                body.set('log_id', select.dataset.logId || '');
                body.set('status', select.value);
                // Variable `row` stores this steps working value.
                const row = select.closest('[data-admin-log-row]');
                // Variable `state` stores this steps working value.
                const state = row ? row.querySelector('[data-admin-log-state]') : null;
                select.disabled = true;
                try {
                    // Variable `response` stores this steps working value.
                    const response = await fetch(select.dataset.updateUrl || window.location.href, {
                        method: 'POST',
                        body,
                        headers: {'Accept': 'application/json'},
                    });
                    if (!response.ok) {
                        select.value = originalValue;
                        return;
                    }
                    // Variable `result` stores this steps working value.
                    const result = await response.json();
                    if (!result.ok) {
                        select.value = originalValue;
                        return;
                    }
                    originalValue = select.value;
                    if (state) {
                        state.textContent = result.label || select.options[select.selectedIndex]?.textContent || select.value;
                    }
                } catch {
                    select.value = originalValue;
                } finally {
                    select.disabled = false;
                }
            });
        });
    }

    // Function `ensureThumbnailProgress` executes this focused behavior.
    function ensureThumbnailProgress(form) {
        // Variable `targetSelector` stores this steps working value.
        const targetSelector = form.dataset.thumbnailProgressTarget || '';
        if (targetSelector) {
            // Variable `target` stores this steps working value.
            const target = document.querySelector(targetSelector);
            if (target) {
                // progress stores state or configuration for the gallery front-end flow.
                let progress = target.querySelector('[data-thumbnail-progress]');
                if (!progress) {
                    progress = createThumbnailProgress();
                    target.append(progress);
                }
                progress.hidden = false;
                return progress;
            }
        }
        // Variable `progress` stores this steps working value.
        let progress = form.classList.contains('inline-form')
            ? form.nextElementSibling?.matches('[data-thumbnail-progress]') ? form.nextElementSibling : null
            : form.querySelector('[data-thumbnail-progress]');
        if (progress) {
            progress.hidden = false;
            return progress;
        }
        progress = createThumbnailProgress();
        if (form.classList.contains('inline-form')) {
            form.insertAdjacentElement('afterend', progress);
        } else {
            form.prepend(progress);
        }
        progress.hidden = false;
        return progress;
    }

    // Function `createThumbnailProgress` executes this focused behavior.
    function createThumbnailProgress() {
        // Variable `progress` stores this steps working value.
        const progress = document.createElement('div');
        progress.className = 'thumbnail-progress';
        progress.dataset.thumbnailProgress = 'true';
        progress.innerHTML = '<progress class="thumbnail-progress-bar" data-thumbnail-progress-fill value="0" max="100"></progress><p class="muted" data-thumbnail-progress-text></p>';
        return progress;
    }

    // Function `updateThumbnailProgress` executes this focused behavior.
    function updateThumbnailProgress(progress, processed, total, created, skipped, label) {
        progress.hidden = false;
        // Variable `percent` stores this steps working value.
        const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
        progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
        progress.querySelector('[data-thumbnail-progress-text]').textContent =
            `${label} ${processed}/${total} images checked, ${created} files created, ${skipped} existing files skipped.`;
    }

    // Function `updateBasicProgress` executes this focused behavior.
    function updateBasicProgress(progress, percent, label) {
        progress.hidden = false;
        progress.querySelector('[data-thumbnail-progress-fill]').value = Math.max(0, Math.min(100, percent));
        progress.querySelector('[data-thumbnail-progress-text]').textContent = label;
    }

    // Function `setGalleryRowHiddenReason` executes this focused behavior.
    function setGalleryRowHiddenReason(row, reason, hidden) {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        if (reason === 'filter') {
            row.dataset.hiddenByFilter = hidden ? '1' : '0';
        }
        if (reason === 'tree') {
            row.dataset.hiddenByTree = hidden ? '1' : '0';
        }
        row.hidden = row.dataset.hiddenByFilter === '1' || row.dataset.hiddenByTree === '1';
    }

    // Function `setupAdminGalleryFilters` executes this focused behavior.
    function setupAdminGalleryFilters() {
        // Variable `filter` stores this steps working value.
        const filter = document.querySelector('[data-gallery-visibility-filter]');
        if (!(filter instanceof HTMLSelectElement)) {
            return;
        }
        // Variable `form` stores this steps working value.
        const form = filter.closest('form');
        // Variable `rows` stores this steps working value.
        const rows = Array.from(document.querySelectorAll('[data-gallery-row]'));
        // Variable `summary` stores this steps working value.
        const summary = document.querySelector('[data-gallery-filter-summary]');
        // Variable `selectAll` stores this steps working value.
        const selectAll = form ? form.querySelector('[data-select-all="gallery_ids[]"]') : null;

        // Function `updateSummary` executes this focused behavior.
        function updateSummary() {
            // displayed stores state or configuration for the gallery front-end flow.
            let displayed = 0;
            // total stores state or configuration for the gallery front-end flow.
            let total = 0;
            // selectedVisibility stores state or configuration for the gallery front-end flow.
            const selectedVisibility = filter.value || 'all';
            rows.forEach((row) => {
                // matchesFilter stores state or configuration for the gallery front-end flow.
                const matchesFilter = selectedVisibility === 'all' || row.dataset.galleryVisibility === selectedVisibility;
                if (matchesFilter && row.dataset.hiddenByTree !== '1') {
                    total++;
                }
                if (!row.hidden) {
                    displayed++;
                }
            });
            if (summary) {
                summary.textContent = `${displayed} / ${total} galleries displayed`;
            }
        }

        // Function `applyFilter` executes this focused behavior.
        function applyFilter() {
            // selectedVisibility stores state or configuration for the gallery front-end flow.
            const selectedVisibility = filter.value || 'all';
            rows.forEach((row) => {
                // A filtered-out row is also unchecked. This prevents a hidden
                // stale selection from being included in the next bulk action.
                const matches = selectedVisibility === 'all' || row.dataset.galleryVisibility === selectedVisibility;
                setGalleryRowHiddenReason(row, 'filter', !matches);
                if (!matches) {
                    row.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
                        checkbox.checked = false;
                    });
                }
            });
            if (selectAll instanceof HTMLInputElement) {
                selectAll.checked = false;
            }
            updateSummary();
        }

        document.addEventListener('galleryRowsChanged', updateSummary);
        filter.addEventListener('change', applyFilter);
        applyFilter();
    }

    // Function `setupAdminGalleryTree` executes this focused behavior.
    function setupAdminGalleryTree() {
        // Variable `rows` stores this steps working value.
        const rows = Array.from(document.querySelectorAll('[data-gallery-row]'));
        if (rows.length === 0) {
            return;
        }
        // Variable `csrf` stores this steps working value.
        const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
        // Variable `saveUrl` stores this steps working value.
        const saveUrl = new URL(window.location.href);
        saveUrl.search = '?page=admin_save_gallery_collapse';

        // Function `collapsedIds` executes this focused behavior.
        function collapsedIds() {
            return rows.filter((row) => row.classList.contains('is-collapsed')).map((row) => row.dataset.galleryId);
        }

        // Function `save` executes this focused behavior.
        function save() {
            // Variable `body` stores this steps working value.
            const body = new FormData();
            body.set('csrf_token', csrf);
            body.set('collapsed_ids', JSON.stringify(collapsedIds()));
            fetch(saveUrl.toString(), {method: 'POST', body, headers: {'Accept': 'application/json'}});
        }

        // Function `refreshVisibility` executes this focused behavior.
        function refreshVisibility() {
            // Variable `collapsed` stores this steps working value.
            const collapsed = new Set(collapsedIds().map(String));
            rows.forEach((row) => {
                // Variable `parentId` stores this steps working value.
                let parentId = row.dataset.parentId || '0';
                // Variable `hidden` stores this steps working value.
                let hidden = false;
                while (parentId !== '0') {
                    if (collapsed.has(parentId)) {
                        hidden = true;
                        break;
                    }
                    // Variable `parent` stores this steps working value.
                    const parent = rows.find((candidate) => candidate.dataset.galleryId === parentId);
                    parentId = parent ? (parent.dataset.parentId || '0') : '0';
                }
                setGalleryRowHiddenReason(row, 'tree', hidden);
                if (hidden) {
                    row.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
                        checkbox.checked = false;
                    });
                }
            });
            // The master checkbox is a one-shot command for the current view,
            // so any tree visibility change clears it rather than leaving a
            // stale checked state after hidden rows have been unchecked.
            const selectAll = document.querySelector('[data-gallery-bulk-form] [data-select-all="gallery_ids[]"]');
            if (selectAll instanceof HTMLInputElement) {
                selectAll.checked = false;
            }
            document.dispatchEvent(new Event('galleryRowsChanged'));
        }

        document.querySelectorAll('[data-gallery-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                // Variable `row` stores this steps working value.
                const row = button.closest('[data-gallery-row]');
                // Variable `collapsed` stores this steps working value.
                const collapsed = !row.classList.contains('is-collapsed');
                row.classList.toggle('is-collapsed', collapsed);
                button.textContent = collapsed ? '+' : '-';
                button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                refreshVisibility();
                save();
            });
        });

        document.querySelectorAll('[data-gallery-tree-action]').forEach((button) => {
            button.addEventListener('click', () => {
                // Variable `collapse` stores this steps working value.
                const collapse = button.dataset.galleryTreeAction === 'collapse-all';
                rows.forEach((row) => {
                    // Variable `toggle` stores this steps working value.
                    const toggle = row.querySelector('[data-gallery-toggle]');
                    if (!toggle) {
                        return;
                    }
                    row.classList.toggle('is-collapsed', collapse);
                    toggle.textContent = collapse ? '+' : '-';
                    toggle.setAttribute('aria-expanded', collapse ? 'false' : 'true');
                });
                refreshVisibility();
                save();
            });
        });

        refreshVisibility();
    }
})();
