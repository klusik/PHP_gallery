<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_http_phase10_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 1.0 viewer-account HTTP invariants through later registration wiring.
 *
 * Responsibilities:
 *   - Verify route, feature-switch, cache, CSRF, and identity separation wiring
 *   - Verify scanner-safe invitation, verification, and reset orchestration
 *   - Verify viewer login/logout/remember-me use only established viewer services
 *   - Verify mail delivery remains behind viewer abuse authorization
 *   - Prove Phase 4.1 open signup reuses Phase 1 verification while collections, sharing, profiles, uploads, and later favourites stay isolated
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

/**
 * Throw when one Phase 1.0 expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function viewer_phase10_assert(bool $condition, string $label): void
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
function viewer_phase10_function_source(string $source, string $functionName): string
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

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
$httpService = (string) file_get_contents($root . '/app/services/viewer_http.php');
$registrationService = (string) file_get_contents($root . '/app/services/viewer_registration.php');
$authService = (string) file_get_contents($root . '/app/services/viewer_authentication.php');
$tokenService = (string) file_get_contents($root . '/app/services/viewer_tokens.php');
$accountsService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
$galleryAccess = (string) file_get_contents($root . '/app/services/gallery_access.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
$requestBootstrap = (string) file_get_contents($root . '/app/bootstrap/request.php');
$security = (string) file_get_contents($root . '/app/security.php');
$seoGuard = (string) file_get_contents($root . '/app/services/seo_request_guard.php');
$layout = (string) file_get_contents($root . '/app/views/layout.php');
$adminChrome = (string) file_get_contents($root . '/app/views/admin_chrome.php');
$servicesLoader = (string) file_get_contents($root . '/app/services.php');
$controllersLoader = (string) file_get_contents($root . '/app/controllers.php');

// Exercise the real response helper binding so namespace/import mistakes fail at runtime, not only by static source checks.
require_once $root . '/app/controllers/http_helpers.php';
require_once $root . '/app/controllers/viewer_accounts.php';
\Gallery\Controllers\viewer_http_no_store();
viewer_phase10_assert(function_exists('Gallery\\Controllers\\clear_response_cache_headers'), 'Viewer no-store handling must use the existing controller response-cache helper.');
viewer_phase10_assert(!str_contains($controller, 'use function Gallery\\Core\\clear_response_cache_headers;'), 'Viewer controller must not import the response-cache helper from the wrong Core namespace.');

// Phase 1.0 routes remain present; Phase 4.1 adds only the generic viewer_register entry point.
foreach ([
    'viewer_login',
    'viewer_logout',
    'viewer_register',
    'viewer_invite',
    'viewer_verify',
    'viewer_forgot_password',
    'viewer_reset_password',
    'viewer_account',
    'admin_viewer_invitations',
] as $route) {
    viewer_phase10_assert(str_contains($dispatch, "'{$route}' =>"), 'Required Phase 1.0 route missing: ' . $route);
}
foreach (['viewer_signup', 'viewer_collection_share', 'viewer_profile', 'viewer_upload'] as $forbiddenRoute) {
    viewer_phase10_assert(!str_contains($dispatch, "'{$forbiddenRoute}' =>"), 'Out-of-scope route must remain absent: ' . $forbiddenRoute);
}
viewer_phase10_assert(str_contains($routing, "\$segments === ['viewer', 'register']") && str_contains($routing, "(\$segments[1] ?? '') === 'invite'") && str_contains($routing, "(\$segments[1] ?? '') === 'verify'") && str_contains($routing, "(\$segments[1] ?? '') === 'reset'"), 'Pretty routing must retain scanner-safe token entry points and add only the tokenless generic register route.');
viewer_phase10_assert(str_contains($servicesLoader, "'/services/viewer_http.php'"), 'Viewer HTTP cookie adapter must be loaded through the service bootstrap.');
viewer_phase10_assert(str_contains($seoGuard, "str_starts_with(\$page, 'viewer_')"), 'Viewer security routes must bypass the public SEO query guard so bearer URLs are neither rejected nor logged as suspicious requests.');
viewer_phase10_assert(str_contains($controllersLoader, "'/controllers/viewer_accounts.php'"), 'Viewer HTTP controller must be loaded through the controller bootstrap.');

// Admin invitation management remains administrator-only and uses historical Admin CSRF.
$adminInvites = viewer_phase10_function_source($controller, 'cms_admin_viewer_invitations');
viewer_phase10_assert(str_contains($adminInvites, 'require_admin();'), 'Invitation management must require administrator authentication.');
viewer_phase10_assert(str_contains($adminInvites, 'verify_csrf();'), 'Invitation mutations must use existing Admin CSRF.');
viewer_phase10_assert(str_contains($adminInvites, 'viewer_invitation_issue(') && str_contains($adminInvites, 'viewer_invitation_revoke('), 'Invitation controller must orchestrate established issue/revoke services.');
viewer_phase10_assert(str_contains($adminInvites, 'viewer_security_url('), 'Invitation links must use trusted configured security-link origin logic.');
viewer_phase10_assert(!str_contains($adminInvites, "hash('sha256'"), 'Invitation controller must not reimplement secret hashing.');
viewer_phase10_assert(str_contains($adminChrome, "'admin_viewer_invitations'"), 'Admin navigation must expose viewer invitation management.');

// Invitation GET inspection is non-consuming; the POST path owns staged registration and bounded mail authorization.
$invite = viewer_phase10_function_source($controller, 'cms_viewer_invite');
viewer_phase10_assert(str_contains($invite, 'viewer_invitation_inspect($token)'), 'Invitation GET must use scanner-safe inspection.');
viewer_phase10_assert(str_contains($invite, 'viewer_registration_request_begin('), 'Invitation POST must use staged registration service.');
viewer_phase10_assert(str_contains($invite, 'viewer_csrf_field()') && str_contains($invite, 'viewer_verify_csrf_or_render_error()'), 'Invitation acceptance must use viewer/pre-auth CSRF.');
$registrationDelivery = viewer_phase10_function_source($controller, 'viewer_deliver_registration_verification');
$inviteMailAuthorization = strpos($registrationDelivery, 'viewer_mail_authorize_send(');
$inviteMailTransport = strpos($registrationDelivery, 'viewer_send_security_mail(');
viewer_phase10_assert(str_contains($invite, 'viewer_deliver_registration_verification($email, $result, true)'), 'Invitation registration must delegate verification delivery to the shared Phase 4.1 helper.');
viewer_phase10_assert($inviteMailAuthorization !== false && $inviteMailTransport !== false && $inviteMailAuthorization < $inviteMailTransport, 'Verification mail transport must execute only after viewer mail-abuse authorization.');
viewer_phase10_assert(str_contains($registrationService, 'function viewer_invitation_inspect(string $token)') && !str_contains(viewer_phase10_function_source($registrationService, 'viewer_invitation_inspect'), 'claimed_at ='), 'Invitation inspection must not consume the invitation on GET.');

// Verification is a scanner-safe GET inspection, explicit POST authorization, then atomic activation.
$verify = viewer_phase10_function_source($controller, 'cms_viewer_verify');
viewer_phase10_assert(str_contains($verify, 'viewer_registration_verification_validate($token)'), 'Verification GET must use non-consuming token validation.');
viewer_phase10_assert(str_contains($verify, 'viewer_registration_verification_confirm($token)'), 'Verification POST must exchange the bearer into server-side activation authority.');
viewer_phase10_assert(str_contains($verify, 'viewer_registration_activate_verified($password)'), 'Final registration POST must use existing atomic activation service.');
viewer_phase10_assert(!str_contains($verify, 'INSERT INTO viewer_accounts'), 'Viewer controller must never implement account creation SQL.');
viewer_phase10_assert(str_contains($registrationService, 'SELECT * FROM viewer_invitations WHERE id = ? LIMIT 1 FOR UPDATE') && str_contains($registrationService, 'viewer_account_capacity_lock();'), 'Existing activation service must retain invitation/account-cap locking.');

// Viewer login remains identity-separated and all slow hashing stays behind service-side admission control.
$login = viewer_phase10_function_source($controller, 'cms_viewer_login');
viewer_phase10_assert(str_contains($login, 'viewer_authenticate_password('), 'Viewer login must delegate password authentication to existing viewer service.');
viewer_phase10_assert(str_contains($login, 'viewer_csrf_field()') && str_contains($login, 'viewer_verify_csrf_or_render_error()'), 'Viewer login must be CSRF protected.');
viewer_phase10_assert(str_contains($login, 'viewer_remember_token_issue(') && str_contains($login, 'viewer_remember_cookie_set('), 'Remember-me must use dedicated viewer token plus cookie bridge.');
viewer_phase10_assert(!str_contains($login, "\$_SESSION['user_id']") && !str_contains($login, 'current_user('), 'Viewer login must never establish or inspect administrator identity.');
viewer_phase10_assert(strpos($authService, 'viewer_login_rate_limits_consume(') < strpos($authService, 'viewer_password_verify('), 'Viewer login rate limiting must execute before expensive password verification.');
viewer_phase10_assert(str_contains(viewer_phase10_function_source($accountsService, 'viewer_session_establish'), 'viewer_session_namespace_key()') && !str_contains(viewer_phase10_function_source($accountsService, 'viewer_session_establish'), "\$_SESSION['user_id']"), 'Viewer session establishment must remain in its dedicated namespace only.');

// Viewer logout is a POST/CSRF mutation and does not destroy or revoke Admin identity.
$logout = viewer_phase10_function_source($controller, 'cms_viewer_logout');
viewer_phase10_assert(str_contains($logout, "request_method() !== 'POST'") && str_contains($logout, 'viewer_verify_csrf_or_render_error()'), 'Viewer logout must require POST plus viewer CSRF.');
viewer_phase10_assert(str_contains($logout, 'viewer_remember_revoke_current_cookie()') && str_contains($logout, 'viewer_session_revoke_current()') && str_contains($logout, 'viewer_clear_reauthentication()'), 'Viewer logout must revoke viewer remember/session/recent-auth authority.');
viewer_phase10_assert(!str_contains($logout, 'session_destroy(') && !str_contains($logout, 'cms_admin_logout') && !str_contains($logout, "unset(\$_SESSION['user_id'])"), 'Viewer logout must preserve administrator session authority.');

// Dedicated persistent viewer authority rotates and never counts as recent reauthentication.
viewer_phase10_assert(str_contains($httpService, "viewer_remember_cookie_contract()['name']") && str_contains($httpService, 'viewer_remember_restore_and_rotate('), 'Viewer remember cookie bridge must use the dedicated established token contract and rotation service.');
viewer_phase10_assert(str_contains($httpService, 'viewer_clear_reauthentication();'), 'Remember restoration must explicitly leave recent reauthentication unsatisfied.');
viewer_phase10_assert(!str_contains($httpService, "\$_SESSION['user_id']"), 'Viewer remember adapter must never write administrator identity.');
viewer_phase10_assert(str_contains($tokenService, "'name' => 'php_gallery_viewer_remember'"), 'Viewer persistent login must retain its dedicated cookie namespace.');
viewer_phase10_assert(strpos($requestBootstrap, 'viewer_remember_restore_from_cookie();') < strpos($requestBootstrap, 'send_security_headers();'), 'Remember restoration must occur before response cache classification.');
$rememberRestore = viewer_phase10_function_source($httpService, 'viewer_remember_restore_from_cookie');
viewer_phase10_assert(str_contains($rememberRestore, 'if (!viewer_accounts_enabled())') && str_contains($rememberRestore, 'viewer_session_clear();') && str_contains($rememberRestore, 'viewer_registration_activation_clear();') && str_contains($rememberRestore, 'viewer_password_reset_state_clear();'), 'Disabling viewer accounts must clear local viewer-only authority so ordinary public requests return to the historical anonymous cache path.');

// Reset link GET remains non-consuming; explicit POST creates short-lived reset authority and final POST delegates transition.
$forgot = viewer_phase10_function_source($controller, 'cms_viewer_forgot_password');
$reset = viewer_phase10_function_source($controller, 'cms_viewer_reset_password');
viewer_phase10_assert(str_contains($forgot, 'viewer_password_reset_request('), 'Forgot-password request must use existing reset request foundation.');
viewer_phase10_assert(str_contains($reset, 'viewer_password_reset_inspect($token)') && str_contains($reset, 'viewer_password_reset_authorize('), 'Reset flow must separate scanner-safe inspection from explicit authorization.');
viewer_phase10_assert(str_contains($reset, 'viewer_password_reset_complete($password)'), 'Final reset POST must use existing atomic reset transition.');
viewer_phase10_assert(!str_contains($reset, 'UPDATE viewer_accounts') && !str_contains($reset, 'DELETE FROM viewer_sessions'), 'Reset controller must not duplicate lifecycle SQL.');
viewer_phase10_assert(str_contains($authService, 'UPDATE viewer_sessions SET revoked_at = ?') && str_contains($authService, 'UPDATE viewer_remember_tokens SET revoked_at = ?'), 'Existing reset service must continue revoking old viewer authority.');

// Personalized responses and bearer-cookie requests are never eligible for shared/public cache treatment.
foreach (['cms_viewer_register', 'cms_viewer_invite', 'cms_viewer_verify', 'cms_viewer_login', 'cms_viewer_logout', 'cms_viewer_forgot_password', 'cms_viewer_reset_password', 'cms_viewer_account'] as $functionName) {
    viewer_phase10_assert(str_contains(viewer_phase10_function_source($controller, $functionName), 'viewer_http_no_store();'), $functionName . ' must explicitly classify its response private/no-store.');
}
viewer_phase10_assert(str_contains($security, "isset(\$_COOKIE['php_gallery_viewer_remember'])"), 'Presence of a viewer remember bearer must force the sensitive cache path even before successful restoration.');
viewer_phase10_assert(!str_contains(viewer_phase10_function_source($security, 'send_security_headers'), 'current_viewer()'), 'Cache classification must remain independent of viewer DB lookups.');

// Feature switches fail closed; Phase 4.1 registration discovery is additionally guarded by exact open availability.
viewer_phase10_assert(str_contains(viewer_phase10_function_source($controller, 'viewer_http_auth_available'), 'viewer_accounts_enabled()'), 'Viewer HTTP boundary must respect the global viewer feature switch.');
viewer_phase10_assert(str_contains(viewer_phase10_function_source($httpService, 'viewer_http_invite_registration_available'), 'viewer_http_registration_lifecycle_available()'), 'Invitation HTTP availability must support the shared invite_only/open registration lifecycle.');
viewer_phase10_assert(str_contains(viewer_phase10_function_source($httpService, 'viewer_http_open_registration_available'), "viewer_registration_mode() === 'open'"), 'Generic registration HTTP availability must require exact open mode.');
viewer_phase10_assert(str_contains($layout, 'viewer_accounts_enabled()') && str_contains($layout, "url_for('viewer_login')") && str_contains($layout, "url_for('viewer_account')"), 'Public navigation must retain Viewer Login/Account while enabled.');
viewer_phase10_assert(str_contains($layout, 'if (viewer_http_open_registration_available())') && str_contains($layout, "url_for('viewer_register')") && !str_contains($layout, "url_for('viewer_signup')"), 'Public Register discovery must exist only behind the narrow Phase 4.1 open-registration gate.');
viewer_phase10_assert(str_contains($layout, "str_starts_with(\$page, 'viewer_') ? [] : ['return' => current_login_return_target()]"), 'Secret-bearing viewer routes must not be copied into the Admin login return parameter.');
viewer_phase10_assert(str_contains(viewer_phase10_function_source($controller, 'viewer_http_no_store'), "Referrer-Policy: no-referrer"), 'Viewer bearer/pre-auth responses must suppress Referer propagation of secret-bearing URLs.');

// Authentication remains separate from gallery authorization and out-of-scope content features remain absent.
viewer_phase10_assert(stripos($galleryAccess, 'current_viewer') === false, 'Viewer authentication must not alter gallery authorization helpers.');
viewer_phase10_assert(!str_contains($controller, 'viewer_collection_create(') && !str_contains($controller, 'viewer_collection_item_add('), 'The Phase 1.0 account controller must not absorb later private-collection mutation logic.');
viewer_phase10_assert(!str_contains($controller, 'viewer_collection_share'), 'The Phase 1.0 account controller must not expose collection-sharing behavior.');
viewer_phase10_assert(!str_contains($controller, 'viewer_favourite_set('), 'The Phase 1.0 account controller must remain focused and must not absorb later favourite mutation logic.');
viewer_phase10_assert(stripos($controller, 'passkey') === false && stripos($controller, 'totp') === false && stripos($controller, 'oidc') === false && stripos($controller, 'captcha') === false && stripos($controller, 'magic-link') === false, 'Optional authentication mechanisms must remain outside Phase 1.0.');

// Security event calls in the HTTP controller must never pass bearer secrets as event context.
foreach (preg_split('/\R/', $controller) ?: [] as $line) {
    if (str_contains($line, 'viewer_security_event_record_best_effort(')) {
        viewer_phase10_assert(!str_contains($line, 'token') && !str_contains($line, 'password') && !str_contains($line, 'url'), 'Viewer HTTP security event call must not log bearer/password/URL secrets.');
    }
}

echo "Viewer Phase 1.0 HTTP boundary tests passed.\n";
