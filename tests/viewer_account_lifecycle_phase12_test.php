<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_account_lifecycle_phase12_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the narrow Phase 1.2 viewer account lifecycle HTTP boundary.
 *
 * Responsibilities:
 *   - Verify route, method, CSRF, recent-reauthentication, and no-store wiring
 *   - Verify password, staged email-change, and deletion controllers delegate to Phase 0.7 services
 *   - Protect administrator/viewer principal separation and bounded reauthentication return destinations
 *   - Verify scanner-safe email-change GET behavior and tokenless final POST confirmation
 *   - Prove later open signup stays isolated from lifecycle logic while collections, sharing, profiles, uploads, and optional authentication remain absent
 *   - Audit imported controller symbols against actual repository definitions
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

/**
 * Throw when one Phase 1.2 expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function viewer_phase12_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Extract one named function declaration/body for focused static assertions.
 *
 * @param string $source Complete PHP source.
 * @param string $functionName Function name.
 * @return string Function declaration/body source.
 */
function viewer_phase12_function_source(string $source, string $functionName): string
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
    $length = strlen($source);
    for ($index = $brace; $index < $length; $index++) {
        if ($source[$index] === '{') {
            $depth++;
        } elseif ($source[$index] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $index - $start + 1);
            }
        }
    }
    throw new RuntimeException('Unterminated function body: ' . $functionName);
}

/**
 * Discover fully qualified named PHP functions defined in the application tree.
 *
 * @param string $appRoot Application source root.
 * @return array<string,bool> Function-definition lookup.
 */
function viewer_phase12_function_definitions(string $appRoot): array
{
    $definitions = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatch) !== 1) {
            continue;
        }
        $namespace = trim($namespaceMatch[1]);
        if (preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches) < 1) {
            continue;
        }
        foreach ($matches[1] as $name) {
            $definitions[$namespace . '\\' . $name] = true;
        }
    }
    return $definitions;
}

$root = dirname(__DIR__);
$lifecycleController = (string) file_get_contents($root . '/app/controllers/viewer_lifecycle.php');
$accountController = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
$lifecycleService = (string) file_get_contents($root . '/app/services/viewer_lifecycle.php');
$accountsService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
$httpService = (string) file_get_contents($root . '/app/services/viewer_http.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
$controllersLoader = (string) file_get_contents($root . '/app/controllers.php');
$migration = (string) file_get_contents($root . '/database/migrations/202608180004_viewer_account_lifecycle_foundations.php');

// Load and directly exercise the real new controller file so parse/load problems are caught.
require_once $root . '/app/helpers_runtime.php';
require_once $root . '/app/controllers/viewer_lifecycle.php';
viewer_phase12_assert(function_exists('Gallery\\Controllers\\cms_viewer_account_reauth'), 'Phase 1.2 lifecycle controller must load at runtime.');
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_route('password') === 'viewer_account_password', 'Password reauthentication destination must resolve internally.');
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_route('email') === 'viewer_account_email', 'Email reauthentication destination must resolve internally.');
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_route('email_confirm') === 'viewer_email_change_confirm', 'Email confirmation reauthentication destination must resolve internally.');
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_route('delete') === 'viewer_account_delete', 'Deletion reauthentication destination must resolve internally.');
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_route('https://evil.example/path') === null, 'Arbitrary reauthentication return URLs must be rejected.');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['destination' => 'password'];
$_POST = [];
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_from_request() === 'password', 'Allowlisted GET destination must survive parsing.');
$_GET = ['destination' => 'https://evil.example/path'];
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_from_request() === '', 'Arbitrary GET return destination must fail closed.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];
$_POST = ['destination' => 'delete'];
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_from_request() === 'delete', 'Allowlisted POST destination must survive parsing.');
$_POST = ['destination' => '//evil.example'];
viewer_phase12_assert(\Gallery\Controllers\viewer_reauthentication_destination_from_request() === '', 'Protocol-relative return destination must fail closed.');

// Exact Phase 1.2 route surface.
foreach ([
    'viewer_account_reauth',
    'viewer_account_password',
    'viewer_account_email',
    'viewer_email_change_verify',
    'viewer_email_change_confirm',
    'viewer_account_delete',
] as $route) {
    viewer_phase12_assert(str_contains($dispatch, "'{$route}' =>"), 'Required Phase 1.2 route missing: ' . $route);
}
viewer_phase12_assert(str_contains($routing, "['viewer', 'account', 'reauth']"), 'Pretty route for viewer reauthentication is missing.');
viewer_phase12_assert(str_contains($routing, "['viewer', 'account', 'password']"), 'Pretty route for viewer password change is missing.');
viewer_phase12_assert(str_contains($routing, "['viewer', 'account', 'email']"), 'Pretty route for viewer email change is missing.');
viewer_phase12_assert(str_contains($routing, "['viewer', 'account', 'delete']"), 'Pretty route for viewer deletion is missing.');
viewer_phase12_assert(str_contains($routing, "(\$segments[1] ?? '') === 'email-change'") && str_contains($routing, "(\$segments[2] ?? '') === 'verify'"), 'Scanner-safe email-change verification pretty route is missing.');
viewer_phase12_assert(str_contains($routing, "['viewer', 'email-change', 'confirm']"), 'Tokenless email-change confirmation pretty route is missing.');
viewer_phase12_assert(str_contains($controllersLoader, "'/controllers/viewer_lifecycle.php'"), 'Phase 1.2 controller must be loaded through the controller bootstrap.');

