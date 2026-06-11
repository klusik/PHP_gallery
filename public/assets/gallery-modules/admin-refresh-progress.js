/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-refresh-progress.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Enhances Admin gallery discovery with Ajax progress feedback.
 *
 * Responsibilities:
 *   - Start gallery discovery without blocking the Admin dashboard
 *   - Process filesystem discovery through small server batches
 *   - Render discovered folder candidates dynamically when the scan is complete
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-11
 */

import { escapeHtmlAttribute, escapeHtmlText, i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Attach Ajax gallery discovery behavior to the dashboard card and discovery page.
 */
export function setupGalleryRefreshProgress() {
    document.querySelectorAll('[data-admin-discovery-launch]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            runAdminGalleryDiscovery(form, {redirectWhenDone: true});
        });
    });

    document.querySelectorAll('[data-admin-discovery-panel]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        runAdminGalleryDiscovery(panel, {redirectWhenDone: false});
    });
}

/**
 * Run the complete Admin gallery discovery workflow.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @param {{redirectWhenDone: boolean}} options Runtime options.
 */
async function runAdminGalleryDiscovery(container, options) {
    if (container.dataset.discoveryRunning === '1') {
        return;
    }

    const endpoint = discoveryEndpoint(container);
    const csrfToken = discoveryCsrfToken(container);
    if (!endpoint || !csrfToken) {
        setDiscoveryProgress(container, 0, 0, 0, 0, 0, i18n('admin.galleries.discovery_failed', 'Gallery discovery failed.'));
        return;
    }

    container.dataset.discoveryRunning = '1';
    const buttons = Array.from(container.querySelectorAll('button, input[type="submit"]'));
    const originalLabels = new Map();
    buttons.forEach((button) => {
        originalLabels.set(button, 'value' in button && button.tagName === 'INPUT' ? button.value : button.textContent);
        button.disabled = true;
        if ('value' in button && button.tagName === 'INPUT') {
            button.value = i18n('admin.galleries.discovery_button_running', 'Scanning...');
        } else {
            button.textContent = i18n('admin.galleries.discovery_button_running', 'Scanning...');
        }
    });

    let payload = null;
    let redirected = false;
    try {
        const existingToken = container.dataset.jobToken || '';
        payload = existingToken
            ? await postDiscoveryAction(endpoint, csrfToken, 'status', existingToken)
            : await postDiscoveryAction(endpoint, csrfToken, 'start', '');

        setDiscoveryProgressFromPayload(container, payload);
        while (payload && payload.done !== true && payload.status !== 'error' && payload.status !== 'missing') {
            payload = await postDiscoveryAction(endpoint, csrfToken, 'step', String(payload.job_token || ''));
            setDiscoveryProgressFromPayload(container, payload);
        }

        if (!payload || payload.ok === false || payload.status === 'error' || payload.status === 'missing') {
            throw new Error(payload?.message || payload?.error || i18n('admin.galleries.discovery_failed', 'Gallery discovery failed.'));
        }

        if (options.redirectWhenDone) {
            redirected = true;
            window.location.href = payload.result_url || endpoint;
            return;
        }

        renderDiscoveryResults(container, payload);
    } catch (error) {
        const message = error instanceof Error ? error.message : i18n('admin.galleries.discovery_failed', 'Gallery discovery failed.');
        setDiscoveryProgress(container, 0, 0, 0, 0, 0, message);
    } finally {
        if (!redirected) {
            buttons.forEach((button) => {
                button.disabled = false;
                const label = originalLabels.get(button) || '';
                if ('value' in button && button.tagName === 'INPUT') {
                    button.value = label;
                } else {
                    button.textContent = label;
                }
            });
            container.dataset.discoveryRunning = '0';
        }
    }
}

/**
 * Return the discovery Ajax endpoint for a form or panel.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @return {string} Endpoint URL.
 */
function discoveryEndpoint(container) {
    if (container.dataset.discoveryEndpoint) {
        return container.dataset.discoveryEndpoint;
    }
    if (container instanceof HTMLFormElement) {
        return container.action || window.location.href;
    }
    return window.location.href;
}

