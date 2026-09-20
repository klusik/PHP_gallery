/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-interaction-policy.js
 * Module Type: Browser Policy
 * Purpose: Own immutable browser-only Admin input, feedback and report-request budgets.
 * Responsibilities:
 *   - Define the reviewed title-completion, Settings search and navigation-data policies.
 *   - Bound report request retries and telemetry-window input without duplicating server batch policy.
 *   - Keep independent meanings separate without loading server configuration or runtime state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Minimum entered prefix eligible for optional title suggestions.
 * @var {number}
 * Units: Unicode code points. Scope: title matcher and request admission.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: Preserve the two-character server contract and avoid broad single-letter queries.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const GALLERY_TITLE_COMPLETION_MIN_CHARACTERS = 2;

/**
 * Maximum entered prefix sent to the optional title endpoint.
 * @var {number}
 * Units: Unicode code points. Scope: title request admission.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: Match the existing title-size request contract; server validation remains authoritative.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const GALLERY_TITLE_COMPLETION_MAX_CHARACTERS = 255;

/**
 * Maximum candidate rows retained from one title response.
 * @var {number}
 * Units: candidate rows. Scope: title response consumption.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: Retain the bounded eight-candidate client guard matching the server response contract.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const GALLERY_TITLE_COMPLETION_RESULT_LIMIT = 8;

/**
 * Wait for a title typing burst to settle before requesting suggestions.
 * @var {number}
 * Units: milliseconds. Scope: title request scheduling.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: Preserve the existing 180 ms balance between optional request traffic and responsive ghost text.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const GALLERY_TITLE_COMPLETION_DEBOUNCE_MS = 180;

/**
 * Abort an optional title request that has not completed promptly.
 * @var {number}
 * Units: milliseconds. Scope: each title fetch.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: Preserve the five-second deadline; suggestion failure must not block ordinary title entry.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const GALLERY_TITLE_COMPLETION_REQUEST_TIMEOUT_MS = 5000;

/**
 * Maximum locally ranked Settings results shown at once.
 * @var {number}
 * Units: result links. Scope: Settings search popover.
 * Consumers: admin-settings-search.js.
 * Rationale: Keep the existing twelve-link keyboard navigation and rendering budget.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_SETTINGS_SEARCH_RESULT_LIMIT = 12;

/**
 * Rank a match at the beginning of a Settings label.
 * @var {number}
 * Units: dimensionless ranking points per token. Scope: Settings local search.
 * Consumers: admin-settings-search.js.
 * Rationale: Preserve the existing 100 > 70 > 50 > 10 relevance order without changing additive multi-token scoring.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_SETTINGS_SEARCH_PREFIX_SCORE = 100;

/**
 * Rank a match following a space in a Settings label.
 * @var {number}
 * Units: dimensionless ranking points per token. Scope: Settings local search.
 * Consumers: admin-settings-search.js.
 * Rationale: Keep a word-start label match below a prefix match and above an interior substring.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_SETTINGS_SEARCH_WORD_SCORE = 70;

/**
 * Rank an interior substring match in a Settings label.
 * @var {number}
 * Units: dimensionless ranking points per token. Scope: Settings local search.
 * Consumers: admin-settings-search.js.
 * Rationale: Keep a label substring above a keyword-only match without changing sort tie breaking.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_SETTINGS_SEARCH_SUBSTRING_SCORE = 50;

/**
 * Rank a token found only in the Settings searchable description.
 * @var {number}
 * Units: dimensionless ranking points per token. Scope: Settings local search.
 * Consumers: admin-settings-search.js.
 * Rationale: Retain discoverability of descriptive keywords while preferring label matches.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_SETTINGS_SEARCH_KEYWORD_SCORE = 10;

/**
 * Keep the destination Settings control highlighted after activation.
 * @var {number}
 * Units: milliseconds. Scope: Settings search destination feedback.
 * Consumers: admin-settings-search.js.
 * Rationale: Preserve the existing brief visual orientation cue after scrolling to a setting.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_SETTINGS_SEARCH_HIGHLIGHT_MS = 1800;

/**
 * Show the navigation-data clipboard result before restoring its label.
 * @var {number}
 * Units: milliseconds. Scope: navigation-data copy buttons.
 * Consumers: admin-navdata-panel.js.
 * Rationale: Preserve the existing readable success/failure feedback interval.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_NAVDATA_COPY_FEEDBACK_MS = 1600;

/**
 * Minimum navigation identifier length accepted by the lookup form.
 * @var {number}
 * Units: UTF-16 code units. Scope: navigation-data diagnostic lookup admission.
 * Consumers: admin-navdata-panel.js.
 * Rationale: Preserve JavaScript string.length behavior and the existing two-character validation message.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_NAVDATA_LOOKUP_MIN_CHARACTERS = 2;

/**
 * Delay the navigation-data POST after the two existing animation frames.
 * @var {number}
 * Units: milliseconds. Scope: navigation-data import submission feedback.
 * Consumers: admin-navdata-update.js.
 * Rationale: Retain the visible busy-state paint opportunity before ordinary form navigation; this is not a network timeout.
 * Range: fixed positive integer; changes require review of the named consumer contract.
 */
