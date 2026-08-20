<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_collection_sharing_phase30_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 3.0 unlisted read-only viewer collection sharing boundary.
 *
 * Responsibilities:
 *   - Verify one active hashed bearer share is created/replaced/revoked through owner-only POST services
 *   - Verify raw share exchange creates only a bounded collection-specific session grant and redirects cleanly
 *   - Verify every clean shared view revalidates durable share/account state and live source authorization
 *   - Verify Admin/viewer/gallery authority domains remain independent from collection-share authority
 *   - Verify suspension/deletion/master-feature/schema-failure behavior remains fail-closed and scoped
 *   - Verify runtime imports and cryptographic/session helper contracts beyond PHP syntax lint
 *
 * Last Updated:
 *   2026-08-19
 */

declare(strict_types=1);

/** Assert one Phase 3.0 regression condition. */
function viewer_phase30_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/** Extract one named function body from PHP source for focused contract checks. */
function viewer_phase30_function_source(string $source, string $functionName): string
{
    $needle = 'function ' . $functionName . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        throw new RuntimeException('Function not found: ' . $functionName);
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        throw new RuntimeException('Function body not found: ' . $functionName);
    }
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('Unterminated function body: ' . $functionName);
}

/** Verify every project function import in one module resolves to a real declaration. */
function viewer_phase30_assert_function_imports_resolve(string $root, string $modulePath): void
{
    $source = (string) file_get_contents($root . '/' . $modulePath);
    preg_match_all('/^use function ([A-Za-z0-9_\\]+)\\([A-Za-z0-9_]+);$/m', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $namespace = (string) $match[1];
        $functionName = (string) $match[2];
        $resolved = false;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }
            $candidate = (string) file_get_contents($fileInfo->getPathname());
            if (!preg_match('/namespace\s+' . preg_quote($namespace, '/') . '\s*;/', $candidate)) {
                continue;
            }
            if (preg_match('/function\s+' . preg_quote($functionName, '/') . '\s*\(/', $candidate)) {
                $resolved = true;
                break;
            }
        }
        viewer_phase30_assert($resolved, 'Imported function must resolve: ' . $modulePath . ' -> ' . $namespace . '\\' . $functionName);
    }
}

$root = dirname(__DIR__);
$servicePath = 'app/services/viewer_collection_shares.php';
$controllerPath = 'app/controllers/viewer_collection_shares.php';
$service = (string) file_get_contents($root . '/' . $servicePath);
$controller = (string) file_get_contents($root . '/' . $controllerPath);
$collectionsService = (string) file_get_contents($root . '/app/services/viewer_collections.php');
$collectionsController = (string) file_get_contents($root . '/app/controllers/viewer_collections.php');
$contentFoundations = (string) file_get_contents($root . '/app/services/viewer_content_foundations.php');
$accountsService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
$adminAccountsService = (string) file_get_contents($root . '/app/services/viewer_admin_accounts.php');
$lifecycleService = (string) file_get_contents($root . '/app/services/viewer_lifecycle.php');
$rateLimits = (string) file_get_contents($root . '/app/services/viewer_rate_limits.php');
$featureFlags = (string) file_get_contents($root . '/app/services/feature_flags.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
$urlHelpers = (string) file_get_contents($root . '/app/helpers_request.php');
$servicesBootstrap = (string) file_get_contents($root . '/app/services.php');
$controllersBootstrap = (string) file_get_contents($root . '/app/controllers.php');
$publicMedia = (string) file_get_contents($root . '/app/controllers/public_media.php');
$galleryAccess = (string) file_get_contents($root . '/app/services/gallery_access.php');
$migration = (string) file_get_contents($root . '/database/migrations/202608180001_viewer_security_foundations.php');
$css = (string) file_get_contents($root . '/public/assets/styles/public-shared.css');

viewer_phase30_assert($service !== '' && $controller !== '', 'Dedicated Phase 3.0 service and controller must exist.');
viewer_phase30_assert(str_contains($servicesBootstrap, "'/services/viewer_collection_shares.php'"), 'Phase 3 service must load through app/services.php.');
viewer_phase30_assert(str_contains($controllersBootstrap, "'/controllers/viewer_collection_shares.php'"), 'Phase 3 controller must load through app/controllers.php.');
viewer_phase30_assert(str_contains($collectionsController, 'render_viewer_collection_share_owner_section($viewer, $collectionId)'), 'Sharing must integrate into the existing private collection detail page.');
viewer_phase30_assert(str_contains($css, '.viewer-collection-share-url') && str_contains($css, '.viewer-collection-share-actions'), 'Share management UI must use scoped existing stylesheet rules.');

// Runtime symbol audit for the new controller and any explicit project imports in the new service.
viewer_phase30_assert_function_imports_resolve($root, $servicePath);
viewer_phase30_assert_function_imports_resolve($root, $controllerPath);
foreach ([
    'viewer_accounts_enabled',
    'viewer_rate_limit_consume',
    'viewer_collection_lock_mutation_account',
    'viewer_collection_lock_owned',
    'security_opaque_token_generate',
    'security_authority_token_hash',
    'viewer_account_can_authenticate',
    'viewer_content_quota_config',
    'viewer_security_event_record',
] as $dependency) {
    $found = false;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/services', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
            $candidate = (string) file_get_contents($fileInfo->getPathname());
            if (preg_match('/function\s+' . preg_quote($dependency, '/') . '\s*\(/', $candidate)) {
                $found = true;
                break;
            }
        }
    }
    viewer_phase30_assert($found, 'Phase 3 service dependency must resolve: ' . $dependency);
}

