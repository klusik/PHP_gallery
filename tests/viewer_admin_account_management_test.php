<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_admin_account_management_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects administrator-created viewer accounts and forced first-login password replacement.
 *
 * Responsibilities:
 *   - Verify direct Admin provisioning uses the separate viewer identity domain and existing hard account cap
 *   - Verify temporary credentials cannot establish normal viewer or remember-me authority
 *   - Verify first-login password replacement is short-lived, CSRF-protected, atomic, and security-versioned
 *   - Verify Admin deletion removes only the selected viewer account through existing lifecycle relationships
 *   - Verify optional account-created email never contains a temporary password
 *   - Verify route, translation, migration, loader, and runtime symbol wiring
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
 *   - This test intentionally avoids requiring an external database.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

/**
 * Throw when one administrator viewer-account management expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function viewer_admin_account_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Extract one named PHP function declaration/body for focused static assertions.
 *
 * @param string $source Complete PHP source.
 * @param string $functionName Function name.
 * @return string Function declaration/body source.
 */
function viewer_admin_account_function_source(string $source, string $functionName): string
{
    $needle = 'function ' . $functionName . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        throw new RuntimeException('Missing function: ' . $functionName);
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        throw new RuntimeException('Missing function body: ' . $functionName);
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
$adminService = (string) file_get_contents($root . '/app/services/viewer_admin_accounts.php');
$accountsService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
$authenticationService = (string) file_get_contents($root . '/app/services/viewer_authentication.php');
$tokensService = (string) file_get_contents($root . '/app/services/viewer_tokens.php');
$lifecycleService = (string) file_get_contents($root . '/app/services/viewer_lifecycle.php');
$controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
$servicesLoader = (string) file_get_contents($root . '/app/services.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
$migration = (string) file_get_contents($root . '/database/migrations/202608180006_viewer_admin_account_management.php');

// Directly load the new files so namespace/declaration mistakes are caught beyond php -l.
require_once $root . '/app/services/viewer_admin_accounts.php';
require_once $root . '/app/controllers/viewer_accounts.php';
viewer_admin_account_assert(function_exists('Gallery\\Services\\viewer_admin_account_create'), 'Admin viewer-account service must load at runtime.');
viewer_admin_account_assert(function_exists('Gallery\\Services\\viewer_admin_account_delete'), 'Admin viewer-account deletion service must load at runtime.');
viewer_admin_account_assert(function_exists('Gallery\\Controllers\\cms_viewer_first_login_password'), 'Forced first-login controller must load at runtime.');

viewer_admin_account_assert(str_contains($migration, 'ADD COLUMN must_change_password TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'), 'Migration must add a non-null forced password-change flag with backward-compatible default 0.');
viewer_admin_account_assert(str_contains($servicesLoader, "'/services/viewer_admin_accounts.php'"), 'Admin viewer-account service must be loaded by the service bootstrap.');
viewer_admin_account_assert(str_contains($servicesLoader, "'/services/viewer_lifecycle.php'") && strpos($servicesLoader, "'/services/viewer_lifecycle.php'") < strpos($servicesLoader, "'/services/viewer_admin_accounts.php'"), 'Lifecycle helpers must be loaded before administrator account deletion uses them.');

$create = viewer_admin_account_function_source($adminService, 'viewer_admin_account_create');
viewer_admin_account_assert(str_contains($create, 'viewer_account_capacity_lock();') && str_contains($create, 'viewer_account_capacity_recount_locked();'), 'Direct Admin creation must enforce the existing race-safe durable account capacity primitive.');
viewer_admin_account_assert(str_contains($create, 'viewer_password_hash($password)'), 'Temporary passwords must be hashed before storage.');
viewer_admin_account_assert(str_contains($create, 'must_change_password') && str_contains($create, 'VALUES (?, ?, ?, 1,'), 'Direct Admin creation must mark the temporary password for forced replacement.');
viewer_admin_account_assert(str_contains($create, 'VIEWER_ACCOUNT_STATUS_ACTIVE') && str_contains($create, 'email_verified_at'), 'Direct Admin creation must create an active administratively verified viewer identity.');
viewer_admin_account_assert(!str_contains($create, '$_SESSION[\'user_id\']') && !str_contains($create, 'current_user()') && !str_contains($create, 'current_viewer()'), 'Direct Admin creation must not mix Admin/viewer principal session semantics into the service.');
viewer_admin_account_assert(!str_contains($create, 'mail(') && !str_contains($create, 'viewer_send_security_mail'), 'Provisioning service must not email the temporary password or perform transport work.');

$list = viewer_admin_account_function_source($adminService, 'viewer_admin_account_list');
viewer_admin_account_assert(!str_contains($list, 'password_hash') && !str_contains($list, 'remember') && !str_contains($list, 'session_hash'), 'Admin account list must not expose password/session/remember secrets.');

$delete = viewer_admin_account_function_source($adminService, 'viewer_admin_account_delete');
viewer_admin_account_assert(str_contains($delete, 'DELETE FROM viewer_accounts WHERE id = ?'), 'Admin deletion must delete the selected viewer identity through the authoritative viewer table.');
viewer_admin_account_assert(str_contains($delete, 'security_version') && str_contains($delete, 'viewer_account_capacity_recount_locked();'), 'Admin deletion must invalidate viewer authority and reconcile account capacity atomically.');
foreach (['DELETE FROM images', 'DELETE FROM galleries', 'DELETE FROM users', 'session_destroy()', '$_SESSION[\'user_id\']'] as $forbidden) {
    viewer_admin_account_assert(!str_contains($delete, $forbidden), 'Admin viewer deletion must not affect gallery/Admin identity state: ' . $forbidden);
}

$requiresChange = viewer_admin_account_function_source($accountsService, 'viewer_account_requires_password_change');
viewer_admin_account_assert(str_contains($requiresChange, 'must_change_password'), 'Viewer account service must expose one authoritative first-login flag helper.');
$sessionEstablish = viewer_admin_account_function_source($accountsService, 'viewer_session_establish');
$currentViewer = viewer_admin_account_function_source($accountsService, 'current_viewer');
viewer_admin_account_assert(str_contains($sessionEstablish, 'viewer_account_requires_password_change($lockedAccount)'), 'Normal viewer session establishment must reject temporary-password accounts.');
viewer_admin_account_assert(str_contains($currentViewer, 'viewer_account_requires_password_change($row)'), 'Existing viewer sessions must fail closed if an account later requires a password change.');
viewer_admin_account_assert(str_contains(viewer_admin_account_function_source($accountsService, 'viewer_account_can_mutate_content'), '!viewer_account_requires_password_change($account)'), 'Temporary-password accounts must not mutate viewer-owned content.');

$authenticate = viewer_admin_account_function_source($authenticationService, 'viewer_authenticate_password');
$branch = strpos($authenticate, 'viewer_account_requires_password_change($lockedAccount)');
$normalSession = strpos($authenticate, 'viewer_session_establish($lockedAccount)');
viewer_admin_account_assert($branch !== false && $normalSession !== false && $branch < $normalSession, 'Password login must branch into forced replacement before normal viewer session establishment.');
viewer_admin_account_assert(str_contains($authenticate, 'viewer_first_login_password_establish($lockedAccount)'), 'Temporary-password login must establish only limited first-login authority.');
viewer_admin_account_assert(str_contains($authenticate, "'password_change_required' => true"), 'Temporary-password login must signal the forced-change controller path.');

$firstState = viewer_admin_account_function_source($authenticationService, 'viewer_first_login_password_state');
$firstComplete = viewer_admin_account_function_source($authenticationService, 'viewer_first_login_password_complete');
viewer_admin_account_assert(str_contains($firstState, 'VIEWER_FIRST_LOGIN_PASSWORD_LIFETIME_SECONDS') || str_contains($authenticationService, 'VIEWER_FIRST_LOGIN_PASSWORD_LIFETIME_SECONDS = 900'), 'Forced first-login authority must be short-lived.');
viewer_admin_account_assert(str_contains($firstState, 'viewer_account_requires_password_change($account)') && str_contains($firstState, 'hash_equals($expected, $context)'), 'Forced first-login state must be revalidated against live account state and an integrity context.');
viewer_admin_account_assert(str_contains($firstComplete, 'must_change_password = 0'), 'Successful first-login password replacement must atomically clear the forced-change flag.');
viewer_admin_account_assert(str_contains($firstComplete, '$newSecurityVersion = (int) $account[\'security_version\'] + 1'), 'Successful replacement must increment viewer security_version.');
viewer_admin_account_assert(str_contains($firstComplete, 'viewer_password_verify($newPassword, (string) $account[\'password_hash\'])'), 'Replacement must reject reuse of the administrator-issued temporary password.');
viewer_admin_account_assert(str_contains($firstComplete, 'UPDATE viewer_sessions SET revoked_at') && str_contains($firstComplete, 'UPDATE viewer_remember_tokens SET revoked_at'), 'Replacement must revoke older normal/persistent viewer authority.');
$clearFlagPosition = strpos($firstComplete, 'must_change_password = 0');
$newNormalSessionPosition = strpos($firstComplete, 'viewer_session_establish($account)');
viewer_admin_account_assert($clearFlagPosition !== false && $newNormalSessionPosition !== false && $clearFlagPosition < $newNormalSessionPosition, 'Normal viewer session may be established only after the forced-change flag is cleared.');
viewer_admin_account_assert(!str_contains($firstComplete, '$_SESSION[\'user_id\']') && !str_contains($firstComplete, 'current_user()'), 'Forced password replacement must never acquire Admin identity.');

$resetComplete = viewer_admin_account_function_source($authenticationService, 'viewer_password_reset_complete');
$changePassword = viewer_admin_account_function_source($lifecycleService, 'viewer_change_password');
viewer_admin_account_assert(str_contains($resetComplete, 'must_change_password = 0'), 'A successful password reset must satisfy the temporary-password replacement requirement.');
viewer_admin_account_assert(str_contains($changePassword, 'must_change_password = 0'), 'Normal password changes must defensively clear the temporary-password flag.');

$rememberIssue = viewer_admin_account_function_source($tokensService, 'viewer_remember_token_issue');
$rememberVerify = viewer_admin_account_function_source($tokensService, 'viewer_remember_token_verify');
$rememberRestore = viewer_admin_account_function_source($tokensService, 'viewer_remember_restore_and_rotate');
viewer_admin_account_assert(str_contains($rememberIssue, 'viewer_account_requires_password_change($account)'), 'Remember-me issuance must reject temporary-password accounts.');
viewer_admin_account_assert(str_contains($rememberVerify, 'viewer_account_requires_password_change($account)'), 'Remember-me verification must reject temporary-password accounts.');
viewer_admin_account_assert(str_contains($rememberRestore, 'viewer_account_requires_password_change($account)'), 'Remember-me restoration must reject temporary-password accounts.');

$adminController = viewer_admin_account_function_source($controller, 'cms_admin_viewer_invitations');
viewer_admin_account_assert(str_contains($adminController, "elseif (\$action === 'create_account')") && str_contains($adminController, 'viewer_admin_account_create('), 'Admin page must expose direct account creation through the service.');
viewer_admin_account_assert(str_contains($adminController, "elseif (\$action === 'delete_account')") && str_contains($adminController, 'viewer_admin_account_delete('), 'Admin page must expose explicit account deletion through the service.');
viewer_admin_account_assert(str_contains($adminController, 'verify_csrf();'), 'Direct Admin account mutations must remain protected by Admin CSRF.');
viewer_admin_account_assert(str_contains($adminController, "viewer.admin.accounts.delete_confirm") && str_contains($adminController, 'return confirm(this.dataset.confirm)'), 'Admin viewer-account deletion must require an explicit browser confirmation before POST submission.');
viewer_admin_account_assert(!str_contains($adminController, 'INSERT INTO viewer_accounts') && !str_contains($adminController, 'DELETE FROM viewer_accounts'), 'Admin controller must not duplicate account SQL.');
viewer_admin_account_assert(str_contains($adminController, "\$_SESSION['viewer_admin_account_show_once']"), 'Temporary password must be passed through bounded show-once Admin session state.');
$mailStart = strpos($adminController, "'viewer.email.admin_created_body'");
$mailEnd = $mailStart === false ? false : strpos($adminController, 'viewer_send_security_mail(', $mailStart);
viewer_admin_account_assert($mailStart !== false && $mailEnd !== false, 'Admin-created account notification email must be wired through the existing mail transport.');
$mailSnippet = substr($adminController, $mailStart, $mailEnd - $mailStart);
viewer_admin_account_assert(!str_contains($mailSnippet, "['temporary_password']") && !str_contains($mailSnippet, '$temporaryPassword'), 'Account notification email must never interpolate the temporary password.');
viewer_admin_account_assert(str_contains($mailSnippet, 'No password is included in this email.'), 'English fallback notification must state that no password is included.');

$firstController = viewer_admin_account_function_source($controller, 'cms_viewer_first_login_password');
viewer_admin_account_assert(str_contains($firstController, 'viewer_http_no_store();'), 'Forced first-login response must be private/no-store.');
viewer_admin_account_assert(str_contains($firstController, 'viewer_verify_csrf_or_render_error()'), 'Forced password replacement POST must require viewer CSRF.');
viewer_admin_account_assert(str_contains($firstController, 'viewer_first_login_password_complete($password)'), 'Forced password controller must delegate the credential transition to the service.');
$logout = viewer_admin_account_function_source($controller, 'cms_viewer_logout');
viewer_admin_account_assert(str_contains($logout, 'viewer_first_login_password_state_clear();') && str_contains($logout, 'unset($_SESSION[viewer_csrf_namespace_key()]);') && !str_contains($logout, '$_SESSION[\'user_id\']'), 'Viewer logout must clear limited first-login authority without touching Admin session keys.');

viewer_admin_account_assert(str_contains($routing, "['viewer', 'first-login']"), 'Pretty first-login password route must exist.');
viewer_admin_account_assert(str_contains($dispatch, "'viewer_first_login_password' =>"), 'First-login password route must dispatch to its controller.');

foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
    viewer_admin_account_assert(is_array($catalog), 'Translation catalog failed to decode: ' . $language);
    foreach ([
        'viewer.admin.accounts.title',
        'viewer.admin.accounts.add_title',
        'viewer.admin.accounts.temporary_password_show_once',
        'viewer.admin.accounts.delete_button',
        'viewer.admin.accounts.delete_confirm',
        'viewer.email.admin_created_subject',
        'viewer.email.admin_created_body',
        'viewer.first_login.title',
        'viewer.first_login.password_reuse',
        'viewer.first_login.completed',
    ] as $key) {
        viewer_admin_account_assert(isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '', 'Missing Admin viewer-account translation ' . $key . ' in ' . $language . '.');
    }
}

echo "Viewer Admin account management tests passed.\n";
