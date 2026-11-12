<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/app_linking.php
 * Module Type: Controller
 * Purpose: Handle browser-approved scoped native application linking.
 * Responsibilities:
 *   - Bind explicit ordinary-Admin consent to one short-lived session attempt.
 *   - Keep protocol secrets out of network-facing gallery request URLs.
 *   - Delegate scoped identity, listing, upload and credential transitions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

require_once __DIR__ . '/../services/app_linking_passive_profile.php';

// Delegated uploads remain part of this controller owner.
require_once __DIR__ . '/app_linking/upload.php';
require_once __DIR__ . '/app_linking/detail.php';
require_once __DIR__ . '/app_linking/create.php';
require_once __DIR__ . '/app_linking/media.php';
require_once __DIR__ . '/app_linking/thumbnail_batch.php';

use Throwable;
use const Gallery\Services\APP_LINKING_RATE_POLICIES;
use function Gallery\Core\cms_config;
use function Gallery\Core\csrf_token;
use function Gallery\Core\current_user;
use function Gallery\Core\request_is_https;
use function Gallery\Core\request_method;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\app_linking_authenticate;
use function Gallery\Services\app_linking_passive_begin;
use function Gallery\Services\app_linking_passive_span_start;
use function Gallery\Services\app_linking_passive_span_end;
use function Gallery\Services\app_linking_passive_authorized;
use function Gallery\Services\app_linking_passive_header;
use function Gallery\Services\app_linking_discovery_capabilities;
use function Gallery\Services\app_linking_canonical_256bit_value_valid;
use function Gallery\Services\app_linking_exchange_code;
use function Gallery\Services\app_linking_gallery_page;
use function Gallery\Services\app_linking_gallery_tree;
use function Gallery\Services\app_linking_gallery_search_page;
use function Gallery\Services\app_linking_issue_code;
use function Gallery\Services\app_link_push_claim_request;
use function Gallery\Services\app_link_push_create;
use function Gallery\Services\app_link_push_session_request;
use function Gallery\Services\app_linking_issuer_valid;
use function Gallery\Services\app_linking_list_owner;
use function Gallery\Services\app_linking_pkce_s256_challenge_valid;
use function Gallery\Services\app_linking_public_client;
use function Gallery\Services\app_linking_scope_allowed;
use function Gallery\Services\app_linking_scope_allows_admin_media;
use function Gallery\Services\app_linking_scope_allows_gallery_search;
use function Gallery\Services\app_linking_scope_allows_gallery_listing;
use function Gallery\Services\app_linking_scope_allows_presentation;
use function Gallery\Services\app_linking_scope_base;
use function Gallery\Services\app_linking_scope_requests_refresh;
use function Gallery\Services\app_link_refresh_revoke_token;
use function Gallery\Services\app_link_refresh_rotate;
use function Gallery\Services\app_linking_rate_limit_consume;
use function Gallery\Services\app_linking_redirect_uri_valid;
use function Gallery\Services\app_linking_revoke_token;
use function Gallery\Services\app_linking_revoke_owner;
use function Gallery\Services\request_client_ip;
use function Gallery\Services\sanitize_hex_color;
use function Gallery\Services\theme_css_defaults;
use function Gallery\Services\theme_settings;
use function Gallery\Services\t;
use function Gallery\Services\translation_active_language;
use function Gallery\Views\view_render_app_linking_devices;
use function Gallery\Views\view_render_app_linking_start;
use const Gallery\Services\APP_LINKING_HTTPS_DEFAULT_PORT;
use const Gallery\Services\APP_LINKING_HTTP_DEFAULT_PORT;
use const Gallery\Services\APP_LINKING_PROTOCOL_VERSION;
use const Gallery\Services\APP_LINKING_BROWSER_HANDOFF_VERSION;
use const Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS;
use const Gallery\Services\APP_LINKING_IDENTITY_SCOPE;
use const Gallery\Services\APP_LINKING_GALLERIES_SCOPE;
use const Gallery\Services\APP_LINKING_SEARCH_QUERY_LIMIT;
use const Gallery\Services\APP_LINKING_UPLOAD_SCOPE;
use const Gallery\Services\APP_LINKING_READ_SCOPE;
use const Gallery\Services\APP_LINKING_READ_UPLOAD_SCOPE;
use const Gallery\Services\APP_LINKING_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_READ_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_READ_UPLOAD_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_CREATE_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_WINAPP_CLIENT_ID;
use const Gallery\Services\APP_LINK_REFRESH_TOKEN_PREFIX;

/**
 * Bound the complete authorization header before parsing a bearer value.
 * Type: int. Units: bytes. Scope: one app-linking request header.
 * Consumers: app_linking_bearer.
 * Rationale: 128 bytes accommodates the issued credential and avoids excessive parsing.
 */
const APP_LINKING_AUTH_HEADER_MAX_BYTES = 128;

/**
 * Bound one browser-to-gallery or native token form before parsing its fields.
 * Type: int. Units: bytes. Scope: one app-linking POST body.
 * Consumers: app_linking_form_fields.
 * Rationale: 4096 bytes fits the registered protocol fields without unbounded reads.
 */
const APP_LINKING_FORM_MAX_BYTES = 4096;

/**
 * Bound a delegated inventory JSON request before parsing.
 * Type: int. Units: bytes. Scope: one inventory request body.
 * Consumers: cms_app_linking_inventory.
 * Rationale: 2 MiB fits the capped descriptor list without an unbounded read.
 */
const APP_LINKING_INVENTORY_MAX_BYTES = 2097152;

/**
 * Bound submitted inventory descriptors to the existing service maximum.
 * Type: int. Units: file descriptors. Scope: one inventory request.
 * Consumers: cms_app_linking_inventory.
 * Rationale: 5000 matches the established upload automation policy.
 */
const APP_LINKING_INVENTORY_MAX_FILES = 5000;

/**
 * Bound original files admitted to one delegated upload.
 * Type: int. Units: files. Scope: one multipart request.
 * Consumers: cms_app_linking_upload.
 * Rationale: 100 bounds PHP processing while allowing useful transfer batches.
 */
const APP_LINKING_UPLOAD_MAX_FILES = 100;

