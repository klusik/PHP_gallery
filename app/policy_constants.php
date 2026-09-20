<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Keep protocol, security and format invariants separate from deployment-tunable defaults.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/policy_constants.php
 * Module Type: Immutable Policy Definitions
 * Purpose: Centralize named invariants; deployment tunables belong in configuration_defaults.php.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core;

/**
 * Fixed prefixes for request-owned migration transfer files.
 * @var array<string,string>
 * Units: filename prefixes. Scope: temporary migration transfers.
 * Consumers: gallery migration temporary-file service.
 * Rationale: distinguish outgoing ZIPs, incoming packages and individual assets without accepting a submitted path or filename.
 */
const GALLERY_MIGRATION_TEMPORARY_PREFIXES = [
    'asset' => 'php_gallery_migration_',
    'package' => 'php_gallery_migration_package_',
    'source_package' => 'php_gallery_migration_zip_',
];

/**
 * Prefix requested for an exclusively allocated WebDAV request-body file.
 * @var string
 * Units: filename prefix. Scope: WebDAV staging. Consumers: mobile_webdav stream service.
 * Rationale: preserve the existing temporary-file naming convention without accepting a submitted cleanup path.
 */
const MOBILE_WEBDAV_TEMPORARY_PREFIX = 'pg-webdav-';
/**
 * Portable leading bytes retained by native temporary-name generation.
 * @var string
 * Units: filename prefix. Scope: WebDAV staging admission. Consumers: mobile_webdav stream service.
 * Rationale: preserve the existing Windows-compatible pg- check; allocation ownership and file identity, not this prefix alone, authorize cleanup.
 */
const MOBILE_WEBDAV_TEMPORARY_PORTABLE_PREFIX = 'pg-';

/**
 * Permissions requested when setup first creates the installation-lock directory.
 * @var int
 * Units: octal POSIX mode, reduced by the process umask. Scope: setup lock storage.
 * Consumers: auth setup service. Rationale: preserve the historical 0775 cache-directory mode, distinct from media directory policy.
 */
const SETUP_LOCK_DIRECTORY_PERMISSIONS = 0775;

/**
 * Policy for normal detector metadata batch.
 * @var int
 * Units: rows. Scope: normal detector metadata batch. Consumers: detector controller/service.
 * Rationale: Preserve the established 200-row request default without reopening source photographs.
 */
const DUPLICATE_PHOTO_DETECTOR_DEFAULT_BATCH_SIZE = 200;
/**
 * Policy for detector request admission.
 * @var int
 * Units: rows. Scope: detector request admission. Consumers: detector service.
 * Rationale: Retain the independent hard ceiling even when a submitted batch is larger.
 */
const DUPLICATE_PHOTO_DETECTOR_MAX_BATCH_SIZE = 300;
/**
 * Policy for idle detector checkpoint expiry.
 * @var int
 * Units: seconds. Scope: idle detector checkpoint expiry. Consumers: detector service.
 * Rationale: Keep the existing one-hour expiry measured from the latest update, falling back to start time.
 */
const DUPLICATE_PHOTO_DETECTOR_JOB_TTL_SECONDS = 3600;
/**
 * Policy for pre-start caller checkpoint pruning.
 * @var int
 * Units: jobs. Scope: pre-start caller checkpoint pruning. Consumers: detector service.
 * Rationale: Preserve the newest three existing jobs before admitting a new one; the new job may temporarily add one entry.
 */
const DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS = 3;
/**
 * Policy for bounded detector result materialization.
 * @var int
 * Units: groups. Scope: bounded detector result materialization. Consumers: detector service.
 * Rationale: Together with the member cap, bound image-row lookup for visible grouped results.
 */
const DUPLICATE_PHOTO_DETECTOR_GROUPS_PER_PAGE = 10;
/**
 * Policy for detector result materialization.
 * @var int
 * Units: images per group. Scope: detector result materialization. Consumers: detector service.
 * Rationale: Preserve the existing per-group bound independently of total duplicate matches.
 */
const DUPLICATE_PHOTO_DETECTOR_MAX_GROUP_MEMBERS = 30;
/**
 * Policy for detector result pagination.
 * @var int
 * Units: pairs. Scope: detector result pagination. Consumers: detector service.
 * Rationale: Keep pair pagination distinct from the numerically equal grouped-result limit.
 */
const DUPLICATE_PHOTO_DETECTOR_PAIRS_PER_PAGE = 10;
/**
 * Policy for detector pair expansion.
 * @var int
 * Units: candidate pairs. Scope: detector pair expansion. Consumers: detector service.
 * Rationale: Prevent combinatorial result expansion from exhausting the checkpoint/request budget.
 */
const DUPLICATE_PHOTO_DETECTOR_MAX_PAIR_REFERENCES = 10000;
/**
 * Policy for possible-duplicate evidence threshold.
 * @var int
 * Units: normalized metadata components. Scope: possible-duplicate evidence threshold. Consumers: detector service.
 * Rationale: Preserve the existing evidence threshold together with required capture time and identity checks.
 */
