<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_support.php
 * Module Type: Controller
 * Purpose: Issue and redeem single-use temporary administrator support access.
 * Responsibilities:
 *   - Authorize owner-only issuance/revocation with CSRF
 *   - Exchange fragment-delivered invitation POSTs for isolated PHP sessions
 *   - Prepare localized, secret-safe support management and consent views
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\absolute_public_url;
use function Gallery\Core\admin_auth_clear_remember_cookie;
use function Gallery\Core\admin_auth_throttle_visitor_subject_for_request;
use function Gallery\Core\admin_support_forbid;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_support_grants;
use function Gallery\Services\admin_support_issue;
use function Gallery\Services\admin_support_redeem;
use function Gallery\Services\admin_support_revoke;
use function Gallery\Services\auth_throttle_check;
use function Gallery\Services\auth_throttle_record_attempt;
use function Gallery\Services\auth_throttle_schema_ready;
use function Gallery\Services\t;

/**
 * Render permanent Admin support invitations and handle explicit mutations.
 *
 * The new bearer invitation is never stored in PHP session or persistent
 * storage and can only be copied from the issuance response.
 *
 * @return void Handles owner actions and renders the management page.
 */
function cms_admin_support(): void
{
    require_admin();
    $user = current_user();
    if (
        !is_array($user)
        || (string) ($user['role'] ?? '') !== 'admin'
        || !empty($user['support_access'])
    ) {
        admin_support_forbid('auth.support_owner_only');
    }

    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    $ownerId = (int) $user['id'];
    $newLink = '';
    $newExpiry = '';
    $notice = '';
    $error = '';
    if (request_method() === 'POST') {
        verify_csrf();
        $action = is_string($_POST['action'] ?? null)
            ? $_POST['action'] : '';
        try {
            if ($action === 'issue') {
                $issued = admin_support_issue($ownerId);
                $newLink = absolute_public_url(
                    url_for('admin_support_redeem'),
                ) . '#support=' . $issued['token'];
                $newExpiry = $issued['grant']['expires_at'] . ' UTC';
                $notice = t(
                    'admin.support.created',
                    'Invitation created. Copy it now; it cannot be displayed again.',
                );
                admin_log_event(
                    'info',
                    'auth.support_issued',
                    'A permanent Admin issued one temporary support invitation.',
                    [
                        'grant_id' => $issued['grant']['id'],
                        'owner_user_id' => $ownerId,
                    ],
                    ['category' => 'security'],
                );
            } elseif ($action === 'revoke') {
                $grantId = filter_var(
                    $_POST['grant_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]],
                );
                if (
                    !is_int($grantId)
                    || !admin_support_revoke($ownerId, $grantId)
                ) {
                    $error = t(
                        'admin.support.revoke_failed',
                        'This invitation cannot be revoked.',
                    );
                } else {
                    $notice = t(
                        'admin.support.revoked',
                        'Access revoked. All linked sessions are now invalid.',
                    );
                    admin_log_event(
                        'info',
                        'auth.support_revoked',
                        'A permanent Admin revoked a support invitation.',
                        [
                            'grant_id' => $grantId,
                            'owner_user_id' => $ownerId,
                        ],
                        ['category' => 'security'],
                    );
                }
            } else {
                $error = t(
                    'admin.support.invalid_action',
                    'Unsupported support-access action.',
                );
            }
        } catch (\Throwable) {
            // Never include database errors, token hashes or bearer URLs.
            $error = t(
                'admin.support.storage_unavailable',
                'Support-access storage is unavailable. Apply database migrations and try again.',
            );
        }
    }

    try {
        $grants = admin_support_grants($ownerId);
    } catch (\Throwable) {
        $grants = [];
        $error = t(
            'admin.support.storage_unavailable',
            'Support-access storage is unavailable. Apply database migrations and try again.',
        );
    }

    \Gallery\Views\view_render_admin_support([
        'csrf_html' => csrf_field(),
        'form_url' => url_for('admin_support'),
        'grants' => $grants,
        'new_link' => $newLink,
        'new_expiry' => $newExpiry,
        'notice' => $notice,
        'error' => $error,
    ]);
}

/**
 * Accept an invitation using POST-only secret redemption, never URL queries.
 *
 * A fragment is copied to the POST field in the browser and removed from
 * the visible address before acceptance. The server never receives the
 * fragment during GET. A no-JavaScript visitor may paste the token manually.
 *
 * @return void Render the consent page or establish a delegated session.
 */
function cms_admin_support_redeem(): void
{
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    $error = '';
    // An existing permanent session cannot be silently replaced with a
    // less-privileged one. Use a separate browser/private profile for support.
    if (current_user() !== null) {
        $error = t(
            'admin.support.separate_session',
            'Sign out or use another browser profile before accepting support access.',
        );
    } elseif (request_method() === 'POST') {
        verify_csrf();
        $token = is_string($_POST['support_access_token'] ?? null)
            ? $_POST['support_access_token'] : '';

        try {
            // Throttle schema uncertainty must not turn rate limiting off.
            if (!auth_throttle_schema_ready()) {
                throw new \RuntimeException('Support throttle unavailable.');
            }

            $visitor = admin_auth_throttle_visitor_subject_for_request();
            $throttle = auth_throttle_check(
                'admin_support_redemption',
                $visitor,
            );
            if (!$throttle['allowed']) {
                $error = t(
                    'admin.support.rate_limited',
                    'Too many attempts. Please try again later.',
                );
            } else {
                // Count attempts regardless of whether a token is recognized.
                auth_throttle_record_attempt(
                    'admin_support_redemption',
                    $visitor,
                );
                $claimed = admin_support_redeem($token);
                if ($claimed === null) {
                    $error = t(
                        'admin.support.invalid_invitation',
                        'This invitation is invalid, used, expired or revoked.',
                    );
                } else {
                    admin_auth_clear_remember_cookie();
                    // Establish a fresh identity, detached from any prior
                    // viewer/admin CSRF state and persistent login cookie.
                    $_SESSION = [];
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) (
                        $claimed['grant']['owner_user_id']
                    );
                    $_SESSION['admin_support'] = [
                        'grant_id' => (int) $claimed['grant']['id'],
                        'owner_id' => (int) (
                            $claimed['grant']['owner_user_id']
                        ),
                        'nonce' => $claimed['nonce'],
                    ];
                    redirect_to(url_for('admin'));
                }
            }
        } catch (\Throwable) {
            $error = t(
                'admin.support.storage_unavailable',
                'Support-access storage is unavailable. Apply database migrations and try again.',
            );
        }
    }

    \Gallery\Views\view_render_admin_support_redeem([
        'csrf_html' => csrf_field(),
        'error' => $error,
        'submit_url' => url_for('admin_support_redeem'),
        'owner_url' => url_for('admin_login'),
    ]);
}