/**
 * Bound a delegated multipart request's declared Content-Length.
 * Type: decimal string. Units: bytes. Scope: one delegated multipart request.
 * Consumers: app_linking_upload_content_length_error.
 * Rationale: 35433480192 matches the linked client's maximum and stays exact
 * on 32-bit PHP. The HTTP server and PHP must independently enforce framing
 * and their own body limits before request parsing.
 */
const APP_LINKING_UPLOAD_MAX_BODY_BYTES = '35433480192';

/**
 * Bound optional client-generated derivatives in one delegated upload.
 * Type: int. Units: files. Scope: one multipart request.
 * Consumers: cms_app_linking_upload.
 * Rationale: 500 accommodates several derivatives for each bounded original.
 */
const APP_LINKING_UPLOAD_MAX_THUMBNAILS = 500;

/**
 * Produce an unpredictable per-attempt form binding and CSP nonce.
 * Type: int. Units: random bytes. Scope: one linking page or pending attempt.
 * Consumers: app_linking_security_nonce.
 * Rationale: 32 bytes gives a distinct cryptographic value without persistent state.
 */
const APP_LINKING_NONCE_BYTES = 32;

/**
 * Attach a passive header only after a caller has proved live authorization.
 *
 * @return void Disabled, anonymous and already-started responses are untouched.
 */
function app_linking_passive_emit_header(): void
{
    $timing = app_linking_passive_header();
    if ($timing !== null && !headers_sent()) {
        header('Server-Timing: ' . $timing, false);
    }
}

/**
 * Send no-store security headers for a linking response.
 *
 * @param string $nonce Base64 CSP nonce for the isolated page; the empty
 *     default disables inline script and style.
 * @return void Replaces cache, framing, referrer and content-sniffing policy.
 */
function app_linking_security_headers(string $nonce = ''): void
{
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    $script = $nonce === '' ? "'none'" : "'nonce-" . $nonce . "'";
    $style = $nonce === '' ? "'none'" : "'nonce-" . $nonce . "'";
    header("Content-Security-Policy: default-src 'none'; script-src " . $script
        . "; style-src " . $style . "; connect-src 'self'; form-action 'none'; "
        . "base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
}

/**
 * Generate a fresh cryptographic nonce for one linking page or form attempt.
 *
 * @return string Base64-encoded random bytes suitable for CSP or form binding.
 * @throws Throwable When the platform random source fails.
 */
function app_linking_security_nonce(): string
{
    return base64_encode(random_bytes(APP_LINKING_NONCE_BYTES));
}

/**
 * Canonicalize the origin before comparing it with the validated issuer.
 *
 * @param string $scheme The admitted http or https transport scheme.
 * @param string $authority Origin hostname and optional TCP port.
 * @return string Lowercase origin authority with the default port omitted.
 */
function app_linking_origin(string $scheme, string $authority): string
{
    $authority = strtolower($authority);
    $defaultPort = ':' . ($scheme === 'https'
        ? APP_LINKING_HTTPS_DEFAULT_PORT : APP_LINKING_HTTP_DEFAULT_PORT);
    if (str_ends_with($authority, $defaultPort)) {
        $authority = substr($authority, 0, -strlen($defaultPort));
    }
    return $scheme . '://' . $authority;
}

/**
 * Confirm that a local HTTP request terminates on this computer.
 *
 * PHP's socket metadata must show a loopback peer and listener at the
 * requested port. Forwarded or proxy requests require the HTTPS path.
 *
 * @param int $port Effective TCP port of the localhost origin.
 * @return bool True for a direct loopback request with matching listener.
 */
function app_linking_direct_loopback_request(int $port): bool
{
    $loopback = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
    $peer = $_SERVER['REMOTE_ADDR'] ?? null;
    $listener = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? null;
    if (!in_array($peer, $loopback, true)
        || !in_array($listener, $loopback, true)
        || (string) ($_SERVER['SERVER_PORT'] ?? '') !== (string) $port) {
        return false;
    }
    foreach (array_keys($_SERVER) as $name) {
        if (str_starts_with((string) $name, 'HTTP_X_FORWARDED_')
            || in_array($name, [
                'HTTP_FORWARDED',
                'HTTP_VIA',
                'HTTP_X_FORWARDED',
                'HTTP_X_REAL_IP',
            ], true)) {
            return false;
        }
    }
    return true;
}

/**
 * Read the request origin on HTTPS or direct localhost HTTP.
 *
 * Host can confirm the configured installation or browser Origin comparison.
 * It supplies no authority for linking on its own.
 *
 * @return string|null Canonical transport origin, or null if not admitted.
 */
function app_linking_request_origin(): ?string
{
    $authority = $_SERVER['HTTP_HOST'] ?? null;
    if (!is_string($authority)
        || preg_match('/\A[A-Za-z0-9.\[\]:-]+\z/D', $authority) !== 1) {
        return null;
    }
    $scheme = request_is_https() ? 'https' : 'http';
    $origin = app_linking_origin($scheme, $authority);
    if (!app_linking_issuer_valid($origin)) {
        return null;
    }
    if ($scheme === 'http' && !app_linking_direct_loopback_request(
        (int) (parse_url($origin, PHP_URL_PORT) ?? APP_LINKING_HTTP_DEFAULT_PORT),
    )) {
        return null;
    }
    return $origin;
}

/**
 * Return the trusted installation address for the matching request.
 *
 * An explicit legacy issuer keeps precedence for existing grants. Otherwise
 * use the website address saved by installation or Admin Settings. Host and
 * proxy headers can confirm the request but never supply the trusted address.
 *
 * @return string|null Canonical installation issuer, or null for invalid
 *     configuration, insecure transport, or a public authority/mount mismatch.
 */
function app_linking_configured_issuer(): ?string
{
    $config = cms_config();
    $linking = $config['app_linking'] ?? null;
    $issuer = is_array($linking) ? ($linking['issuer'] ?? null) : null;
    if ($issuer === null || $issuer === '') {
        $address = $config['base_url'] ?? null;
        if (!is_string($address) || preg_match(
            '~\A(https?)://([^/?#]+)(/[^?#]*)?\z~iD',
            trim($address),
            $parts,
        ) !== 1) {
            return null;
        }
        $issuer = app_linking_origin(strtolower($parts[1]), $parts[2])
            . rtrim($parts[3] ?? '', '/');
    }
    if (!is_string($issuer) || !app_linking_issuer_valid($issuer)) {
        return null;
    }
    $origin = app_linking_request_origin();
    $mount = (string) (parse_url($issuer, PHP_URL_PATH) ?? '');
    $request = $_SERVER['REQUEST_URI'] ?? null;
    if (!is_string($request)
        || str_contains($request, '#')
        || str_contains($request, '\\')
        || preg_match('/[\x00-\x20\x7F]/', $request) === 1) {
        return null;
    }
    $publicPath = explode('?', $request, 2)[0];
    // Internal rewrites can put a private filesystem mount into SCRIPT_NAME.
    // Only the public request path confirms the configured issuer; it never
    // supplies an authority. Keep the supported direct public front controller.
    if (!hash_equals($mount . '/index.php', $publicPath)
        && !hash_equals($mount . '/public/index.php', $publicPath)) {
        return null;
    }
    return $origin !== null
        && hash_equals($issuer, $origin . $mount)
        ? $issuer : null;
}

/**
 * Keep linking forms and login returns on the public front controller.
 *
 * A verified issuer owns authorization URLs. Owner-only grant inspection must
 * also remain usable with invalid issuance settings; its permanent fallback
 * uses the current safe public request path, never an internal script mount.
 * Neither path supplies authentication or changes grant ownership.
 *
 * @param string $page Fixed linking start, consent, devices or Admin login route.
 * @param string|null $issuer Previously verified issuer, or null for owner-only
 *     inspection independent of issuance readiness.
 * @return string Same-origin public route path without secrets or handles.
 * @throws \InvalidArgumentException For an unsupported route or unsafe path.
 */
function app_linking_public_route_path(string $page, ?string $issuer): string
{
    if (!in_array($page, [
        'app_linking_start', 'app_linking_consent', 'app_linking_devices',
        'admin_login',
    ], true)) {
        throw new \InvalidArgumentException('Invalid linking browser route.');
    }
    if ($issuer !== null) {
        if (!app_linking_issuer_valid($issuer)) {
            throw new \InvalidArgumentException('Invalid linking issuer.');
        }
        $controller = (string) (parse_url($issuer, PHP_URL_PATH) ?? '')
            . '/index.php';
    } else {
        $request = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($request)
            || !str_starts_with($request, '/')
            || str_starts_with($request, '//')
            || str_contains($request, '\\') || str_contains($request, '#')
            || preg_match('/[\x00-\x20\x7F]/', $request) === 1) {
            throw new \InvalidArgumentException('Invalid public linking path.');
        }
        $controller = explode('?', $request, 2)[0];
        if (!str_ends_with($controller, '/index.php')) {
            throw new \InvalidArgumentException('Invalid public linking path.');
        }
    }
    return $controller . '?page=' . $page;
}