// Every lifecycle page must classify itself as private/no-store before doing work.
foreach ([
    'cms_viewer_account_reauth',
    'cms_viewer_account_password',
    'cms_viewer_account_email',
    'cms_viewer_email_change_verify',
    'cms_viewer_email_change_confirm',
    'cms_viewer_account_delete',
] as $functionName) {
    $source = viewer_phase12_function_source($lifecycleController, $functionName);
    viewer_phase12_assert(str_contains($source, 'viewer_http_no_store();'), $functionName . ' must emit viewer private/no-store policy.');
}

// Recent reauthentication is bounded, password-backed, and never an open redirect.
$reauth = viewer_phase12_function_source($lifecycleController, 'cms_viewer_account_reauth');
viewer_phase12_assert(str_contains($reauth, 'viewer_reauthenticate_password('), 'Recent reauthentication must use the established password service.');
viewer_phase12_assert(str_contains($reauth, 'viewer_verify_csrf_or_render_error()'), 'Recent reauthentication POST must use viewer CSRF.');
viewer_phase12_assert(str_contains($lifecycleService, 'viewer_login_rate_limits_consume('), 'Recent password proof must retain established viewer login throttles.');
viewer_phase12_assert(str_contains($httpService, 'Remember restoration deliberately does not establish recent reauthentication.'), 'Remember-me restoration must explicitly remain insufficient for recent reauthentication.');
viewer_phase12_assert(!str_contains($lifecycleController, 'return=') && !str_contains($lifecycleController, 'next=') && !str_contains($lifecycleController, 'redirect='), 'Lifecycle forms must not carry arbitrary URL return parameters.');

// Password change is POST-only for mutation and delegates all credential transitions to the lifecycle service.
$password = viewer_phase12_function_source($lifecycleController, 'cms_viewer_account_password');
viewer_phase12_assert(str_contains($password, "!in_array(request_method(), ['GET', 'POST'], true)"), 'Password route must reject unsupported HTTP methods.');
viewer_phase12_assert(str_contains($password, "request_method() === 'POST'"), 'Password mutation must be inside POST handling.');
viewer_phase12_assert(!str_contains($password, 'minlength="15"'), 'Password form must not duplicate the service-owned password-policy length rule in HTML.');
viewer_phase12_assert(str_contains($password, 'viewer_verify_csrf_or_render_error()'), 'Password change POST must use viewer CSRF.');
viewer_phase12_assert(str_contains($password, "viewer_require_recent_reauthentication('password')"), 'Password change must require recent viewer authentication.');
viewer_phase12_assert(str_contains($password, 'viewer_password_input_is_acceptable('), 'Password change must use the established viewer password policy.');
viewer_phase12_assert(str_contains($password, 'viewer_change_password($password)'), 'Password change must delegate to the Phase 0.7 atomic lifecycle service.');
viewer_phase12_assert(!preg_match('/\bUPDATE\b|\bINSERT\b|\bDELETE\s+FROM\b/i', $password), 'Password controller must not contain lifecycle SQL.');
viewer_phase12_assert(str_contains($lifecycleService, 'viewer_session_clear();') && str_contains($lifecycleService, 'UPDATE viewer_remember_tokens SET revoked_at = ?'), 'Password service must retain logout and remember-token invalidation semantics.');

// Email request is staged, budget-controlled in the service, and mail transport occurs only after a staged request succeeds.
$email = viewer_phase12_function_source($lifecycleController, 'cms_viewer_account_email');
viewer_phase12_assert(str_contains($email, "!in_array(request_method(), ['GET', 'POST'], true)"), 'Email-change request route must reject unsupported HTTP methods.');
$stagePosition = strpos($email, 'viewer_email_change_request_start(');
$mailPosition = strpos($email, 'viewer_send_security_mail(');
viewer_phase12_assert($stagePosition !== false && $mailPosition !== false && $stagePosition < $mailPosition, 'Email mail transport must occur only after the Phase 0.7 staged request service succeeds.');
viewer_phase12_assert(str_contains($lifecycleService, 'viewer_mail_authorize_send(VIEWER_MAIL_ACTION_EMAIL_CHANGE'), 'Email-change mail budget authorization must remain inside the established lifecycle service.');
viewer_phase12_assert(str_contains($email, 'viewer_security_url('), 'Email-change links must use the trusted viewer security origin builder.');
viewer_phase12_assert(!preg_match('/UPDATE\s+viewer_accounts/i', $email), 'Email request controller must never update viewer_accounts directly.');
viewer_phase12_assert(str_contains($email, "viewer.email_change_verification_sent") && !str_contains($email, "'verification_token' =>"), 'Mail transport diagnostics must remain secret-free.');