// Dormant schema is reused as-is. No plaintext/display-copy field is introduced.
viewer_phase30_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_collection_share_tokens'), 'Existing dormant share-token table must be reused.');
foreach (['viewer_collection_id', 'created_by_viewer_account_id', 'token_hash', 'created_at', 'last_used_at', 'expires_at', 'revoked_at'] as $column) {
    viewer_phase30_assert(str_contains($migration, $column), 'Dormant share schema is missing expected field: ' . $column);
}
viewer_phase30_assert(str_contains($migration, 'viewer_collection_share_token_hash_unique') && str_contains($migration, 'viewer_collection_share_collection_state_index'), 'Dormant token-hash and collection/state indexes must remain available.');
viewer_phase30_assert(!preg_match('/\b(plaintext_token|token_plaintext|share_url|encrypted_token|display_token)\b/i', $migration), 'Share schema must not gain plaintext/reversible secret storage.');
$shareSchema = viewer_phase30_function_source($service, 'viewer_collection_shares_schema_status');
$privateSchema = viewer_phase30_function_source($collectionsService, 'viewer_collections_schema_status');
viewer_phase30_assert(str_contains($shareSchema, "schema_inspection_table('viewer_collection_share_tokens')"), 'Sharing must have its own schema capability boundary.');
viewer_phase30_assert(!str_contains($privateSchema, 'viewer_collection_share_tokens'), 'Private collection schema availability must not depend on share storage.');

// Exact Phase 3 route surface. All route ids are viewer_* so the existing master feature wrapper owns them.
$phase30Routes = [
    'viewer_collection_share_replace',
    'viewer_collection_share_revoke',
    'viewer_collection_share_exchange',
    'viewer_collection_shared',
];
foreach ($phase30Routes as $route) {
    viewer_phase30_assert(substr_count($dispatch, "'{$route}' =>") === 1, 'Missing or duplicated Phase 3 route: ' . $route);
    viewer_phase30_assert(str_starts_with($route, 'viewer_'), 'Phase 3 route must remain under the viewer master feature namespace: ' . $route);
}
$featureOwner = viewer_phase30_function_source($featureFlags, 'feature_flag_for_route');
viewer_phase30_assert(str_contains($featureOwner, 'str_starts_with($page, \'viewer_\')') && str_contains($featureOwner, "return 'viewer_accounts'"), 'All Phase 3 viewer_* routes must be owned by the global Viewer Accounts feature.');
viewer_phase30_assert(str_contains($featureFlags, "'viewer_accounts' =>") && str_contains($featureFlags, "'default_enabled' => false"), 'Viewer Accounts master feature must remain default OFF.');
$disabledRoute = viewer_phase30_function_source($featureFlags, 'feature_flag_render_disabled_route');
viewer_phase30_assert(str_contains($disabledRoute, 'if ($featureKey === \'viewer_accounts\')') && str_contains($disabledRoute, 'http_response_code(404)'), 'Anonymous Viewer Accounts routes must remain generic not-found while the master feature is OFF.');
viewer_phase30_assert(str_contains($routing, "'viewer_collection_share_exchange'") && str_contains($routing, "'viewer_collection_shared'"), 'Clean exchange/shared routes must be parsed by the existing router.');
viewer_phase30_assert(str_contains($urlHelpers, "'viewer_collection_share_exchange'") && str_contains($urlHelpers, "'viewer_collection_shared'"), 'Phase 3 URLs must be built through url_for clean-route support.');
viewer_phase30_assert(!str_contains($controller, 'localhost') && !str_contains($controller, 'Galerie/index.php'), 'Phase 3 controller must not hard-code installation paths.');