/**
 * Prepare the public linking readiness envelope for this exact mount.
 *
 * @return array{available:bool,protocol_version?:int,browser_handoff_version?:int,issuer?:string,capabilities?:list<string>}
 *     Versioned ready operations, or an unavailable result without private data.
 */
function app_linking_discovery_model(): array
{
    $issuer = app_linking_configured_issuer();
    if ($issuer === null) {
        return ['available' => false];
    }
    $capabilities = app_linking_discovery_capabilities(
        str_starts_with($issuer, 'https://'),
        true,
    );
    if (!in_array('linking', $capabilities, true)) {
        return ['available' => false];
    }
    return [
        'available' => true,
        'protocol_version' => APP_LINKING_PROTOCOL_VERSION,
        'browser_handoff_version' => APP_LINKING_BROWSER_HANDOFF_VERSION,
        'issuer' => $issuer,
        'capabilities' => $capabilities,
    ];
}

/**
 * Reject query parameters other than the exact fixed front-controller route.
 *
 * @param string $page Canonical route identifier selected by the controller.
 * @return bool True only when no extra query parameter can carry protocol data.
 */
function app_linking_fixed_query(string $page): bool
{
    return (string) ($_SERVER['QUERY_STRING'] ?? '') === 'page=' . $page;
}

/**
 * Accept only the fixed browser page and its optional nonauthorizing handle.
 *
 * @param string $query Raw front-controller query without decoding ambiguity.
 * @return string|null Canonical handle, empty for the fixed page, or null for
 *     duplicate, encoded, credential-bearing or otherwise unexpected fields.
 */
function app_linking_start_request_handle(string $query): ?string
{
    if ($query === 'page=app_linking_start') {
        return '';
    }
    return preg_match(
        '/\Apage=app_linking_start&request_handle=([A-Z2-7]{25}[AEIMQUY4])\z/D',
        $query, $matches,
    ) === 1 ? $matches[1] : null;
}

/**
 * Parse an exact bounded URL-encoded form without duplicate or unknown fields.
 *
 * @param list<string> $required Exact required scalar ASCII field names.
 * @return array<string,string>|null Decoded unique values, or null on any
 *     malformed encoding, wrong media type, duplicate or unexpected field.
 */
function app_linking_form_fields(array $required): ?array
{
    $mediaType = strtolower(trim(explode(';', (string) (
        $_SERVER['CONTENT_TYPE'] ?? ''
    ), 2)[0]));
    if ($mediaType !== 'application/x-www-form-urlencoded') {
        return null;
    }
    $raw = file_get_contents('php://input', false, null, 0,
        APP_LINKING_FORM_MAX_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > APP_LINKING_FORM_MAX_BYTES
        || $raw === '') {
        return null;
    }
    $result = [];
    foreach (explode('&', $raw) as $part) {
        if ($part === '' || !str_contains($part, '=')
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $part) === 1) {
            return null;
        }
        [$encodedKey, $encodedValue] = explode('=', $part, 2);
        $key = urldecode($encodedKey);
        $value = urldecode($encodedValue);
        if (!in_array($key, $required, true) || array_key_exists($key, $result)
            || preg_match('/[\\x00-\\x1F\\x7F]/', $value) === 1
            || !is_string($_POST[$key] ?? null)
            || !hash_equals($_POST[$key], $value)) {
            return null;
        }
        $result[$key] = $value;
    }
    return count($result) === count($required) ? $result : null;
}

