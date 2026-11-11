<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking.php
 * Module Type: Service
 * Purpose: Orchestrate scoped native-client authorization and gallery access.
 * Responsibilities:
 *   - Validate client, issuer, consent inputs and PKCE policy before transitions.
 *   - Require available schema and pass only credential hashes to the model.
 *   - Preserve existing gallery-scoped upload authentication independently.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/app_linking_passive_profile.php';

use RuntimeException;
use Throwable;
use function Gallery\Models\app_link_model_exchange;
use function Gallery\Models\app_link_model_issue;
use function Gallery\Models\app_link_model_list_owner;
use function Gallery\Models\app_link_model_lookup_token;
use function Gallery\Models\app_link_model_pending_code_scope;
use function Gallery\Models\app_link_model_revoke;
use function Gallery\Models\app_link_model_revoke_token;
use function Gallery\Models\app_link_model_watch_key_open;
use function Gallery\Models\app_link_model_watch_key_close;
use function Gallery\Models\app_link_model_watch_key_valid;
use function Gallery\Models\app_link_refresh_model_extend_on_access;
use function Gallery\Models\app_settings_model_get;
use function Gallery\Models\gallery_model_linked_client_page;
use function Gallery\Models\gallery_model_linked_detail;
use function Gallery\Models\gallery_model_linked_upload_target;

/**
 * Limit the lifetime of one browser-approved authorization code.
 * Type: int. Units: seconds. Scope: one linking authorization code.
 * Consumers: app_linking_issue_code, app_link_model_issue.
 * Rationale: 120 seconds bounds interception and gives the local callback time.
 */
const APP_LINKING_CODE_TTL_SECONDS = 120;

/**
 * Limit the lifetime of one scoped bearer credential.
 * Type: int. Units: seconds. Scope: one issued access token.
 * Consumers: app_linking_exchange_code, app_link_model_exchange.
 * Rationale: 900 seconds limits exposure and requires renewed consent later.
 */
const APP_LINKING_ACCESS_TTL_SECONDS = 900;

/**
 * Bound one native gallery listing or search response.
 * Type: int. Units: gallery rows. Scope: one linking API response.
 * Consumers: app_linking_gallery_page, app_linking_gallery_picker_results.
 * Rationale: 50 keeps page responses bounded. Basic listing reads one extra
 * row to detect continuation. Search pages come from the bounded catalog.
 */
const APP_LINKING_GALLERY_PAGE_SIZE = 50;

/**
 * Bound the optional complete native catalogue to one authorized SQL read.
 * Type: int. Units: gallery rows. Scope: one tree-v1 response.
 * Consumers: app_linking_gallery_tree.
 * Rationale: 10,000 matches the companion's bounded tree preferences; one
 * lookahead row detects larger sites and preserves the paged fallback.
 */
const APP_LINKING_GALLERY_TREE_MAX_ROWS = 10000;

/**
 * Bound the complete encoded tree before it can become an HTTP response.
 * Type: int. Units: bytes. Scope: one tree-v1 JSON response.
 * Consumers: app_linking_gallery_tree.
 * Rationale: Four MiB accommodates ordinary catalogues without unbounded
 * transfer or client allocation; oversized trees use the existing pages.
 */
const APP_LINKING_GALLERY_TREE_MAX_BYTES = 4 * 1024 * 1024;

/**
 * Identify the optional complete catalogue envelope independently of releases.
 * Type: int. Units: protocol version. Scope: tree-v1 payload and revision.
 * Consumers: app_linking_gallery_tree.
 * Rationale: Version one keeps older paged clients and future tree formats
 * distinct without changing consent, scopes or the linking protocol version.
 */
const APP_LINKING_GALLERY_TREE_VERSION = 1;

/**
 * Bound the exact configured issuer to its persisted schema capacity.
 * Type: int. Units: bytes. Scope: one linking issuer including its mount.
 * Consumers: app_linking_issuer_valid.
 * Rationale: 512 bytes matches the grant column and prevents truncation.
 */
const APP_LINKING_ISSUER_MAX_BYTES = 512;

/**
 * Enforce the DNS presentation length for a configured issuer hostname.
 * Type: int. Units: ASCII bytes. Scope: one issuer DNS hostname.
 * Consumers: app_linking_issuer_valid.
 * Rationale: 253 bytes is the maximum textual DNS name without a root dot.
 */
const APP_LINKING_ISSUER_HOST_MAX_BYTES = 253;

/**
 * Reject configured issuer ports outside the TCP transport range.
 * Type: int. Units: TCP port number. Scope: one issuer authority.
 * Consumers: app_linking_issuer_valid.
 * Rationale: 65535 is the largest representable TCP port number.
 */
const APP_LINKING_ISSUER_PORT_MAX = 65535;

/**
 * Identify the standard HTTPS port omitted by canonical issuer URLs.
 * Type: int. Units: TCP port number. Scope: app-linking HTTPS issuers.
 * Consumers: app_linking_issuer_valid, app_linking_configured_issuer.
 * Rationale: 443 is the HTTPS default and must have one canonical spelling.
 */
const APP_LINKING_HTTPS_DEFAULT_PORT = 443;

