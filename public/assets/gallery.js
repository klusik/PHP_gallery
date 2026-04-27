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

    // Function `detectInlineStyleTampering` executes this focused behavior.
    function detectInlineStyleTampering() {
        if (document.querySelector('[style]')) {
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
        }
    });

    // Table-level select-all checkboxes are scoped by input name so gallery and
    // image bulk forms can share the same behavior.
    document.querySelectorAll('[data-select-all]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            // Variable `name` stores this steps working value.
            const name = checkbox.dataset.selectAll;
            document.querySelectorAll(`input[type="checkbox"][name="${name}"]`).forEach((item) => {
                item.checked = checkbox.checked;
            });
        });
    });

    setupThumbnailProgress();

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
    // Variable `currentIndex` stores this steps working value.
    let currentIndex = 0;

    // Function `syncLightboxVote` executes this focused behavior.
    function syncLightboxVote(card) {
        if (!lightboxVoteForm) {
            return;
        }
        lightboxVoteForm.querySelector('input[name="image_id"]').value = card.dataset.imageId || '';
        score.dataset.scoreFor = card.dataset.imageId || '';
        lightboxVoteForm.querySelectorAll('button[name="vote"]').forEach((button) => {
            // Variable `active` stores this steps working value.
            const active = button.value === (card.dataset.userVote || '0');
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
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
        image.src = card.dataset.fullSrc;
        image.alt = card.dataset.title || '';
        originalLinks.forEach((originalLink) => {
            originalLink.href = card.dataset.fullSrc || '#';
        });
        title.textContent = card.dataset.title || '';
        description.textContent = card.dataset.description || '';
        score.textContent = card.dataset.score || '0';
        if (counter) {
            counter.textContent = `${index + 1} / ${cards.length}`;
        }
        overlay.dataset.currentImageId = card.dataset.imageId || '';
        syncLightboxVote(card);
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
            if (event.target.closest('form')) {
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

    // Function `setupThumbnailProgress` executes this focused behavior.
    function setupThumbnailProgress() {
        document.addEventListener('submit', (event) => {
            // Variable `form` stores this steps working value.
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !isThumbnailSubmission(form, event.submitter)) {
                return;
            }
            event.preventDefault();
            runThumbnailJob(form, event.submitter);
        });
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

    // Function `ensureThumbnailProgress` executes this focused behavior.
    function ensureThumbnailProgress(form) {
        // Variable `progress` stores this steps working value.
        let progress = form.classList.contains('inline-form')
            ? form.nextElementSibling?.matches('[data-thumbnail-progress]') ? form.nextElementSibling : null
            : form.querySelector('[data-thumbnail-progress]');
        if (progress) {
            return progress;
        }
        progress = document.createElement('div');
        progress.className = 'thumbnail-progress';
        progress.dataset.thumbnailProgress = 'true';
        progress.innerHTML = '<progress class="thumbnail-progress-bar" data-thumbnail-progress-fill value="0" max="100"></progress><p class="muted" data-thumbnail-progress-text></p>';
        if (form.classList.contains('inline-form')) {
            form.insertAdjacentElement('afterend', progress);
        } else {
            form.prepend(progress);
        }
        return progress;
    }

    // Function `updateThumbnailProgress` executes this focused behavior.
    function updateThumbnailProgress(progress, processed, total, created, skipped, label) {
        // Variable `percent` stores this steps working value.
        const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
        progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
        progress.querySelector('[data-thumbnail-progress-text]').textContent =
            `${label} ${processed}/${total} images checked, ${created} files created, ${skipped} existing files skipped.`;
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
                row.hidden = hidden;
            });
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