// Verification GET is scanner-safe: inspection and bounded session authorization only, never the durable email transition.
$verify = viewer_phase12_function_source($lifecycleController, 'cms_viewer_email_change_verify');
viewer_phase12_assert(str_contains($verify, "request_method() !== 'GET'"), 'Email-change verification entry must accept GET only.');
viewer_phase12_assert(str_contains($verify, 'viewer_email_change_request_inspect($token)'), 'Verification GET must use non-consuming inspection.');
viewer_phase12_assert(str_contains($verify, 'viewer_email_change_authorize($token)'), 'Verification GET must exchange secret proof into bounded server-side confirmation authority.');
viewer_phase12_assert(!str_contains($verify, 'viewer_email_change_confirm()'), 'Verification GET must never perform the durable email change.');
viewer_phase12_assert(str_contains($verify, 'viewer_csrf_field()'), 'Verification GET must render a CSRF-protected tokenless confirmation POST.');

// Final confirmation allows a safe GET form after reauthentication but mutates only on CSRF-protected POST.
$confirm = viewer_phase12_function_source($lifecycleController, 'cms_viewer_email_change_confirm');
$confirmCall = strpos($confirm, '$result = viewer_email_change_confirm();');
$postBranch = strpos($confirm, "request_method() !== 'POST'");
viewer_phase12_assert(str_contains($confirm, "request_method() === 'GET'"), 'Tokenless confirmation route may render a safe GET form after reauthentication.');
viewer_phase12_assert($confirmCall !== false && $postBranch !== false && $postBranch < $confirmCall, 'Durable email transition must execute only after method handling reaches POST.');
viewer_phase12_assert(str_contains($confirm, 'viewer_verify_csrf_or_render_error()'), 'Final email confirmation POST must use viewer CSRF.');
viewer_phase12_assert(str_contains($confirm, "viewer_require_recent_reauthentication('email_confirm')"), 'Final email confirmation must retain recent viewer authentication.');
viewer_phase12_assert(!str_contains($confirm, "\$_GET['token']") && !str_contains($confirm, "\$_POST['token']"), 'Final email confirmation must be tokenless and rely on server-side authority.');
viewer_phase12_assert(str_contains($lifecycleService, 'UPDATE viewer_accounts SET email = ?, normalized_email = ?'), 'Final email transition must remain inside the Phase 0.7 lifecycle service.');
viewer_phase12_assert(str_contains($lifecycleService, 'consumed_at = ?') && str_contains($lifecycleService, 'security_version = ?'), 'Email-change service must retain one-time consumption and security-version invalidation.');

// Deletion requires active viewer auth, recent reauthentication, CSRF, and explicit server-side confirmation.
$delete = viewer_phase12_function_source($lifecycleController, 'cms_viewer_account_delete');
viewer_phase12_assert(str_contains($delete, "!in_array(request_method(), ['GET', 'POST'], true)"), 'Viewer deletion route must reject unsupported HTTP methods.');
viewer_phase12_assert(str_contains($delete, "viewer_require_recent_reauthentication('delete')"), 'Viewer deletion must require recent viewer authentication.');
viewer_phase12_assert(str_contains($delete, 'viewer_verify_csrf_or_render_error()'), 'Viewer deletion POST must use viewer CSRF.');
viewer_phase12_assert(str_contains($delete, "(string) (\$_POST['confirm_delete'] ?? '') !== '1'"), 'Viewer deletion must require explicit server-side destructive confirmation.');
viewer_phase12_assert(str_contains($delete, 'viewer_account_delete();'), 'Viewer deletion must delegate to the Phase 0.7 deletion service.');
viewer_phase12_assert(!preg_match('/DELETE\s+FROM\s+viewer_/i', $delete), 'Deletion controller must not implement cascade SQL.');
viewer_phase12_assert(str_contains($migration, 'FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE'), 'Existing Phase 0.7 schema must retain account-owned cascade semantics.');