const DUPLICATE_PHOTO_DETECTOR_MIN_EXIF_COMPONENTS = 4;
/**
 * Policy for new detector job identity.
 * @var int
 * Units: random bytes. Scope: new detector job identity. Consumers: detector service.
 * Rationale: Preserve 128-bit opaque identifiers; the caller's session and Admin checks remain the authorization boundary.
 */
const DUPLICATE_PHOTO_DETECTOR_TOKEN_BYTES = 16;

/**
 * Session key for the one active complete-gallery report checkpoint.
 * @var string
 * Units: session-map key. Scope: report controller storage, not domain policy.
 * Consumers: admin_gallery_report controller.
 * Rationale: retain existing in-progress jobs through the session ownership migration.
 */
const ADMIN_GALLERY_REPORT_JOB_KEY = 'admin_gallery_report_job_v1';
/**
 * Normal image-row work per complete-report batch.
 * @var int
 * Units: rows. Scope: report processing. Consumers: report controller/job service.
 * Rationale: retain the established bounded shared-hosting workload.
 */
const ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE = 250;
/**
 * Hard ceiling for one requested complete-report image batch.
 * @var int
 * Units: rows. Scope: untrusted batch admission. Consumers: report controller/job service.
 * Rationale: retain the 500-row ceiling even when callers request more.
 */
const ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE = 500;
/**
 * Maximum distinct EXIF aggregate groups retained by report summaries.
 * @var int
 * Units: groups. Scope: report grouping. Consumers: report query helpers.
 * Rationale: keep high-cardinality aggregates bounded in caller checkpoint storage.
 */
const ADMIN_GALLERY_REPORT_MAX_GROUPS = 500;
/**
 * Approximate grouping radius for nearby report GPS observations.
 * @var float
 * Units: kilometers. Scope: report GPS clusters. Consumers: report GPS/job services.
 * Rationale: preserve the existing 20-kilometer approximation and its presentation label.
 */
const ADMIN_GALLERY_REPORT_GPS_AREA_KM = 20.0;
/**
 * Maximum approximate GPS clusters retained by a report job.
 * @var int
 * Units: clusters. Scope: report GPS aggregation. Consumers: report GPS service.
 * Rationale: bound checkpoint memory and repeated proximity matching on large libraries.
 */
const ADMIN_GALLERY_REPORT_MAX_GPS_CLUSTERS = 500;
/**
 * Fallback matching radius when a known report place has no explicit radius.
 * @var float
 * Units: kilometers. Scope: known-place labeling. Consumers: report GPS service.
 * Rationale: preserve the existing 35-kilometer fallback without changing per-place definitions.
 */
const ADMIN_GALLERY_REPORT_PLACE_MATCH_DEFAULT_RADIUS_KM = 35.0;

/**
 * Default telemetry lookback when a report request omits a window.
 * @var int
 * Units: days. Scope: complete report. Consumers: report controller/job service.
 * Rationale: retain the existing month-sized default across request and checkpoint boundaries.
 */
const ADMIN_GALLERY_REPORT_TELEMETRY_DEFAULT_DAYS = 30;
/**
 * Longest admitted telemetry lookback for complete reports.
 * @var int
 * Units: days. Scope: complete report admission. Consumers: report controller/job service.
 * Rationale: preserve the historical ten-year cap even for untrusted requests.
 */
const ADMIN_GALLERY_REPORT_TELEMETRY_MAX_DAYS = 3650;
/**
 * Fixed comparison windows included alongside the requested telemetry window.
 * @var list<int>
 * Units: days per window. Scope: complete report comparisons. Consumers: report content service.
 * Rationale: retain the weekly, monthly, quarterly and annual comparisons in their existing order.
 */
const ADMIN_GALLERY_REPORT_TELEMETRY_COMPARISON_WINDOWS = [7, 30, 90, 365];
/**
 * Per-section report row limits, separate from image-processing batch admission.
 * @var array<string,int>
 * Units: rows. Scope: complete report output. Consumers: report model and aggregation services.
 * Rationale: retain existing output sizes while keeping independent sections independently named.
 */
const ADMIN_GALLERY_REPORT_ROW_LIMITS = [
    'galleries' => 80,
    'tag_usage' => 80,
    'log_errors' => 80,
    'log_events' => 80,
    'feature_settings' => 250,
    'top_images' => 200,
    'max_top_images' => 500,
    'groups' => 80,
    'telemetry_top' => 60,
    'telemetry_distribution' => 20,
    'telemetry_database' => 80,
    'telemetry_jobs' => 80,
    'telemetry_events' => 120,
];

/**
 * Fixed Google OpenID Connect authorization destination.
 * @var string
 * Units: HTTPS URL. Scope: provider redirect. Consumers: google_auth service.
 * Rationale: preserve the existing provider endpoint; user input must not select the host.
 */
const CMS_GOOGLE_AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
/**
 * Fixed Google authorization-code exchange destination.
 * @var string
 * Units: HTTPS URL. Scope: provider token exchange. Consumers: google_auth service.
 * Rationale: keep client-secret exchange bound to the established provider endpoint.
 */