/**
 * Return the CSRF token for a discovery request.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @return {string} CSRF token.
 */
function discoveryCsrfToken(container) {
    if (container.dataset.csrfToken) {
        return container.dataset.csrfToken;
    }
    const field = container.querySelector('input[name="csrf_token"]');
    return field instanceof HTMLInputElement ? field.value : '';
}

/**
 * POST one gallery discovery action and parse its JSON response.
 *
 * @param {string} endpoint Ajax endpoint URL.
 * @param {string} csrfToken CSRF token emitted by the server.
 * @param {string} action Discovery action name.
 * @param {string} jobToken Existing job token, when available.
 * @return {Promise<Object<string, *>>} Parsed payload.
 */
async function postDiscoveryAction(endpoint, csrfToken, action, jobToken) {
    const body = new FormData();
    body.set('csrf_token', csrfToken);
    body.set('ajax', '1');
    body.set('action', action);
    body.set('batch_size', '80');
    if (jobToken) {
        body.set('job_token', jobToken);
    }

    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        headers: {'Accept': 'application/json'},
    });
    if (!response.ok) {
        throw new Error(i18n('admin.galleries.discovery_failed_http', 'Gallery discovery request failed.'));
    }

    try {
        return await response.json();
    } catch (error) {
        throw new Error(i18n('admin.galleries.discovery_failed_json', 'Gallery discovery response was not valid JSON.'));
    }
}

/**
 * Update the discovery progress UI from a server payload.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @param {Object<string, *> | null} payload Parsed payload.
 */
function setDiscoveryProgressFromPayload(container, payload) {
    const percent = Number(payload?.percent || 0);
    const processed = Number(payload?.processed_directories || 0);
    const total = Number(payload?.discovered_directories || 0);
    const candidates = Number(payload?.candidate_count || 0);
    const metadataOnly = Number(payload?.metadata_only_count || 0);
    const message = String(payload?.message || i18n('admin.galleries.discovery_running', 'Scanning gallery folders...'));
    setDiscoveryProgress(container, percent, processed, total, candidates, metadataOnly, message);
}

/**
 * Update the discovery progress controls.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @param {number} percent Completion estimate.
 * @param {number} processed Number of scanned directories.
 * @param {number} total Number of discovered directories.
 * @param {number} candidates Number of discovered folders requiring review.
 * @param {number} metadataOnly Number of metadata-only folders ignored after completion.
 * @param {string} message Human-readable progress message.
 */
function setDiscoveryProgress(container, percent, processed, total, candidates, metadataOnly, message) {
    const progress = ensureDiscoveryProgress(container);
    progress.hidden = false;

    const bar = progress.querySelector('[data-admin-discovery-progress-bar]');
    if (bar instanceof HTMLProgressElement) {
        bar.max = 100;
        bar.value = Math.max(0, Math.min(100, percent));
    }

    const status = progress.querySelector('[data-admin-discovery-status]');
    if (status) {
        status.textContent = message;
    }

    const counts = progress.querySelector('[data-admin-discovery-counts]');
    if (counts) {
        const baseCounts = i18n('admin.galleries.discovery_counts', '{processed} / {total} folder(s) checked, {candidates} folder(s) need review.', {
            processed,
            total,
            candidates,
        });
        counts.textContent = metadataOnly > 0
            ? `${baseCounts} ${i18n('admin.galleries.discovery_metadata_only_count', '{count} metadata-only folder(s) ignored.', {count: metadataOnly})}`
            : baseCounts;
    }
}

/**
 * Ensure a discovery progress element exists for the supplied container.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @return {HTMLElement} Progress element.
 */
function ensureDiscoveryProgress(container) {
    let progress = container.querySelector('[data-admin-discovery-progress]');
    if (progress instanceof HTMLElement) {
        return progress;
    }

    progress = document.createElement('div');
    progress.className = 'thumbnail-progress';
    progress.dataset.adminDiscoveryProgress = 'true';
    progress.hidden = true;
    progress.innerHTML = '<progress class="thumbnail-progress-bar" max="100" value="0" data-admin-discovery-progress-bar></progress><p class="muted" data-admin-discovery-status></p><p class="muted" data-admin-discovery-counts></p>';
    container.append(progress);
    return progress;
}