// Owner mutation HTTP integrity.
foreach (['cms_viewer_collection_share_replace', 'cms_viewer_collection_share_revoke'] as $functionName) {
    $source = viewer_phase30_function_source($controller, $functionName);
    viewer_phase30_assert(str_contains($source, 'viewer_collection_require_viewer()'), $functionName . ' must require current viewer authority.');
    viewer_phase30_assert(str_contains($source, "request_method() !== 'POST'"), $functionName . ' must be POST-only.');
    viewer_phase30_assert(str_contains($source, 'viewer_verify_csrf_or_render_error()'), $functionName . ' must use Viewer CSRF.');
    viewer_phase30_assert(str_contains($source, 'viewer_collection_positive_id('), $functionName . ' must reject invalid/bounded collection ids through the canonical parser.');
    viewer_phase30_assert(!str_contains($source, 'admin_csrf') && !str_contains($source, 'current_user('), $functionName . ' must not accept Admin identity/CSRF as owner authority.');
}
$ownerSection = viewer_phase30_function_source($controller, 'render_viewer_collection_share_owner_section');
viewer_phase30_assert(str_contains($ownerSection, 'viewer_csrf_token()'), 'Owner share forms must emit Viewer CSRF.');
viewer_phase30_assert(str_contains($ownerSection, 'readonly') && str_contains($ownerSection, 'viewer_collection_share_secret_flash_key($collectionId)'), 'New share secret must be readonly and collection-scoped show-once state.');
viewer_phase30_assert(str_contains($ownerSection, 'Replace this share link?') && str_contains($ownerSection, 'Revoke this share link?'), 'Replace/revoke owner actions must use simple confirmations.');

// Share create/replace transaction, ownership, stale-session, must-change-password, and rate-limit boundary.
$replace = viewer_phase30_function_source($service, 'viewer_collection_share_replace');
viewer_phase30_assert(str_contains($replace, "viewer_rate_limit_consume('viewer_share_create_account'"), 'Create/replace must reuse the existing viewer_share_create_account limiter.');
viewer_phase30_assert(str_contains($rateLimits, "'viewer_share_create_account'"), 'Existing share-create limiter policy must remain defined.');
viewer_phase30_assert(str_contains($replace, 'beginTransaction()') && str_contains($replace, 'commit()') && str_contains($replace, 'rollBack()'), 'Create/replace must be transactional.');
viewer_phase30_assert(strpos($replace, 'viewer_collection_lock_mutation_account') < strpos($replace, 'viewer_collection_lock_owned') && strpos($replace, 'viewer_collection_lock_owned') < strpos($replace, 'FOR UPDATE'), 'Create/replace lock order must be account -> collection -> share rows.');
viewer_phase30_assert(str_contains($replace, 'WHERE viewer_collection_id = ? AND revoked_at IS NULL ORDER BY id ASC FOR UPDATE'), 'Create/replace must lock all unrevoked share rows for the collection.');
viewer_phase30_assert(str_contains($replace, 'UPDATE viewer_collection_share_tokens SET revoked_at = ?') && str_contains($replace, 'INSERT INTO viewer_collection_share_tokens'), 'Replacement must revoke previous unrevoked rows before creating new authority.');
viewer_phase30_assert(str_contains($replace, 'security_opaque_token_generate(32)') && str_contains($replace, 'security_authority_token_hash($token)'), 'Share token must use existing 32-byte opaque token and authority hash primitives.');
viewer_phase30_assert(str_contains($replace, '(viewer_collection_id, created_by_viewer_account_id, token_hash, created_at, expires_at)'), 'Only token_hash, not plaintext token, may be persisted.');
viewer_phase30_assert(!preg_match('/INSERT INTO viewer_collection_share_tokens[^;]*(?:\btoken\b)(?!_hash)/is', $replace), 'Share INSERT must never persist a plaintext token column.');
viewer_phase30_assert(str_contains($replace, 'viewer_collection_share_lifetime_seconds()'), 'Share creation must use the fixed Phase 3 lifetime.');
$accountLock = viewer_phase30_function_source($collectionsService, 'viewer_collection_lock_mutation_account');
viewer_phase30_assert(str_contains($accountLock, 'must_change_password') && str_contains($accountLock, 'viewer_account_can_mutate_content($account)') && str_contains($accountLock, 'security_version\'] ?? 0) !== $expectedSecurityVersion'), 'Transaction account lock must revalidate must_change_password and security_version.');