const CMS_GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
/**
 * Fixed Google public-signing-key discovery destination.
 * @var string
 * Units: HTTPS URL. Scope: ID-token verification. Consumers: google_auth service.
 * Rationale: preserve the trusted key source rather than following a token-supplied URL.
 */
const CMS_GOOGLE_JWKS_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';
/**
 * Maximum lifetime of a pending Google login/link challenge.
 * @var int
 * Units: seconds. Scope: OAuth state admission. Consumers: google_auth service.
 * Rationale: preserve the 15-minute one-use challenge lifetime.
 */
const GOOGLE_AUTH_STATE_TTL_SECONDS = 900;
/**
 * Entropy used to generate the opaque Google OAuth state challenge.
 * @var int
 * Units: random bytes. Scope: OAuth state generation. Consumers: google_auth service.
 * Rationale: preserve 192-bit challenges, independently of CSRF and mutation replay keys.
 */
const GOOGLE_AUTH_STATE_RANDOM_BYTES = 24;

/**
 * Default directory work admitted by one discovery step.
 * @var int
 * Units: directories. Scope: discovery steps. Consumers: discovery controller/service.
 * Rationale: preserve the existing 80-directory default; posted batches still obey the hard ceiling.
 */
const ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE = 80;
/**
 * Maximum directory work accepted from one browser discovery step.
 * @var int
 * Units: directories. Scope: untrusted batch admission. Consumers: discovery controller/service.
 * Rationale: preserve the 300-directory bound independently of a submitted batch size.
 */
const ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE = 300;
/**
 * Idle lifetime of a discovery checkpoint before it becomes unavailable.
 * @var int
 * Units: seconds. Scope: transient discovery jobs. Consumers: discovery service.
 * Rationale: preserve two-hour recovery while expiring stale traversal context.
 */
const ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS = 7200;
/**
 * Number of recently updated discovery checkpoints retained before starting a job.
 * @var int
 * Units: jobs. Scope: one authenticated caller's map. Consumers: discovery service.
 * Rationale: preserve the existing five-job pruning policy and token-key ordering.
 */
const ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT = 5;
/**
 * Entropy for a discovery checkpoint identifier, separate from mutation replay keys.
 * @var int
 * Units: random bytes. Scope: discovery job identity. Consumers: discovery service.
 * Rationale: preserve the historical 24-hex-character identifier; session/auth checks still own access.
 */
const ADMIN_GALLERY_DISCOVERY_TOKEN_BYTES = 12;

/**
 * Minimum source/runtime compatibility; changing it requires maintainer approval.
 * @var string
 * Units: PHP major.minor branch identifier. Scope: immutable runtime compatibility policy.
 * Consumers: runtime_support_status() and its standalone policy regression tests.
 * Rationale: retain PHP 8.1 compatibility without implying upstream security maintenance.
 */
const RUNTIME_SUPPORT_MINIMUM_COMPATIBLE = '8.1';
/**
 * Oldest recommended deployment branch at the last policy review.
 * @var string
 * Units: PHP major.minor branch identifier. Scope: reviewed deployment advice, not access control.
 * Consumers: runtime_support_status() and its standalone policy regression tests.
 * Rationale: retain the reviewed PHP 8.3 baseline; recommendation also requires unexpired support.
 */
const RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT = '8.3';
/**
 * Preferred maintained branch for new deployments after staging verification.
 * @var string
 * Units: PHP major.minor branch identifier. Scope: immutable reviewed deployment guidance.
 * Consumers: runtime_support_status(), prepared Admin health labels and policy regression tests.
 * Rationale: preserve PHP 8.5 preference without upgrading or locking out an installation.
 */
const RUNTIME_SUPPORT_PREFERRED_BRANCH = '8.5';
/**
 * Date the official supported and unsupported branch tables were verified.
 * @var string
 * Units: UTC calendar date, YYYY-MM-DD. Scope: reviewed lifecycle observations.
 * Consumers: runtime_support_status(), prepared Admin health labels and policy regression tests.
 * Rationale: evaluations before this date stay unknown rather than inventing historical availability.
 */
const RUNTIME_SUPPORT_VERIFIED_ON = '2026-09-20';
/**
 * Canonical upstream reference; diagnostics never fetch it during a request.
 * @var string
 * Units: absolute HTTPS URL. Scope: public runtime-support reference, not a network endpoint to probe.
 * Consumers: runtime_support_status(), prepared Admin health labels/reports and policy regression tests.
 * Rationale: retain the official PHP schedule link without a runtime network or configuration dependency.
 */
const RUNTIME_SUPPORT_REFERENCE = 'https://www.php.net/supported-versions.php';
/**
 * Reviewed branch schedule, with inclusive active/security end dates in UTC.
 * @var array<string,array{active_until:?string,security_until:string}>
 * Units: PHP major.minor keys and inclusive UTC YYYY-MM-DD end dates.
 * Scope: immutable reviewed upstream lifecycle data; no vendor/patch-level guarantees.
 * Consumers: runtime_support_status() and its standalone policy regression tests.
 * Rationale: preserve the verified schedule; unlisted branches remain unknown.
 * A null active date means that historical date is unnecessary for this policy;
 * PHP 8.1 is recorded solely as an already unsupported compatibility branch.
 */