/**
 * Render discovered candidates and their available actions into the dynamic discovery page.
 *
 * @param {HTMLElement} container Discovery panel.
 * @param {Object<string, *>} payload Completed discovery payload.
 */
function renderDiscoveryResults(container, payload) {
    const target = container.querySelector('[data-admin-discovery-results]');
    if (!(target instanceof HTMLElement)) {
        return;
    }

    const candidates = Array.isArray(payload.candidates) ? payload.candidates : [];
    if (candidates.length === 0) {
        const metadataOnly = Number(payload?.metadata_only_count || 0);
        const metadataNotice = metadataOnly > 0
            ? `<div class="notice">${escapeHtmlText(i18n('admin.galleries.discover_metadata_only_ignored', 'Ignored {count} metadata-only folder(s) because they do not contain supported photos. Use Add gallery if you intentionally want an empty gallery.', {count: metadataOnly}))}</div>`
            : '';
        target.innerHTML = `<p>${escapeHtmlText(i18n('admin.galleries.discover_none_found', 'No new importable photo folders found.'))}</p>${metadataNotice}`;
        return;
    }

    const importUrl = container.dataset.importUrl || '';
    const csrfToken = discoveryCsrfToken(container);
    const rows = candidates.map((candidate) => discoveryCandidateRow(candidate)).join('');
    const moveOptions = discoveryMoveTargetOptions(container);
    target.innerHTML = `
        <form method="post" action="${escapeHtmlAttribute(importUrl)}" data-import-galleries-form data-discovery-action-form>
            <input type="hidden" name="csrf_token" value="${escapeHtmlAttribute(csrfToken)}">
            <div class="notice">${escapeHtmlText(i18n('admin.galleries.discover_action_explanation', 'Choose what to do with the checked folders. Import creates new CMS galleries at their current disk folders. Move physically moves photo files into an existing gallery folder and scans them there. Delete removes the checked unmanaged folders from disk.'))}</div>
            <div class="admin-discovery-actions">
                <label><input type="radio" name="discovery_action" value="import_in_place" checked> ${escapeHtmlText(i18n('admin.galleries.discover_action_import', 'Import here as new galleries'))}</label>
                <label><input type="radio" name="discovery_action" value="move_photos"> ${escapeHtmlText(i18n('admin.galleries.discover_action_move', 'Move photos into an existing gallery'))}</label>
                <label><input type="radio" name="discovery_action" value="delete_from_disk"> ${escapeHtmlText(i18n('admin.galleries.discover_action_delete', 'Delete selected folders from disk'))}</label>
            </div>
            <div class="admin-discovery-move-target" data-discovery-move-target hidden>
                <label>${escapeHtmlText(i18n('admin.galleries.discover_move_target_label', 'Destination gallery'))}<select name="target_gallery_id">${moveOptions}</select></label>
                <p class="muted">${escapeHtmlText(i18n('admin.galleries.discover_move_help', 'Move copies no database gallery structure. It physically moves supported photo files into the selected gallery folder, keeps existing destination files, adds numbered suffixes for duplicate filenames, scans that destination gallery, and removes source folders only if they become empty.'))}</p>
            </div>
            <p data-discovery-thumbnail-row><label><input type="checkbox" name="create_thumbnails" value="1" checked> ${escapeHtmlText(i18n('admin.galleries.discover_create_thumbnails', 'Create optimized thumbnails after import or move'))}</label></p>
            <table>
                <thead><tr><th>${escapeHtmlText(i18n('admin.galleries.discover_column_select', 'Use'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_folder', 'Folder'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_destination', 'Destination'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_photos', 'Photos found'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_visibility', 'Visibility'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_effect', 'What happens'))}</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <button type="submit">${escapeHtmlText(i18n('admin.galleries.discover_run_selected_action', 'Run selected action'))}</button>
        </form>
    `;

    const form = target.querySelector('[data-discovery-action-form]');
    if (form instanceof HTMLFormElement) {
        setupDiscoveryActionControls(form);
    }
}