/**
 * Identify the default port omitted by the local HTTP issuer.
 * Type: int. Units: TCP port number. Scope: direct localhost HTTP linking.
 * Consumers: app_linking_issuer_valid, app_linking_origin, app_linking_request_origin.
 * Rationale: 80 is the HTTP default and must have one canonical spelling.
 */
const APP_LINKING_HTTP_DEFAULT_PORT = 80;

/**
 * Check the canonical syntax of an independently trusted installation issuer.
 *
 * The caller must obtain this value from verified installation configuration,
 * never directly from request headers. This predicate cannot establish trust.
 * Accept HTTPS and the literal localhost HTTP origin with unambiguous mount
 * segments. The caller verifies both socket endpoints for HTTP. Root uses the
 * bare origin and the scheme's default port is omitted. IPv6 uses canonical
 * inet_ntop spelling; mapped and deprecated IPv4-compatible IPv6 are excluded.
 * Numeric-ending hostnames must be canonical dotted-decimal IPv4 so browser
 * host normalization cannot change the trusted issuer.
 *
 * @param string $issuer Preverified installation origin and mount.
 * @return bool True for an accepted canonical issuer; false otherwise.
 */
function app_linking_issuer_valid(string $issuer): bool
{
    if (strlen($issuer) > APP_LINKING_ISSUER_MAX_BYTES) {
        return false;
    }
    $pattern = '#\\A(https?)://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'
        . '(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*'
        . '|\\[[0-9a-f:.]+\\]))(?::([1-9][0-9]{0,4}))?'
        . '((?:/[A-Za-z0-9._~-]+)*)\\z#D';
    if (preg_match($pattern, $issuer, $matches) !== 1) {
        return false;
    }
    $scheme = $matches[1];
    $host = $matches[2];
    if ($scheme === 'http' && $host !== 'localhost') {
        return false;
    }
    if (str_starts_with($host, '[')) {
        $address = substr($host, 1, -1);
        $packed = @inet_pton($address);
        if (!is_string($packed) || strlen($packed) !== 16) {
            return false;
        }
        // Mapped and deprecated IPv4-compatible encodings can be rendered in
        // dotted form by some platforms. Preserve the native :: and ::1 forms.
        $mapped = substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff";
        $compatible = substr($packed, 0, 12) === str_repeat("\0", 12)
            && substr($packed, 12) !== str_repeat("\0", 4)
            && substr($packed, 12) !== "\0\0\0\1";
        if ($mapped || $compatible || inet_ntop($packed) !== $address) {
            return false;
        }
    } else {
        if (strlen($host) > APP_LINKING_ISSUER_HOST_MAX_BYTES) {
            // The DNS name length is a protocol boundary, not an issuer policy.
            return false;
        }
        $labels = explode('.', $host);
        $lastLabel = (string) end($labels);
        // Browsers interpret a numeric or hex-like final label as IPv4. Only
        // a complete canonical address can survive that normalization intact.
        if (preg_match('/\A(?:[0-9]+|0x[0-9a-f]*)\z/D', $lastLabel) === 1) {
            $packed = @inet_pton($host);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || !is_string($packed) || inet_ntop($packed) !== $host) {
                return false;
            }
        }
    }
    if (($matches[3] ?? '') !== '') {
        $port = (int) $matches[3];
        $defaultPort = $scheme === 'https'
            ? APP_LINKING_HTTPS_DEFAULT_PORT : APP_LINKING_HTTP_DEFAULT_PORT;
        if ($port > APP_LINKING_ISSUER_PORT_MAX || $port === $defaultPort) {
            // Omit the default port so one installation has one exact issuer.
            return false;
        }
    }
    $mount = $matches[4] ?? '';
    foreach (explode('/', $mount) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }
    return true;
}

/**
 * Require a complete known schema before a security-sensitive transition.
 *
 * @param array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} $status
 *     Three-state schema inspection produced by the corresponding owner.
 * @return void Continues only when available; otherwise throws a generic error.
 * @throws RuntimeException When required schema is missing or unknown.
 */