/**
 * Emit one generic JSON linking error without reflecting request values.
 *
 * @param int $status HTTP status selected for the bounded failure.
 * @param string $error Fixed protocol error identifier selected by the caller.
 * @return void Sends a minimal JSON response without terminating PHP.
 */
function app_linking_json_error(int $status, string $error): void
{
    app_linking_security_headers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error], JSON_THROW_ON_ERROR);
}

/**
 * Enforce both the selected IP and global fixed rate-limit buckets.
 *
 * @param string $ipBucket Fixed app-linking IP bucket name.
 * @param string $globalBucket Fixed app-linking global bucket name.
 * @param bool $sendRetryAfter Whether a rate-limit refusal includes a retry hint.
 * @return bool True only after both independently committed attempts succeed.
 */
function app_linking_admit_public(
    string $ipBucket,
    string $globalBucket,
    bool $sendRetryAfter = false,
): bool {
    $bucket = $ipBucket;
    $admission = app_linking_rate_limit_consume($bucket, request_client_ip());
    if ($admission['allowed']) {
        $bucket = $globalBucket;
        $admission = app_linking_rate_limit_consume($bucket, 'global');
    }
    if ($sendRetryAfter && !$admission['allowed']
        && $admission['retry_after_seconds'] > 0) {
        header('Retry-After: ' . min(
            $admission['retry_after_seconds'],
            APP_LINKING_RATE_POLICIES[$bucket]['lock_seconds'],
        ));
    }
    return $admission['allowed'];
}

/**
 * Resolve an ordinary Admin principal while rejecting delegated support grants.
 *
 * @return array{id:int,username:string}|null Ordinary Admin identity, or null.
 */
function app_linking_ordinary_admin(): ?array
{
    $user = current_user();
    if (!is_array($user) || (string) ($user['role'] ?? '') !== 'admin'
        || !empty($user['support_access']) || isset($_SESSION['admin_support'])
        || (int) ($user['id'] ?? 0) <= 0) {
        return null;
    }
    return ['id' => (int) $user['id'], 'username' => (string) $user['username']];
}

/**
 * Resolve the ordinary Admin while the browser session remains active.
 *
 * @return int|null Positive matching session account ID, or null on refusal.
 */
function app_link_push_session_user(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_id() === ''
        || array_key_exists('admin_support', $_SESSION)) {
        return null;
    }
    $user = current_user();
    if (!is_array($user) || ($user['role'] ?? null) !== 'admin') {
        return null;
    }
    $id = (int) ($user['id'] ?? 0);
    return $id > 0 && (int) ($_SESSION['user_id'] ?? 0) === $id ? $id : null;
}

/**
 * Verify a submitted token against the current session without creating one.
 *
 * @param string $token Submitted form token.
 * @return bool True only for the current nonempty session token.
 */
function app_link_push_csrf_valid(string $token): bool
{
    $stored = $_SESSION['csrf_token'] ?? null;
    return is_string($stored) && $stored !== '' && $token !== ''
        && hash_equals($stored, $token);
}

/**
 * Claim a request and retain its identity only in the current browser session.
 *
 * @param string $code Canonical uppercase Base32 claim code.
 * @param string $issuer Trusted exact installation issuer.
 * @param string $csrf Submitted current session CSRF token.
 * @return bool True when the live Admin successfully claimed the request.
 */
function app_link_push_claim(string $code, string $issuer, string $csrf): bool
{
    $userId = app_link_push_session_user();
    if ($userId === null || !app_link_push_csrf_valid($csrf)) {
        return false;
    }
    // A failed replacement cannot redisplay an earlier session request.
    unset($_SESSION['app_link_push_request']);
    $id = app_link_push_claim_request($code, $issuer, $userId, session_id());
    if ($id === null) {
        return false;
    }
    $_SESSION['app_link_push_request'] = $id;
    return true;
}

/**
 * Read or consume only the claimed request bound to this live browser session.
 *
 * @param string $issuer Trusted exact installation issuer.
 * @param bool $consume Whether to consume the opportunity atomically.
 * @return array{client_id:string,issuer:string,scope:string,redirect_uri:string,pkce_s256:string,state?:string}|null
 *     Verified bindings; state is included only when consumed, or null on refusal.
 */