/**
 * Return rendered move-target select options from the server template.
 *
 * @param {HTMLElement} container Discovery panel.
 * @return {string} Safe option markup generated by PHP.
 */
function discoveryMoveTargetOptions(container) {
    const template = container.querySelector('[data-admin-gallery-move-options]');
    return template instanceof HTMLTemplateElement ? template.innerHTML : '';
}

/**
 * Attach action-specific controls to the discovery action form.
 *
 * @param {HTMLFormElement} form Discovery action form.
 */
function setupDiscoveryActionControls(form) {
    const update = () => updateDiscoveryActionControls(form);
    form.querySelectorAll('input[name="discovery_action"]').forEach((radio) => {
        radio.addEventListener('change', update);
    });
    form.addEventListener('submit', (event) => {
        const action = discoverySelectedAction(form);
        if (action === 'move_photos' && !form.querySelector('select[name="target_gallery_id"]')?.value) {
            event.preventDefault();
            window.alert(i18n('admin.galleries.discover_move_target_required', 'Choose the existing gallery where the photos should be moved.'));
            return;
        }
        if (action === 'delete_from_disk' && !window.confirm(i18n('admin.galleries.discover_delete_confirm', 'Delete the selected discovered folders from disk? This removes files and folders under galleries/. It does not delete existing CMS gallery records.'))) {
            event.preventDefault();
        }
    });
    update();
}

/**
 * Update thumbnail and target-gallery controls for the selected action.
 *
 * @param {HTMLFormElement} form Discovery action form.
 */
function updateDiscoveryActionControls(form) {
    const action = discoverySelectedAction(form);
    const moveTarget = form.querySelector('[data-discovery-move-target]');
    if (moveTarget instanceof HTMLElement) {
        moveTarget.hidden = action !== 'move_photos';
    }

    const thumbnailRow = form.querySelector('[data-discovery-thumbnail-row]');
    if (thumbnailRow instanceof HTMLElement) {
        thumbnailRow.hidden = action === 'delete_from_disk';
    }

    const thumbnailInput = form.querySelector('input[name="create_thumbnails"]');
    if (thumbnailInput instanceof HTMLInputElement) {
        thumbnailInput.disabled = action === 'delete_from_disk';
    }
}

/**
 * Return the selected discovery action for a form.
 *
 * @param {HTMLFormElement} form Discovery action form.
 * @return {string} Selected action value.
 */
function discoverySelectedAction(form) {
    return String(form.querySelector('input[name="discovery_action"]:checked')?.value || 'import_in_place');
}

/**
 * Render one discovery candidate table row.
 *
 * @param {Object<string, *>} candidate Candidate metadata from the server.
 * @return {string} Safe table row HTML.
 */
function discoveryCandidateRow(candidate) {
    const folderPath = String(candidate?.folder_path || '');
    const title = String(candidate?.title || '');
    const visibility = String(candidate?.visibility || '');
    const parentPath = String(candidate?.parent_folder_path || '');
    const parentTitle = String(candidate?.parent_title || i18n('admin.galleries.discover_parent_root', 'Gallery root'));
    const directImageCount = Number(candidate?.direct_image_count || 0);
    const branchImageCount = Number(candidate?.branch_image_count || 0);
    const descendantCount = Number(candidate?.descendant_candidate_count || 0);
    const hasSidecar = candidate?.has_sidecar === true;
    const hasTitleConflict = candidate?.existing_title_conflict === true;
    const conflictTitle = String(candidate?.existing_title_conflict_title || '');
    const conflictPath = String(candidate?.existing_title_conflict_path || '');
    const destination = discoveryDestinationText(title, parentPath, parentTitle);
    const photoSummary = discoveryPhotoSummary(directImageCount, branchImageCount, hasSidecar);
    const effect = discoveryImportEffect(descendantCount, hasTitleConflict, conflictTitle, conflictPath);
    const checkboxLabel = i18n('admin.galleries.discover_select_folder', 'Use {folder}', {folder: folderPath});
    const checkboxState = branchImageCount > 0 && !hasTitleConflict ? 'checked' : '';
    const warningClass = hasTitleConflict ? ' class="warning"' : '';

    return `<tr${warningClass}><td><input type="checkbox" name="folders[]" value="${escapeHtmlAttribute(folderPath)}" ${checkboxState} aria-label="${escapeHtmlAttribute(checkboxLabel)}"></td><td><strong>${escapeHtmlText(folderPath)}</strong></td><td>${escapeHtmlText(destination)}</td><td>${escapeHtmlText(photoSummary)}</td><td>${escapeHtmlText(visibility)}</td><td>${escapeHtmlText(effect)}</td></tr>`;
}

