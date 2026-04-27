(() => {
    // Inline styles bypass the theme/custom CSS workflow. If somebody edits the
    // rendered HTML directly, make the tampering obvious to public visitors.
    function showCompromiseWarning() {
        if (document.querySelector('[data-compromise-warning]')) {
            return;
        }
        const warning = document.createElement('div');
        warning.className = 'compromise-warning';
        warning.dataset.compromiseWarning = 'true';
        warning.textContent = 'unoriginal changes, this page is corrupted and compromised!';
        document.body.append(warning);
    }

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
        const form = event.target.closest('[data-vote-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const body = new FormData(form);
        if (event.submitter && event.submitter.name) {
            body.set(event.submitter.name, event.submitter.value);
        }
        const response = await fetch(form.action, {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            return;
        }
        const result = await response.json();
        document.querySelectorAll(`[data-score-for="${result.image_id}"]`).forEach((node) => {
            node.textContent = result.score;
        });
        document.querySelectorAll(`[data-image-id="${result.image_id}"]`).forEach((node) => {
            node.dataset.score = String(result.score);
            node.dataset.userVote = String(result.vote);
        });
        form.querySelectorAll('button[name="vote"]').forEach((button) => {
            const active = button.value === String(result.vote);
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        const lightbox = document.querySelector('[data-lightbox]');
        const lightboxScore = document.querySelector('[data-lightbox-score]');
        if (lightbox && lightboxScore && lightbox.dataset.currentImageId === String(result.image_id)) {
            lightboxScore.textContent = String(result.score);
        }
    });

    // Table-level select-all checkboxes are scoped by input name so gallery and
    // image bulk forms can share the same behavior.
    document.querySelectorAll('[data-select-all]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
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
        const list = document.querySelector(`#${input.getAttribute('list')}`);
        const names = list ? Array.from(list.options).map((option) => option.value) : [];
        const suggestions = document.createElement('div');
        suggestions.className = 'tag-suggestions';
        input.insertAdjacentElement('afterend', suggestions);

        function currentPrefix() {
            const parts = input.value.split(',');
            return parts[parts.length - 1].trim().toLowerCase();
        }

        function choose(name) {
            const parts = input.value.split(',');
            parts[parts.length - 1] = ` ${name}`;
            input.value = parts.map((part) => part.trim()).filter(Boolean).join(', ');
            suggestions.innerHTML = '';
            input.focus();
        }

        input.addEventListener('input', () => {
            const prefix = currentPrefix();
            suggestions.innerHTML = '';
            if (!prefix) {
                return;
            }
            names.filter((name) => name.toLowerCase().startsWith(prefix)).slice(0, 6).forEach((name) => {
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
    const overlay = document.querySelector('[data-lightbox]');
    setupAdminGalleryTree();
    if (!overlay || cards.length === 0) {
        return;
    }

    const image = overlay.querySelector('[data-lightbox-img]');
    const originalLink = overlay.querySelector('[data-lightbox-original-link]');
    const title = overlay.querySelector('[data-lightbox-title]');
    const description = overlay.querySelector('[data-lightbox-description]');
    const score = overlay.querySelector('[data-lightbox-score]');
    const lightboxVoteForm = overlay.querySelector('[data-lightbox-vote-form]');
    let currentIndex = 0;

    function syncLightboxVote(card) {
        if (!lightboxVoteForm) {
            return;
        }
        lightboxVoteForm.querySelector('input[name="image_id"]').value = card.dataset.imageId || '';
        score.dataset.scoreFor = card.dataset.imageId || '';
        lightboxVoteForm.querySelectorAll('button[name="vote"]').forEach((button) => {
            const active = button.value === (card.dataset.userVote || '0');
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function openAt(index) {
        const card = cards[index];
        if (!card) {
            return;
        }
        currentIndex = index;
        image.src = card.dataset.fullSrc;
        image.alt = card.dataset.title || '';
        if (originalLink) {
            originalLink.href = card.dataset.fullSrc || '#';
        }
        title.textContent = card.dataset.title || '';
        description.textContent = card.dataset.description || '';
        score.textContent = card.dataset.score || '0';
        overlay.dataset.currentImageId = card.dataset.imageId || '';
        syncLightboxVote(card);
        overlay.hidden = false;
        document.body.classList.add('has-lightbox');
    }

    function close() {
        overlay.hidden = true;
        image.removeAttribute('src');
        document.body.classList.remove('has-lightbox');
    }

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

    function submitLightboxVote(value) {
        if (!lightboxVoteForm) {
            return;
        }
        const button = lightboxVoteForm.querySelector(`button[name="vote"][value="${value}"]`);
        if (button) {
            button.click();
        }
    }

    function setupThumbnailProgress() {
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !isThumbnailSubmission(form, event.submitter)) {
                return;
            }
            event.preventDefault();
            runThumbnailJob(form, event.submitter);
        });
    }

    function isThumbnailSubmission(form, submitter) {
        const action = submitter?.formAction || form.action || '';
        const selectedAction = form.querySelector('select[name="action"]')?.value || '';
        return action.includes('admin_create_thumbnails') || selectedAction === 'thumbs';
    }

    function thumbnailEndpoint(form, submitter) {
        const action = submitter?.formAction || form.action || window.location.href;
        const endpoint = new URL(action, window.location.href);
        endpoint.searchParams.set('page', 'admin_create_thumbnails');
        return endpoint.toString();
    }

    async function runThumbnailJob(form, submitter) {
        const progress = ensureThumbnailProgress(form);
        const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
        buttons.forEach((button) => {
            button.disabled = true;
        });
        let offset = 0;
        let total = 0;
        let created = 0;
        let skipped = 0;
        updateThumbnailProgress(progress, 0, 0, created, skipped, 'Preparing thumbnails...');
        try {
            while (true) {
                const body = new FormData(form);
                if (submitter?.name) {
                    body.set(submitter.name, submitter.value);
                }
                body.set('ajax', '1');
                body.set('offset', String(offset));
                body.set('batch_size', '6');
                const response = await fetch(thumbnailEndpoint(form, submitter), {
                    method: 'POST',
                    body,
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

    function ensureThumbnailProgress(form) {
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

    function updateThumbnailProgress(progress, processed, total, created, skipped, label) {
        const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
        progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
        progress.querySelector('[data-thumbnail-progress-text]').textContent =
            `${label} ${processed}/${total} images checked, ${created} files created, ${skipped} existing files skipped.`;
    }

    function setupAdminGalleryTree() {
        const rows = Array.from(document.querySelectorAll('[data-gallery-row]'));
        if (rows.length === 0) {
            return;
        }
        const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
        const saveUrl = new URL(window.location.href);
        saveUrl.search = '?page=admin_save_gallery_collapse';

        function collapsedIds() {
            return rows.filter((row) => row.classList.contains('is-collapsed')).map((row) => row.dataset.galleryId);
        }

        function save() {
            const body = new FormData();
            body.set('csrf_token', csrf);
            body.set('collapsed_ids', JSON.stringify(collapsedIds()));
            fetch(saveUrl.toString(), {method: 'POST', body, headers: {'Accept': 'application/json'}});
        }

        function refreshVisibility() {
            const collapsed = new Set(collapsedIds().map(String));
            rows.forEach((row) => {
                let parentId = row.dataset.parentId || '0';
                let hidden = false;
                while (parentId !== '0') {
                    if (collapsed.has(parentId)) {
                        hidden = true;
                        break;
                    }
                    const parent = rows.find((candidate) => candidate.dataset.galleryId === parentId);
                    parentId = parent ? (parent.dataset.parentId || '0') : '0';
                }
                row.hidden = hidden;
            });
        }

        document.querySelectorAll('[data-gallery-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const row = button.closest('[data-gallery-row]');
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
                const collapse = button.dataset.galleryTreeAction === 'collapse-all';
                rows.forEach((row) => {
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