const RUNTIME_SUPPORT_SCHEDULE = [
    '8.1' => ['active_until' => null, 'security_until' => '2025-12-31'],
    '8.2' => ['active_until' => '2024-12-31', 'security_until' => '2026-12-31'],
    '8.3' => ['active_until' => '2025-12-31', 'security_until' => '2027-12-31'],
    '8.4' => ['active_until' => '2026-12-31', 'security_until' => '2028-12-31'],
    '8.5' => ['active_until' => '2027-12-31', 'security_until' => '2029-12-31'],
];

/**
 * Pagination size for an expanded Admin log group.
 * @var int
 * Units: rows. Scope: Admin log group presentation. Consumers: logs service/controller.
 * Rationale: retain the existing 50-row page; one extra row is query lookahead only.
 */
const ADMIN_LOG_GROUP_MEMBER_PAGE_SIZE = 50;
/**
 * Default keyset batch for bounded streamed CSV/JSON exports.
 * @var int
 * Units: rows. Scope: log export APIs. Consumers: logs service.
 * Rationale: retain 500-row memory batches, independently of total export size.
 */
const ADMIN_LOG_EXPORT_BATCH_SIZE = 500;
/**
 * Fallback when the administrator has never chosen a log-retention preference.
 * @var int
 * Units: days. Scope: existing admin_log_retention_days setting. Consumers: logs service.
 * Rationale: retain the historical 30-day fallback; this does not override a saved choice.
 */
const ADMIN_LOG_DEFAULT_RETENTION_DAYS = 30;
/**
 * Smallest accepted administrator log-retention preference.
 * @var int
 * Units: days. Scope: setting validation. Consumers: logs service.
 * Rationale: reject zero/negative periods rather than interpreting them as delete-all.
 */
const ADMIN_LOG_MIN_RETENTION_DAYS = 1;
/**
 * Largest accepted administrator log-retention preference.
 * @var int
 * Units: days. Scope: setting validation. Consumers: logs service.
 * Rationale: retain the existing ten-year operational ceiling, not a calendar-year calculation.
 */
const ADMIN_LOG_MAX_RETENTION_DAYS = 3650;
/**
 * Default size of one retention-delete statement.
 * @var int
 * Units: rows. Scope: bounded retention cleanup. Consumers: logs service.
 * Rationale: preserve 2,000-row database work slices; deadline and total cap still apply.
 */
const ADMIN_LOG_RETENTION_DELETE_BATCH_SIZE = 2000;
/**
 * Default total work admitted by one log-retention cleanup invocation.
 * @var int
 * Units: rows. Scope: retention cleanup admission. Consumers: logs service.
 * Rationale: preserve 100,000-row cap so backlog cleanup yields between invocations.
 */
const ADMIN_LOG_RETENTION_MAX_DELETE_PER_RUN = 100000;

/**
 * Immutable gallery-edit advisory-lock protocol owned by application writers.
 * @var string
 * Units: ASCII suffix, hashed with DATABASE() to a 64-byte MySQL lock name.
 * Scope: all gallery writers; conservatively serializes across the installation.
 * Consumers: gallery_edit_concurrency model and all guarded gallery writer services.
 * Compatibility: changing this suffix while requests are active defeats cross-request exclusion.
 */
const GALLERY_EDIT_LOCK_SUFFIX = ':gallery_edit';
/**
 * Do not queue an HTTP editor behind a long-running gallery filesystem operation.
 * @var int Seconds; zero means return busy immediately, with the draft preserved.
 * Scope/consumers: gallery edit and specialized-writer advisory-lock acquisition.
 * Units: seconds. Rationale: preserve nonblocking admission and let the administrator retry the retained draft.
 */
const GALLERY_EDIT_LOCK_WAIT_SECONDS = 0;
/**
 * Maximum physical destinations returned by one SQL search page.
 * @var int
 * Units: gallery rows. Scope: all destination-picker search requests.
 * Consumers: gallery_picker_search model and gallery_picker service.
 * Rationale: bound materialization without a browser-controlled LIMIT or lookahead.
 */
const GALLERY_PICKER_SEARCH_PAGE_SIZE = 30;
/**
 * Maximum encoded destination query length.
 * @var int
 * Units: UTF-8 bytes. Scope: destination search validation.
 * Consumers: gallery_picker service.
 * Rationale: keep parsed request work bounded alongside the character limit.
 */
const GALLERY_PICKER_QUERY_MAX_BYTES = 1024;
/**
 * Maximum encoded stored title length accepted by the picker.
 * @var int
 * Units: UTF-8 bytes. Scope: normalized destination rows.
 * Consumers: gallery_picker service.
 * Rationale: admit schema-sized Unicode titles while bounding response allocation.
 */
