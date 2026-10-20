/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_side_panel_delegation/cover_stars.mjs
 * Module Type: Node Regression Test
 * Purpose: Verify three-state cover button intents and single-click defaults.
 * Responsibilities:
 *   - Preserve exclusive one-click cover selection.
 *   - Check repeated gold/blue clicks and add-to-cover transitions.
 *   - Retain the existing form-owned API for the side-panel implementation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../../public/assets/gallery-modules/admin-gallery-images.js', import.meta.url), 'utf8');
const cinematicStyles = readFileSync(new URL('../../public/assets/styles/admin-cinematic.css', import.meta.url), 'utf8');
const sidePanelStyles = readFileSync(new URL('../../public/assets/styles/side-panel.css', import.meta.url), 'utf8');
const {coverStarActionForState, coverSelectionPresentation} = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));

assert.equal(coverStarActionForState('single', 'off'), 'cover', 'Clicking G2 selects only G2');
assert.equal(coverStarActionForState('single', 'off'), 'cover', 'Clicking G3 replaces G2 in exclusive mode');
assert.equal(coverStarActionForState('single', 'single'), 'cover_arm', 'Clicking G3 again turns it blue');
assert.equal(coverStarActionForState('multi', 'off'), 'cover_add', 'Clicking G2 now accumulates a second blue star');
assert.equal(coverStarActionForState('multi', 'multi'), 'cover_reset', 'Clicking either blue star resets to only that picture');
assert.equal(coverStarActionForState('automatic', 'off'), 'cover', 'Explicit photo choices override Automatic');
assert.deepEqual(coverSelectionPresentation({cover_selection_mode: 'multi', cover_selection_ids: [2, 3], cover_selection_revision: 'r4'}),
    {mode: 'multi', ids: [2, 3], revision: 'r4'}, 'Canonical responses normalize selected IDs and revision');
assert.equal(coverSelectionPresentation({cover_selection_mode: 'multi', cover_selection_ids: [1, 2, 3, 4, 5]}), null,
    'The frontend rejects a canonical state beyond the four-photo limit');
assert.equal(coverSelectionPresentation({cover_selection_mode: 'single', cover_selection_ids: [2]}), null,
    'The frontend rejects cover state without its opaque revision');
assert.match(source, /data-admin-image-cover-star/);
assert.match(source, /form\.dataset\.coverSelectionMode/);
assert.match(source, /form\.dataset\.coverMultiSupported === '1'/);
assert.match(source, /form\.dataset\.coverSelectionMode = mode/);
assert.match(source, /settingsForm\?\.elements\.namedItem\('cover_image_id'\)/,
    'The shared cover selector must be found through native form ownership when Artwork controls live outside the form element.');
assert.match(source, /php-gallery:side-panel-success/);
assert.match(source, /aria-pressed/);
assert.match(cinematicStyles,
    /\.admin-page button:not\(\.secondary\):not\(\.danger\)/,
    'The visual fixture must retain the real generic Admin button rule that previously overrode cover-state colors.');
assert.match(sidePanelStyles,
    /#admin-edit-images \.admin-image-row-actions \.admin-image-cover-button\.is-cover\s*\{[^}]*background:\s*#fff4c2/s,
    'The scoped Images owner must out-rank the generic Admin button rule for the exclusive gold state.');
assert.match(sidePanelStyles,
    /#admin-edit-images \.admin-image-row-actions \.admin-image-cover-button\.is-cover-multi\s*\{[^}]*background:\s*#dbeafe/s,
    'The scoped Images owner must out-rank the generic Admin button rule for accumulated pale-blue state.');
const panelSource = readFileSync(new URL('../../public/assets/gallery-modules/admin-side-panel.js', import.meta.url), 'utf8');
const controllerSource = readFileSync(new URL('../../app/controllers/admin_galleries_edit_actions.php', import.meta.url), 'utf8');
const bulkSource = readFileSync(new URL('../../app/controllers/admin_images_bulk.php', import.meta.url), 'utf8');
const operations = readFileSync(new URL('../../public/assets/gallery-modules/admin-operations.js', import.meta.url), 'utf8');
assert.match(panelSource, /submitter\.matches\('\[data-admin-image-cover-star\]'\)/,
    'The panel must recognize star actions without relying on a separate click listener.');
assert.match(panelSource, /form\.__adminPanelSubmitter = null;/,
    'Each submission must consume and clear the previous submitter fallback.');
// Import identity and invalidation belong to scripts/admin_import_contracts.php.
assert.match(panelSource, /form\.matches\('\[data-admin-image-bulk-form\]'\)/,
    'Standalone editor stars must use delegated asynchronous submissions.');
assert.match(panelSource, /cover_state', '1'/,
    'Ambiguous POST failures must read authoritative cover state.');
assert.match(panelSource, /pendingGalleryCoverForms = new WeakSet\(\)/,
    'A mounted form cannot start overlapping title-picture writes.');
assert.match(panelSource, /action === 'cover' \|\| Boolean\(rowAction/,
    'The toolbar cover action uses the same asynchronous cover lifecycle as row stars.');
assert.match(panelSource, /uncertainGalleryCoverForms = new WeakSet\(\)/,
    'An ambiguous write blocks retries until passive state reconciliation succeeds.');
assert.match(panelSource, /if \(!form\.isConnected \|\| !owner\.isCurrent\(\)\)/,
    'Late successful responses cannot mutate a superseded editor.');
assert.match(panelSource, /cover_state_refreshed_after_failure/,
    'Reconciliation preserves the failed write notice while reporting refreshed state.');
assert.match(source, /settingsRevision\.value = editRevision/,
    'A server-confirmed cover write advances only the hidden editor revision.');
assert.match(controllerSource, /gallery_editor_set_cover_images\(\s*\$galleryId,\s*\$ownedIds,\s*\$gallery,\s*\$expectedRevision,?\s*\)/,
    'Bulk cover selection must preserve all selected images rather than only the first.');
assert.match(controllerSource, /gallery\.cover_update_failed/,
    'Server title-picture failures must reach the Admin log.');
assert.match(controllerSource, /reference.*gallery_id.*action/s,
    'Title-picture errors must be correlated with a reference and gallery ID.');
assert.match(bulkSource, /cover_arm\|cover_add\|cover_reset/,
    'All three-star actions must be accepted by the image bulk endpoint.');
console.log('gallery cover star logic tests passed');
