<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/auth_persistence.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides durable administrator login persistence for shared hosting.
 *
 * Responsibilities:
 *   - Keep admin sessions alive across normal browser restarts
 *   - Restore an admin session from a hashed database token when PHP session files expire
 *   - Revoke persistent tokens on logout and account security changes
 *   - Issue and validate nonrenewable delegated support sessions
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
 *   2026-05-31
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\now_sql;
use function Gallery\Models\auth_persistence_model_prune;
use function Gallery\Models\auth_persistence_model_issue_token;
use function Gallery\Models\auth_persistence_model_find_active;
use function Gallery\Models\auth_persistence_model_revoke_id;
use function Gallery\Models\auth_persistence_model_touch;
use function Gallery\Models\auth_persistence_model_revoke_selector;
use function Gallery\Models\auth_persistence_model_revoke_user;
use function Gallery\Models\admin_support_model_active_session;
use function Gallery\Models\admin_support_model_claim;
use function Gallery\Models\admin_support_model_create;
use function Gallery\Models\admin_support_model_list;
use function Gallery\Models\admin_support_model_mark_expired;
use function Gallery\Models\admin_support_model_prune;
use function Gallery\Models\admin_support_model_revoke;
use function Gallery\Models\admin_support_model_touch;

/**
 * Raised when authentication policy cannot safely determine required schema state.
 *
 * The exception exposes only a bounded feature key. Database error text and
 * credentials remain inside the structured schema-inspection diagnostic.
 */
final class AuthenticationSchemaUnavailableException extends RuntimeException
{
    private string $feature;

    /**
     * Create a bounded storage-unavailable exception for an authentication feature.
     *
     * @param string $feature Stable authentication feature identifier.
     * @return void Initializes the exception message and stores a bounded feature key.
     */
    public function __construct(string $feature)
    {
        $this->feature = preg_match('/^[a-z0-9_]+$/', $feature) === 1
            ? $feature
            : 'authentication';
        parent::__construct(
            'Authentication storage is temporarily unavailable.',
        );
    }

    /**
     * Return the bounded capability key used by diagnostics.
     *
     * @return string Text result for the caller.
     */
    public function feature(): string
    {
        return $this->feature;
    }
}

/**
 * Return structured schema status for DB-backed persistent administrator login.
 *
 * @return array Structured schema-inspection result.
 */
function auth_persistent_login_schema_status(): array
{
    return schema_inspection_feature('auth_persistent_login', [
        schema_inspection_table('admin_remember_tokens'),
    ]);
}

/**
 * Return structured schema status for the optional administrator email column.
 *
 * Missing is a supported pre-email compatibility state for username login.
 * Unknown must not be interpreted as missing because that could silently change
 * identity lookup semantics while metadata inspection is failing.
 *
 * @return array Structured schema-inspection result.
 */
function auth_user_email_schema_status(): array
{
    return schema_inspection_feature('auth_user_email', [
        schema_inspection_column('users', 'email'),
    ]);
}

/**
 * Return structured schema status for password-reset issue and consume operations.
 *
 * @return array Structured schema-inspection result.
 */
function auth_password_reset_schema_status(): array
{
    return schema_inspection_feature('auth_password_reset', [
        schema_inspection_table('password_reset_tokens'),
        schema_inspection_column('users', 'email'),
    ]);
}

/**
 * Return true when a schema capability is verified available.
 *
 * @param array $status Structured schema-inspection status.
 * @return bool True when the capability is available.
 */
function auth_schema_status_available(array $status): bool
{
    return schema_inspection_is_available($status);
}

/**
 * Throw when an authentication schema capability is operationally unknown.
 *
 * Confirmed missing state is handled by each feature's explicit migration or
 * compatibility policy. This helper prevents metadata failures from being
 * silently converted into confirmed absence.
 *
 * @param array $status Structured schema-inspection status.
 * @param string $feature Bounded feature key.
 */
function auth_schema_assert_known(array $status, string $feature): void
{
    if (schema_inspection_is_unknown($status)) {
        throw new AuthenticationSchemaUnavailableException($feature);
    }
}

/**
 * Log one authentication schema inspection failure without credential material.
 *
 * @param string $feature Bounded capability key.
 * @param string $operation Fixed operation identifier.
 * @return void Writes a warning event with sanitized feature and operation identifiers when logging is available.
 */