function app_link_push_session_bound_request(string $issuer, bool $consume): ?array
{
    try {
        $userId = app_link_push_session_user();
        $id = $_SESSION['app_link_push_request'] ?? null;
        if ($userId === null || !is_int($id) || $id <= 0) {
            return null;
        }
        $result = app_link_push_session_request(
            $issuer, $userId, session_id(), $id, $consume,
        );
        if ($consume && $result !== null) {
            unset($_SESSION['app_link_push_request']);
        }
        return $result;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Preview a claimed request without exposing its client state.
 *
 * @param string $issuer Trusted exact installation issuer.
 * @return array{client_id:string,issuer:string,scope:string,redirect_uri:string,pkce_s256:string}|null
 *     Validated display bindings, or null on refusal.
 */
function app_link_push_pending(string $issuer): ?array
{
    return app_link_push_session_bound_request($issuer, false);
}

/**
 * Consume an explicitly approved opportunity after current-session CSRF validation.
 *
 * @param string $issuer Trusted exact installation issuer.
 * @param string $csrf Submitted current session CSRF token.
 * @return array{client_id:string,issuer:string,scope:string,redirect_uri:string,pkce_s256:string,state:string}|null
 *     Validated bindings and callback-only state, or null on refusal.
 */
function app_link_push_consume(string $issuer, string $csrf): ?array
{
    if (!app_link_push_csrf_valid($csrf)) {
        return null;
    }
    return app_link_push_session_bound_request($issuer, true);
}

/**
 * Prepare sanitized active Theme colors for the isolated linking document.
 *
 * @return array{ink:string,paper:string,panel:string,accent:string} Hex colors
 *     from the active Theme, validated before they reach a nonce style block.
 */
function app_linking_theme_model(): array
{
    $theme = theme_settings();
    $defaults = theme_css_defaults();
    return [
        'ink' => sanitize_hex_color((string) ($theme['header_text'] ?? ''), '#0f172a'),
        'paper' => sanitize_hex_color((string) ($theme['paper'] ?? ''), $defaults['paper']),
        'panel' => sanitize_hex_color((string) ($theme['panel'] ?? ''), $defaults['panel']),
        'accent' => sanitize_hex_color((string) ($theme['accent'] ?? ''), $defaults['accent']),
    ];
}

/**
 * Check that a browser POST originated from this configured authority.
 *
 * @param string $issuer Trusted exact gallery issuer including its mount.
 * @return bool True only for an exact Origin and compatible Fetch Metadata.
 */
function app_linking_browser_origin_valid(string $issuer): bool
{
    $mount = (string) (parse_url($issuer, PHP_URL_PATH) ?? '');
    $origin = $mount === '' ? $issuer : substr($issuer, 0, -strlen($mount));
    $header = $_SERVER['HTTP_ORIGIN'] ?? null;
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    return is_string($header) && hash_equals($origin, $header)
        && ($site === null || $site === 'same-origin');
}

/**
 * Verify an owner mutation's same-origin transport without issuer config.
 *
 * The Host header is only a comparison value for the browser's Origin, never
 * an authority for tokens or issuers. The browser session and CSRF token remain
 * mandatory; trusted reverse proxies must preserve the actual authority.
 *
 * @return bool True only for a same-origin HTTPS or direct localhost mutation.
 */
function app_linking_owner_origin_valid(): bool
{
    $origin = app_linking_request_origin();
    if ($origin === null) {
        return false;
    }
    $header = $_SERVER['HTTP_ORIGIN'] ?? null;
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    return is_string($header) && hash_equals($origin, $header)
        && ($site === null || $site === 'same-origin');
}

/**
 * Accept a native client's pushed request without putting its fields in a URL.
 *
 * @return void Emits a short-lived nonauthorizing browser handle and start URL,
 *     or a bounded generic error. No consent or credential is issued here.
 */
function cms_app_linking_push(): void
{
    try {
        app_linking_security_headers();
        $issuer = app_linking_configured_issuer();
        if ($issuer === null || !app_linking_fixed_query('app_linking_push')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (request_method() !== 'POST') {
            header('Allow: POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        if (!app_linking_admit_public('app_link_start_ip', 'app_link_start_global')) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $form = app_linking_form_fields([
            'response_type', 'client_id', 'redirect_uri', 'scope', 'state',
            'code_challenge', 'code_challenge_method',
        ]);
        if ($form === null || $form['response_type'] !== 'code'
            || $form['code_challenge_method'] !== 'S256') {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $result = app_link_push_create(
            $form['client_id'], $issuer, $form['scope'], $form['redirect_uri'],
            $form['code_challenge'], $form['state'],
        );
        if ($result === null) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        app_linking_security_headers();
        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'handoff_version' => APP_LINKING_BROWSER_HANDOFF_VERSION,
            'request_handle' => $result['claim_code'],
            'start_url' => $issuer . '/index.php?page=app_linking_start&request_handle='
                . $result['claim_code'],
            'expires_in' => $result['expires_in'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Bind a browser handle after ordinary login and show explicit consent.
 *
 * @return void Renders an isolated page, records a claim or refuses it.
 */
function cms_app_linking_start(): void
{
    try {
        app_linking_security_headers();
        $issuer = app_linking_configured_issuer();
        $handle = app_linking_start_request_handle(
            (string) ($_SERVER['QUERY_STRING'] ?? ''),
        );
        if ($issuer === null || $handle === null) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $method = request_method();
        if ($method !== 'GET' && $method !== 'POST') {
            header('Allow: GET, POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        if ($method === 'GET' && $handle !== '') {
            // This value can select only a pending request, never approve it.
            // Remove it from the address before login/consent, retain no state
            // or PKCE material in the browser URL, and claim only after login
            // using the current session's CSRF-protected POST.
            $_SESSION['app_link_browser_handle'] = $handle;
            header('Location: ' . app_linking_public_route_path(
                'app_linking_start', $issuer,
            ), true, 303);
            return;
        }
        if ($method === 'POST' && $handle !== '') {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $existingUser = current_user();
        $admin = app_linking_ordinary_admin();
        if ($admin === null) {
            if (is_array($existingUser)) {
                app_linking_json_error(403, 'access_denied');
                return;
            }
            if ($method !== 'GET') {
                app_linking_json_error(401, 'access_denied');
                return;
            }
            $return = app_linking_public_route_path('app_linking_start', $issuer);
            $login = app_linking_public_route_path('admin_login', $issuer) . '&'
                . http_build_query(['return' => $return], '', '&', PHP_QUERY_RFC3986);
            app_linking_security_headers();
            header('Location: ' . $login, true, 303);
            return;
        }
        if ($method === 'GET') {
            $savedHandle = $_SESSION['app_link_browser_handle'] ?? '';
            $pending = app_link_push_pending($issuer);
            $attempt = $_SESSION['app_link_ui_attempt'] ?? null;
            $pendingConsent = $savedHandle === '' && $pending !== null
                && is_string($attempt) && $attempt !== ''
                ? ['ok' => true, 'attempt' => $attempt, 'scope' => $pending['scope']]
                : null;
            $nonce = app_linking_security_nonce();
            app_linking_security_headers($nonce);
            header('Content-Type: text/html; charset=utf-8');
            view_render_app_linking_start(
                app_linking_public_route_path('app_linking_start', $issuer),
                app_linking_public_route_path('app_linking_consent', $issuer),
                csrf_token(), $nonce, translation_active_language(),
                app_linking_theme_model(), $issuer, $admin['username'],
                [
                    'request_handle' => is_string($savedHandle) ? $savedHandle : '',
                    'pending' => $pendingConsent,
                    'access_lifetime' => APP_LINKING_ACCESS_TTL_SECONDS,
                ],
            );
            return;
        }
        if (!app_linking_browser_origin_valid($issuer)) {
            app_linking_json_error(403, 'access_denied');
            return;
        }
        $form = app_linking_form_fields(['csrf_token', 'request_handle']);
        $savedHandle = $_SESSION['app_link_browser_handle'] ?? null;
        if ($form === null || !is_string($savedHandle)
            || !hash_equals($savedHandle, $form['request_handle'])) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        verify_csrf();
        if (!app_linking_admit_public('app_link_claim_ip', 'app_link_claim_global')
            || !app_linking_rate_limit_consume(
                'app_link_claim_account', (string) $admin['id'],
            )['allowed']) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        // Invalidate earlier tabs before the service can replace the session claim.
        unset($_SESSION['app_link_ui_attempt']);
        if (!app_link_push_claim(
            $form['request_handle'], $issuer, $form['csrf_token'],
        )) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        unset($_SESSION['app_link_browser_handle']);
        $pending = app_link_push_pending($issuer);
        if ($pending === null
            || !app_linking_scope_allowed($pending['client_id'], $pending['scope'])) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        // A second tab replacing the session claim invalidates the first tab's UI.
        $attempt = app_linking_security_nonce();
        $_SESSION['app_link_ui_attempt'] = $attempt;
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'attempt' => $attempt,
            'scope' => $pending['scope'],
        ], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Consume the bound claim only after an explicit ordinary-Admin decision.
 *
 * @return void Returns an approved loopback callback as JSON or a refusal.
 */
function cms_app_linking_consent(): void
{
    try {
        app_linking_security_headers();
        $issuer = app_linking_configured_issuer();
        if ($issuer === null || !app_linking_fixed_query('app_linking_consent')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (request_method() !== 'POST') {
            header('Allow: POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        $admin = app_linking_ordinary_admin();
        if ($admin === null || !app_linking_browser_origin_valid($issuer)) {
            app_linking_json_error(403, 'access_denied');
            return;
        }
        $form = app_linking_form_fields(['csrf_token', 'attempt', 'decision']);
        $attempt = $_SESSION['app_link_ui_attempt'] ?? null;
        if ($form === null || !is_string($attempt) || $attempt === ''
            || !hash_equals($attempt, $form['attempt'])
            || !in_array($form['decision'], ['approve', 'deny'], true)) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        verify_csrf();
        $pending = app_link_push_pending($issuer);
        if ($pending === null || $pending['issuer'] !== $issuer
            || !app_linking_scope_allowed($pending['client_id'], $pending['scope'])
            || app_linking_public_client($pending['client_id']) === null
            || !app_linking_redirect_uri_valid($pending['client_id'], $pending['redirect_uri'])
            || !app_linking_pkce_s256_challenge_valid($pending['pkce_s256'])) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if ($form['decision'] === 'approve') {
            $admission = app_linking_rate_limit_consume(
                'app_link_approve_account', (string) $admin['id'],
            );
            if (!$admission['allowed']) {
                app_linking_json_error(429, 'temporarily_unavailable');
                return;
            }
        }
        unset($_SESSION['app_link_ui_attempt']);
        $consumed = app_link_push_consume($issuer, $form['csrf_token']);
        if ($consumed === null || $consumed['issuer'] !== $issuer
            || $consumed['client_id'] !== $pending['client_id']
            || $consumed['scope'] !== $pending['scope']
            || $consumed['redirect_uri'] !== $pending['redirect_uri']
            || $consumed['pkce_s256'] !== $pending['pkce_s256']
            || !app_linking_canonical_256bit_value_valid($consumed['state'])) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        if ($form['decision'] === 'deny') {
            $callbackResult = ['error' => 'access_denied'];
        } else {
            $result = app_linking_issue_code(
                $admin['id'], $consumed['client_id'], $issuer, $consumed['scope'],
                $consumed['redirect_uri'], $consumed['pkce_s256'], 'S256', true,
            );
            if ($result === null) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            $callbackResult = ['code' => $result['code']];
        }
        $callback = $consumed['redirect_uri'] . '?' . http_build_query(
            $callbackResult + ['state' => $consumed['state'], 'iss' => $issuer],
            '', '&', PHP_QUERY_RFC3986,
        );
        echo json_encode(['ok' => true, 'callback' => $callback], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Return a bounded list or search page for an explicitly scoped grant.
 *
 * @return void Sends a no-store JSON page, optional complete/unchanged tree
 *     envelope, or generic refusal without exposing a partial complete tree.
 */
function cms_app_linking_galleries(): void
{
    $treeRequest = false;
    try {
        $issuer = app_linking_configured_issuer();
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $method = request_method();
        $pagedRequest = preg_match(
            '/\Apage=app_linking_galleries(?:&after=([1-9][0-9]{0,18}))?\z/D',
            $query,
            $matches,
        ) === 1;
        $treeRequest = preg_match(
            '/\Apage=app_linking_galleries&tree=1'
                . '(?:&revision=([a-f0-9]{64}))?\z/D',
            $query,
            $treeMatches,
        ) === 1;
        if ($issuer === null || (!$pagedRequest && !$treeRequest)) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $allowedMethods = $treeRequest ? ['GET'] : ['GET', 'POST'];
        if (!in_array($method, $allowedMethods, true)) {
            header('Allow: ' . implode(', ', $allowedMethods));
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        $searchQuery = null;
        $cursor = $pagedRequest ? ($matches[1] ?? '') : '';
        if ($method === 'POST') {
            $form = $cursor === '' ? app_linking_form_fields(['query', 'after']) : null;
            if ($form === null || preg_match('//u', $form['query']) !== 1
                || preg_match_all('/./us', $form['query']) > APP_LINKING_SEARCH_QUERY_LIMIT
                || preg_match('/\A(?:[1-9][0-9]{0,18})?\z/D', $form['after']) !== 1) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            $searchQuery = trim(preg_replace('/\s+/u', ' ', $form['query']) ?? '');
            $cursor = $form['after'];
        }
        $afterId = $cursor === '' ? 0 : (int) $cursor;
        if ($cursor !== '' && (string) $afterId !== $cursor) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        app_linking_passive_begin($treeRequest ? 'gallery_tree' : 'gallery_page');
        $authStarted = app_linking_passive_span_start();
        if (!app_linking_admit_public('app_link_galleries_ip', 'app_link_galleries_global')) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $token = app_linking_bearer();
        $identity = $token === null ? null : app_linking_authenticate($token, $issuer);
        if ($identity === null) {
            header('WWW-Authenticate: Bearer');
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        if (!app_linking_scope_allows_gallery_listing($identity['scope'])) {
            app_linking_json_error(403, 'insufficient_scope');
            return;
        }
        if ($searchQuery !== null && !app_linking_scope_allows_gallery_search($identity['scope'])) {
            app_linking_json_error(403, 'insufficient_scope');
            return;
        }
        app_linking_passive_span_end('auth', $authStarted);
        $page = $treeRequest
            ? app_linking_gallery_tree(
                $token, $issuer, $treeMatches[1] ?? null,
            )
            : ($searchQuery === null
                ? app_linking_gallery_page($token, $issuer, $afterId)
                : app_linking_gallery_search_page(
                    $identity, $token, $issuer, $searchQuery, $afterId,
                ));
        if ($page === null) {
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        $encoded = json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        // The full tree/page service has rechecked the current bearer and grant.
        app_linking_passive_authorized();
        app_linking_passive_emit_header();
        echo $encoded;
    } catch (\OverflowException) {
        if ($treeRequest) {
            app_linking_json_error(503, 'temporarily_unavailable');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['galleries' => [], 'next_after' => null, 'limited' => true]);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Exchange an authorization code or rotate an explicitly approved refresh.
 *
 * @return void Sends a no-store JSON token response or generic bounded error.
 */
function cms_app_linking_token(): void
{
    try {
        $issuer = app_linking_configured_issuer();
        if ($issuer === null || !app_linking_fixed_query('app_linking_token')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (request_method() !== 'POST') {
            header('Allow: POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        if (!app_linking_admit_public('app_link_exchange_ip', 'app_link_exchange_global')) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $grantType = $_POST['grant_type'] ?? null;
        $form = app_linking_form_fields($grantType === 'refresh_token'
            ? ['grant_type', 'refresh_token', 'client_id']
            : ['grant_type', 'code', 'client_id', 'redirect_uri', 'code_verifier']);
        if ($form === null) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (!in_array($form['grant_type'], ['authorization_code', 'refresh_token'], true)) {
            app_linking_json_error(400, 'unsupported_grant_type');
            return;
        }
        $result = $form['grant_type'] === 'refresh_token'
            ? app_link_refresh_rotate(
                $form['refresh_token'], $form['client_id'], $issuer,
            )
            : app_linking_exchange_code(
                $form['code'], $form['client_id'], $issuer, $form['redirect_uri'],
                $form['code_verifier'],
            );
        if ($result === null) {
            app_linking_json_error(400, 'invalid_grant');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        $response = [
            'access_token' => $result['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $result['expires_in'],
            'scope' => $result['scope'],
        ];
        if (isset($result['refresh_token'], $result['refresh_expires_in'])) {
            $response['refresh_token'] = $result['refresh_token'];
            $response['refresh_expires_in'] = $result['refresh_expires_in'];
        }
        echo json_encode($response, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Extract a single bearer value from the server-provided authorization header.
 *
 * @return string|null Header token, or null for missing, conflicting or malformed
 *     headers. This does not authorize or validate the credential itself.
 */
function app_linking_bearer(): ?string
{
    $primary = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $redirected = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (($primary !== null && !is_string($primary))
        || ($redirected !== null && !is_string($redirected))
        || (is_string($primary) && is_string($redirected)
            && !hash_equals($primary, $redirected))) {
        return null;
    }
    $header = $primary ?? $redirected;
    if (!is_string($header) || strlen($header) > APP_LINKING_AUTH_HEADER_MAX_BYTES
        || preg_match('/\\ABearer ([A-Za-z0-9_-]+)\\z/iD', $header, $matches) !== 1) {
        return null;
    }
    return $matches[1];
}

/**
 * Return the current minimal identity for one authorized bearer credential.
 *
 * @return void Sends the scoped identity or a generic authorization failure.
 */
function cms_app_linking_identity(): void
{
    try {
        $issuer = app_linking_configured_issuer();
        if ($issuer === null || !app_linking_fixed_query('app_linking_identity')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (request_method() !== 'GET') {
            header('Allow: GET');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        if (!app_linking_admit_public('app_link_identity_ip', 'app_link_identity_global')) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $token = app_linking_bearer();
        $identity = $token === null ? null : app_linking_authenticate($token, $issuer);
        if ($identity === null) {
            header('WWW-Authenticate: Bearer');
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'user' => ['id' => $identity['user_id'], 'username' => $identity['username']],
            'scope' => $identity['scope'],
        ], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Revoke the bearer-held link without depending on issuance or rate-limit state.
 *
 * @return void Sends the same empty JSON object for valid and invalid credentials.
 */
function cms_app_linking_revoke(): void
{
    try {
        $issuer = app_linking_configured_issuer();
        if ($issuer === null || !app_linking_fixed_query('app_linking_revoke')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (request_method() !== 'POST') {
            header('Allow: POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        $body = file_get_contents('php://input', false, null, 0, 1);
        if ($body !== '') {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $token = app_linking_bearer();
        if ($token !== null) {
            if (str_starts_with($token, APP_LINK_REFRESH_TOKEN_PREFIX)) {
                app_link_refresh_revoke_token(
                    $token, APP_LINKING_WINAPP_CLIENT_ID, $issuer,
                );
            } else {
                app_linking_revoke_token($token, $issuer);
            }
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo '{}';
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * List the current ordinary Admin's links or revoke one by a CSRF-protected POST.
 *
 * Revocation and listing use their own narrow schema checks and never depend on
 * a rate-limit bucket or a bearer credential. IDs are accepted only in the POST
 * body; the model rechecks the authenticated owner and current Admin role.
 *
 * @return void Renders an owner-scoped page, confirms revocation as JSON or
 *     sends a generic refusal without disclosing another owner's grants.
 */
function cms_app_linking_devices(): void
{
    try {
        app_linking_security_headers();
        if (app_linking_request_origin() === null
            || !app_linking_fixed_query('app_linking_devices')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (!in_array(request_method(), ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        $existingUser = current_user();
        $admin = app_linking_ordinary_admin();
        if (is_array($existingUser) && $admin === null) {
            app_linking_json_error(403, 'access_denied');
            return;
        }
        if ($admin === null) {
            if (request_method() !== 'GET') {
                app_linking_json_error(401, 'access_denied');
                return;
            }
            $issuer = app_linking_configured_issuer();
            $return = app_linking_public_route_path('app_linking_devices', $issuer);
            $login = app_linking_public_route_path('admin_login', $issuer) . '&'
                . http_build_query(['return' => $return], '', '&', PHP_QUERY_RFC3986);
            header('Location: ' . $login, true, 303);
            return;
        }
        if (request_method() === 'POST') {
            if (!app_linking_owner_origin_valid()) {
                app_linking_json_error(403, 'access_denied');
                return;
            }
            $form = app_linking_form_fields(['csrf_token', 'grant_id']);
            if ($form === null) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            verify_csrf();
            $grantId = filter_var($form['grant_id'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if (!is_int($grantId)) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            if (!app_linking_revoke_owner($admin['id'], $grantId, true)) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            app_linking_security_headers();
            header('Content-Type: application/json; charset=utf-8');
            echo '{"ok":true}';
            return;
        }
        $listing = app_linking_list_owner($admin['id'], true);
        if ($listing === null) {
            app_linking_json_error(403, 'access_denied');
            return;
        }
        $rows = [];
        foreach ($listing['links'] as $grant) {
            $status = $grant['revoked_at'] !== null
                ? t('app_linking.devices.revoked', 'Revoked')
                : ($grant['access_issued_at'] === null
                    ? t('app_linking.devices.unexchanged', 'Not exchanged; may have expired')
                    : ($grant['access_expires_at'] === null
                        ? t('app_linking.devices.expiry_unknown', 'Access issued; expiry unknown')
                        : t('app_linking.devices.issued', 'Access issued')));
            $client = $grant['client_id'] === APP_LINKING_WINAPP_CLIENT_ID
                ? t('app_linking.devices.winapp', 'PHP Gallery for Windows (unverified)')
                : t('app_linking.devices.unknown_client', 'Unknown client (unverified)');
            $adminMedia = app_linking_scope_allows_admin_media($grant['scope']);
            $scope = match (app_linking_scope_base($grant['scope'])) {
                APP_LINKING_IDENTITY_SCOPE => t(
                    'app_linking.devices.identity_scope',
                    'Identity check only',
                ),
                APP_LINKING_GALLERIES_SCOPE, APP_LINKING_CREATE_SCOPE => t(
                    'app_linking.devices.galleries_scope',
                    'Identity and gallery listing',
                ),
                APP_LINKING_UPLOAD_SCOPE, APP_LINKING_UPLOAD_CREATE_SCOPE => t(
                    'app_linking.devices.upload_scope',
                    'Identity, gallery listing and image upload',
                ),
                APP_LINKING_READ_SCOPE, APP_LINKING_READ_CREATE_SCOPE,
                APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_CREATE_VISIBILITY_SCOPE => t(
                    'app_linking.devices.read_scope',
                    'Identity, gallery listing and stored gallery settings',
                ),
                APP_LINKING_READ_UPLOAD_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
                APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
                APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE => t(
                    'app_linking.devices.read_upload_scope',
                    'Identity, gallery settings and image upload',
                ),
                default => t('app_linking.devices.unknown_scope', 'Unrecognized scope'),
            };
            if (in_array(app_linking_scope_base($grant['scope']), [
                APP_LINKING_CREATE_SCOPE, APP_LINKING_READ_CREATE_SCOPE,
                APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
                APP_LINKING_CREATE_VISIBILITY_SCOPE,
                APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
            ], true)) {
                $scope .= ' · ' . t('app_linking.devices.create_scope', 'Gallery creation');
            }
            if (in_array(app_linking_scope_base($grant['scope']), [
                APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
                APP_LINKING_CREATE_VISIBILITY_SCOPE,
                APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
            ], true)) {
                $scope .= ' · ' . ($adminMedia
                    ? t('app_linking.devices.media_visibility_scope', 'Change gallery and photo visibility')
                    : t('app_linking.devices.visibility_scope', 'Change gallery visibility'));
            }
            if ($adminMedia) {
                $scope .= ' · ' . t('app_linking.devices.admin_media_scope', 'Admin photo access');
            } elseif (app_linking_scope_allows_gallery_listing($grant['scope'])) {
                $scope .= ' · ' . t('app_linking.devices.media_scope', 'Public photo browsing');
            }
            if (app_linking_scope_allows_presentation($grant['scope'])) {
                $scope .= ' · ' . t('app_linking.devices.presentation_scope', 'Public presentation and music');
            }
            if (app_linking_scope_requests_refresh($grant['scope'])) {
                $scope .= ' · ' . t(
                    'app_linking.devices.offline_scope',
                    'Continuing access approved',
                );
            }
            if (app_linking_scope_allows_gallery_search($grant['scope'])) {
                $scope .= ' · ' . t('app_linking.devices.search_scope', 'Gallery search');
            }
            $rows[] = [
                'id' => $grant['id'],
                'issuer' => $grant['issuer'],
                'client' => $client,
                'scope' => $scope,
                'created_at' => $grant['created_at'],
                'expires_at' => $grant['access_expires_at'],
                'status' => $status,
                'revocable' => $grant['revoked_at'] === null,
            ];
        }
        $nonce = app_linking_security_nonce();
        app_linking_security_headers($nonce);
        header('Content-Type: text/html; charset=utf-8');
        view_render_app_linking_devices(
            app_linking_public_route_path(
                'app_linking_devices', app_linking_configured_issuer(),
            ),
            csrf_token(),
            $nonce,
            translation_active_language(),
            app_linking_theme_model(),
            $rows,
            $listing['truncated'],
        );
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}