function app_linking_require_schema(array $status): void
{
    if (!schema_inspection_is_available($status)) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Describe only server operations with verified current storage and policy.
 *
 * Discovery confers no authority; every later operation rechecks the live
 * account, grant, scope and target. Missing optional storage hides only that
 * operation, while unavailable core storage hides linking altogether.
 *
 * @param bool $allowRefresh Whether the transport permits legacy offline access.
 * @param bool $allowRolling Whether the verified issuer permits rolling access.
 * @return list<string> Ready capability identifiers, or an empty list when
 *     linking cannot safely issue and revoke its own credentials.
 */
function app_linking_discovery_capabilities(
    bool $allowRefresh = true,
    bool $allowRolling = false,
): array {
    try {
        app_link_push_key('discovery');
        foreach ([
            app_linking_issuance_schema_status(),
            app_linking_authentication_schema_status(),
            app_linking_push_schema_status(),
            app_linking_rate_limit_schema_status(),
            app_linking_owner_listing_schema_status(),
            app_linking_owner_revocation_schema_status(),
        ] as $status) {
            app_linking_require_schema($status);
        }
    } catch (Throwable) {
        return [];
    }
    $capabilities = ['linking'];
    $optional = [
        'galleries:list' => APP_LINKING_GALLERIES_SCOPE,
        'gallery:read' => APP_LINKING_READ_SCOPE,
        'gallery:upload' => APP_LINKING_UPLOAD_SCOPE,
        'offline_access' => APP_LINKING_IDENTITY_OFFLINE_SCOPE,
        'rolling_access' => APP_LINKING_GALLERIES_ROLLING_SCOPE,
    ];
    foreach ($optional as $capability => $scope) {
        if (($capability === 'offline_access' && !$allowRefresh)
            || ($capability === 'rolling_access' && !$allowRolling)) {
            continue;
        }
        try {
            $status = match ($capability) {
                'galleries:list' => app_linking_gallery_listing_schema_status(),
                'gallery:read' => app_linking_gallery_detail_schema_status(),
                'gallery:upload' => app_linking_upload_schema_status(),
                'offline_access', 'rolling_access' => app_link_refresh_schema_status(),
            };
            if (schema_inspection_is_available($status)
                && app_linking_scope_allowed(APP_LINKING_WINAPP_CLIENT_ID, $scope)
                && ($capability === 'galleries:list'
                    || $capability === 'offline_access'
                    || in_array('galleries:list', $capabilities, true))) {
                $capabilities[] = $capability;
            }
        } catch (Throwable) {
            // A failed optional observation hides only its own operation.
        }
    }
    if (in_array('galleries:list', $capabilities, true)) {
        $capabilities[] = 'galleries:tree-v1';
        try {
            if (schema_inspection_is_available(
                app_linking_gallery_search_schema_status(),
            ) && tag_media_type_schema_state() !== 'unknown') {
                $capabilities[] = 'galleries:search';
            }
        } catch (Throwable) {
            // Search readiness is independent of the original list operation.
        }
    }
    try {
        if (in_array('galleries:list', $capabilities, true)
            && app_linking_scope_allowed(
            APP_LINKING_WINAPP_CLIENT_ID, APP_LINKING_CREATE_SCOPE,
        )) {
            app_linking_create_require_schema(true);
            $capabilities[] = 'galleries:create';
        }
    } catch (Throwable) {
        // Creation stays unavailable when its additive storage is incomplete.
        // This does not change linking, read/upload or the legacy pgu_ path.
    }
    if (in_array('gallery:read', $capabilities, true)
        && app_linking_scope_allowed(APP_LINKING_WINAPP_CLIENT_ID, APP_LINKING_VISIBILITY_SCOPE)) {
        $capabilities[] = 'gallery:visibility';
    }
    if (in_array('galleries:list', $capabilities, true)
        && app_linking_scope_allowed(
            APP_LINKING_WINAPP_CLIENT_ID, APP_LINKING_GALLERIES_SCOPE . APP_LINKING_MEDIA_SUFFIX,
        )) {
        $capabilities[] = 'media:read';
        if (app_linking_scope_allowed(
            APP_LINKING_WINAPP_CLIENT_ID,
            APP_LINKING_GALLERIES_SCOPE . APP_LINKING_ADMIN_MEDIA_SUFFIX,
        )) {
            $capabilities[] = 'media:admin';
            $capabilities[] = 'media:admin-preload-v1';
            if (app_linking_admin_media_delete_ready() && app_linking_scope_allowed(
                APP_LINKING_WINAPP_CLIENT_ID,
                APP_LINKING_GALLERIES_SCOPE . APP_LINKING_ADMIN_MEDIA_SUFFIX
                    . APP_LINKING_ADMIN_DELETE_SUFFIX,
            )) {
                $capabilities[] = 'media:delete';
            }
        }
        if (app_linking_scope_allowed(
            APP_LINKING_WINAPP_CLIENT_ID,
            APP_LINKING_GALLERIES_SCOPE . APP_LINKING_PRESENTATION_SUFFIX,
        )) {
            $capabilities[] = 'presentation:read';
        }
    }
    if (in_array('rolling_access', $capabilities, true)
        && in_array('presentation:read', $capabilities, true)
        && app_linking_scope_allowed(
            APP_LINKING_WINAPP_CLIENT_ID, APP_LINKING_GALLERIES_PRESENTATION_ROLLING_SCOPE,
        )) {
        $capabilities[] = 'presentation:rolling';
    }
    return $capabilities;
}

/**
 * Issue a one-use code only after explicit authorized consent and validation.
 *
 * The transport must verify the same pending request, current authenticated
 * ordinary Admin, CSRF, issuer provenance and explicit consent; a boolean is
 * not a substitute for those checks. The model rechecks the live Admin role.
 *
 * @param int $adminId Stable authenticated administrator identifier.
 * @param string $clientId Claimed registered public-client identifier.
 * @param string $issuer Independently trusted exact installation origin and mount.
 * @param string $scope Scope approved by the administrator.
 * @param string $redirectUri Complete registered loopback callback URI.
 * @param string $challenge Canonical unpadded SHA-256 PKCE challenge.
 * @param string $challengeMethod Claimed PKCE method; only S256 is accepted.
 * @param bool $authorizedConsent True only after all transport consent checks.
 * @return array{code:string,expires_in:int}|null Raw code and lifetime after
 *     commit; null for invalid policy, refusal or a no-longer-Admin user.
 * @throws RuntimeException When required schema, randomness or persistence fails.
 */
function app_linking_issue_code(
    int $adminId,
    string $clientId,
    string $issuer,
    string $scope,
    string $redirectUri,
    string $challenge,
    string $challengeMethod,
    bool $authorizedConsent,
): ?array {
    if (
        !$authorizedConsent || $adminId <= 0
        || app_linking_public_client($clientId) === null
        || !app_linking_issuer_valid($issuer)
        || !app_linking_scope_transport_allowed($issuer, $scope)
        || !app_linking_scope_allowed($clientId, $scope)
        || !app_linking_redirect_uri_valid($clientId, $redirectUri)
        || $challengeMethod !== 'S256'
        || !app_linking_pkce_s256_challenge_valid($challenge)
    ) {
        return null;
    }

    try {
        app_linking_require_schema(app_linking_issuance_schema_status());
        if (app_linking_scope_requests_refresh($scope)) {
            app_linking_require_schema(app_link_refresh_schema_status());
        }
        $rawCode = null;
        $codeId = app_link_model_issue(
            $adminId,
            $clientId,
            $issuer,
            $scope,
            static function () use ($clientId, $scope, &$rawCode): string {
                app_linking_recheck_wide_scope($clientId, $scope);
                $rawCode = app_linking_code_generate();
                $hash = app_linking_code_hash($rawCode);
                if ($hash === null) {
                    throw new RuntimeException('Invalid generated code.');
                }
                return $hash;
            },
            $redirectUri,
            $challenge,
            APP_LINKING_CODE_TTL_SECONDS,
        );
        if ($codeId === null || !is_string($rawCode)) {
            return null;
        }
        return ['code' => $rawCode, 'expires_in' => APP_LINKING_CODE_TTL_SECONDS];
    } catch (Throwable) {
        // Keep database, schema and random-source diagnostics away from callers.
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Exchange one valid code for an explicitly scoped bearer token exactly once.
 *
 * Model locks recheck issuer, current Admin, code, redirect, scope and PKCE
 * before invoking the token factory. A lost successful reply requires a new
 * consent flow; no plaintext token can be recovered from persistent storage.
 *
 * @param string $code Raw one-time authorization code.
 * @param string $clientId Claimed registered public-client identifier.
 * @param string $issuer Independently trusted exact installation origin and mount.
 * @param string $redirectUri Exact callback used at authorization.
 * @param string $verifier Client-provided RFC 7636 PKCE verifier.
 * @return array{access_token:string,expires_in:int,scope:string,refresh_token?:string,refresh_expires_in?:int}|null
 *     Token and consented scope after commit; refresh fields appear only
 *     for explicitly approved offline access; null for an invalid grant.
 * @throws RuntimeException When required schema, randomness or persistence fails.
 */
function app_linking_exchange_code(
    string $code,
    string $clientId,
    string $issuer,
    string $redirectUri,
    string $verifier,
): ?array {
    $codeHash = app_linking_code_hash($code);
    $verifierLength = strlen($verifier);
    if (
        $codeHash === null
        || app_linking_public_client($clientId) === null
        || !app_linking_issuer_valid($issuer)
        || !app_linking_redirect_uri_valid($clientId, $redirectUri)
        || $verifierLength < APP_LINKING_PKCE_VERIFIER_MIN_LENGTH
        || $verifierLength > APP_LINKING_PKCE_VERIFIER_MAX_LENGTH
        || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $verifier) !== 1
    ) {
        return null;
    }
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)),
        '+/', '-_'), '=');
    if (!app_linking_pkce_s256_verify($challenge, $verifier)) {
        return null;
    }

    try {
        app_linking_require_schema(app_linking_issuance_schema_status());
        $reclaimState = app_link_refresh_reclaim_state();
        $pendingScope = app_link_model_pending_code_scope(
            $codeHash, $clientId, $issuer,
        );
        if ($pendingScope === null
            || !app_linking_scope_transport_allowed($issuer, $pendingScope)) {
            return null;
        }
        $refreshReady = false;
        if ($reclaimState === 'ready'
            && app_linking_scope_requests_refresh($pendingScope)) {
            try {
                $refreshReady = schema_inspection_is_available(
                    app_link_refresh_schema_status(),
                );
            } catch (Throwable) {
                // Optional refresh inspection cannot block an otherwise valid
                // transient exchange; offline issuance remains unavailable.
                $refreshReady = false;
            }
        }
        $client = app_linking_public_client($clientId);
        if ($client === null) {
            return null;
        }
        $allowedScopes = array_values(array_filter(
            $client['scopes'],
            static fn(string $scope): bool =>
                $scope === $pendingScope
                && ($refreshReady || !app_linking_scope_requests_refresh($scope))
                && app_linking_scope_allowed($clientId, $scope),
        ));
        // Only the locked pending scope can be exchanged. Every registered
        // offline bundle uses the same atomic refresh issuance owner.
        $refreshScopes = $refreshReady
            && app_linking_scope_requests_refresh($pendingScope)
            ? $allowedScopes : [];
        $familyTtl = app_linking_scope_requests_rolling($pendingScope)
            ? APP_LINK_REFRESH_IDLE_TTL_SECONDS : APP_LINK_REFRESH_FAMILY_TTL_SECONDS;
        $rawToken = null;
        $rawRefreshToken = null;
        $grant = app_link_model_exchange(
            $codeHash,
            $clientId,
            $issuer,
            $redirectUri,
            $challenge,
            $allowedScopes,
            static function () use ($clientId, $pendingScope, &$rawToken): string {
                app_linking_recheck_wide_scope($clientId, $pendingScope);
                $rawToken = app_linking_access_token_generate();
                $hash = app_linking_access_token_hash($rawToken);
                if ($hash === null) {
                    throw new RuntimeException('Invalid generated token.');
                }
                return $hash;
            },
            APP_LINKING_ACCESS_TTL_SECONDS,
            $refreshScopes,
            static function () use (&$rawRefreshToken): string {
                $rawRefreshToken = app_link_refresh_token_generate();
                $hash = app_link_refresh_token_hash($rawRefreshToken);
                if ($hash === null) {
                    throw new RuntimeException('Invalid generated refresh token.');
                }
                return $hash;
            },
            $reclaimState,
            $familyTtl,
            APP_LINK_REFRESH_IDLE_TTL_SECONDS,
            $pendingScope,
        );
        if ($grant === null || !is_string($rawToken)) {
            return null;
        }
        $result = [
            'access_token' => $rawToken,
            'expires_in' => APP_LINKING_ACCESS_TTL_SECONDS,
            'scope' => $grant['scope'],
        ];
        if (isset($grant['refresh_expires_in'])) {
            if (!is_string($rawRefreshToken)) {
                // The transaction has committed; never claim a complete
                // offline connection when its refresh credential is absent.
                throw new RuntimeException('Refresh token is unavailable.');
            }
            $result['refresh_token'] = $rawRefreshToken;
            $result['refresh_expires_in'] = $grant['refresh_expires_in'];
        }
        return $result;
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Resolve a live permitted grant and renew its rolling inactivity window.
 *
 * @param string $token Raw bearer token supplied by the client.
 * @param string $issuer Independently trusted exact installation origin and mount.
 * @return array{grant_id:int,user_id:int,username:string,scope:string}|null
 *     Current Admin identity and exact consented scope, or null for an invalid token.
 * @throws RuntimeException When required schema or persistence is unavailable.
 */
function app_linking_authenticate(string $token, string $issuer): ?array
{
    $hash = app_linking_access_token_hash($token);
    if ($hash === null || !app_linking_issuer_valid($issuer)) {
        return null;
    }
    try {
        app_linking_require_schema(app_linking_authentication_schema_status());
        $identity = app_link_model_lookup_token($hash, $issuer);
        if ($identity === null
            || !app_linking_scope_transport_allowed($issuer, $identity['scope'])
            || !app_linking_scope_allowed(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'])) {
            return null;
        }
        if (app_linking_scope_requests_rolling($identity['scope'])) {
            app_linking_require_schema(app_link_refresh_schema_status());
            if (!app_link_refresh_model_extend_on_access(
                $identity['grant_id'], $identity['user_id'], $hash,
                APP_LINKING_WINAPP_CLIENT_ID, $issuer, $identity['scope'],
                APP_LINK_REFRESH_IDLE_TTL_SECONDS,
            )) {
                return null;
            }
        }
        return $identity;
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Read one fresh bounded page for a bearer explicitly granted gallery listing.
 *
 * Every ordinary Admin currently has access to the physical Admin gallery
 * catalog. The separate scope explicitly grants listing that full catalog,
 * including private and unpublished galleries, but no upload or mutation.
 * The model rechecks role, grant, issuer, token, scope and expiry when reading.
 *
 * @param string $token Raw bearer presented over HTTPS.
 * @param string $issuer Exact configured installation issuer.
 * @param int $afterId Exclusive positive ID cursor, or zero for the first page.
 * @return array{galleries:list<array{id:int,parent_id:?int,title:string}>,next_after:?int}|null
 *     A bounded page, or null for invalid/insufficient authority or cursor.
 * @throws RuntimeException When required schema, row mapping or storage fails.
 */
function app_linking_gallery_page(
    string $token,
    string $issuer,
    int $afterId,
): ?array {
    if ($afterId < 0) {
        return null;
    }
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null || !app_linking_scope_allows_gallery_listing($identity['scope'])) {
        return null;
    }
    try {
        app_linking_require_schema(app_linking_gallery_listing_schema_status());
        $hash = app_linking_access_token_hash($token);
        if ($hash === null) {
            return null;
        }
        $rows = gallery_model_linked_client_page(
            $identity['grant_id'], $identity['user_id'], $hash, $issuer,
            $identity['scope'], $afterId,
            APP_LINKING_GALLERY_PAGE_SIZE + 1,
        );
        if ($rows === null) {
            // Authority may have changed after the initial bearer lookup.
            return null;
        }
        $hasMore = count($rows) > APP_LINKING_GALLERY_PAGE_SIZE;
        if ($hasMore) {
            array_pop($rows);
        }
        $galleries = [];
        foreach ($rows as $row) {
            $galleries[] = app_linking_gallery_identity($row);
        }
        $last = $galleries === [] ? null : $galleries[count($galleries) - 1]['id'];
        return ['galleries' => $galleries, 'next_after' => $hasMore ? $last : null];
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Read one complete, bounded catalogue using current native-list authority.
 *
 * A single authorized model SELECT produces the ordered snapshot. Revision
 * comparison follows a fresh authority check, so a prior cached tree cannot
 * make a revoked or differently scoped grant appear unchanged. The digest
 * includes issuer, account and exact scope, but never a bearer credential.
 *
 * @param string $token Raw bearer presented over the trusted issuer transport.
 * @param string $issuer Exact configured installation issuer and mount.
 * @param string|null $knownRevision Prior lowercase SHA-256 revision from the
 *     same verified account/scope cache, or null to request a complete tree.
 * @return array{version:int,revision:string,unchanged:bool,galleries?:list<array{id:int,parent_id:?int,title:string}>}|null
 *     A complete or unchanged envelope, or null for invalid current authority
 *     or an invalid revision. No partial tree is returned as complete.
 * @throws \OverflowException When row or encoded-byte bounds require paging.
 * @throws RuntimeException When required schema, mapping or storage fails.
 * @throws \JsonException When stored titles cannot be encoded as valid JSON.
 */
function app_linking_gallery_tree(
    string $token,
    string $issuer,
    ?string $knownRevision = null,
): ?array {
    if ($knownRevision !== null
        && preg_match('/\A[a-f0-9]{64}\z/D', $knownRevision) !== 1) {
        return null;
    }
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null
        || !app_linking_scope_allows_gallery_listing($identity['scope'])) {
        return null;
    }
    app_linking_require_schema(app_linking_gallery_listing_schema_status());
    $hash = app_linking_access_token_hash($token);
    if ($hash === null) {
        return null;
    }
    $modelStarted = app_linking_passive_span_start();
    $rows = gallery_model_linked_client_page(
        $identity['grant_id'], $identity['user_id'], $hash, $issuer,
        $identity['scope'], 0, APP_LINKING_GALLERY_TREE_MAX_ROWS + 1,
    );
    app_linking_passive_span_end('gallery_model', $modelStarted);
    if ($rows === null) {
        return null;
    }
    if (count($rows) > APP_LINKING_GALLERY_TREE_MAX_ROWS) {
        throw new \OverflowException('Gallery tree requires paged loading.');
    }
    $galleries = [];
    foreach ($rows as $row) {
        $galleries[] = app_linking_gallery_identity($row);
    }
    $jsonStarted = app_linking_passive_span_start();
    $encodedTree = json_encode([
        'version' => APP_LINKING_GALLERY_TREE_VERSION,
        'issuer' => $issuer,
        'account' => $identity['user_id'],
        'scope' => $identity['scope'],
        'galleries' => $galleries,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    app_linking_passive_span_end('tree_json', $jsonStarted);
    $hashStarted = app_linking_passive_span_start();
    $revision = hash('sha256', $encodedTree);
    app_linking_passive_span_end('tree_hash', $hashStarted);
    $result = [
        'version' => APP_LINKING_GALLERY_TREE_VERSION,
        'revision' => $revision,
        'unchanged' => false,
        'galleries' => $galleries,
    ];
    if (strlen(json_encode(
        $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    )) > APP_LINKING_GALLERY_TREE_MAX_BYTES) {
        throw new \OverflowException('Gallery tree requires paged loading.');
    }
    $current = app_linking_authenticate($token, $issuer);
    if ($current === null
        || $current['grant_id'] !== $identity['grant_id']
        || $current['user_id'] !== $identity['user_id']
        || $current['scope'] !== $identity['scope']) {
        return null;
    }
    if ($knownRevision !== null && hash_equals($revision, $knownRevision)) {
        unset($result['galleries']);
        $result['unchanged'] = true;
    }
    return $result;
}

/**
 * Normalize the stable public identity fields of one authorized gallery row.
 *
 * @param array{id:int|string,parent_id:int|string|null,title:string} $row Authorized database row.
 * @return array{id:int,parent_id:?int,title:string} Physical gallery identity.
 * @throws RuntimeException When an identifier or title is invalid.
 */
function app_linking_gallery_identity(array $row): array
{
    $id = (int) $row['id'];
    $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
    if ($id <= 0 || (string) $id !== (string) $row['id']
        || ($parent !== null && ($parent < 0
            || (string) $parent !== (string) $row['parent_id']))
        || !is_string($row['title'])) {
        throw new RuntimeException('Invalid gallery identity.');
    }
    return [
        'id' => $id,
        'parent_id' => $parent === 0 ? null : $parent,
        'title' => $row['title'],
    ];
}

/**
 * Require the stored fields and revision used by gallery detail reads.
 *
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>}
 *     Three-state readiness for the exact read projection.
 */
function app_linking_gallery_detail_schema_status(): array
{
    $status = app_linking_schema_status(
        'app_linking.gallery_detail',
        ['galleries' => [
            'id', 'title', 'edit_revision', 'visibility', 'access_mode',
            'access_listing', 'folder_path',
        ]],
        ['galleries' => ['PRIMARY' => ['id']]],
        [],
    );
    $status['requirements'][] = schema_inspection_column_exact(
        'galleries', 'edit_revision', 'bigint unsigned', false, null, false, true,
    );
    return schema_inspection_feature(
        'app_linking.gallery_detail', $status['requirements'],
    );
}

/**
 * Read only explicitly authorized stored gallery settings, never inherited state.
 *
 * @param string $token Raw access bearer received over verified HTTPS.
 * @param string $issuer Independently trusted installation issuer and mount.
 * @param int $galleryId Positive server-owned physical gallery identifier.
 * @return array{id:int,title:string,revision:string,visibility:string,access_mode:string,access_listing:string}|null
 *     Validated stored values, or null if the bearer or gallery is unavailable.
 * @throws RuntimeException When schema, data or storage cannot be verified.
 */
function app_linking_gallery_detail(
    string $token,
    string $issuer,
    int $galleryId,
): ?array {
    if ($galleryId <= 0) {
        return null;
    }
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null || !in_array(app_linking_scope_base($identity['scope']), [
        APP_LINKING_READ_SCOPE, APP_LINKING_READ_UPLOAD_SCOPE,
        APP_LINKING_READ_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
        APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
        APP_LINKING_CREATE_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
    ], true)) {
        return null;
    }
    try {
        app_linking_require_schema(app_linking_gallery_detail_schema_status());
        $hash = app_linking_access_token_hash($token);
        if ($hash === null) {
            return null;
        }
        $row = gallery_model_linked_detail(
            $identity['grant_id'], $identity['user_id'], $hash, $issuer,
            $identity['scope'], $galleryId,
        );
        if ($row === null) {
            return null;
        }
        if (app_linking_visibility_scope_allowed($identity['scope'])) {
            // A partial write cannot supply a new edit snapshot. The operator
            // may inspect the retained marker independently of the bearer.
            gallery_visibility_marker_assert_importable($row['folder_path']);
        }
        $id = (int) $row['id'];
        $revisionValue = $row['edit_revision'];
        $revision = (string) $revisionValue;
        if ($id !== $galleryId || (string) $id !== (string) $row['id']
            || !is_string($row['title'])
            || (!is_int($revisionValue) && !is_string($revisionValue))
            || preg_match('/\A[1-9][0-9]{0,19}\z/D', $revision) !== 1
            || !in_array($row['visibility'], ['draft', 'unpublished', 'public', 'private'], true)
            || !in_array($row['access_mode'], ['normal', 'password'], true)
            || !in_array($row['access_listing'], ['listed', 'unlisted'], true)) {
            throw new RuntimeException('Invalid stored gallery detail.');
        }
        return [
            'id' => $id,
            'title' => $row['title'],
            'revision' => $revision,
            'visibility' => $row['visibility'],
            'access_mode' => $row['access_mode'],
            'access_listing' => $row['access_listing'],
        ];
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Resolve an explicitly authorized upload target against current grant state.
 *
 * The upload bundle grants access to all physical galleries of the consenting
 * ordinary Admin, including private and unpublished galleries. Recheck the
 * target immediately before each read or write; no upload-key authority is
 * synthesized and older identity/list-only grants remain restricted.
 *
 * @param string $token Raw native bearer provided over verified HTTPS.
 * @param string $issuer Exact configured installation issuer.
 * @param int $galleryId Positive server-owned physical gallery identifier.
 * @param string|null $watchKey Optional client-generated gallery watcher key.
 * @return array{id:int|string,folder_path:string,title:string,...}|null Current
 *     authorized gallery database row for the internal workflow, or null when
 *     identity, scope, grant or target is no longer valid.
 * @throws RuntimeException When required schema or storage cannot be verified.
 */
function app_linking_upload_target(
    string $token,
    string $issuer,
    int $galleryId,
    ?string $watchKey = null,
): ?array {
    if ($galleryId <= 0) {
        return null;
    }
    $identity = app_linking_authenticate($token, $issuer);
    if ($identity === null
        || !in_array(app_linking_scope_base($identity['scope']), [
            APP_LINKING_UPLOAD_SCOPE, APP_LINKING_READ_UPLOAD_SCOPE,
            APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
            APP_LINKING_UPLOAD_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
        ], true)) {
        return null;
    }
    try {
        app_linking_require_schema(app_linking_upload_schema_status());
        $hash = app_linking_access_token_hash($token);
        if ($hash === null) {
            return null;
        }
        $target = gallery_model_linked_upload_target(
            $identity['grant_id'], $identity['user_id'], $hash, $issuer,
            $identity['scope'], $galleryId,
        );
        if ($target === null || $watchKey === null) {
            return $target;
        }
        if (preg_match('/\Apgw_[a-f0-9]{64}\z/D', $watchKey) !== 1) {
            return null;
        }
        app_linking_require_schema(app_linking_watch_key_schema_status());
        return app_link_model_watch_key_valid(
            $identity['grant_id'], $identity['user_id'], $hash, $issuer,
            $identity['scope'], $galleryId, hash('sha256', $watchKey),
        ) ? $target : null;
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Open or replace the exact gallery watcher generation after explicit client action.
 * @param array{grant_id:int,user_id:int,scope:string} $identity Authenticated grant identity.
 * @param string $token Current bearer credential.
 * @param string $issuer Exact configured installation issuer.
 * @param int $galleryId Selected physical gallery.
 * @param string $keyHash Lowercase SHA-256 digest of the client key.
 * @return bool True when the current grant still owns the authorized gallery.
 */
function app_linking_watch_key_open(
    array $identity, string $token, string $issuer, int $galleryId, string $keyHash,
): bool {
    app_linking_require_schema(app_linking_upload_schema_status());
    app_linking_require_schema(app_linking_watch_key_schema_status());
    $target = app_linking_upload_target($token, $issuer, $galleryId);
    $current = app_linking_authenticate($token, $issuer);
    if ($target === null || $current === null
        || $current['grant_id'] !== $identity['grant_id']) {
        return false;
    }
    $tokenHash = app_linking_access_token_hash($token);
    if ($tokenHash === null) {
        return false;
    }
    return app_link_model_watch_key_open(
        $identity['grant_id'], $identity['user_id'], $tokenHash, $issuer,
        $identity['scope'], $galleryId, $keyHash,
    );
}

/**
 * Close only the exact watcher generation supplied by this client.
 * @param int $grantId Current authenticated grant identifier.
 * @param int $galleryId Selected gallery identifier.
 * @param string $keyHash Client generation digest.
 * @return void Removal is idempotent and cannot revoke a replacement hash.
 */
function app_linking_watch_key_close(int $grantId, int $galleryId, string $keyHash): void
{
    app_linking_require_schema(app_linking_watch_key_schema_status());
    app_link_model_watch_key_close($grantId, $galleryId, $keyHash);
}

/**
 * Recheck the existing upload capability against current settings storage.
 *
 * The ordinary capability owner is request-cached and can use its default on
 * persistence errors. Delegated uploads additionally require a fresh model
 * read, so an observed OFF or unknown settings storage cannot admit a write.
 * A confirmed absent setting retains the existing capability default.
 *
 * @return bool True only when the persisted setting and capability both allow
 *     upload; false when disabled.
 * @throws RuntimeException When settings schema or persistence is unavailable.
 */
function app_linking_upload_feature_enabled(): bool
{
    try {
        app_linking_require_schema(mutation_schema_table_columns_status(
            'app_linking.upload_capability',
            'app_settings',
            ['setting_key', 'setting_value'],
        ));
        $value = app_settings_model_get(feature_flag_setting_key('upload_api'));
        return $value !== '0' && feature_capability_effective_enabled('upload_api');
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * List a bounded set of links belonging to one verified database account.
 *
 * The caller must establish the owner's session, ordinary account authority,
 * and support-session restrictions before passing true. The model filters by
 * the exact live owner across issuers sharing the database; this projection
 * never returns credential hashes, codes, tokens or callback details.
 *
 * @param int $userId Authenticated owner's stable user identifier.
 * @param bool $authorizedOwner Whether the transport verified owner authority.
 * @return array{links:list<array{id:int,issuer:string,client_id:string,scope:string,created_at:string,revoked_at:?string,access_issued_at:?string,access_expires_at:?string}>,truncated:bool}|null
 *     Bounded owner links and a truncation flag, or null for invalid authority.
 * @throws RuntimeException When required schema or persistence is unavailable.
 */
function app_linking_list_owner(
    int $userId,
    bool $authorizedOwner,
): ?array {
    if (!$authorizedOwner || $userId <= 0) {
        return null;
    }
    try {
        app_linking_require_schema(app_linking_owner_listing_schema_status());
        $result = app_link_model_list_owner($userId);
        $links = [];
        foreach ($result['links'] as $row) {
            // Keep this explicit projection even if model rows grow later.
            $links[] = [
                'id' => $row['id'],
                'issuer' => $row['issuer'],
                'client_id' => $row['client_id'],
                'scope' => $row['scope'],
                'created_at' => $row['created_at'],
                'revoked_at' => $row['revoked_at'],
                'access_issued_at' => $row['access_issued_at'],
                'access_expires_at' => $row['access_expires_at'],
            ];
        }
        return ['links' => $links, 'truncated' => $result['truncated']];
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Revoke an owned link after the transport verifies the authenticated owner.
 *
 * The transport owns session, CSRF and support-session restrictions; pass true
 * only after those checks and ownership of the selected grant are established.
 *
 * @param int $userId Authenticated owner's stable user identifier.
 * @param int $grantId Grant identifier explicitly selected for revocation.
 * @param bool $authorizedOwner Whether the transport verified owner authority.
 * @return bool True for a newly revoked matching grant; false for refusal.
 * @throws RuntimeException When required schema or persistence is unavailable.
 */
function app_linking_revoke_owner(
    int $userId,
    int $grantId,
    bool $authorizedOwner,
): bool {
    if (!$authorizedOwner || $userId <= 0 || $grantId <= 0) {
        return false;
    }
    try {
        app_linking_require_schema(app_linking_owner_revocation_schema_status());
        return app_link_model_revoke($userId, $grantId, null);
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

/**
 * Revoke the link identified by an opaque bearer credential.
 *
 * @param string $token Raw bearer token presented by its holder.
 * @param string $issuer Independently trusted exact installation origin and mount.
 * @return bool True for a newly revoked matching grant; false for refusal.
 * @throws RuntimeException When required schema or persistence is unavailable.
 */
function app_linking_revoke_token(string $token, string $issuer): bool
{
    $hash = app_linking_access_token_hash($token);
    if ($hash === null || !app_linking_issuer_valid($issuer)) {
        return false;
    }
    try {
        app_linking_require_schema(app_linking_token_revocation_schema_status());
        return app_link_model_revoke_token($hash, $issuer);
    } catch (Throwable) {
        throw new RuntimeException('App linking is unavailable.');
    }
}

require_once __DIR__ . '/app_linking/search.php';