// Revoke remains easy, owner-scoped, transactional, and does not touch collections/media/favourites.
$revoke = viewer_phase30_function_source($service, 'viewer_collection_share_revoke');
viewer_phase30_assert(!str_contains($revoke, 'viewer_rate_limit_consume'), 'Revocation must not be rate-limited.');
viewer_phase30_assert(str_contains($revoke, 'viewer_collection_lock_mutation_account') && str_contains($revoke, 'viewer_collection_lock_owned') && str_contains($revoke, 'FOR UPDATE'), 'Revocation must revalidate owner/account state transactionally.');
viewer_phase30_assert(str_contains($revoke, 'UPDATE viewer_collection_share_tokens SET revoked_at = ?'), 'Revocation must mark share authority revoked.');
viewer_phase30_assert(!preg_match('/DELETE\s+FROM\s+(viewer_collections|viewer_collection_items|viewer_favourites|images|galleries)/i', $revoke), 'Revocation must not delete collection state, favourites, or source media.');

// Raw token exchange is scanner-safe, strict before DB access, and establishes no identity/gallery authority.
$exchange = viewer_phase30_function_source($service, 'viewer_collection_share_exchange');
$syntaxValidator = viewer_phase30_function_source($service, 'viewer_collection_share_token_syntax_valid');
viewer_phase30_assert(str_contains($syntaxValidator, 'strlen($token) === 43') && str_contains($syntaxValidator, '[A-Za-z0-9_-]{43}'), 'Raw token syntax must be strictly bounded to canonical 32-byte base64url encoding.');
viewer_phase30_assert(strpos($exchange, 'viewer_collection_share_token_syntax_valid($token)') < strpos($exchange, 'viewer_collection_shares_storage_available()') && strpos($exchange, 'viewer_collection_shares_storage_available()') < strpos($exchange, 'security_authority_token_hash($token)') && strpos($exchange, 'security_authority_token_hash($token)') < strpos($exchange, 'prepare('), 'Malformed tokens must be rejected before schema/DB lookup.');
viewer_phase30_assert(str_contains($exchange, 'token_hash = ?') && str_contains($exchange, 'revoked_at') && str_contains($exchange, 'viewer_collection_share_expiry_active'), 'Exchange must revalidate the exact durable share and its revoke/expiry state.');
viewer_phase30_assert(str_contains($exchange, 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE') && str_contains($exchange, 'viewer_account_can_authenticate($account)'), 'Exchange must reject missing/suspended/disabled owners.');
viewer_phase30_assert(str_contains($exchange, 'FROM viewer_collections') && str_contains($exchange, 'viewer_account_id = ? LIMIT 1 FOR UPDATE'), 'Exchange must verify collection still exists under the share owner.');
viewer_phase30_assert(str_contains($exchange, 'session_regenerate_id(true)'), 'Bearer capability exchange must rotate the PHP session id.');
viewer_phase30_assert(str_contains($exchange, 'viewer_collection_share_session_grant_store('), 'Exchange must mint only a narrow collection session grant.');
viewer_phase30_assert(str_contains($exchange, 'last_used_at') && str_contains($exchange, 'operational telemetry'), 'last_used_at must remain best-effort operational metadata.');
foreach (['viewer_session_establish', 'viewer_user_id', "\$_SESSION['user_id']", 'gallery_access_grant', 'gallery_share', 'session_destroy('] as $forbidden) {
    viewer_phase30_assert(!str_contains($exchange, $forbidden), 'Token exchange must not establish broader authority or destroy unrelated session state: ' . $forbidden);
}
$exchangeController = viewer_phase30_function_source($controller, 'cms_viewer_collection_share_exchange');
viewer_phase30_assert(str_contains($exchangeController, "request_method() !== 'GET'"), 'Raw bearer exchange must be GET.');
viewer_phase30_assert(str_contains($exchangeController, "url_for('viewer_collection_shared'") && !str_contains($exchangeController, "'token' =>"), 'Successful exchange redirect target must be token-free.');
viewer_phase30_assert(!str_contains($exchange, 'DELETE FROM viewer_collection_share_tokens') && !preg_match('/UPDATE viewer_collection_share_tokens SET revoked_at/', $exchange), 'Scanner/human GET exchange must not consume or revoke the reusable link.');

// Strict secret-bearing and clean-page response headers.
$publicHeaders = viewer_phase30_function_source($controller, 'viewer_collection_share_public_headers');
viewer_phase30_assert(str_contains($publicHeaders, 'no-store') && str_contains($publicHeaders, 'no-referrer') && str_contains($publicHeaders, 'noindex, nofollow'), 'Share exchange/shared pages must emit no-store, no-referrer, and noindex/nofollow headers.');
viewer_phase30_assert(str_contains($exchangeController, 'viewer_collection_share_public_headers()'), 'Raw exchange must emit strict secret-route headers before validation.');
$sharedController = viewer_phase30_function_source($controller, 'cms_viewer_collection_shared');
viewer_phase30_assert(str_contains($sharedController, 'viewer_collection_share_public_headers()') && str_contains($sharedController, '<meta name="robots" content="noindex,nofollow">'), 'Clean shared page must remain no-store/noindex defense in depth.');
viewer_phase30_assert(!str_contains($exchangeController, 'render_header(') && !str_contains($exchangeController, '<script') && !str_contains($exchangeController, 'analytics'), 'Raw token endpoint must not render content/third parties before redirect.');

// Session grants are isolated, token-free, bounded, deduplicated, and revalidated on every clean view.
viewer_phase30_assert(str_contains($service, "const VIEWER_COLLECTION_SHARE_SESSION_NAMESPACE = 'viewer_collection_share_grants'"), 'Session share grant must use a dedicated namespace.');
viewer_phase30_assert(str_contains($service, 'const VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS = 16'), 'Session grants must have a small hard cap.');
$prune = viewer_phase30_function_source($service, 'viewer_collection_share_session_grants_prune');
$store = viewer_phase30_function_source($service, 'viewer_collection_share_session_grant_store');
viewer_phase30_assert(str_contains($prune, 'array_slice($grants, -VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS)') && str_contains($store, 'array_slice($filtered, -VIEWER_COLLECTION_SHARE_SESSION_MAX_GRANTS)'), 'Attacker-controlled exchanges must not grow the PHP session without bound.');
viewer_phase30_assert(str_contains($store, "'share_id'") && str_contains($store, "'collection_id'") && str_contains($store, "'expires_at'") && !str_contains($store, "'token'"), 'Long-lived collection share session grants must never store the raw bearer token.');
$authorize = viewer_phase30_function_source($service, 'viewer_collection_share_session_authorize');
viewer_phase30_assert(str_contains($authorize, 'WHERE vcs.id = ? AND vcs.viewer_collection_id = ?') && str_contains($authorize, 'vcs.revoked_at') && str_contains($authorize, 'viewer_collection_share_expiry_active') && str_contains($authorize, 'viewer_account_can_authenticate($row)'), 'Every clean shared view must revalidate durable share, expiry, collection owner, and active account state.');
viewer_phase30_assert(str_contains($authorize, 'viewer_collection_share_session_grant_remove('), 'Invalid durable share state must remove the stale local grant reference.');
$sharedRead = viewer_phase30_function_source($service, 'viewer_collection_shared_read');
viewer_phase30_assert(strpos($sharedRead, 'viewer_collection_share_session_authorize($collectionId)') < strpos($sharedRead, 'SELECT id, title, created_at, updated_at FROM viewer_collections'), 'Collection metadata must not load before matching session-grant revalidation.');
viewer_phase30_assert(str_contains($sharedRead, 'SELECT image_id, position, created_at FROM viewer_collection_items') && !str_contains($sharedRead, 'filename') && !str_contains($sharedRead, 'relative_path'), 'Shared read model must return references only, not source metadata or paths.');

// Live source ACL intersection is the existing no-Admin-bypass recipient policy.
viewer_phase30_assert(str_contains($sharedController, 'viewer_source_images_resolve_authorized('), 'Shared page must resolve references through the canonical live source authorization helper.');
$sourceResolver = viewer_phase30_function_source($contentFoundations, 'viewer_source_images_resolve_authorized');
viewer_phase30_assert(str_contains($sourceResolver, 'visitor_can_access_gallery_without_admin_bypass($gallery)') && str_contains($sourceResolver, 'visitor_can_access_nsfw_content_without_admin_bypass()'), 'Shared recipient rendering must explicitly exclude Admin gallery/NSFW bypass.');
viewer_phase30_assert(str_contains($sourceResolver, "visibility = ?") && str_contains($sourceResolver, "['public']"), 'Shared rendering must require current public image visibility before source authorization.');
viewer_phase30_assert(!str_contains($sourceResolver, 'current_user()'), 'Canonical shared source resolver must not consult the Admin principal.');
viewer_phase30_assert(str_contains($sharedController, 'e((string) $collection[\'title\'])'), 'Malicious collection titles must be HTML-escaped on the recipient page.');
viewer_phase30_assert(!str_contains($sharedController, 'viewer email') && !str_contains($sharedController, "['email']") && !str_contains($sharedController, 'token_hash') && !str_contains($sharedController, 'relative_path') && !str_contains($sharedController, 'filename'), 'Recipient page must not expose owner identity, token hash, or hidden path/filename metadata.');
viewer_phase30_assert(str_contains($sharedController, '$hiddenCount') && str_contains($sharedController, 'some_unavailable'), 'Denied items may only produce generic unavailability feedback.');
viewer_phase30_assert(!str_contains($sharedController, '<form') && !str_contains($sharedController, 'viewer_favourite') && !str_contains($sharedController, 'render_viewer_collection_add_control_html'), 'Shared collection page must remain read-only and must not expose owner/favourite mutation controls.');
viewer_phase30_assert(!str_contains($publicMedia, 'viewer_collection_share') && !str_contains($publicMedia, 'viewer_collection_share_grants'), 'Direct media routes must not treat collection-share authority as source-media authorization.');
viewer_phase30_assert(!str_contains($galleryAccess, 'viewer_collection_share'), 'Existing gallery access policy must not accept collection-share authority.');

// Lifecycle invariants: suspension revokes, restore never resurrects, sign-out-all preserves shares, deletion is scoped.
$transition = viewer_phase30_function_source($accountsService, 'viewer_account_transition_status');
viewer_phase30_assert(str_contains($transition, 'UPDATE viewer_collection_share_tokens SET revoked_at = ?'), 'Suspension/disable transition must revoke collection-share capabilities.');
viewer_phase30_assert(!preg_match('/UPDATE viewer_collection_share_tokens SET revoked_at\s*=\s*NULL/i', $transition), 'Restoration must never clear share revoked_at.');
$logoutAll = viewer_phase30_function_source($accountsService, 'viewer_session_revoke_all');
viewer_phase30_assert(!str_contains($logoutAll, 'viewer_collection_share_tokens'), 'Sign out everywhere must not revoke active collection shares.');
$accountDelete = viewer_phase30_function_source($lifecycleService, 'viewer_account_delete');
viewer_phase30_assert(str_contains($accountDelete, 'UPDATE viewer_collection_share_tokens SET revoked_at = ?') && str_contains($accountDelete, 'DELETE FROM viewer_accounts'), 'Viewer account deletion must invalidate shares then use existing account/FK cleanup.');
$adminDelete = viewer_phase30_function_source($adminAccountsService, 'viewer_admin_account_delete');
viewer_phase30_assert(str_contains($adminDelete, 'UPDATE viewer_collection_share_tokens SET revoked_at = ?') && str_contains($adminDelete, 'DELETE FROM viewer_accounts'), 'Admin viewer deletion must invalidate shares then use existing account/FK cleanup.');
viewer_phase30_assert(str_contains($migration, 'viewer_collection_items_collection_foreign') && str_contains($migration, 'ON DELETE CASCADE') && str_contains($migration, 'viewer_collection_share_collection_foreign'), 'Collection deletion must cascade item/share references through existing FKs.');
viewer_phase30_assert(!preg_match('/FOREIGN KEY \(viewer_collection_id\)[^\n]*REFERENCES images/i', $migration), 'Collection share deletion must never cascade into source images.');

// Product scope remains narrow and unlisted.
foreach (['viewer_profile', 'viewer_public_collection_index', 'viewer_shared_with_me', 'viewer_signup', 'viewer_upload', 'viewer_comment', 'viewer_totp', 'viewer_oidc', 'viewer_passkey', 'viewer_impersonate'] as $forbiddenRoute) {
    viewer_phase30_assert(!str_contains($dispatch, "'{$forbiddenRoute}' =>"), 'Out-of-scope route must not be introduced: ' . $forbiddenRoute);
}
foreach (['sitemap', 'public_search', 'Smart Gallery'] as $discoverySurface) {
    viewer_phase30_assert(!str_contains($controller, $discoverySurface), 'Phase 3 controller must not add public discovery integration: ' . $discoverySurface);
}

// Pure helper behavior: 256-bit tokens, distinct values, fixed 30 days, strict syntax, and bounded token-free grants.
require_once $root . '/app/services/security_tokens.php';
require_once $root . '/' . $servicePath;
$tokenA = \Gallery\Services\security_opaque_token_generate(32);
$tokenB = \Gallery\Services\security_opaque_token_generate(32);
viewer_phase30_assert(strlen($tokenA) === 43 && strlen($tokenB) === 43 && $tokenA !== $tokenB, '32-byte share tokens must be canonical 43-char base64url values and differ across generations.');
viewer_phase30_assert(\Gallery\Services\viewer_collection_share_token_syntax_valid($tokenA), 'Generated token must pass strict collection-share syntax validation.');
viewer_phase30_assert(!\Gallery\Services\viewer_collection_share_token_syntax_valid('short') && !\Gallery\Services\viewer_collection_share_token_syntax_valid(str_repeat('a', 44)), 'Malformed/wrong-length share token must fail before lookup.');
viewer_phase30_assert(strlen(\Gallery\Services\security_authority_token_hash($tokenA)) === 64, 'Authority hash must be SHA-256 hex length.');
viewer_phase30_assert(\Gallery\Services\viewer_collection_share_lifetime_seconds() === 30 * 86400, 'Phase 3.0 share lifetime must be exactly 30 days.');
$_SESSION = [];
$future = date('Y-m-d H:i:s', time() + 86400);
for ($i = 1; $i <= 25; $i++) {
    viewer_phase30_assert(\Gallery\Services\viewer_collection_share_session_grant_store($i, 1000 + $i, $future), 'Valid narrow grant must store.');
}
$grants = $_SESSION[\Gallery\Services\viewer_collection_share_session_namespace_key()] ?? null;
viewer_phase30_assert(is_array($grants) && count($grants) === 16, 'Session share-grant namespace must enforce the hard 16-grant cap.');
foreach ($grants as $grant) {
    viewer_phase30_assert(array_keys($grant) === ['share_id', 'collection_id', 'expires_at', 'granted_at'], 'Session share grant must contain only narrow identifiers/expiry metadata.');
    viewer_phase30_assert(!array_key_exists('token', $grant) && !array_key_exists('viewer_account_id', $grant) && !array_key_exists('user_id', $grant), 'Session share grant must contain no bearer secret or principal identity.');
}

// Maintained language catalogs must expose the complete new visible string set with key parity.
$requiredTranslationKeys = [
    'viewer.collection_share.title',
    'viewer.collection_share.unavailable',
    'viewer.collection_share.created',
    'viewer.collection_share.shown_once',
    'viewer.collection_share.help',
    'viewer.collection_share.create',
    'viewer.collection_share.active',
    'viewer.collection_share.expires_at',
    'viewer.collection_share.replace',
    'viewer.collection_share.revoke',
    'viewer.collection_share.shared_label',
    'viewer.collection_share.some_unavailable',
];
$languageKeys = null;
foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($requiredTranslationKeys as $key) {
        viewer_phase30_assert(isset($catalog[$key]) && trim((string) $catalog[$key]) !== '', 'Missing Phase 3 translation ' . $key . ' in ' . $language . '.');
    }
    $keys = array_keys($catalog);
    if ($languageKeys === null) {
        $languageKeys = $keys;
    } else {
        viewer_phase30_assert($keys === $languageKeys, 'Selectable language keys must remain aligned: ' . $language);
    }
}

fwrite(STDOUT, "Viewer collection sharing Phase 3.0 regression checks passed\n");
