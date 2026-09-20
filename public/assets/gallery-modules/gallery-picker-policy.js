/**
 * Project: PHP Gallery
 * Purpose: Define browser destination-picker timing and representation limits.
 * Responsibilities:
 *   - Share search debounce, visible depth and exact decimal identifier rules with the picker.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-picker-policy.js
 * Module Type: Browser Policy
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Delay destination search until the current typing burst settles.
 * @var {number}
 * Units: milliseconds. Scope: every searchable-gallery-picker input.
 * Consumers: searchable-gallery-picker.js.
 * Rationale: reduce repeated requests while retaining immediate ID invalidation.
 */
export const GALLERY_PICKER_SEARCH_DEBOUNCE_MS = 200;

/**
 * Maximum visual path indentation; the complete path remains readable text.
 * @var {number}
 * Units: hierarchy levels. Scope: destination result button presentation.
 * Consumers: searchable-gallery-picker.js.
 * Rationale: deep catalogs must not push result labels outside the control.
 */
export const GALLERY_PICKER_DISPLAY_DEPTH_LIMIT = 8;

/**
 * Positive decimal ID syntax matching the PHP request contract.
 * @var {RegExp}
 * Units: decimal characters. Scope: server-returned IDs and continuation cursors.
 * Consumers: searchable-gallery-picker.js.
 * Rationale: preserve exact BIGINT identities without JavaScript number coercion.
 */
export const GALLERY_PICKER_POSITIVE_ID_PATTERN = /^[1-9][0-9]{0,18}$/;