function auth_log_schema_unavailable(string $feature, string $operation): void
{
    $safeFeature = preg_match('/^[a-z0-9_]+$/', $feature) === 1
        ? $feature
        : 'authentication';
    $safeOperation = preg_match('/^[a-z0-9_.]+$/', $operation) === 1
        ? $operation
        : 'operation';
    try {
        if (function_exists('Gallery\\Services\\admin_log_event')) {
            admin_log_event(
                'warning',
                'auth.schema_inspection_unavailable',
                'Authentication schema inspection unavailable.',
                [
                    'feature' => $safeFeature,
                    'operation' => $safeOperation,
                ],
            );
        }
    } catch (Throwable) {
        // Logging must never hide the original authentication policy result.
    }
}

/**
 * Return authentication configuration merged with safe defaults.
 *
 * @return array{session_lifetime_days:int,remember_lifetime_days:int,persistent_login_enabled:bool,persistent_login_default_checked:bool} Authentication settings merged with bounded defaults.
 */
function auth_persistence_config(): array
{
    // $config stores the installed configuration array.
    $config = cms_config();
    // $auth stores optional authentication settings from config.php.
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];

    return [
        'session_lifetime_days' => max(1, min(
            90,
            (int) ($auth['session_lifetime_days'] ?? 14),
        )),
        'remember_lifetime_days' => max(1, min(
            365,
            (int) ($auth['remember_lifetime_days'] ?? 30),
        )),
        'persistent_login_enabled' => array_key_exists(
            'persistent_login_enabled',
            $auth,
        )
            ? (bool) $auth['persistent_login_enabled']
            : true,
        'persistent_login_default_checked' => array_key_exists(
            'persistent_login_default_checked',
            $auth,
        )
            ? (bool) $auth['persistent_login_default_checked']
            : true,
    ];
}

/**
 * Return the long-lived PHP session lifetime in seconds.
 *
 * @return int Integer result for the caller.
 */
function auth_admin_session_lifetime_seconds(): int
{
    // $settings stores normalized authentication settings.
    $settings = auth_persistence_config();
    return (int) $settings['session_lifetime_days'] * 86400;
}

/**
 * Return the persistent login cookie lifetime in seconds.
 *
 * @return int Integer result for the caller.
 */
function auth_remember_lifetime_seconds(): int
{
    // $settings stores normalized authentication settings.
    $settings = auth_persistence_config();
    return (int) $settings['remember_lifetime_days'] * 86400;
}

/**
 * Return true when DB-backed persistent login is enabled and migrated.
 *
 * @return bool True when the condition matches.
 */
function auth_persistent_login_ready(): bool
{
    // $settings stores normalized authentication settings.
    $settings = auth_persistence_config();
    return (bool) $settings['persistent_login_enabled']
        && auth_schema_status_available(auth_persistent_login_schema_status());
}

/**
 * Apply persistent-login schema policy for an operation that may issue or use a token.
 *
 * Confirmed missing storage disables only durable login so ordinary session login
 * remains available. Unknown metadata state is an operational failure and must not
 * be treated as the same compatibility state.
 *
 * @param string $operation Fixed operation identifier for bounded diagnostics.
 * @return bool True when persistent-token storage is available.
 */
function auth_persistent_login_operation_available(string $operation): bool
{
    $settings = auth_persistence_config();
    if (!(bool) $settings['persistent_login_enabled']) {
        return false;
    }

    $status = auth_persistent_login_schema_status();
    if (schema_inspection_is_unknown($status)) {
        auth_log_schema_unavailable('auth_persistent_login', $operation);
        throw new AuthenticationSchemaUnavailableException(
            'auth_persistent_login',
        );
    }
    return schema_inspection_is_available($status);
}

/**
 * Return the persistent login cookie name for this installation.
 *
 * @return string Text result for the caller.
 */
function auth_remember_cookie_name(): string
{
    // $sessionName stores the configured admin session name.
    $sessionName = preg_replace(
        '/[^a-zA-Z0-9_]/',
        '_',
        (string) cms_config()['admin_session_name'],
    );
    return $sessionName . '_remember';
}

