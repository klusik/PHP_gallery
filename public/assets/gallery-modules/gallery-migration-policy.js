/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-migration-policy.js
 * Module Type: Browser Policy
 * Purpose: Own the gallery-migration browser reconnect and recovery-work bounds.
 * Responsibilities:
 *   - Keep administrator-selected reconnect values within the existing transport range.
 *   - Bound package attempts and status probes without weakening receipt checks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Fallback request-refresh interval when the form value is absent or non-finite.
 * @var {number}
 * Units: seconds. Scope: migration reconnect form and request timeout normalization.
 * Consumers: admin-gallery-migration.js.
 * Rationale: Retain the existing 30-second fallback; valid administrator-entered values still win.
 * Range: positive fallback within the minimum/maximum reconnect bounds.
 */
export const GALLERY_MIGRATION_DEFAULT_RECONNECT_SECONDS = 30;

/**
 * Shortest admitted migration reconnect interval.
 * @var {number}
 * Units: seconds. Scope: browser migration request normalization.
 * Consumers: admin-gallery-migration.js.
 * Rationale: Preserve the existing five-second floor and form/server contract; avoid immediate repeated aborts.
 * Range: positive lower bound no greater than the default.
 */
export const GALLERY_MIGRATION_MIN_RECONNECT_SECONDS = 5;

/**
 * Longest admitted migration reconnect interval.
 * @var {number}
 * Units: seconds. Scope: browser migration request normalization.
 * Consumers: admin-gallery-migration.js.
 * Rationale: Preserve the existing five-minute ceiling and form/server contract; do not remove bounded requests.
 * Range: upper bound no smaller than the default.
 */
export const GALLERY_MIGRATION_MAX_RECONNECT_SECONDS = 300;

/**
 * Maximum total transfer attempts for one ZIP package, including the first attempt.
 * @var {number}
 * Units: transfer attempts per package. Scope: migration package retry loop.
 * Consumers: admin-gallery-migration.js.
 * Rationale: The old MAX_PACKAGE_RETRIES value counted six total attempts, not six retries; preserve the status check after every failed transfer.
 * Range: fixed positive integer; at most five resends after the initial attempt.
 */
export const GALLERY_MIGRATION_PACKAGE_ATTEMPT_LIMIT = 6;

/**
 * Maximum target-status requests after an interrupted package transfer.
 * @var {number}
 * Units: status probes per reconnect confirmation. Scope: migration recovery status loop.
 * Consumers: admin-gallery-migration.js.
 * Rationale: Retain four opportunities to observe remote completion before allowing the existing retry decision.
 * Range: fixed positive integer; a complete observed package returns immediately.
 */
export const GALLERY_MIGRATION_STATUS_PROBE_LIMIT = 4;

/**
 * Pause between successive target-status probes, never before the first probe.
 * @var {number}
 * Units: milliseconds. Scope: migration recovery status loop.
 * Consumers: admin-gallery-migration.js.
 * Rationale: Retain 1.5-second spacing so remote completion can become observable without an immediate request burst.
 * Range: fixed positive integer; at most three such waits per confirmation.
 */
export const GALLERY_MIGRATION_STATUS_PROBE_DELAY_MS = 1500;
