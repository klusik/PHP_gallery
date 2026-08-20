<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_identity_boundary_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the critical administrator/viewer identity and gallery-authorization boundary.
 *
 * Responsibilities:
 *   - Prove current_user() remains backed only by the existing admin users/session domain
 *   - Prove current_viewer() is backed only by viewer-specific session/account storage
 *   - Prove historical gallery/media admin bypass checks do not recognize viewer state
 *   - Prove Phase 0 leaves existing admin authentication, CSRF, and share-token implementations untouched
 */

declare(strict_types=1);

/**
 * Throw when one viewer identity boundary expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function viewer_identity_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Extract one named function source body for static security-boundary assertions.
 *
 * @param string $source Complete PHP source.
 * @param string $functionName Function name.
 * @return string Function declaration/body source.
 */
function viewer_identity_function_source(string $source, string $functionName): string
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
$security = (string) file_get_contents($root . '/app/security.php');
$galleryAccess = (string) file_get_contents($root . '/app/services/gallery_access.php');
$publicMedia = (string) file_get_contents($root . '/app/controllers/public_media.php');
$viewerAccounts = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
$adminAuth = (string) file_get_contents($root . '/app/controllers/admin_auth.php');
$adminPersistence = (string) file_get_contents($root . '/app/services/auth_persistence.php');
$sessionBootstrap = (string) file_get_contents($root . '/app/bootstrap/session.php');

$currentUser = viewer_identity_function_source($security, 'current_user');
viewer_identity_assert(str_contains($currentUser, "\$_SESSION['user_id']"), 'current_user() must continue using the historical admin session key.');
viewer_identity_assert(str_contains($currentUser, 'FROM users WHERE id = ?'), 'current_user() must continue loading only the existing users table.');
viewer_identity_assert(stripos($currentUser, 'viewer') === false, 'current_user() must never inspect viewer session/account state.');

$currentViewer = viewer_identity_function_source($viewerAccounts, 'current_viewer');
viewer_identity_assert(str_contains($currentViewer, 'viewer_session_state()'), 'current_viewer() must consume only viewer-specific session state.');
viewer_identity_assert(str_contains($currentViewer, 'FROM viewer_sessions') && str_contains($currentViewer, 'viewer_accounts'), 'current_viewer() must load only the viewer identity domain.');
viewer_identity_assert(stripos($currentViewer, 'FROM users') === false && !str_contains($currentViewer, "\$_SESSION['user_id']"), 'current_viewer() must never depend on the admin users/session identity.');

$visitorAccess = viewer_identity_function_source($galleryAccess, 'visitor_can_access_gallery');
viewer_identity_assert(str_contains($visitorAccess, 'current_user()'), 'Historical authenticated-admin gallery access behavior must remain present.');
viewer_identity_assert(stripos($visitorAccess, 'current_viewer') === false, 'A viewer principal must never satisfy the historical admin gallery bypass.');

$publicVisibility = viewer_identity_function_source($galleryAccess, 'public_image_visible_to_current_visitor');
viewer_identity_assert(str_contains($publicVisibility, 'visitor_can_access_gallery($gallery)'), 'Canonical public image visibility must still delegate to normal gallery authorization.');
viewer_identity_assert(stripos($publicVisibility, 'viewer_collection') === false && stripos($publicVisibility, 'viewer_favourite') === false, 'Viewer references must not become an alternative media authorization path.');
viewer_identity_assert(stripos($publicMedia, 'current_viewer') === false, 'Existing public media controller must not treat viewer authentication as administrator authentication in Phase 0.');

viewer_identity_assert(stripos($adminAuth, 'viewer_') === false, 'Existing admin authentication controller must remain viewer-unaware.');
viewer_identity_assert(stripos($adminPersistence, 'viewer_') === false, 'Existing durable admin login must remain viewer-unaware.');
viewer_identity_assert(str_contains($sessionBootstrap, "session_name((string) \$config['admin_session_name'])"), 'Existing admin PHP session naming must remain unchanged.');
viewer_identity_assert(str_contains($security, "\$_SESSION['csrf_token']") && str_contains($security, 'hash_equals'), 'Existing CSRF token generation/verification contract must remain intact.');

$shareTokenValidation = viewer_identity_function_source($galleryAccess, 'request_share_token_allows_gallery');
viewer_identity_assert(str_contains($shareTokenValidation, 'access_token_hash') && str_contains($shareTokenValidation, "hash('sha256', \$token)"), 'Existing gallery share-token authorization must remain on its historical hashed validation path.');
viewer_identity_assert(stripos($shareTokenValidation, 'viewer_collection') === false, 'Future collection sharing must not be mixed into existing gallery share-token authorization.');

echo "Viewer/admin identity boundary tests passed.\n";
