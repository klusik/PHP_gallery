(() => {
    // Inline styles bypass the theme/custom CSS workflow. If somebody edits the
    // rendered HTML directly, make the tampering obvious to public visitors.
    function showCompromiseWarning() {
        if (document.querySelector('[data-compromise-warning]')) {
            return;
        }
        // Variable `warning` stores this steps working value.
        const warning = document.createElement('div');
        warning.className = 'compromise-warning';
        warning.dataset.compromiseWarning = 'true';
        warning.textContent = 'unoriginal changes, this page is corrupted and compromised!';
        document.body.append(warning);
    }

    // Function `isAllowedRuntimeInlineStyle` executes this focused behavior.
    // Leaflet is allowed to use inline styles inside the trusted map overlay
    // because its pan, zoom, tile, marker and popup positioning logic depends
    // on runtime-calculated transform and size attributes. The gallery still
    // treats inline styles everywhere else as page tampering.
    function isAllowedRuntimeInlineStyle(node) {
        if (!(node instanceof Element)) {
            return false;
        }
        return Boolean(node.closest('[data-inline-style-allowed]')) || isBrowserTranslationInlineStyle(node);
    }

    // Function `isBrowserTranslationInlineStyle` executes this focused behavior.
    function isBrowserTranslationInlineStyle(node) {
        if (!(node instanceof Element)) {
            return false;
        }
        if (node.closest('.skiptranslate, .goog-te-gadget, [id^="goog-gt-"], [class^="VIpgJd-"]')) {
            return true;
        }
        if (node.tagName !== 'FONT') {
            return false;
        }
        const style = (node.getAttribute('style') || '').replace(/\s+/g, '').toLowerCase();
        return style === 'vertical-align:inherit;' || style === 'vertical-align:inherit';
    }

    // Function `hasUnauthorizedInlineStyle` executes this focused behavior.
    function hasUnauthorizedInlineStyle() {
        return Array.from(document.querySelectorAll('[style]')).some((node) => !isAllowedRuntimeInlineStyle(node));
    }

    // Function `detectInlineStyleTampering` executes this focused behavior.
    function detectInlineStyleTampering() {
        if (hasUnauthorizedInlineStyle()) {
            showCompromiseWarning();
        }
    }

    detectInlineStyleTampering();
    new MutationObserver(detectInlineStyleTampering).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['style'],
        childList: true,
        subtree: true,
    });

    function setupThemeOverrideForm() {
        const form = document.querySelector('[data-theme-form]');
        if (!form) {
            return;
        }
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

    setupAdminGalleryFilters();
    setupThumbnailProgress();
    setupGalleryRefreshProgress();
    setupPictureGame();
    setupAdminLogStatusForms();
    setupGpsMaps();
    setupThemeOverrideForm();

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
    // Variable `originalLinks` stores this steps working value.
    const originalLinks = Array.from(overlay.querySelectorAll('[data-lightbox-original-link]'));
    // Variable `title` stores this steps working value.
    const title = overlay.querySelector('[data-lightbox-title]');
    // Variable `description` stores this steps working value.
    const description = overlay.querySelector('[data-lightbox-description]');
    // Variable `score` stores this steps working value.
    const score = overlay.querySelector('[data-lightbox-score]');
    const counter = overlay.querySelector('[data-lightbox-counter]');
    // Variable `lightboxVoteForm` stores this steps working value.
    const lightboxVoteForm = overlay.querySelector('[data-lightbox-vote-form]');
    // Variable `lightboxVoteIndicator` stores this steps working value.
    const lightboxVoteIndicator = overlay.querySelector('[data-lightbox-vote-indicator]');
    // Variable `lightboxMapButton` stores this steps working value.
    const lightboxMapButton = overlay.querySelector('[data-lightbox-map]');
    // Variable `currentIndex` stores this steps working value.
    let currentIndex = 0;

    // Function `syncLightboxVote` executes this focused behavior.
    function syncLightboxVote(card) {
        if (!lightboxVoteForm) {
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

    // Function `openAt` executes this focused behavior.
    function openAt(index) {
        // Variable `card` stores this steps working value.
        const card = cards[index];
        if (!card) {
            return;
        }
        currentIndex = index;
        image.src = card.dataset.fullSrc;
        image.alt = card.dataset.title || '';
        originalLinks.forEach((originalLink) => {
            originalLink.href = card.dataset.fullSrc || '#';
        });
        title.textContent = card.dataset.title || '';
        description.textContent = card.dataset.description || 'No description.';
        score.textContent = card.dataset.score || '0';
        if (counter) {
            counter.textContent = `${index + 1} / ${cards.length}`;
        }
        overlay.dataset.currentImageId = card.dataset.imageId || '';
        syncLightboxVote(card);
        if (lightboxMapButton) {
            lightboxMapButton.hidden = !card.dataset.mapPoint;
            lightboxMapButton.dataset.mapPoint = card.dataset.mapPoint || '';
        }
        overlay.hidden = false;
        document.body.classList.add('has-lightbox');
    }

    // Function `close` executes this focused behavior.
    function close() {
        overlay.hidden = true;
        image.removeAttribute('src');
        document.body.classList.remove('has-lightbox');
    }

    // Function `step` executes this focused behavior.
    function step(offset) {
        openAt((currentIndex + offset + cards.length) % cards.length);
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
        // Variable `action` stores this steps working value.
        const action = event.target.dataset.lightboxAction;
        if (action === 'close' || event.target === overlay) {
            close();
        }
        if (action === 'previous') {
            step(-1);
        }
        if (action === 'next') {
            step(1);
        }
        if (event.target.closest('[data-lightbox-map]')) {
            event.preventDefault();
            openPhotoMapFromJson(event.target.closest('[data-lightbox-map]').dataset.mapPoint || '');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
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
    });

    // Function `submitLightboxVote` executes this focused behavior.
    function submitLightboxVote(value) {
        if (!lightboxVoteForm) {
            return;
        }
        // Variable `button` stores this steps working value.
        const button = lightboxVoteForm.querySelector(`button[name="vote"][value="${value}"]`);
        if (button) {
            button.click();
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
        if (window.L) {
            return Promise.resolve();
        }
        if (window.galleryLeafletLoading) {
            return window.galleryLeafletLoading;
        }

        // The local stylesheet now contains the required Leaflet layout rules.
        // Only the JavaScript library is loaded dynamically so the map button
        // does not wait on stylesheet load events that can be unreliable after
        // cache restores or browser hard-refreshes.
        window.galleryLeafletLoading = ensureLeafletScript().then(() => undefined);

        return window.galleryLeafletLoading;
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
            overlay.dataset.inlineStyleAllowed = 'true';
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
        if (overlay.galleryLeafletMap) {
            overlay.galleryLeafletMap.remove();
            overlay.galleryLeafletMap = null;
        }
        canvas.innerHTML = '';

        // Variable `map` stores this steps working value.
        const map = L.map(canvas);
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
            const marker = L.marker([point.lat, point.lng]).addTo(map);
            marker.bindPopup(mapPopupHtml(point));
            bounds.push([point.lat, point.lng]);
        });

        map.invalidateSize(false);
        if (bounds.length === 1) {
            map.setView(bounds[0], 15);
        } else {
            map.fitBounds(bounds, {padding: [30, 30]});
        }

        // Recheck size once more after tile panes and markers are attached.
        // This keeps the map stable after font loading, scrollbar changes or
        // custom CSS skins that slightly alter dialog dimensions.
        setTimeout(() => {
            map.invalidateSize(false);
            if (bounds.length === 1) {
                map.setView(bounds[0], map.getZoom());
            } else {
                map.fitBounds(bounds, {padding: [30, 30]});
            }
        }, 150);
    }

    // Function `mapPopupHtml` executes this focused behavior.
    function mapPopupHtml(point) {
        // Variable `title` stores this steps working value.
        const title = escapeHtml(point.title || 'Photo');
        // Variable `description` stores this steps working value.
        const description = point.description ? `<p>${escapeHtml(point.description)}</p>` : '';
        // Variable `thumb` stores this steps working value.
        const thumb = point.thumb ? `<img src="${escapeAttribute(point.thumb)}" alt="">` : '';
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
        let progress = document.querySelector('[data-gallery-refresh-progress]');
        if (progress) {
            return progress;
        }
        progress = document.createElement('div');
        progress.className = 'thumbnail-progress';
        progress.dataset.galleryRefreshProgress = 'true';
        progress.innerHTML = '<progress class="thumbnail-progress-bar"></progress><p class="muted">Scanning existing galleries and checking for new gallery folders...</p>';
        const target = form.closest('.hero') || form;
        target.insertAdjacentElement('afterend', progress);
        return progress;
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

    async function runImportWithThumbnailProgress(form) {
        const progress = ensureThumbnailProgress(form);
        const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
        buttons.forEach((button) => {
            button.disabled = true;
        });
        updateThumbnailProgress(progress, 0, 0, 0, 0, 'Importing selected galleries...');
        try {
            const importBody = new FormData(form);
            importBody.set('ajax', '1');
            const importResponse = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: importBody,
                headers: {'Accept': 'application/json'},
            });
            if (!importResponse.ok) {
                throw new Error('Import request failed.');
            }
            const importResult = await importResponse.json();
            const galleryIds = Array.isArray(importResult.gallery_ids) ? importResult.gallery_ids : [];
            if (galleryIds.length === 0) {
                updateThumbnailProgress(progress, 0, 0, 0, 0, `Import complete. ${importResult.imported || 0} galleries imported, ${importResult.scanned || 0} images scanned.`);
                window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: 0});
                return;
            }
            let offset = 0;
            let total = 0;
            let created = 0;
            let skipped = 0;
            while (true) {
                const thumbBody = new FormData();
                thumbBody.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
                thumbBody.set('ajax', '1');
                thumbBody.set('offset', String(offset));
                thumbBody.set('batch_size', '6');
                galleryIds.forEach((galleryId) => {
                    thumbBody.append('gallery_ids[]', String(galleryId));
                });
                const response = await fetch(thumbnailEndpoint(form, null), {
                    method: 'POST',
                    body: thumbBody,
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) {
                    throw new Error('Thumbnail request failed.');
                }
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

    function adminUrlWithParams(params) {
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
            let displayed = 0;
            let total = 0;
            const selectedVisibility = filter.value || 'all';
            rows.forEach((row) => {
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

