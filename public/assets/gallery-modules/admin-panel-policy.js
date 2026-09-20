/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Module
 * Purpose: Define immutable browser-only drawer and create/upload intent policy.
 * Responsibilities:
 *   - Bound transient recovery/comparison memory and share public browser protocol invariants.
 * File: public/assets/gallery-modules/admin-panel-policy.js
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Immutable browser-only drawer policy. These limits bound transient memory and
 * match the existing drawer transition; they are not deployment settings.
 */

/**
 * Drawer exit animation duration shared by close/reopen ownership.
 * @var {number} Units: milliseconds. Scope: browser drawer lifecycle.
 * Consumers: admin-side-panel.js, admin-panel-lifecycle.js.
 * Rationale: delayed hiding must match the existing exit transition without hiding a reopened drawer.
 */
export const ADMIN_PANEL_MOTION_MS = 280;

/**
 * Maximum retained entity/workflow drafts per document; full capacity refuses replacement.
 * @var {number} Units: draft records. Scope: document-local gallery text recovery.
 * Consumers: admin-panel-drafts.js.
 * Rationale: bound retained contexts without evicting or silently losing earlier edits.
 */
export const ADMIN_PANEL_DRAFT_LIMIT = 8;

/**
 * Maximum combined retained text length; never truncate a draft to fit.
 * @var {number} Units: UTF-16 code units. Scope: document-local gallery text recovery.
 * Consumers: admin-panel-drafts.js.
 * Rationale: bound copied text independently of server field validation limits.
 */
export const ADMIN_PANEL_DRAFT_TEXT_LIMIT = 262144;

/**
 * Maximum length of an explicitly named non-credential edit revision, including opaque snapshots.
 * @var {number} Units: ASCII characters. Scope: gallery draft revision comparisons.
 * Consumers: admin-panel-drafts.js.
 * Rationale: accept bounded legacy snapshots without retaining arbitrary hidden-field contents.
 */
export const ADMIN_PANEL_REVISION_LENGTH_LIMIT = 128;

/**
 * Canonical positive decimal edit revision with at most 20 ASCII digits.
 * @var {RegExp} Units: decimal-string syntax. Scope: successful gallery-save acknowledgments.
 * Consumers: admin-panel-drafts.js.
 * Rationale: match the public unsigned BIGINT protocol without Number rounding or accepting credential-shaped input.
 */
export const ADMIN_PANEL_SAVED_REVISION_PATTERN = Object.freeze(/^[1-9][0-9]{0,19}$/);

/**
 * Explicit non-credential text fields owned by ordinary gallery create/edit forms.
 * @var {readonly string[]} Units: form-control names. Scope: recoverable gallery text.
 * Consumers: admin-panel-drafts.js.
 * Rationale: exclude files, authentication fields and access settings from text-only draft restoration.
 */
export const ADMIN_PANEL_DRAFT_FIELDS = Object.freeze(['title', 'description']);

/**
 * Native keyboard candidates; visibility, disabled fieldsets and inert ancestors are checked separately.
 * @var {string} Units: CSS selector. Scope: drawer focus containment and restoration.
 * Consumers: admin-panel-lifecycle.js.
 * Rationale: use native interactive controls and explicit tabindex without focusing hidden or inert content.
 */
export const ADMIN_PANEL_FOCUS_SELECTOR = 'a[href], button, input:not([type="hidden"]), select, textarea, [tabindex], [contenteditable="true"]';

/**
 * Public operation-key protocol entropy; the encoded value is not an authentication token.
 * @var {number} Units: random bytes. Scope: create/classic-upload intent identity.
 * Consumers: admin-operation-keys.js.
 * Rationale: 32 cryptographically random bytes match the server's 64-lowercase-hex protocol; expose no server secret/default map.
 */
export const OPERATION_KEY_BYTES = 32;

/**
 * Syntax of the public replay-intent identifier, independent of authorization or CSRF.
 * @var {RegExp} Units: hexadecimal-string syntax. Scope: generated create/upload forms.
 * Consumers: admin-operation-keys.js.
 * Rationale: require the server protocol's exact encoding rather than manufacturing missing form authority.
 */
export const OPERATION_KEY_PATTERN = Object.freeze(/^[a-f0-9]{64}$/);

/**
 * Explicit non-credential scalar inputs compared before retrying an unresolved intent.
 * @var {readonly string[]} Units: form-control names. Scope: document-local create/upload comparison, never draft restoration.
 * Consumers: admin-operation-keys.js.
 * Rationale: detect semantic input changes while excluding CSRF, passwords, URLs and arbitrary hidden fields.
 */
export const OPERATION_FIELDS = Object.freeze(['title', 'folder_name', 'description', 'gallery_date', 'gallery_date_end', 'visibility', 'count_badge_visibility', 'parent_id', 'voting_enabled', 'show_filenames', 'sort_order', 'upload_mode', 'gallery_id', 'create_thumbnails']);

/**
 * Maximum retained scalar comparison representation; oversized input stays in the original form.
 * @var {number} Units: UTF-16 code units. Scope: one document-local create/upload intent.
 * Consumers: admin-operation-keys.js.
 * Rationale: bound comparison memory without copying multipart bodies or imposing a new server upload-size limit.
 */
export const OPERATION_SCALAR_LIMIT = 1048576;
