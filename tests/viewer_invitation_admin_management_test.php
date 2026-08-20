<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_invitation_admin_management_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects administrator invitation email visibility and permanent deletion semantics.
 *
 * Responsibilities:
 *   - Verify new invitations retain an administrator-visible intended email
 *   - Verify the HMAC fingerprint remains the actual email-binding authority
 *   - Verify deleting an invitation invalidates staged authority before removing the row
 *   - Verify Admin invitation deletion remains CSRF-protected and does not touch viewer accounts
 *   - Verify maintained translation catalogs expose the new Admin labels
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

/**
 * Throw when one invitation-management expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function viewer_invitation_admin_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Extract one named PHP function body from source for bounded static assertions.
 *
 * @param string $source PHP source.
 * @param string $functionName Function name.
 * @return string Function source including declaration and body.
 */
function viewer_invitation_admin_function_source(string $source, string $functionName): string
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
$service = (string) file_get_contents($root . '/app/services/viewer_registration.php');
$controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
$migration = (string) file_get_contents($root . '/database/migrations/202608180005_viewer_invitation_admin_management.php');

viewer_invitation_admin_assert(str_contains($migration, 'ADD COLUMN target_email VARCHAR(190) NULL'), 'Migration must add the administrator-visible intended email column.');
viewer_invitation_admin_assert(str_contains($service, "schema_inspection_column('viewer_invitations', 'target_email')"), 'Registration storage must fail closed until the invitation email column exists.');
viewer_invitation_admin_assert(str_contains($service, '(token_hash, target_email, target_email_fingerprint,'), 'Invitation issuance must persist both display email and authorization fingerprint.');
viewer_invitation_admin_assert(str_contains($service, 'SELECT vi.id, vi.target_email,'), 'Administrator invitation list must load the intended email.');
viewer_invitation_admin_assert(!str_contains(viewer_invitation_admin_function_source($service, 'viewer_invitation_list_for_admin'), 'vi.token_hash'), 'Administrator invitation list must never expose invitation token hashes.');

$deleteService = viewer_invitation_admin_function_source($service, 'viewer_invitation_delete');
$revokePosition = strpos($deleteService, 'viewer_invitation_revoke($invitationId);');
$deletePosition = strpos($deleteService, "DELETE FROM viewer_invitations WHERE id = ?");
viewer_invitation_admin_assert($revokePosition !== false && $deletePosition !== false && $revokePosition < $deletePosition, 'Deletion must revoke staged authority before deleting the invitation row.');
viewer_invitation_admin_assert(!str_contains($deleteService, 'DELETE FROM viewer_accounts') && !str_contains($deleteService, 'session_destroy()'), 'Invitation deletion must not delete viewer accounts or destroy shared sessions.');

$adminController = viewer_invitation_admin_function_source($controller, 'cms_admin_viewer_invitations');
viewer_invitation_admin_assert(str_contains($adminController, "elseif (\$action === 'delete')"), 'Admin invitation page must handle the delete action.');
viewer_invitation_admin_assert(str_contains($adminController, 'viewer_invitation_delete($invitationId)'), 'Controller must delegate deletion to the invitation service.');
viewer_invitation_admin_assert(str_contains($adminController, 'verify_csrf();'), 'Invitation deletion must remain protected by Admin CSRF.');
viewer_invitation_admin_assert(str_contains($adminController, 'viewer.admin.invites.email'), 'Invitation table must render an Email column.');
viewer_invitation_admin_assert(str_contains($adminController, 'viewer.admin.invites.delete_button'), 'Invitation table must render a Delete action.');
viewer_invitation_admin_assert(!str_contains($adminController, 'DELETE FROM viewer_invitations'), 'Controller must not duplicate invitation deletion SQL.');

foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
    viewer_invitation_admin_assert(is_array($catalog), 'Translation catalog failed to decode: ' . $language);
    foreach ([
        'viewer.admin.invites.email',
        'viewer.admin.invites.email_any',
        'viewer.admin.invites.email_legacy_bound',
        'viewer.admin.invites.delete_button',
        'viewer.admin.invites.deleted',
        'viewer.admin.invites.delete_failed',
    ] as $key) {
        viewer_invitation_admin_assert(isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '', 'Missing invitation management translation ' . $key . ' in ' . $language . '.');
    }
}

echo "Viewer invitation Admin management tests passed.\n";