const GALLERY_PICKER_TITLE_MAX_BYTES = 1024;
/**
 * Maximum encoded relative path length accepted by the picker.
 * @var int
 * Units: UTF-8 bytes. Scope: destination and committed-selection context.
 * Consumers: gallery_picker service.
 * Rationale: retain complete schema-sized ancestry with bounded allocation.
 */
const GALLERY_PICKER_PATH_MAX_BYTES = 4096;
/**
 * Schema-compatible title and destination-query character ceiling.
 * @var int
 * Units: Unicode code points. Scope: destination query/title validation.
 * Consumers: gallery_picker service.
 * Rationale: support the complete VARCHAR(255) title without oversized payloads.
 */
const GALLERY_PICKER_TITLE_MAX_CHARACTERS = 255;
/**
 * Schema-compatible destination path character ceiling.
 * @var int
 * Units: Unicode code points. Scope: normalized destination paths.
 * Consumers: gallery_picker service.
 * Rationale: preserve full VARCHAR(1024) ancestry and escaped HTML bounds.
 */
const GALLERY_PICKER_PATH_MAX_CHARACTERS = 1024;
/**
 * Maximum encoded JSON search response, including selected context.
 * @var int
 * Units: bytes. Scope: admin_gallery_picker_search JSON responses.
 * Consumers: admin_gallery_picker_search controller.
 * Rationale: bound transport output even at maximum schema-sized labels.
 */
const GALLERY_PICKER_JSON_MAX_BYTES = 524288;
/**
 * Maximum complete destination-picker markup including its fallback.
 * @var int
 * Units: bytes. Scope: canonical server-rendered picker controls.
 * Consumers: admin_gallery_renderers controller.
 * Rationale: prevent catalog-sized HTML or accidental oversized presentation.
 */
const GALLERY_PICKER_HTML_MAX_BYTES = 1048576;

// Fixed deployment budgets, never supplied by a browser request. At most three
// 513-row SQL pages (including lookahead), with at most 1024 titles normalized.
// These are immutable application work ceilings, not config.php override defaults.
/**
 * Stop optional title matching once this many candidates are available.
 * @var int
 * Units: candidate rows. Scope: title-completion service responses.
 * Consumers: gallery_picker service; browser result guard retains the same public cap.
 * Rationale: retain eight newest sibling-first suggestions and bounded response materialization.
 * Range: fixed positive integer; changing it requires client/response-contract review.
 */
const GALLERY_TITLE_COMPLETION_MAX_CANDIDATES = 8;
/**
 * Bound examined title rows across sibling and disjoint fallback scopes.
 * @var int
 * Units: examined gallery rows, excluding SQL lookahead. Scope: one title-completion request.
 * Consumers: gallery_picker service.
 * Rationale: retain a 1024-row work ceiling; omitted matches must be reported as truncated.
 * Range: fixed positive integer; must admit at least one page and the sibling allowance.
 */
const GALLERY_TITLE_COMPLETION_SCAN_BUDGET = 1024;
/**
 * Bound the preferred sibling scan before considering fallback eligibility.
 * @var int
 * Units: examined sibling rows, excluding lookahead. Scope: one title-completion request.
 * Consumers: gallery_picker service.
 * Rationale: retain the 512-row sibling allowance; exhausted allowance suppresses fallback
 * rather than allowing lower-priority matches to outrank unexamined siblings.
 * Range: fixed positive integer no greater than the complete scan budget.
 */
const GALLERY_TITLE_COMPLETION_SIBLING_BUDGET = 512;
/**
 * Bound each title-matching keyset page before its one-row lookahead.
 * @var int
 * Units: rows per service page, excluding lookahead. Scope: title-completion keyset traversal.
 * Consumers: gallery_picker service; galleries model independently caps fetched pages at 513.
 * Rationale: retain 512 examined rows per page and one lookahead row without OFFSET traversal.
 * Range: fixed positive integer; page plus lookahead must respect the model's defensive cap.
 */
const GALLERY_TITLE_COMPLETION_PAGE_SIZE = 512;

/**
 * A healthy, readable target directory was observed inside trusted storage.
 * @var string
 * Units: state identifier. Scope: creation observation. Consumers: gallery_creation_safety.
 * Rationale: distinguish usable existing storage from absence or failed observation.
 */
const FILESYSTEM_OBSERVATION_AVAILABLE = 'available';
/**
 * A readable, stable parent enumeration confirmed that the target entry is absent.
 * @var string
 * Units: state identifier. Scope: creation observation. Consumers: gallery_creation_safety.
 * Rationale: absence needs positive enumeration evidence, not a failed is_dir call.
 */
const FILESYSTEM_OBSERVATION_MISSING = 'missing';
/**
 * Observation was inconclusive; callers must not interpret it as absence.
 * @var string
 * Units: state identifier. Scope: creation observation. Consumers: gallery_creation_safety.
 * Rationale: failed storage observations must never authorize catalog deletion.
 */
