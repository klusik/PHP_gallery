<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_gallery_quick_access_test.php
 * Module Type: Regression Test
 * Purpose: Verify shared widget safety and bounded password policy without application data.
 * Responsibilities:
 *   - Render both shared visibility widget modes and verify embedded form safety.
 *   - Verify password field isolation and missing/unknown schema refusal without application data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_edit_runtime.php';
require_once dirname(__DIR__) . '/app/services/gallery_access.php';
require_once dirname(__DIR__) . '/app/services/gallery_editor_quick_access.php';
require_once dirname(__DIR__) . '/app/views/public_gallery_cards.php';

/**
 * Refuse a failed confined regression with a clear description.
 *
 * @param bool $condition Expected contract result.
 * @param string $message Failure guidance without test credential values.
 * @return void
 */
function quick_access_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$model = ['entity_id' => 7, 'kind' => 'gallery', 'name' => '<unsafe>', 'visibility' => 'private',
    'action_url' => '/synthetic?gallery=7&x=<unsafe>', 'csrf_html' => '<input name="csrf_token" value="fixture">', 'edit_revision' => '13'];
ob_start();
\Gallery\Views\view_render_public_admin_visibility_menu($model);
$public = (string) ob_get_clean();
quick_access_check(substr_count($public, '<form ') === 3 && substr_count($public, 'type="submit"') === 3, 'Public mode lost native POST fallbacks.');
ob_start();
\Gallery\Views\view_render_public_admin_visibility_menu($model + ['embedded' => true]);
$embedded = (string) ob_get_clean();
quick_access_check(!str_contains($embedded, '<form') && !str_contains($embedded, '<input'), 'Embedded widget introduced fields or nested forms.');
quick_access_check(substr_count($embedded, 'data-admin-gallery-visibility-choice') === 3 && substr_count($embedded, 'type="button"') === 3, 'Embedded choices must remain native buttons.');
quick_access_check(str_contains($embedded, 'data-edit-revision="13"') && str_contains($embedded, 'data-gallery-id="7"'), 'Embedded metadata lost exact prepared identity/revision.');
quick_access_check(str_contains($embedded, '&lt;unsafe&gt;') && !str_contains($embedded, '<unsafe>'), 'Prepared endpoint metadata was not escaped.');
quick_access_check(substr_count($embedded, 'aria-pressed="true"') === 1, 'Current visibility selection must remain explicit.');

$gallery = ['id' => 7, 'edit_revision' => '13', 'visibility' => 'private', 'access_listing' => 'unlisted',
    'access_token_hash' => 'fixture-token', 'access_share_token' => 'fixture-display', 'parent_id' => 8];
$enabled = \Gallery\Services\gallery_editor_own_password_fields($gallery, true, ' fixture password ');
quick_access_check(array_keys($enabled) === ['access_password_hash', 'access_mode'], 'Password change escaped its two owned fields.');
quick_access_check(password_verify('fixture password', (string) $enabled['access_password_hash']), 'Password did not use existing editor trim/hash semantics.');
quick_access_check($enabled['access_mode'] === 'password', 'Password enable lost protected mode.');
$disabled = \Gallery\Services\gallery_editor_own_password_fields($gallery, false, '');
quick_access_check($disabled === ['access_password_hash' => null, 'access_mode' => 'password'], 'Password removal revoked token protection.');
$safeState = \Gallery\Services\gallery_editor_quick_access_state($gallery + $disabled);
quick_access_check($safeState['access_label'] === 'Direct-link token' && $safeState['visibility_label'] === 'private', 'Acknowledged state lost bounded localized token/visibility labels.');
quick_access_check(!array_key_exists('access_token_hash', $safeState) && !array_key_exists('access_share_token', $safeState), 'Acknowledged state exposed credential storage.');
unset($gallery['access_token_hash']);
quick_access_check(\Gallery\Services\gallery_editor_own_password_fields($gallery, false, '')['access_mode'] === 'normal', 'Password-only removal left a phantom local password.');
try {
    \Gallery\Services\gallery_editor_own_password_fields($gallery, true, '   ');
    throw new RuntimeException('Empty password was accepted.');
} catch (\Gallery\Services\GalleryQuickAccessRefusal $exception) {
    quick_access_check(str_contains($exception->getMessage(), 'password'), 'Empty password refusal lacks guidance.');
}
foreach (['missing', 'unknown'] as $state) {
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        /**
         * Simulate bounded metadata state without any database connection.
         * @param string $type Metadata object type.
         * @param string $table Validated table identifier.
         * @param string $column Validated column identifier.
         * @return bool Confirmed absence, or an injected inspection failure.
         */
        static function (string $type, string $table, string $column) use ($state): bool {
            if ($state === 'unknown') throw new RuntimeException('private fixture diagnostic');
            return false;
        }
    );
    try {
        \Gallery\Services\gallery_editor_change_own_password($gallery, '13', false, '');
        throw new RuntimeException('Unverified access schema allowed a mutation.');
    } catch (\Gallery\Services\GalleryQuickAccessRefusal $exception) {
        quick_access_check(!str_contains($exception->getMessage(), 'private fixture'), 'Schema failure exposed raw diagnostics.');
    }
}
\Gallery\Services\schema_inspection_set_query_executor_for_tests(
    /**
     * Simulate verified access/revision columns without application persistence.
     * @param string $type Metadata object type.
     * @param string $table Validated table identifier.
     * @param string $column Validated column identifier.
     * @return bool Verified availability.
     */
    static function (string $type, string $table, string $column): bool { return true; }
);
try {
    \Gallery\Services\gallery_editor_change_own_password($gallery, [], true, 'fixture password');
    throw new RuntimeException('Malformed exact revision was accepted.');
} catch (\Gallery\Services\GalleryEditConflict $exception) {
    quick_access_check(!array_key_exists('access_share_token', $exception->latest), 'Revision refusal leaked a share credential.');
}
echo "Admin gallery quick access contracts passed.\n";