/**
 * Build a persistent-login cookie instruction for the HTTP adapter.
 *
 * @param string $value Cookie value.
 * @param int $expiresAt Unix expiry timestamp.
 * @return array{name:string,value:string,expires_at:int,path:string,httponly:bool,samesite:string}
 */
function auth_remember_cookie_instruction(string $value, int $expiresAt): array
{
    return [
        'name' => auth_remember_cookie_name(),
        'value' => $value,
        'expires_at' => $expiresAt,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Remove expired and revoked persistent login tokens.
 *
 * @param ?int $userId User id identifier.
 */
function auth_prune_persistent_tokens(?int $userId = null): void
{
    if (!auth_persistent_login_operation_available('prune')) {
        return;
    }

    auth_persistence_model_prune($userId, now_sql());
}

/**
 * Issue a new persistent login token and store only its hash in the database.
 *
 * @param int $userId User id identifier.
 * @param string $userAgent Request user-agent value supplied by the HTTP adapter.
 * @return ?array Cookie instruction, or null when persistent login is unavailable.
 */
function auth_issue_persistent_login(
    int $userId,
    string $userAgent = '',
): ?array {
    if (!auth_persistent_login_operation_available('issue')) {
        return null;
    }

    auth_prune_persistent_tokens($userId);
    // $selector stores the public database lookup key saved in the browser cookie.
    $selector = bin2hex(random_bytes(18));
    // $validator stores the private credential saved only in the browser cookie.
    $validator = bin2hex(random_bytes(32));
    // $validatorHash stores the one-way credential hash saved in the database.
    $validatorHash = hash('sha256', $validator);
    // $expiresAt stores the database expiration timestamp.
    $expiresAt = date('Y-m-d H:i:s', time() + auth_remember_lifetime_seconds());
    // $userAgentHash stores a privacy-safe diagnostic for future troubleshooting.
    $userAgentHash = hash('sha256', $userAgent);

    // Limit old active tokens per admin account so forgotten browsers do not accumulate forever.
    // $oldTokenLimit stores the number of newest active tokens to keep before issuing this one.
    $oldTokenLimit = 10;
    // Persistence receives only the selector and one-way credential hashes, never the raw validator.
    auth_persistence_model_issue_token(
        $userId,
        $selector,
        $validatorHash,
        $userAgentHash,
        now_sql(),
        $expiresAt,
        $oldTokenLimit,
    );

    return auth_remember_cookie_instruction(
        $selector . ':' . $validator,
        time() + auth_remember_lifetime_seconds(),
    );
}

/**
 * Parse the browser persistent login cookie into selector and validator parts.
 *
 * @return ?array Structured result data for the caller.
 */
function auth_parse_remember_cookie(string $cookie): ?array
{
    // $cookie stores the raw browser cookie value supplied by the HTTP adapter.
    if ($cookie === '' || !str_contains($cookie, ':')) {
        return null;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if (
        !preg_match('/^[a-f0-9]{36}$/', $selector)
        || !preg_match('/^[a-f0-9]{64}$/', $validator)
    ) {
        return null;
    }

    return ['selector' => $selector, 'validator' => $validator];
}

/**
 * Restore an admin session from a valid persistent login cookie.
 *
 * @param string $cookieValue Raw remember-cookie value supplied by the HTTP adapter.
 * @return array{user:?array,clear_cookie:bool} Authentication result for the HTTP adapter.
 */
function auth_restore_persistent_login(string $cookieValue): array
{
    try {
        if (!auth_persistent_login_operation_available('restore')) {
            return ['user' => null, 'clear_cookie' => false];
        }
    } catch (AuthenticationSchemaUnavailableException) {
        return ['user' => null, 'clear_cookie' => true];
    }

    // $cookie stores validated persistent cookie parts.
    $cookie = auth_parse_remember_cookie($cookieValue);
    if ($cookie === null) {
        return ['user' => null, 'clear_cookie' => false];
    }

    // $row stores the matched remember token and user data returned by the persistence model.
    $row = auth_persistence_model_find_active(
        (string) $cookie['selector'],
        now_sql(),
    );
    if (!$row) {
        return ['user' => null, 'clear_cookie' => true];
    }

    if (!hash_equals((string) $row['token_hash'], hash(
        'sha256',
        (string) $cookie['validator'],
    ))) {
        // A selector with a bad validator is treated as a stolen or corrupted cookie.
        auth_persistence_model_revoke_id((int) $row['id'], now_sql());
        return ['user' => null, 'clear_cookie' => true];
    }

    if ((string) $row['role'] !== 'admin') {
        return ['user' => null, 'clear_cookie' => true];
    }

    auth_persistence_model_touch((int) $row['id'], now_sql());

    return [
        'user' => [
            'id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'email' => $row['email'] ?? null,
            'role' => (string) $row['role'],
        ],
        'clear_cookie' => false,
    ];
}

/**
 * Revoke the current persistent login token when an explicit browser cookie is supplied.
 *
 * @param string $cookieValue Raw remember-cookie value supplied by the HTTP adapter.
 * @return void Revokes the parsed selector when schema policy permits; unavailable schema or invalid cookies leave state unchanged.
 */
function auth_revoke_current_persistent_login(string $cookieValue): void
{
    try {
        if (!auth_persistent_login_operation_available('revoke_current')) {
            return;
        }
    } catch (AuthenticationSchemaUnavailableException) {
        return;
    }

    // $cookie stores validated persistent cookie parts.
    $cookie = auth_parse_remember_cookie($cookieValue);
    if ($cookie !== null) {
        auth_persistence_model_revoke_selector(
            (string) $cookie['selector'],
            now_sql(),
        );
    }
}

/**
 * Revoke every persistent login token for one user.
 *
 * @param int $userId User id identifier.
 */
function auth_revoke_user_persistent_logins(int $userId): void
{
    try {
        if (!auth_persistent_login_operation_available('revoke_user')) {
            return;
        }
    } catch (AuthenticationSchemaUnavailableException) {
        return;
    }

    auth_persistence_model_revoke_user($userId, now_sql());
}

// Single-use delegated authority shares the Admin authentication service
// owner without adding modules to ordinary public requests.

/**
 * Set the nonrenewable lifetime of a temporary administrator support grant.
 * Type: int. Units: seconds. Scope: each grant from creation, never renewal.
 * Consumers: issuance/model and operator documentation.
 * Rationale: support access must stop after exactly 24 hours.
 */
const ADMIN_SUPPORT_LIFETIME_SECONDS = 86400;

/**
 * Set the CSPRNG token size used for single-use support invitations.
 * Type: int. Units: random bytes. Scope: bearer invitations.
 * Consumers: issue. Rationale: 256-bit unpredictable token entropy.
 */
const ADMIN_SUPPORT_INVITATION_BYTES = 32;

/**
 * Set the independent CSPRNG verifier size of support sessions.
 * Type: int. Units: random bytes. Scope: delegated PHP sessions.
 * Consumers: redeem. Rationale: separate 256-bit session verifier.
 */
const ADMIN_SUPPORT_SESSION_BYTES = 32;

/**
 * Limit the number of support invitations shown in the owner UI.
 * Type: int. Units: grants. Scope: one owner's management screen.
 * Consumers: list. Rationale: bound memory and database results.
 */
const ADMIN_SUPPORT_LIST_LIMIT = 50;

/**
 * Retain an auditable bounded history after a support grant expires.
 * Type: int. Units: days. Scope: expired grants and owned audit metadata.
 * Consumers: management cleanup. Rationale: retain 90 days of traceable history.
 */
const ADMIN_SUPPORT_AUDIT_RETENTION_DAYS = 90;

/**
 * Cap expired support-grant deletions for one owner-page request.
 * Type: int. Units: rows. Scope: on-demand cleanup from management requests.
 * Consumers: management cleanup. Rationale: bound deletion cost on shared hosts.
 */
const ADMIN_SUPPORT_PRUNE_BATCH = 50;


/**
 * Mint an invitation once and return its otherwise unpersisted secret.
 *
 * @param int $ownerId Permanent Admin user identifier.
 * @return array{token:string,grant:array{id:int,owner_user_id:int,created_at:string,expires_at:string}} Invitation secret and display-safe metadata.
 */
function admin_support_issue(int $ownerId): array
{
    $token = bin2hex(random_bytes(ADMIN_SUPPORT_INVITATION_BYTES));
    $grant = admin_support_model_create(
        $ownerId,
        hash('sha256', $token),
        ADMIN_SUPPORT_LIFETIME_SECONDS,
    );
    return ['token' => $token, 'grant' => $grant];
}

/**
 * Exchange a valid invitation for an independently random session verifier.
 *
 * @param string $token 64-character lowercase hexadecimal invitation.
 * @return array{grant:array{id:int,owner_user_id:int,expires_at:string},nonce:string}|null Claimed grant and session nonce, or null for any invalid/used invitation.
 */
function admin_support_redeem(string $token): ?array
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
        return null;
    }

    $nonce = bin2hex(random_bytes(ADMIN_SUPPORT_SESSION_BYTES));
    $grant = admin_support_model_claim(
        hash('sha256', $token),
        hash('sha256', $nonce),
    );
    return $grant === null ? null : ['grant' => $grant, 'nonce' => $nonce];
}

/**
 * Validate live database grant status for a session-bound delegated Admin.
 *
 * @param int $grantId Session's grant identifier.
 * @param int $ownerId Permanent granting Admin identifier.
 * @param string $nonce Session's independent 256-bit random verifier.
 * @return array{id:int,owner_user_id:int,expires_at:string}|null Active grant or null.
 */
function admin_support_validate_session(
    int $grantId,
    int $ownerId,
    string $nonce,
): ?array {
    if (
        $grantId <= 0
        || $ownerId <= 0
        || preg_match('/\A[a-f0-9]{64}\z/D', $nonce) !== 1
    ) {
        return null;
    }

    $grant = admin_support_model_active_session(
        $grantId,
        $ownerId,
        hash('sha256', $nonce),
    );
    if ($grant === null) {
        admin_support_model_mark_expired($grantId);
        return null;
    }
    admin_support_model_touch($grantId);
    return $grant;
}

/**
 * Provide lifecycle metadata for an owner's invitation-management page.
 *
 * @param int $ownerId Permanent granting Admin identifier.
 * @return list<array<string,int|string|null>> Recent metadata-only grants.
 */
function admin_support_grants(int $ownerId): array
{
    admin_support_model_prune(
        ADMIN_SUPPORT_AUDIT_RETENTION_DAYS,
        ADMIN_SUPPORT_PRUNE_BATCH,
    );
    return admin_support_model_list($ownerId, ADMIN_SUPPORT_LIST_LIMIT);
}

/**
 * Revoke an owned invitation and any established session immediately.
 *
 * @param int $ownerId Permanent granting Admin identifier.
 * @param int $grantId Identifier to revoke.
 * @return bool Whether revocation changed a grant.
 */
function admin_support_revoke(int $ownerId, int $grantId): bool
{
    if ($ownerId <= 0 || $grantId <= 0) {
        return false;
    }
    return admin_support_model_revoke($ownerId, $grantId);
}

/**
 * Strictly allowlist delegated HTTP routes, including non-Admin routes.
 *
 * The dispatcher never grants authority simply because a route lacks the
 * admin_ prefix. Normal public GET media and gallery reads retain their
 * established access checks. HTTP mutations are limited to reviewed,
 * non-credential operational actions; everything else is denied.
 *
 * @param string $page Canonical dispatcher route identifier.
 * @param string $method Uppercase HTTP method.
 * @param array<string,mixed> $submitted Sanitized only by route-specific
 *     controller checks; untrusted POST field names/values for action gating.
 * @return bool True only for an explicitly permitted delegated request.
 */
function admin_support_route_allowed(
    string $page,
    string $method,
    array $submitted = [],
): bool {
    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        return false;
    }
    if ($page === 'admin_logout') {
        return true;
    }
    if (!str_starts_with($page, 'admin_') && $page !== 'admin') {
        // Explicit public read list, not a blanket non-Admin exemption.
        // Peer-pairing and migration APIs, setup, visitor-account workflows,
        // private workers and future routes are intentionally excluded.
        $publicReadRoutes = [
            'home',
            'gallery',
            'smart_gallery',
            'gallery_access',
            'share',
            'tag',
            'robots',
            'sitemap',
            'picture_game',
            'media',
            'gallery_ofp_pdf',
            'gallery_music',
            'thumb',
            'public_media',
            'public_thumb',
            'thumbnail_warmup',
            'gallery_cover_asset',
            'gallery_branding_asset',
            'theme_background_asset',
            'theme_branding_asset',
            'favicon_asset',
            'link_favicon_asset',
            'theme_css',
            'browser_i18n',
            'gallery_map_data',
            'gallery_lightbox_data',
            'smart_gallery_lightbox_data',
            'smart_gallery_map_data',
            'public_search',
            'navdata_lookup',
            'download_gallery',
            'download_gallery_manifest',
            'download_gallery_file',
            'download_smart_gallery',
            'download_smart_gallery_manifest',
            'download_smart_gallery_file',
            'download_all',
        ];
        return in_array($method, ['GET', 'HEAD'], true)
            && in_array($page, $publicReadRoutes, true);
    }

    $readable = [
        'admin',
        'admin_dashboard_fragment',
        'admin_browser_i18n',
        'admin_gallery_features_plan',
        'admin_diagnostics',
        'admin_search_diagnostics',
        'admin_storage_statistics',
        'admin_gallery_report',
        'admin_gallery_report_generate',
        'admin_gallery_benchmark_browser',
        'admin_gallery_benchmark_status',
        'admin_gallery_benchmark_probe',
        'admin_gallery_benchmark_download',
        'admin_test_run_probe',
        'admin_test_run_download',
        'admin_database_maintenance_inspect',
        'admin_discover',
        'admin_new_gallery',
        'admin_gallery_title_completion',
        'admin_gallery_picker_search',
        'admin_upload',
        'admin_upload_browser_batch',
        'admin_media_renamer',
        'admin_gallery_dates',
        'admin_gallery_date_suggestion',
        'admin_duplicate_photos',
        'admin_bulk_galleries',
        'admin_trash',
        'admin_tags',
        'admin_music',
        'admin_smart_galleries',
        'admin_navdata',
        'admin_check_thumbnail_maintenance',
        'admin_edit_gallery',
        'admin_edit_image',
        'admin_metadata_organizer_preview_batch',
        'admin_bulk_images',
        'admin_reorder_images',
        'admin_logs',
        'admin_log_group_members',
        'admin_log_export',
        'admin_logs_export_zip',
        'admin_log_archive_view',
        'admin_log_archive_download',
        'admin_telemetry',
        'admin_telemetry_export',
        'admin_integrity',
        'admin_theme',
        'admin_settings',
        'admin_features',
    ];
    if ($method === 'GET' || $method === 'HEAD') {
        return in_array($page, $readable, true);
    }

    // A full gallery editor save also manages share-link credentials and
    // password locking. Restrict delegated POSTs to an explicit set of
    // non-credential operations, including the browser's targeted actions.
    if ($page === 'admin_edit_gallery') {
        $safeActions = [
            'cover',
            'rename_files',
            'apply_exif_date_suggestion',
            'apply_metadata_organizer_date_plan',
            'apply_metadata_organizer_date_plan_batch',
        ];
        return !isset($submitted['access_action'])
            && !isset($submitted['access_password'])
            && in_array(
                (string) ($submitted['action'] ?? ''),
                $safeActions,
                true,
            );
    }

    $writable = [
        'admin_gallery_features_plan',
        'admin_gallery_features_apply',
        'admin_gallery_title_completion',
        'admin_gallery_picker_search',
        'admin_import',
        'admin_new_gallery',
        'admin_upload',
        'admin_upload_browser_batch',
        'admin_media_renamer',
        'admin_gallery_dates',
        'admin_gallery_date_suggestion',
        'admin_duplicate_photos',
        'admin_bulk_galleries',
        'admin_tags',
        'admin_music',
        'admin_smart_galleries',
        'admin_edit_image',
        'admin_bulk_images',
        'admin_reorder_images',
        'admin_metadata_organizer_preview_batch',
        'admin_metadata_organizer_apply_date_plan_batch',
        'admin_reorder_galleries',
        'admin_reorder_public_galleries',
        'admin_sort_public_subgalleries_by_date',
        'admin_scan_images',
        'admin_create_thumbnails',
        'admin_thumbnail_browser_source_chunk',
        'admin_thumbnail_browser_upload_batch',
        'admin_save_gallery_collapse',
        'admin_public_update_gallery',
        'admin_public_update_image',
        'admin_test_run_start',
        'admin_test_run_finish',
        'admin_test_run_finalize',
    ];
    return in_array($page, $writable, true);
}