const FILESYSTEM_OBSERVATION_UNKNOWN = 'unknown';
/**
 * Gallery folders remain group-writable subject to the process umask.
 * @var int
 * Units: octal permission bits. Scope: application-created gallery directories.
 * Consumers: creation and image-move services. Rationale: preserve existing 0775 policy.
 */
const GALLERY_DIRECTORY_PERMISSIONS = 0775;
/**
 * HTTP conflict: caller must reconcile existing state before trying a new mutation.
 * @var int
 * Units: HTTP status code. Scope: transport refusal. Consumers: Admin controllers.
 * Rationale: 409 describes a conflict with current state, not invalid credentials.
 */
const HTTP_STATUS_CONFLICT = 409;
/**
 * HTTP validation refusal: semantic input cannot be admitted to a mutation.
 * @var int
 * Units: HTTP status code. Scope: transport refusal. Consumers: Admin controllers.
 * Rationale: retain the existing 422 semantic-validation contract.
 */
const HTTP_STATUS_UNPROCESSABLE_ENTITY = 422;
/**
 * HTTP unavailable: verified storage or an acknowledged outcome is unavailable.
 * @var int
 * Units: HTTP status code. Scope: transport refusal. Consumers: Admin controllers.
 * Rationale: 503 distinguishes temporary uncertainty from an accepted operation.
 */
const HTTP_STATUS_SERVICE_UNAVAILABLE = 503;
/**
 * Operation keys contain 256 random bits and confer no authentication authority.
 * @var int Bytes of entropy; consumed by the admin operation service for generated forms.
 * Units: random bytes. Scope: operation keys and claim-owner hashes. Consumers: admin operation service.
 * Rationale: keep a 256-bit opaque per-intent identity without making it an authentication credential.
 */
const ADMIN_OPERATION_KEY_BYTES = 32;
/**
 * Bound private durable completion storage independently of request-size settings.
 * @var int UTF-8 JSON bytes; consumed by the admin operation service before persistence/replay.
 * Units: serialized bytes. Scope: durable replay envelopes. Consumers: admin operation service.
 * Rationale: cap one stored/read completion at 256 KiB without tying safety to configurable upload sizes.
 */
const ADMIN_OPERATION_RESPONSE_MAX_BYTES = 262144;
/**
 * Bound semantic fingerprint serialization; plaintext inputs are never persisted.
 * @var int UTF-8 JSON bytes; consumed by the admin operation service before claiming.
 * Units: serialized bytes. Scope: semantic fingerprints and reconciliation evidence. Consumers: admin operation and maintenance services.
 * Rationale: admit bounded one-MiB documents before hashing/parsing without persisting plaintext input fingerprints.
 */
const ADMIN_OPERATION_INPUT_MAX_BYTES = 1048576;
/**
 * Bound per-request file hashing and completed-response identity validation.
 * @var int Files per classic multipart operation; consumed by the admin operation service.
 * Units: files or completed image identities. Scope: one classic-upload intent. Consumers: admin operation and diagnostics services.
 * Rationale: bound hashing work and replay identity validation independently of total gallery size.
 */
const ADMIN_OPERATION_MAX_FILES = 256;
/**
 * Bound filenames carried by each safe partial-success diagnostic category.
 * @var int Basenames per category; consumed by admin operation diagnostic projection.
 * Units: filenames. Scope: one partial-success diagnostic category. Consumers: admin operation diagnostics service.
 * Rationale: retain actionable examples without copying an unbounded upload list into every warning category.
 */
const ADMIN_OPERATION_DIAGNOSTIC_MAX_FILENAMES = 32;
/**
 * Keep diagnostic filenames within ordinary filesystem component bounds.
 * @var int UTF-8 bytes per basename; consumed by admin operation diagnostic projection.
 * Units: UTF-8 bytes. Scope: one safe diagnostic basename. Consumers: admin operation diagnostics service.
 * Rationale: retain the existing component-length bound; this is not a cross-filesystem filename-validity guarantee.
 */
const ADMIN_OPERATION_DIAGNOSTIC_FILENAME_MAX_BYTES = 255;
/**
 * Bound numeric diagnostic counters without retaining arbitrary event context.
 * @var int Items; consumed by admin operation diagnostic projection and compatibility events.
 * Units: diagnostic items. Scope: bounded numeric feedback. Consumers: admin operation diagnostics service.
 * Rationale: saturate oversized counters at one million without serializing arbitrary event context.
 */
const ADMIN_OPERATION_DIAGNOSTIC_MAX_COUNT = 1000000;
/**
 * Bound the operator's retained-claim listing and its pagination lookahead.
 * @var int Rows per page; consumed by admin operation maintenance service/model.
 * Units: retained claims. Scope: operator listing. Consumers: admin operation maintenance service and model.
 * Rationale: bound one page at fifty rows plus one lookahead while retaining explicit cursor continuation.
 */
const ADMIN_OPERATION_PENDING_PAGE_SIZE = 50;
/**
 * Exact full-column PRIMARY identity required before an operation can be claimed.
 * @var list<string>
 * Units: ordered SQL column names. Scope: operation ledger schema observation.
 * Consumers: admin operation model. Rationale: a named but unrelated or prefixed
 * index does not guarantee one retained result per authenticated actor and key.
 */