/**
 * Return the destination summary for one candidate row.
 *
 * @param {string} title Gallery title that will be written to the DB.
 * @param {string} parentPath Parent folder path relative to the gallery root.
 * @param {string} parentTitle Parent gallery title or root label.
 * @return {string} Destination summary for the table.
 */
function discoveryDestinationText(title, parentPath, parentTitle) {
    if (!parentPath) {
        return i18n('admin.galleries.discover_destination_root', 'A new gallery named "{title}" would be created in {parent}.', {title, parent: parentTitle});
    }

    return i18n('admin.galleries.discover_destination_parent', 'A new gallery named "{title}" would be created inside {parent}. Folder path: {path}.', {
        title,
        parent: parentTitle,
        path: parentPath,
    });
}

/**
 * Return a photo-count summary for one candidate row.
 *
 * @param {number} directImageCount Supported images directly inside the folder.
 * @param {number} branchImageCount Supported images inside the folder and descendants.
 * @param {boolean} hasSidecar Whether the folder has gallery.json metadata.
 * @return {string} Photo-count summary for the table.
 */
function discoveryPhotoSummary(directImageCount, branchImageCount, hasSidecar) {
    if (directImageCount === 0 && branchImageCount === 0 && hasSidecar) {
        return i18n('admin.galleries.discover_photos_sidecar_only', 'This folder only has gallery metadata. No photos were found.');
    }

    const nestedCount = Math.max(0, branchImageCount - directImageCount);
    if (nestedCount === 0) {
        return i18n('admin.galleries.discover_photos_direct_only', 'This folder contains {count} photo file(s).', {count: directImageCount});
    }
    if (directImageCount === 0) {
        return i18n('admin.galleries.discover_photos_nested_only', 'This folder has no photos directly inside it. Its child folders contain {count} photo file(s).', {count: nestedCount});
    }

    return i18n('admin.galleries.discover_photos_direct_and_nested', 'This folder contains {direct} photo file(s). Child folders contain {nested} more photo file(s).', {
        direct: directImageCount,
        nested: nestedCount,
    });
}

/**
 * Return the import behavior summary for one candidate row.
 *
 * @param {number} descendantCount Number of descendant candidates under the folder.
 * @param {boolean} hasTitleConflict Whether a same-title sibling already exists.
 * @param {string} conflictTitle Existing sibling gallery title.
 * @param {string} conflictPath Existing sibling gallery folder path.
 * @return {string} Import behavior summary for the table.
 */
function discoveryImportEffect(descendantCount, hasTitleConflict, conflictTitle, conflictPath) {
    if (hasTitleConflict) {
        return i18n('admin.galleries.discover_effect_title_conflict', 'Possible duplicate: an existing gallery named "{title}" already exists at {path}. This row is left unchecked. Move the photos into the existing gallery or delete the extra folder unless you really want a second gallery.', {
            title: conflictTitle,
            path: conflictPath,
        });
    }

    if (descendantCount > 0) {
        return i18n('admin.galleries.discover_effect_with_descendants', 'Import here creates this gallery and {count} missing child gallery folder(s). Files stay where they are.', {count: descendantCount});
    }

    return i18n('admin.galleries.discover_effect_single', 'Import here creates this gallery and scans photos stored in this folder. Files stay where they are.');
}
