<?php
/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Exercise token fidelity, schema refusal and redacted review without a live database.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_edit_concurrency_test.php
 * Module Type: Regression Test
 * Purpose: Verify token fidelity, schema refusal and secret-free conflict review without a database.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_edit_runtime.php';
require_once __DIR__ . '/support/gallery_workflow_safety.php';
require_once dirname(__DIR__) . '/app/views/admin_gallery_edit_tabs.php';

use Gallery\Services\GalleryEditUnavailable;
use function Gallery\Services\gallery_edit_begin;
use function Gallery\Services\gallery_edit_comparison;
use function Gallery\Services\gallery_edit_revision;
use function Gallery\Services\gallery_edit_review_draft;
use function Gallery\Services\gallery_edit_schema_state;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
use function Gallery\Views\view_render_admin_gallery_edit_conflict;
use function Gallery\Views\view_render_admin_gallery_editor_form_open;
use function GalleryWorkflow\check;

$gallery = ['id' => 8, 'edit_revision' => '17', 'title' => 'Original',
    'access_password_hash' => 'private-password-hash', 'access_token_hash' => 'private-token-hash',
    'access_share_token' => 'private-share-token'];
check(gallery_edit_revision($gallery) === '17', 'Rendered revision must belong to the supplied row.');
foreach ([null, [], '', '0', '01', '-1', '1e3', '17 ', '0x10', '100000000000000000000'] as $invalid) {
    check(gallery_edit_revision(['edit_revision' => $invalid]) === '', 'Malformed revision accepted.');
}
$comparison = gallery_edit_comparison($gallery);
check($comparison === ['id' => 8, 'edit_revision' => '17', 'title' => 'Original'], 'Comparison exposed non-allowlisted fields.');
$draft = gallery_edit_review_draft($gallery + ['access_password' => 'private-submitted-password',
    'csrf_token' => 'private-csrf', 'folder_name' => '</textarea><script>unsafe</script>', 'tags' => 'keep these tags',
    'gallery_thumbnail_bounds_recursive' => '1', 'remove_branding_logo' => '1']);
check(!isset($draft['id'], $draft['edit_revision'], $draft['access_password']), 'Draft contains transport or credential fields.');
check(($draft['gallery_thumbnail_bounds_recursive'] ?? '') === '1' && ($draft['remove_branding_logo'] ?? '') === '1',
    'Review dropped an entered recursive or asset-removal choice.');
ob_start();
view_render_admin_gallery_editor_form_open(['gallery_id' => 8, 'edit_revision' => gallery_edit_revision($gallery)]);
$form = (string) ob_get_clean();
check(str_contains($form, 'name="edit_revision" value="17"'), 'Form omitted exact prepared revision.');
ob_start();
view_render_admin_gallery_edit_conflict(['message' => 'Conflict', 'reload_url' => '?id=8', 'latest' => $comparison,
    'draft' => $draft, 'labels' => ['draft' => 'Entered', 'latest' => 'Latest', 'open' => 'Review', 'help' => 'Keep this page']]);
$html = (string) ob_get_clean();
check(str_contains($html, 'keep these tags') && str_contains($html, 'target="_blank"'), 'No-JavaScript review lost entered values or latest-editor recovery.');
check(!str_contains($html, '<script>') && !str_contains($html, 'private-'), 'Conflict markup leaked secrets or unescaped values.');

schema_inspection_set_query_executor_for_tests(
    /**
     * Simulate a conclusive missing revision column without connecting to any database.
     * @param string $objectType Metadata object kind.
     * @param string $table Owning table.
     * @param string $object Requested column.
     * @return bool Confirmed absence.
     * @author Rudolf Klusal
     */
    static function (string $objectType, string $table, string $object): bool { return false; });
check(gallery_edit_schema_state() === 'missing', 'Missing revision state was not preserved.');
try {
    gallery_edit_begin($gallery, '17');
    throw new RuntimeException('Missing revision schema allowed a mutation.');
} catch (GalleryEditUnavailable $exception) {
    check(str_contains($exception->getMessage(), 'migration'), 'Missing schema should explain migration recovery.');
}

schema_inspection_set_query_executor_for_tests(
    /**
     * Simulate an unavailable metadata source; exception text must never escape.
     * @param string $objectType Metadata object kind.
     * @param string $table Owning table.
     * @param string $object Requested column.
     * @return bool Never returns; throws the injected metadata exception.
     * @author Rudolf Klusal
     */
    static function (string $objectType, string $table, string $object): bool { throw new RuntimeException('private-database-detail'); });
check(gallery_edit_schema_state() === 'unknown', 'Inspection uncertainty was collapsed into missing.');
try {
    gallery_edit_begin($gallery, '17');
    throw new RuntimeException('Unknown revision schema allowed a mutation.');
} catch (GalleryEditUnavailable $exception) {
    check(!str_contains($exception->getMessage(), 'private-'), 'Raw metadata exception escaped.');
}
schema_inspection_set_query_executor_for_tests(null);
foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $json = json_decode((string) file_get_contents(dirname(__DIR__) . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
    $fallback = require dirname(__DIR__) . '/app/lang/' . $language . '.php';
    foreach (['busy', 'conflict', 'schema_missing', 'schema_unknown', 'connection_lost', 'review_title', 'review_draft',
        'review_latest', 'review_open', 'review_help'] as $suffix) {
        $key = 'admin.gallery_editor.edit_' . $suffix;
        check(is_string($json[$key] ?? null) && $json[$key] !== '' && ($fallback[$key] ?? null) === $json[$key],
            'A maintained conflict translation or matching PHP fallback is missing.');
    }
}
echo "PASS gallery edit revision fidelity, secret-free review, missing and unknown refusal\n";