const ADMIN_OPERATION_PRIMARY_COLUMNS = ['actor_id', 'key_hash'];
/**
 * Random bytes used for a durable image-move identifier (32 hexadecimal characters).
 * @var int
 * Units: entropy bytes. Scope: journal identity. Consumers: image-move service/health.
 * Rationale: 128 random bits identify an operation without acting as authorization.
 */
const IMAGE_MOVE_OPERATION_RANDOM_BYTES = 16;
/**
 * Version of the durable relative-path/fingerprint manifest.
 * @var int
 * Units: format version. Scope: persisted image-move intent. Consumers: journal service.
 * Rationale: reject unknown layouts instead of guessing recovery field semantics.
 */
const IMAGE_MOVE_MANIFEST_VERSION = 1;
/**
 * Persisted before any filesystem mutation.
 * @var string
 * Units: lifecycle identifier. Scope: journal. Consumers: journal model/service/health.
 * Rationale: recovery can distinguish admitted intent from file movement.
 */
const IMAGE_MOVE_PREPARED = 'prepared';
/**
 * File operations may have started; database ownership is not yet committed.
 * @var string
 * Units: lifecycle identifier. Scope: journal. Consumers: journal model/service/health.
 * Rationale: worker death cannot be mistaken for an untouched operation.
 */
const IMAGE_MOVE_MOVING = 'moving';
/**
 * Written atomically with image ownership, never inferred from HTTP delivery.
 * @var string
 * Units: lifecycle identifier. Scope: journal. Consumers: ownership model/service/health.
 * Rationale: successful commit determines forward recovery independently of response delivery.
 */
const IMAGE_MOVE_DB_COMMITTED = 'db_committed';
/**
 * Physical state and post-move maintenance were verified.
 * @var string
 * Units: terminal state identifier. Scope: journal. Consumers: journal model/service/health.
 * Rationale: completed recovery is a safe no-op on a later explicit replay.
 */
const IMAGE_MOVE_FINALIZED = 'finalized';
/**
 * Verified rollback completed without changing image ownership.
 * @var string
 * Units: terminal state identifier. Scope: journal. Consumers: journal model/service/health.
 * Rationale: distinguish verified compensation from incomplete or guessed rollback.
 */
const IMAGE_MOVE_ROLLED_BACK = 'rolled_back';
/**
 * Unexpected identity/state requires operator reconciliation; do not guess or overwrite.
 * @var string
 * Units: lifecycle identifier. Scope: journal. Consumers: journal model/service/health.
 * Rationale: preserve evidence and block conflicting moves when recovery is uncertain.
 */
const IMAGE_MOVE_NEEDS_RECONCILIATION = 'needs_reconciliation';
/**
 * Bound the operator's read-only pending-operation list.
 * @var int
 * Units: journal rows. Scope: diagnostics/CLI. Consumers: image-move model and health.
 * Rationale: 30 pending identities allow bounded discovery without public tree scans.
 */
const IMAGE_MOVE_DIAGNOSTIC_LIMIT = 30;
/**
 * Conservative GD surface/codec workspace estimate in bytes per source pixel.
 * @var int
 * Units: bytes/pixel. Scope: raster admission. Consumers: image_decode_policy.
 * Rationale: allow two four-byte surfaces before separate rotation/target accounting.
 */
const IMAGE_DECODE_GD_BYTES_PER_PIXEL = 8;
/**
 * Conservative ImageMagick quantum surface/workspace estimate in bytes per source pixel.
 * @var int
 * Units: bytes/pixel. Scope: raster admission. Consumers: image_decode_policy.
 * Rationale: budget larger quantum/workspace surfaces; this is an estimate, not a native allocator cap.
 */
const IMAGE_DECODE_IMAGICK_BYTES_PER_PIXEL = 16;
/**
 * Compressed input copies reserved while codec reads overlap application buffering.
 * @var int
 * Units: source-file copies. Scope: raster admission. Consumers: image_decode_policy.
 * Rationale: budget both the retained input and codec-side compressed buffer before surfaces.
 */
const IMAGE_DECODE_COMPRESSED_COPY_COUNT = 2;
/**
 * Fixed codec/row/metadata working allowance, separate from deployment reserve.
 * @var int
 * Units: bytes. Scope: raster admission. Consumers: image_decode_policy.
 * Rationale: retain an 8 MiB non-pixel allowance even for small images; native costs vary.
 */
const IMAGE_DECODE_FIXED_OVERHEAD_BYTES = 8 * 1024 * 1024;

/**
 * Private PHP-session key retained by the Viewer registration/resend controller.
 * @var string
 * Units: session-key string. Scope: first-party Viewer anti-automation.
 * Consumers: controller ticket storage.
 * Rationale: Preserve existing anonymous ticket authority across requests without touching Viewer or Admin identity.
 */
const VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE = 'viewer_anti_automation';