export const ADMIN_NAVDATA_SUBMIT_FEEDBACK_MS = 250;

/**
 * Telemetry window used when the report selector is absent, empty or non-finite.
 * @var {number}
 * Units: days. Scope: report input fallback, not an override of a selected window.
 * Consumers: admin-gallery-report.js.
 * Rationale: Preserve the existing thirty-day browser fallback; the server validates its own input.
 * Range: fixed positive integer within the report telemetry-window bounds.
 */
export const ADMIN_GALLERY_REPORT_DEFAULT_TELEMETRY_DAYS = 30;

/**
 * Smallest telemetry window sent by the report control.
 * @var {number}
 * Units: days. Scope: report input lower clamp.
 * Consumers: admin-gallery-report.js.
 * Rationale: Preserve the existing one-day lower bound without changing Number conversion or fractional input.
 * Range: fixed positive integer; server validation remains authoritative.
 */
export const ADMIN_GALLERY_REPORT_MIN_TELEMETRY_DAYS = 1;

/**
 * Largest telemetry window sent by the report control.
 * @var {number}
 * Units: days. Scope: report input upper clamp, not telemetry retention policy.
 * Consumers: admin-gallery-report.js.
 * Rationale: Preserve the existing 3650-day browser bound; this does not authorize server work or change stored data.
 * Range: fixed positive integer no smaller than the report's minimum window.
 */
export const ADMIN_GALLERY_REPORT_MAX_TELEMETRY_DAYS = 3650;

/**
 * Total HTTP attempts allowed for each report start or step action.
 * @var {number}
 * Units: attempts including the first request. Scope: one report action, not the entire job.
 * Consumers: admin-gallery-report.js.
 * Rationale: Preserve four total attempts and at most three resends on transport errors or retryable HTTP statuses.
 * Range: fixed positive integer; an unsuccessful final response is returned to the action's error handler.
 */
export const ADMIN_GALLERY_REPORT_REQUEST_ATTEMPT_LIMIT = 4;

/**
 * Delay before the first retry of a failed report action.
 * @var {number}
 * Units: milliseconds. Scope: retry backoff; the initial request has no wait.
 * Consumers: admin-gallery-report.js.
 * Rationale: Preserve the first 750 ms pause between transient failures without introducing a request deadline.
 * Range: fixed positive integer, scaled by the report backoff factor on later retries.
 */
export const ADMIN_GALLERY_REPORT_RETRY_BASE_DELAY_MS = 750;

/**
 * Growth factor applied to successive report retry delays.
 * @var {number}
 * Units: dimensionless multiplier. Scope: bounded report request retry backoff.
 * Consumers: admin-gallery-report.js.
 * Rationale: Preserve waits of 750, 1500 and 3000 ms for the existing four-attempt budget, with no jitter.
 * Range: fixed multiplier greater than one; it is not a separate attempt budget.
 */
export const ADMIN_GALLERY_REPORT_RETRY_BACKOFF_FACTOR = 2;

/**
 * HTTP failures eligible for a bounded retry of the same report action.
 * @var {ReadonlyArray<number>}
 * Units: HTTP status codes. Scope: report requests only.
 * Consumers: admin-gallery-report.js.
 * Rationale: Preserve the existing transient-hosting allowlist; other HTTP errors stop immediately and transport errors retry separately.
 * Range: frozen exact allowlist of 408, 429, 500, 502, 503 and 504; no other workflow inherits it implicitly.
 */
export const ADMIN_GALLERY_REPORT_RETRYABLE_HTTP_STATUSES = Object.freeze([408, 429, 500, 502, 503, 504]);
