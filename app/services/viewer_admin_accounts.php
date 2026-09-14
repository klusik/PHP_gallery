<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_admin_accounts.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides administrator-only provisioning and deletion primitives for the separate viewer identity domain.
 *
 * Responsibilities:
 *   - Create active viewer accounts directly without issuing or consuming viewer invitations
 *   - Generate or accept one temporary password that must be replaced after the first successful password login
 *   - List bounded viewer-account metadata for the administrator management screen
 *   - Permanently delete one viewer account through existing foreign-key lifecycle semantics
 *   - Keep administrator identity separate from viewer ownership and authentication authority
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
 *   - Administrator provisioning never writes the Admin user id into viewer authentication/session state.
 *   - Temporary passwords are hashed immediately and must never be logged or emailed by this service.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Models\viewer_account_model_admin_list;
use function Gallery\Models\viewer_account_model_admin_create;
use function Gallery\Models\viewer_account_model_admin_delete;

/**
 * Return the schema capability required for direct administrator viewer provisioning/listing.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_admin_account_schema_status(): array
{
    return schema_inspection_feature('viewer.admin_account_management', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_column('viewer_accounts', 'must_change_password'),
        schema_inspection_table('viewer_account_state'),
    ]);
}

/**
 * Return true only when direct administrator viewer provisioning/listing storage is available.
 *
 * @return bool True only for confirmed available storage.
 */
function viewer_admin_account_storage_available(): bool
{
    return schema_inspection_is_available(viewer_admin_account_schema_status());
}

/**
 * Return true only when all viewer-owned authority can be safely removed with an account.
 *
 * @return bool True only for confirmed available destructive lifecycle storage.
 */
function viewer_admin_account_deletion_storage_available(): bool
{
    if (!viewer_admin_account_storage_available()) {
        return false;
    }
    return schema_inspection_is_available(viewer_account_deletion_schema_status());
}

/**
 * Generate one high-entropy temporary viewer password suitable for administrator provisioning.
 *
 * The returned value is plaintext and must be shown only to the administrator delivery path.
 * It is never persisted outside the normal password hash.
 *
 * @return string Temporary password that satisfies the viewer password policy.
 */
function viewer_admin_account_generate_temporary_password(): string
{
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $password = security_opaque_token_generate(24);
        if (viewer_password_input_is_acceptable($password)) {
            return $password;
        }
    }
    throw new RuntimeException('A temporary viewer password could not be generated safely.');
}

/**
 * List bounded viewer-account metadata for the administrator management screen.
 *
 * Password hashes, sessions, remember credentials, collection contents, and other authority-bearing
 * state are intentionally excluded from the result.
 *
 * @param int $limit Maximum number of recent accounts to return.
 * @return array<int,array<string,mixed>> Viewer-account rows ordered newest first.
 */
function viewer_admin_account_list(int $limit = 250): array
{
    if (!viewer_admin_account_storage_available()) {
        throw new RuntimeException('Viewer account management storage is unavailable.');
    }
    return viewer_account_model_admin_list($limit);
}

/**
 * Create one viewer account directly under administrator authority.
 *
 * The administrator controls only the account creation request. Viewer ownership remains the new
 * viewer account id, not the administrator id. The email is treated as administratively provisioned
 * and therefore marked verified immediately. The temporary password never establishes a normal
 * viewer principal because must_change_password is set until the first-login replacement succeeds.
 *
 * @param int $adminUserId Authenticated administrator user id used only for audit context.
 * @param string $email Viewer login/recovery email.
 * @param ?string $temporaryPassword Optional administrator-supplied temporary password; null/blank generates one.
 * @return array{created:bool,reason:string,account_id:?int,email:?string,temporary_password:?string,password_generated:bool}
 */