// Admin/viewer coexistence: no lifecycle controller may write or destroy administrator authority.
foreach (['session_destroy(', "\$_SESSION['user_id']", 'admin_remember', 'current_user('] as $forbidden) {
    viewer_phase12_assert(!str_contains($lifecycleController, $forbidden), 'Lifecycle controller must not touch administrator authority: ' . $forbidden);
}
viewer_phase12_assert(str_contains(viewer_phase12_function_source($lifecycleController, 'viewer_clear_local_lifecycle_state'), 'unset($_SESSION[viewer_csrf_namespace_key()])'), 'Terminal cleanup must clear only viewer-local CSRF authority.');
viewer_phase12_assert(str_contains($accountsService, 'Clear only viewer authentication state, preserving existing administrator/session data.'), 'Viewer session cleanup must retain the documented Admin coexistence invariant.');

// Account page retains the requested Phase 1.2 lifecycle navigation plus existing favourites/logout. Later phases may add separate private-content links.
$account = viewer_phase12_function_source($accountController, 'cms_viewer_account');
foreach (['viewer_favourites', 'viewer_account_password', 'viewer_account_email', 'viewer_account_delete', 'viewer_logout'] as $route) {
    viewer_phase12_assert(str_contains($account, "url_for('{$route}')"), 'Viewer account page missing expected action: ' . $route);
}
viewer_phase12_assert(!str_contains($account, 'viewer_profile'), 'Account page must not expose a public viewer profile.');

// Feature-disable and schema-failure boundaries remain fail-closed without coupling anonymous galleries to lifecycle storage.
$availability = viewer_phase12_function_source($lifecycleController, 'viewer_lifecycle_http_available');
viewer_phase12_assert(str_contains($availability, 'viewer_http_auth_available()') && str_contains($availability, 'viewer_lifecycle_schema_status()'), 'Lifecycle availability must require the established feature/auth boundary plus lifecycle storage.');
viewer_phase12_assert(str_contains(viewer_phase12_function_source($accountController, 'viewer_http_auth_available'), 'viewer_accounts_enabled()'), 'Viewer HTTP auth availability must still fail closed when viewer accounts are disabled.');
viewer_phase12_assert(!str_contains($routing, 'viewer_lifecycle_schema_status'), 'Anonymous request routing must not depend on viewer lifecycle schema inspection.');

// Phase 1.2 itself introduced no database foundation. Later additive viewer migrations may follow.
$phase12FoundationMigrations = [
    '202608180001_viewer_security_foundations.php',
    '202608180002_viewer_registration_foundations.php',
    '202608180003_viewer_authentication_foundations.php',
    '202608180004_viewer_account_lifecycle_foundations.php',
];
foreach ($phase12FoundationMigrations as $migrationFile) {
    viewer_phase12_assert(is_file($root . '/database/migrations/' . $migrationFile), 'Required pre-Phase-1.2 viewer foundation migration is missing: ' . $migrationFile);
}
viewer_phase12_assert(!is_file($root . '/database/migrations/202608180004_viewer_account_lifecycle_phase12.php'), 'Phase 1.2 must not retroactively gain a dedicated lifecycle migration.');

// Scope regression: no routes or implementation for excluded features/authentication methods.
foreach ([
    'viewer_signup',
    'viewer_collection_share',
    'viewer_profile',
    'viewer_upload',
    'viewer_oidc',
    'viewer_totp',
    'viewer_passkey',
    'viewer_magic_login',
] as $forbiddenRoute) {
    viewer_phase12_assert(!str_contains($dispatch, "'{$forbiddenRoute}' =>"), 'Out-of-scope Phase 1.2 route must remain absent: ' . $forbiddenRoute);
}
viewer_phase12_assert(!str_contains($lifecycleController, 'viewer_collection_create(') && !str_contains($lifecycleController, 'viewer_collection_item_add('), 'The Phase 1.2 lifecycle controller must not absorb later collection CRUD services.');

// Runtime symbol audit: every imported project function must have a real definition in the source tree.
$definitions = viewer_phase12_function_definitions($root . '/app');
if (preg_match_all('~^use function\s+(Gallery\\\\(?:Core|Services)\\\\[A-Za-z_][A-Za-z0-9_]*)\s*;~m', $lifecycleController, $imports) > 0) {
    foreach ($imports[1] as $import) {
        viewer_phase12_assert(isset($definitions[$import]), 'Imported Phase 1.2 function has no real definition: ' . $import);
    }
}

// Translation catalogs must remain aligned for the new account lifecycle UI.
foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
    viewer_phase12_assert(is_array($catalog), 'Translation catalog failed to decode: ' . $language);
    foreach ([
        'viewer.reauth.title',
        'viewer.password_change.title',
        'viewer.email_change.title',
        'viewer.email_change.confirm_button',
        'viewer.delete.title',
    ] as $key) {
        viewer_phase12_assert(isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '', 'Missing Phase 1.2 translation key ' . $key . ' in ' . $language . '.');
    }
}

echo "Viewer Phase 1.2 lifecycle HTTP regression checks passed.\n";