/**
 * Signed action identifier for anonymous open registration.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: anti-automation service, registration controller and contracts.
 * Rationale: Keep registration tickets unusable for verification resend.
 */
const VIEWER_ANTI_AUTOMATION_ACTION_REGISTER = 'register';

/**
 * Signed action identifier for explicit verification resend.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: anti-automation service, resend controller and contracts.
 * Rationale: Keep resend tickets unusable for open registration.
 */
const VIEWER_ANTI_AUTOMATION_ACTION_RESEND = 'resend';

/**
 * Decision permitting only the current anonymous action to reach its existing business service.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: anti-automation service and Viewer controller.
 * Rationale: This is not authentication, activation, invitation or verification authority.
 */
const VIEWER_ANTI_AUTOMATION_RESULT_ALLOW = 'allow';

/**
 * Decision requesting a fresh local proof or accessible fallback challenge.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: anti-automation service and Viewer controller.
 * Rationale: Preserve challenge presentation without revealing account or limiter state.
 */
const VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED = 'challenge_required';

/**
 * Decision refusing downstream work with the established generic completion response.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: anti-automation service and Viewer controller.
 * Rationale: Preserve anti-enumeration on honeypot and hard-limit refusal.
 */
const VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS = 'suppress';

/**
 * Decision requiring fresh signed form authority after invalid or replayed input.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: anti-automation service and Viewer controller.
 * Rationale: Never treat absent, expired or mismatched authority as permission to continue.
 */
const VIEWER_ANTI_AUTOMATION_RESULT_INVALID = 'invalid';

/**
 * Signed kind identifier for short-lived form and honeypot authority.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: ticket issue, validation, retention and consumption.
 * Rationale: Prevent a challenge ticket from being reinterpreted as an ordinary form ticket.
 */
const VIEWER_ANTI_AUTOMATION_KIND_FORM = 'form';

/**
 * Signed kind identifier for one-use proof/fallback authority.
 * @var string
 * Units: enum string. Scope: first-party Viewer anti-automation.
 * Consumers: ticket issue, validation, retention and consumption.
 * Rationale: Keep proof difficulty and challenge lifetime validation separate from form metadata.
 */
const VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE = 'challenge';

/**
 * Maximum unconsumed form/challenge authorities retained in one private context.
 * @var int
 * Units: ticket entries. Scope: first-party Viewer anti-automation.
 * Consumers: context pruning and isolated contracts.
 * Rationale: Preserve the existing twelve-entry bound on newest retained authorities; tabs share this budget.
 */
const VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP = 12;

/**
 * Maximum challenge lifetime, further bounded by configured form lifetime.
 * @var int
 * Units: seconds. Scope: first-party Viewer anti-automation.
 * Consumers: challenge issue and signed-payload validation.
 * Rationale: Retain the existing 180-second replay window with exclusive expiry.
 */
const VIEWER_ANTI_AUTOMATION_CHALLENGE_LIFETIME_SECONDS = 180;

/**
 * Minimum server-measured age before accepting the accessible first-party fallback.
 * @var int
 * Units: seconds. Scope: first-party Viewer anti-automation.
 * Consumers: challenge authorization.
 * Rationale: Preserve three seconds of bounded friction without requiring JavaScript.
 */
const VIEWER_ANTI_AUTOMATION_FALLBACK_MIN_AGE_SECONDS = 3;

/**
 * Inclusive maximum decimal proof counter accepted by PHP and delivered to the local solver.
 * @var int
 * Units: counter attempts, zero-based. Scope: first-party Viewer anti-automation.
 * Consumers: proof verification, prepared challenge data and contracts.
 * Rationale: Preserve the 1,048,575 ceiling and reject oversized submissions before hashing.
 */
const VIEWER_ANTI_AUTOMATION_MAX_COUNTER = 1048575;

/**
 * Maximum browser-carried signed ticket length before decoding or signature validation.
 * @var int
 * Units: bytes. Scope: first-party Viewer anti-automation.
 * Consumers: ticket decoder.
 * Rationale: Retain the 1,024-byte input bound; it is not an administrator-tunable allowance.
 */
const VIEWER_ANTI_AUTOMATION_TICKET_MAX_BYTES = 1024;

/**
 * Maximum decoy field length treated as a bounded ordinary string.
 * @var int
 * Units: bytes. Scope: first-party Viewer anti-automation.
 * Consumers: submission honeypot checks.
 * Rationale: Values above 256 bytes suppress, as do any populated or non-string decoys.
 */
const VIEWER_ANTI_AUTOMATION_HONEYPOT_MAX_BYTES = 256;

/**
 * Maximum valid legacy entries collected before sorting and applying the retention cap.
 * @var int
 * Units: candidate entries. Scope: Viewer anti-automation context normalization.
 * Consumers: viewer_anti_automation_context_prune().
 * Rationale: preserve the existing 64-candidate normalization bound; malformed input rows are still inspected and this is not a scan-count guarantee.
 */
const VIEWER_ANTI_AUTOMATION_PRUNE_CANDIDATE_CAP = 64;