function viewer_admin_account_create(int $adminUserId, string $email, ?string $temporaryPassword = null): array
{
    if ($adminUserId <= 0) {
        throw new InvalidArgumentException('Administrator identity is required for viewer provisioning.');
    }
    if (!viewer_admin_account_storage_available()) {
        return [
            'created' => false,
            'reason' => 'storage_unavailable',
            'account_id' => null,
            'email' => null,
            'temporary_password' => null,
            'password_generated' => false,
        ];
    }

    $submittedEmail = trim($email);
    $normalizedEmail = viewer_email_normalize($submittedEmail);
    if ($normalizedEmail === null) {
        return [
            'created' => false,
            'reason' => 'invalid_email',
            'account_id' => null,
            'email' => null,
            'temporary_password' => null,
            'password_generated' => false,
        ];
    }

    $providedPassword = $temporaryPassword ?? '';
    $passwordGenerated = $providedPassword === '';
    $password = $passwordGenerated ? viewer_admin_account_generate_temporary_password() : $providedPassword;
    if (!viewer_password_input_is_acceptable($password)) {
        return [
            'created' => false,
            'reason' => 'password_policy',
            'account_id' => null,
            'email' => $submittedEmail,
            'temporary_password' => null,
            'password_generated' => $passwordGenerated,
        ];
    }

    try {
        $result = viewer_account_model_admin_create(
            $submittedEmail,
            $normalizedEmail,
            viewer_password_hash($password),
            VIEWER_ACCOUNT_STATUS_ACTIVE,
            viewer_account_cap(),
            VIEWER_ACCOUNT_CAPACITY_STATE_KEY,
            now_sql()
        );
    } catch (Throwable $exception) {
        if ((string) $exception->getCode() === '23000') {
            $result = ['created' => false, 'reason' => 'account_exists', 'account_id' => null];
        } else {
            throw $exception;
        }
    }

    if (empty($result['created'])) {
        return [
            'created' => false,
            'reason' => (string) ($result['reason'] ?? 'storage_unavailable'),
            'account_id' => null,
            'email' => $submittedEmail,
            'temporary_password' => null,
            'password_generated' => $passwordGenerated,
        ];
    }

    $accountId = (int) ($result['account_id'] ?? 0);
    viewer_security_event_record_best_effort('viewer.account_admin_created', $accountId, 'success', [
        'admin_user_id' => $adminUserId,
        'must_change_password' => true,
    ]);
    return [
        'created' => true,
        'reason' => 'created',
        'account_id' => $accountId,
        'email' => $submittedEmail,
        'temporary_password' => $password,
        'password_generated' => $passwordGenerated,
    ];
}

/**
 * Permanently delete one viewer account selected by an authenticated administrator.
 *
 * Existing foreign-key cascades remove viewer-owned sessions, remember/reset/verification authority,
 * favourites, collections/items, shares, and passkeys. Administrator users/sessions, galleries, images,
 * gallery share links, and Smart Galleries are not referenced by this operation.
 *
 * @param int $adminUserId Authenticated administrator user id used only for audit context.
 * @param int $viewerAccountId Viewer account id to delete.
 * @return array{deleted:bool,reason:string}
 */
function viewer_admin_account_delete(int $adminUserId, int $viewerAccountId): array
{
    if ($adminUserId <= 0 || $viewerAccountId <= 0) {
        throw new InvalidArgumentException('Administrator and viewer account identifiers must be positive integers.');
    }
    if (!viewer_admin_account_deletion_storage_available()) {
        return ['deleted' => false, 'reason' => 'storage_unavailable'];
    }

    $result = viewer_account_model_admin_delete(
        $viewerAccountId,
        VIEWER_ACCOUNT_CAPACITY_STATE_KEY,
        now_sql()
    );
    if (empty($result['deleted'])) {
        return ['deleted' => false, 'reason' => (string) ($result['reason'] ?? 'not_found')];
    }

    viewer_security_event_record_best_effort('viewer.account_admin_deleted', null, 'success', [
        'admin_user_id' => $adminUserId,
        'deleted_viewer_account_id' => $viewerAccountId,
        'security_version' => (int) ($result['security_version'] ?? 0),
    ]);
    return ['deleted' => true, 'reason' => 'account_deleted'];
}
